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
 * Real-DB controller coverage for ALL NINE registered minigame routes
 * (CS-PLAN-068 §3.5, CR-053-22): saveScore, coins, leaderboard, weekly
 * leaderboard, personal best, rank, recent scores, boosts and purchase —
 * with membership denial, validation and limit failure envelopes, exact
 * coin deltas and repeat-read idempotence.
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
		self::assertSame(0, (int)$this->walletOf($houseId, 'mg_parent'));
	}

	public static function membershipRoutes(): array {
		return array_map(static fn (string $method): array => [$method, $method === 'purchaseBoost' ? ['magnet'] : []], [
			'leaderboard', 'weeklyLeaderboard', 'saveScore', 'coins', 'personalBest', 'userRank', 'purchaseBoost', 'recentScores',
		]);
	}

	/** @dataProvider scoreGames */
	public function testUnregisteredGame_DoesNotExpandRewardWhitelist(string $gameId): void {
		['houseId' => $houseId] = $this->houseWithMembers();
		$response = $this->controller('mg_parent')->saveScore($gameId, 100);
		self::assertSame(400, $response->getStatus());
		self::assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
		self::assertSame(0, (int)$this->walletOf($houseId, 'mg_parent'));
		self::assertSame(0, $this->scoreRowCount($houseId));
	}

	public static function scoreGames(): array {
		return [['2048'], ['memory']];
	}

	public function testBoosts_GlobalCatalog_IsReadableWithoutHouseMembershipAndDoesNotWrite(): void {
		['houseId' => $houseId] = $this->houseWithMembers();
		$response = $this->controller('mg_outsider')->boosts();
		self::assertSame(200, $response->getStatus());
		self::assertSame(30, $response->getData()['data']['magnet']);
		self::assertSame(0, $this->scoreRowCount($houseId));
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
				'contribution_score' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'coins' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'current_streak' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'longest_streak' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'streak_freezes' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'unlocked_titles' => $qb->createNamedParameter('[]'),
				'joined_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			])
			->executeStatement();
		$id = (int)$this->db->lastInsertId('chorestrike_house_users');
		$this->instanceMembershipIds[] = $id;
		return $id;
	}

	private function setWallet(int $houseId, string $userId, int $coins): void {
		$member = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
			->findByNextcloudUserId($houseId, $userId);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_house_users')
			->set('coins', $qb->createNamedParameter($coins, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($member->getId(), IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * SaveScore_PersistsScore_AndCreditsExactCoinDelta
	 */
	public function testSaveScore_PersistsScore_AndCreditsExactCoinDelta(): void {
		['houseId' => $houseId] = $this->houseWithMembers();
		$this->setWallet($houseId, 'mg_parent', 100);

		$response = $this->controller('mg_parent')->saveScore('snake', 250, ['slow_motion']);

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success']);
		self::assertSame(250, $data['data']['score']);
		self::assertSame('snake', $data['data']['gameId']);
		self::assertSame(['slow_motion'], $data['data']['boostsUsed']);
		// CR-SVC-51: the service is the single source of truth for the award.
		self::assertSame(25, $data['data']['coinsEarned']);

		// Exact persisted delta + repeat-read idempotence of the wallet read.
		$coinsResponse = $this->controller('mg_parent')->coins();
		self::assertSame(200, $coinsResponse->getStatus());
		self::assertSame(125, $coinsResponse->getData()['data']['currentCoins']);
		$repeat = $this->controller('mg_parent')->coins();
		self::assertSame(125, $repeat->getData()['data']['currentCoins']);
	}

	/**
	 * SaveScore_OutOfBoundsScores_Get400ValidationEnvelope
	 */
	public function testSaveScore_OutOfBoundsScores_Get400ValidationEnvelope(): void {
		['houseId' => $houseId] = $this->houseWithMembers();

		$negative = $this->controller('mg_parent')->saveScore('snake', -1);
		self::assertSame(400, $negative->getStatus());
		self::assertFalse($negative->getData()['success']);
		self::assertSame('VALIDATION_ERROR', $negative->getData()['error']['code']);

		$overCap = $this->controller('mg_parent')->saveScore('snake', MinigameService::MAX_SCORE + 1);
		self::assertSame(400, $overCap->getStatus());
		self::assertSame('VALIDATION_ERROR', $overCap->getData()['error']['code']);

		// No score row and no coin mutation for the rejected inputs.
		self::assertSame(0, $this->scoreRowCount($houseId));
		self::assertSame(0, (int)$this->walletOf($houseId, 'mg_parent'));
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
		['houseId' => $houseId] = $this->houseWithMembers();
		$this->setWallet($houseId, 'mg_parent', 500);
		$this->setWallet($houseId, 'mg_teener', 500);

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

	/**
	 * Boosts_ListsCatalog_PurchaseBoost_DebitsExactDelta
	 */
	public function testBoosts_ListsCatalog_PurchaseBoost_DebitsExactDelta(): void {
		['houseId' => $houseId] = $this->houseWithMembers();
		$this->setWallet($houseId, 'mg_parent', 35);

		$boosts = $this->controller('mg_parent')->boosts();
		self::assertSame(200, $boosts->getStatus());
		self::assertSame(MinigameService::BOOST_COSTS, $boosts->getData()['data']);

		$purchase = $this->controller('mg_parent')->purchaseBoost('magnet');
		self::assertSame(200, $purchase->getStatus());
		self::assertTrue($purchase->getData()['success']);
		self::assertSame('magnet', $purchase->getData()['data']['boostId']);
		self::assertSame(30, $purchase->getData()['data']['cost']);
		self::assertSame(5, $purchase->getData()['data']['remainingCoins']);
		self::assertSame(5, (int)$this->walletOf($houseId, 'mg_parent'), 'the wallet debit is exact (35 - 30)');

		// Insufficient funds → 400 envelope, zero writes.
		$insufficient = $this->controller('mg_parent')->purchaseBoost('ghost_mode');
		self::assertSame(400, $insufficient->getStatus());
		self::assertSame('PURCHASE_BOOST_ERROR', $insufficient->getData()['error']['code']);
		self::assertSame(5, (int)$this->walletOf($houseId, 'mg_parent'));

		// Unknown boost → 400 envelope.
		$unknown = $this->controller('mg_parent')->purchaseBoost('time_machine');
		self::assertSame(400, $unknown->getStatus());
		self::assertSame('PURCHASE_BOOST_ERROR', $unknown->getData()['error']['code']);
	}

	/**
	 * Coins_NonMember_AnswerZero_WithoutFailureLeak
	 *
	 * getUserCoins answers 0 for a non-member (documented default) — but the
	 * controller's membership gate denies the request before the read.
	 */
	public function testCoins_NonMember_AnswerZero_WithoutFailureLeak(): void {
		$this->houseWithMembers();

		$response = $this->controller('mg_outsider')->coins();

		self::assertSame(403, $response->getStatus());
		self::assertSame('FORBIDDEN', $response->getData()['error']['code']);
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

	private function walletOf(int $houseId, string $userId): int {
		$member = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
			->findByNextcloudUserId($houseId, $userId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('coins')->from('chorestrike_house_users')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($member->getId(), IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
