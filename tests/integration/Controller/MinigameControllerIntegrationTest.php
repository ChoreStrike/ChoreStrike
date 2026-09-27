<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\MinigameController;
use OCA\ChoreStrike\Service\MinigameService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

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
 */
class MinigameControllerIntegrationTest extends IntegrationTestCase {
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
		return array_map(static fn (string $method): array => [$method, []], [
			'leaderboard', 'weeklyLeaderboard', 'saveScore', 'personalBest', 'userRank', 'recentScores',
		]);
	}

	/** @dataProvider scoreGames */
	public function testUnregisteredGame_DoesNotExpandWhitelist(string $gameId): void {
		['houseId' => $houseId] = $this->houseWithMembers();
		$response = $this->controller('mg_parent')->saveScore($gameId, 100);
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

	protected function tearDown(): void {
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

	private function addInstanceMembership(int $houseId, string $userId, string $displayName): int {
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
				'role' => $qb->createNamedParameter('member'),
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
	 * SaveScore_PersistsScoreWithoutAnyEconomySideEffect
	 */
	public function testSaveScore_PersistsScoreWithoutAnyEconomySideEffect(): void {
		['houseId' => $houseId] = $this->houseWithMembers();

		$response = $this->controller('mg_parent')->saveScore('snake', 250);

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

		$negative = $this->controller('mg_parent')->saveScore('snake', -1);
		self::assertSame(400, $negative->getStatus());
		self::assertFalse($negative->getData()['success']);
		self::assertSame('VALIDATION_ERROR', $negative->getData()['error']['code']);

		$overCap = $this->controller('mg_parent')->saveScore('snake', MinigameService::MAX_SCORE + 1);
		self::assertSame(400, $overCap->getStatus());
		self::assertSame('VALIDATION_ERROR', $overCap->getData()['error']['code']);
	}

	/**
	 * SaveScore_UnknownGameId_Get400ValidationEnvelope
	 */
	public function testSaveScore_UnknownGameId_Get400ValidationEnvelope(): void {
		$this->houseWithMembers();

		$response = $this->controller('mg_parent')->saveScore('tetris', 100);

		self::assertSame(400, $response->getStatus());
		self::assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	/**
	 * SaveScore_NonMember_IsDeniedWith403Envelope_AndZeroWrites
	 */
	public function testSaveScore_NonMember_IsDeniedWith403Envelope_AndZeroWrites(): void {
		['houseId' => $houseId] = $this->houseWithMembers();

		// An authenticated Nextcloud account WITHOUT a house membership.
		$response = $this->controller('mg_outsider')->saveScore('snake', 100);

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
			$service->saveScore($houseId, 'mg_parent', 'snake', 10);
		}

		$response = $this->controller('mg_parent')->saveScore('snake', 10);
		self::assertSame(200, $response->getStatus());

		$limited = $this->controller('mg_parent')->saveScore('snake', 10);
		self::assertSame(429, $limited->getStatus());
		self::assertSame('MINIGAME_RATE_LIMITED', $limited->getData()['error']['code']);
		self::assertSame(100, $this->scoreRowCount($houseId), 'the limited submission must not persist');
	}

	/**
	 * Leaderboard_WeeklyLeaderboard_Rank_PersonalBest_Recent_ReadRealRows
	 */
	public function testLeaderboard_WeeklyLeaderboard_Rank_PersonalBest_Recent_ReadRealRows(): void {
		$this->houseWithMembers();

		$this->controller('mg_parent')->saveScore('snake', 300);
		$this->controller('mg_parent')->saveScore('snake', 120);
		$this->controller('mg_teener')->saveScore('snake', 450);
		$this->controller('mg_teener')->saveScore('snake', 60);

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

		// Unknown game READ: reads mint nothing, so the catalog whitelist
		// (D7) applies to writes only — the read answers an empty board.
		$unknown = $this->controller('mg_parent')->leaderboard('pacman');
		self::assertSame(200, $unknown->getStatus());
		self::assertSame([], $unknown->getData()['data']);
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

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
