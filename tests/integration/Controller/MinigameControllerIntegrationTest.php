<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\HouseController;
use OCA\ChoreStrike\Controller\MinigameController;
use OCA\ChoreStrike\Db\MinigameScore;
use OCA\ChoreStrike\Db\MinigameScoreMapper;
use OCA\ChoreStrike\Service\ArcadeCatalog;
use OCA\ChoreStrike\Service\MinigameService;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;

/**
 * Real-DB controller coverage for the registered scores-only minigame
 * routes (CS-PLAN-068 §3.5; WP3 cutover T10/D7d): saveScore, leaderboard,
 * weekly leaderboard, personal best, rank and recent scores — with
 * membership denial, validation and limit failure envelopes. The coin and
 * boost surfaces retired with the wallet cutover.
 *
 * Dispatch/middleware behavior on the wire additionally stays owned by the
 * browser suites (snake-game.spec.ts) per the WireStatusAuthDenialTest
 * evidence-layer convention; here the real service/DB stack is exercised
 * through the DI container.
 *
 * CS-PLAN-086 WP-B: the score contract runs with the owner gate OFF (the
 * test members finished no chores); the gate tests switch it on
 * explicitly. tearDown restores the default either way.
 */
class MinigameControllerIntegrationTest extends IntegrationTestCase {
	/** A one-minute round: Snake accepts up to 1900 points (D-086-04). */
	private const ROUND_MS = 60000;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig()->setValueString('chorestrike', RewardConfigService::KEY_ARCADE_GATE_ENABLED, '0');
	}

	private function appConfig(): IAppConfig {
		return \OC::$server->get(IAppConfig::class);
	}

	private function gateOn(): void {
		$this->appConfig()->deleteKey('chorestrike', RewardConfigService::KEY_ARCADE_GATE_ENABLED);
	}

	/** @dataProvider membershipRoutes */
	public function testRegisteredRoute_NonMember_DeniesWithoutWrites(string $method, array $arguments): void {
		['houseId' => $houseId] = $this->houseWithMembers();
		$before = $this->scoreRowCount($houseId);
		$response = $this->controller('mg_outsider')->$method(...$arguments);
		self::assertSame(403, $response->getStatus());
		self::assertSame('FORBIDDEN', $response->getData()['error']['code']);
		self::assertSame($before, $this->scoreRowCount($houseId));
	}

	public static function membershipRoutes(): array {
		return [
			'leaderboard' => ['leaderboard', ['snake']],
			'weekly leaderboard' => ['weeklyLeaderboard', ['snake']],
			'save score' => ['saveScore', ['snake', 100, self::ROUND_MS]],
			'personal best' => ['personalBest', ['snake']],
			'rank' => ['userRank', ['snake']],
			'recent scores' => ['recentScores', ['snake']],
		];
	}

	/** @dataProvider scoreGames */
	public function testUnregisteredGame_DoesNotExpandWhitelist(string $gameId): void {
		['houseId' => $houseId] = $this->houseWithMembers();
		$response = $this->controller('mg_parent')->saveScore($gameId, 100, self::ROUND_MS);
		self::assertSame(400, $response->getStatus());
		self::assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
		self::assertSame(0, $this->scoreRowCount($houseId));
	}

	public static function scoreGames(): array {
		return [['2048'], ['memory']];
	}

	/**
	 * RetiredCoinAndBoostSurfaces_StayGone (T10/D7d)
	 *
	 * The arcade is scores-only: the coin balance and boost purchase
	 * endpoints must not silently linger on the controller.
	 */
	public function testRetiredCoinAndBoostSurfaces_StayGone(): void {
		$controller = \OC::$server->get(MinigameController::class);
		self::assertFalse(method_exists($controller, 'coins'));
		self::assertFalse(method_exists($controller, 'boosts'));
		self::assertFalse(method_exists($controller, 'purchaseBoost'));
	}

	/** @var array<int> Instance-house membership ids created by these tests. */
	private array $instanceMembershipIds = [];
	/** @var int[] */
	private array $orderedScoreIds = [];

	protected function tearDown(): void {
		if ($this->orderedScoreIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_minigame_scores')->where($qb->expr()->in('id', $qb->createNamedParameter($this->orderedScoreIds, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
		}
		if ($this->instanceMembershipIds !== []) {
			// Scores carry the NC user id (string) and the instance house id;
			// remove the arcade scores and memberships the tests created.
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_minigame_scores')
				->where($qb->expr()->eq(
					'house_id',
					$qb->createNamedParameter($this->instanceHouseId(), IQueryBuilder::PARAM_INT)
				))
				->andWhere($qb->expr()->in(
					'user_id',
					$qb->createNamedParameter(['mg_parent', 'mg_teener'], IQueryBuilder::PARAM_STR_ARRAY)
				))
				->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_house_users')
				->where($qb->expr()->in('id', $qb->createNamedParameter($this->instanceMembershipIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
			$this->instanceMembershipIds = [];
		}
		$this->gateOn();
		parent::tearDown();
	}

	private function controller(string $userId): MinigameController {
		$this->actingAs($userId);
		return \OC::$server->get(MinigameController::class);
	}

	private function instanceHouseId(): int {
		$houseService = \OC::$server->get(\OCA\ChoreStrike\Service\HouseService::class);
		return $houseService->getOrCreateHouse($houseService->getInstanceId())->getId();
	}

	/**
	 * The MinigameController endpoints resolve the SHARED instance house
	 * (HouseService::getInstanceId); memberships are explicit (ADR-010).
	 *
	 * @return array{houseId: int, parentMemberId: int, teenMemberId: int}
	 */
	private function houseWithMembers(): array {
		$houseService = \OC::$server->get(\OCA\ChoreStrike\Service\HouseService::class);
		$houseId = $houseService->getOrCreateHouse($houseService->getInstanceId())->getId();
		return [
			'houseId' => $houseId,
			'parentMemberId' => $this->addInstanceMembership($houseId, 'mg_parent', 'Arcade Parent'),
			'teenMemberId' => $this->addInstanceMembership($houseId, 'mg_teener', 'Teen'),
		];
	}

	private function addInstanceMembership(int $houseId, string $userId, string $displayName, string $role = 'member'): int {
		$existing = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
			->findByHouseAndUser($houseId, $userId);
		if ($existing !== null) {
			return $existing->getId();
		}
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_house_users')
			->values([
				'house_id' => $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT),
				'nextcloud_user_id' => $qb->createNamedParameter($userId),
				'display_name' => $qb->createNamedParameter($displayName),
				'role' => $qb->createNamedParameter($role),
				'contribution_minor' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'current_streak' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'longest_streak' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'unlocked_titles' => $qb->createNamedParameter('[]'),
				'joined_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			])
			->executeStatement();
		$id = (int)$this->db->lastInsertId('chorestrike_house_users');
		$this->instanceMembershipIds[] = $id;
		return $id;
	}

	/**
	 * Catalog_MemberSeesLockStates_NonMemberDenied (CS-PLAN-086 §3.2)
	 */
	public function testCatalog_MemberSeesLockStates_NonMemberDenied(): void {
		$this->houseWithMembers();
		$this->gateOn();

		$response = $this->controller('mg_parent')->catalog();

		self::assertSame(200, $response->getStatus());
		$catalog = $response->getData()['data'];
		self::assertTrue($catalog['gateEnabled']);
		self::assertSame(0, $catalog['finishedChores'], 'the test member finished no chore');
		self::assertSame(
			[['gameId' => 'snake', 'threshold' => 10, 'unlocked' => false]],
			$catalog['games']
		);

		$denied = $this->controller('mg_outsider')->catalog();
		self::assertSame(403, $denied->getStatus());
		self::assertSame('FORBIDDEN', $denied->getData()['error']['code']);
	}

	/**
	 * SaveScore_LockedGame_Get403MinigameLocked_BoardStaysReadable
	 */
	public function testSaveScore_LockedGame_Get403MinigameLocked_BoardStaysReadable(): void {
		['houseId' => $houseId] = $this->houseWithMembers();
		$this->controller('mg_teener')->saveScore('snake', 80, self::ROUND_MS);
		$this->gateOn();

		$locked = $this->controller('mg_parent')->saveScore('snake', 100, self::ROUND_MS);

		self::assertSame(403, $locked->getStatus());
		self::assertSame('MINIGAME_LOCKED', $locked->getData()['error']['code']);
		self::assertSame(1, $this->scoreRowCount($houseId), 'a locked game takes no score');
		$board = $this->controller('mg_parent')->leaderboard('snake');
		self::assertSame(200, $board->getStatus());
		$ownRows = array_values(array_filter(
			$board->getData()['data'],
			static fn (array $row): bool => in_array($row['userId'], ['mg_parent', 'mg_teener'], true)
		));
		self::assertSame([['mg_teener', 80]], array_map(static fn (array $row): array => [$row['userId'], $row['score']], $ownRows), 'the locked game keeps its readable board');
	}

	/**
	 * ArcadeGate_OwnerSwitchPersistsAndOpensEveryGame_MemberDenied
	 * (D-086-02): the owner-only writer stores the flag in app config, the
	 * house payload reflects it, and every member can play every game.
	 */
	public function testArcadeGate_OwnerSwitchPersistsAndOpensEveryGame_MemberDenied(): void {
		['houseId' => $houseId] = $this->houseWithMembers();
		$this->addInstanceMembership($houseId, 'mg_owner', 'Arcade Owner', 'owner');
		$this->gateOn();

		$denied = $this->gateWriter('mg_teener', false);
		self::assertSame(403, $denied->getStatus());
		self::assertSame('1', $this->appConfig()->getValueString('chorestrike', RewardConfigService::KEY_ARCADE_GATE_ENABLED, '1'));

		$invalid = $this->gateWriter('mg_owner', 'off');
		self::assertSame(400, $invalid->getStatus());
		self::assertSame('VALIDATION_ERROR', $invalid->getData()['error']['code']);

		$opened = $this->gateWriter('mg_owner', false);
		self::assertSame(200, $opened->getStatus());
		self::assertFalse($opened->getData()['data']['arcadeGateEnabled']);
		self::assertSame('0', $this->appConfig()->getValueString('chorestrike', RewardConfigService::KEY_ARCADE_GATE_ENABLED, '1'));

		$games = $this->controller('mg_teener')->catalog()->getData()['data']['games'];
		self::assertSame([true], array_column($games, 'unlocked'));
		self::assertSame(200, $this->controller('mg_teener')->saveScore('snake', 100, self::ROUND_MS)->getStatus());
	}

	private function gateWriter(string $userId, mixed $value): \OCP\AppFramework\Http\JSONResponse {
		$this->actingAs($userId);
		$controller = \OC::$server->get(HouseController::class);
		$this->injectRequestParams($controller, ['arcadeGateEnabled' => $value]);
		return $controller->updateArcadeGate();
	}

	/**
	 * SaveScore_PersistsScoreWithoutAnyEconomySideEffect
	 */
	public function testSaveScore_PersistsScoreWithoutAnyEconomySideEffect(): void {
		['houseId' => $houseId] = $this->houseWithMembers();

		$response = $this->controller('mg_parent')->saveScore('snake', 250, self::ROUND_MS);

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success']);
		self::assertSame(250, $data['data']['score']);
		self::assertSame('snake', $data['data']['gameId']);
		// Scores-only contract: no boost metadata, no coin outputs.
		self::assertArrayNotHasKey('boostsUsed', $data['data']);
		self::assertArrayNotHasKey('coinsEarned', $data['data']);
		// No HouseUser row was mutated (no coins, no contribution).
		$member = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
			->findByNextcloudUserId($houseId, 'mg_parent');
		self::assertSame(0, $member->getContributionMinor());
	}

	/**
	 * SaveScore_OutOfBoundsScores_Get400ValidationEnvelope
	 */
	public function testSaveScore_OutOfBoundsScores_Get400ValidationEnvelope(): void {
		$this->houseWithMembers();

		$negative = $this->controller('mg_parent')->saveScore('snake', -1, self::ROUND_MS);
		self::assertSame(400, $negative->getStatus());
		self::assertFalse($negative->getData()['success']);
		self::assertSame('VALIDATION_ERROR', $negative->getData()['error']['code']);

		$overCap = $this->controller('mg_parent')->saveScore('snake', ArcadeCatalog::GAMES['snake']['maxScore'] + 1, ArcadeCatalog::GAMES['snake']['maxDurationMs']);
		self::assertSame(400, $overCap->getStatus());
		self::assertSame('VALIDATION_ERROR', $overCap->getData()['error']['code']);
	}

	/**
	 * SaveScore_UnknownGameId_Get400ValidationEnvelope
	 */
	public function testSaveScore_UnknownGameId_Get400ValidationEnvelope(): void {
		$this->houseWithMembers();

		$response = $this->controller('mg_parent')->saveScore('tetris', 100, self::ROUND_MS);

		self::assertSame(400, $response->getStatus());
		self::assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	/**
	 * SaveScore_MissingOrImplausibleDuration_Get400_AndZeroWrites (D-086-04)
	 */
	public function testSaveScore_MissingOrImplausibleDuration_Get400_AndZeroWrites(): void {
		['houseId' => $houseId] = $this->houseWithMembers();

		$missing = $this->controller('mg_parent')->saveScore('snake', 100);
		self::assertSame(400, $missing->getStatus());
		self::assertSame('VALIDATION_ERROR', $missing->getData()['error']['code']);

		// 5000 points in one second: far beyond 100 burst + 30 per second.
		$implausible = $this->controller('mg_parent')->saveScore('snake', 5000, 1000);
		self::assertSame(400, $implausible->getStatus());
		self::assertSame('MINIGAME_IMPLAUSIBLE_SCORE', $implausible->getData()['error']['code']);

		self::assertSame(0, $this->scoreRowCount($houseId), 'rejected submissions must not write');
	}

	/**
	 * SaveScore_NonMember_IsDeniedWith403Envelope_AndZeroWrites
	 */
	public function testSaveScore_NonMember_IsDeniedWith403Envelope_AndZeroWrites(): void {
		['houseId' => $houseId] = $this->houseWithMembers();

		// An authenticated Nextcloud account WITHOUT a house membership.
		$response = $this->controller('mg_outsider')->saveScore('snake', 100, self::ROUND_MS);

		self::assertSame(403, $response->getStatus());
		self::assertSame('FORBIDDEN', $response->getData()['error']['code']);
		self::assertSame(0, $this->scoreRowCount($houseId), 'a denied submission must not write');
	}

	/**
	 * SaveScore_DailyCapReached_Get429RateLimitEnvelope
	 */
	public function testSaveScore_DailyCapReached_Get429RateLimitEnvelope(): void {
		['houseId' => $houseId] = $this->houseWithMembers();

		// Seed the cap minus one row through the real service path, then the
		// final submission via the controller crosses DAILY_SCORE_SAVE_LIMIT.
		$service = \OC::$server->get(\OCA\ChoreStrike\Service\MinigameService::class);
		for ($i = 0; $i < MinigameService::DAILY_SCORE_SAVE_LIMIT - 1; $i++) {
			$service->saveScore($houseId, 'mg_parent', 'snake', 10, self::ROUND_MS);
		}

		$response = $this->controller('mg_parent')->saveScore('snake', 10, self::ROUND_MS);
		self::assertSame(200, $response->getStatus());

		$limited = $this->controller('mg_parent')->saveScore('snake', 10, self::ROUND_MS);
		self::assertSame(429, $limited->getStatus());
		self::assertSame('MINIGAME_RATE_LIMITED', $limited->getData()['error']['code']);
		self::assertSame(100, $this->scoreRowCount($houseId), 'the limited submission must not persist');
	}

	/**
	 * Leaderboard_WeeklyLeaderboard_Rank_PersonalBest_Recent_ReadRealRows
	 */
	public function testLeaderboard_WeeklyLeaderboard_Rank_PersonalBest_Recent_ReadRealRows(): void {
		$this->houseWithMembers();

		$this->controller('mg_parent')->saveScore('snake', 300, self::ROUND_MS);
		$this->controller('mg_parent')->saveScore('snake', 120, self::ROUND_MS);
		$this->controller('mg_teener')->saveScore('snake', 450, self::ROUND_MS);
		$this->controller('mg_teener')->saveScore('snake', 60, self::ROUND_MS);

		$board = $this->controller('mg_parent')->leaderboard('snake', 10);
		self::assertSame(200, $board->getStatus());
		$rows = $board->getData()['data'];
		self::assertCount(4, $rows);
		self::assertSame(450, $rows[0]['score']);
		self::assertSame('Teen', $rows[0]['displayName'], 'the leaderboard carries display names');
		self::assertSame(300, $rows[1]['score']);

		$weekly = $this->controller('mg_parent')->weeklyLeaderboard('snake', 10);
		self::assertSame(200, $weekly->getStatus());
		$weeklyRows = $weekly->getData()['data'];
		self::assertCount(4, $weeklyRows, 'all four scores were saved this week');
		self::assertSame(450, $weeklyRows[0]['score']);

		$best = $this->controller('mg_parent')->personalBest('snake');
		self::assertSame(200, $best->getStatus());
		self::assertSame(300, $best->getData()['data']['score']);

		$rank = $this->controller('mg_parent')->userRank('snake');
		self::assertSame(200, $rank->getStatus());
		self::assertSame(2, $rank->getData()['data']['rank'], 'mg_parent (300) ranks behind the 450');
		self::assertSame('snake', $rank->getData()['data']['gameId']);

		$recent = $this->controller('mg_parent')->recentScores('snake', 2);
		self::assertSame(200, $recent->getStatus());
		$recentRows = $recent->getData()['data'];
		self::assertCount(2, $recentRows);
		self::assertSame(300, $recentRows[0]['score'], 'the personal-history read orders by score (shipped behavior)');
		self::assertSame(120, $recentRows[1]['score']);

		// Unknown game READ (CS-PLAN-086): a 400 like the write, never a
		// silent empty board.
		$unknown = $this->controller('mg_parent')->leaderboard('pacman');
		self::assertSame(400, $unknown->getStatus());
		self::assertSame('VALIDATION_ERROR', $unknown->getData()['error']['code']);

		// RF-086-01: a negative or huge limit is clamped, never LIMIT -n or
		// the whole board.
		self::assertCount(1, $this->controller('mg_parent')->leaderboard('snake', -3)->getData()['data']);
		self::assertCount(4, $this->controller('mg_parent')->leaderboard('snake', 100000)->getData()['data']);
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------
	public function testEqualScores_AllOrderedReads_KeepIdOrderAndTiedRanksWithinHouseAndPeriod(): void {
		$houseId = $this->createHouse('mg_tie_owner');
		$foreignId = $this->createHouse('mg_tie_foreign');
		$mapper = \OC::$server->get(MinigameScoreMapper::class);
		$insert = function (int $house, string $user, int $score, string $at = '2026-07-15 12:00:00', string $game = 'snake') use ($mapper): int {
			$row = new MinigameScore();
			$row->setHouseId($house);
			$row->setUserId($user);
			$row->setGameId($game);
			$row->setScore($score);
			$row->setCreatedAt(new \DateTime($at, new \DateTimeZone('UTC')));
			$id = (int)$mapper->insert($row)->getId();
			$this->orderedScoreIds[] = $id;
			return $id;
		};
		$first = $insert($houseId, 'mg_tie_parent', 500);
		$peer = $insert($houseId, 'mg_tie_teen', 500);
		$again = $insert($houseId, 'mg_tie_parent', 500);
		$low = $insert($houseId, 'mg_tie_parent', 100);
		$old = $insert($houseId, 'mg_tie_old', 900, '2026-07-01 12:00:00');
		$insert($foreignId, 'mg_tie_parent', 10000);
		$insert($houseId, 'mg_tie_parent', 10000, '2026-07-15 12:00:00', 'other-game');
		$ids = static fn (array $rows): array => array_map(static fn (MinigameScore $row): int => (int)$row->getId(), $rows);
		self::assertSame([$old, $first, $peer, $again, $low], $ids($mapper->getLeaderboard($houseId, 'snake')));
		self::assertSame([$first, $peer, $again, $low], $ids($mapper->getLeaderboardForPeriod($houseId, 'snake', new \DateTime('2026-07-13', new \DateTimeZone('UTC')))));
		self::assertSame($first, (int)$mapper->getPersonalBest($houseId, 'mg_tie_parent', 'snake')->getId());
		self::assertSame([$first, $again, $low], $ids($mapper->findByUserAndGame($houseId, 'mg_tie_parent', 'snake')));
		self::assertSame(2, $mapper->getUserRank($houseId, 'mg_tie_parent', 'snake'));
		self::assertSame(2, $mapper->getUserRank($houseId, 'mg_tie_teen', 'snake'), 'equal best scores stay tied regardless of row IDs');
	}

	private function scoreRowCount(int $houseId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('chorestrike_minigame_scores')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in(
				'user_id',
				$qb->createNamedParameter(['mg_parent', 'mg_teener'], IQueryBuilder::PARAM_STR_ARRAY)
			));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
