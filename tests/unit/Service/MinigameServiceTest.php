<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MinigameScore;
use OCA\ChoreStrike\Db\MinigameScoreMapper;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\MinigameService;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class MinigameServiceTest extends TestCase {
	private MinigameService $service;
	private MinigameScoreMapper $scoreMapper;
	private HouseUserMapper $houseUserMapper;
	private IDBConnection $db;
	private AppLogger $appLogger;
	private HouseMapper $houseMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->scoreMapper = $this->createMock(MinigameScoreMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->appLogger = $this->createMock(AppLogger::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		// CS-PLAN-057: the weekly leaderboard week resolves the house timezone.
		$utcHouse = new House();
		$utcHouse->setTimezone('UTC');
		$this->houseMapper->method('find')->willReturn($utcHouse);
		// CS-PLAN-071 WP0: the score/boost writes enter through the house
		// row lock (findForUpdate) — a stub is enough for these unit tests.
		$this->houseMapper->method('findForUpdate')->willReturn($utcHouse);
		$l10n = $this->createMock(IL10N::class);
		// Identity mock: message assertions match the wrapped source text
		// (no parameter substitution), mirroring TemplateServiceTest.
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		$this->service = new MinigameService($this->scoreMapper, $this->houseUserMapper, new \OCA\ChoreStrike\Db\TransactionExecutor($this->db), $l10n, $this->appLogger, $this->houseMapper, new CalendarService());
	}

	private function makeScore(int $score, array $boostsUsed = []): MinigameScore {
		$scoreEntity = new MinigameScore();
		$scoreEntity->setId(1);
		$scoreEntity->setHouseId(1);
		$scoreEntity->setUserId('user1');
		$scoreEntity->setGameId('snake');
		$scoreEntity->setScore($score);
		$scoreEntity->setBoostsUsed(!empty($boostsUsed) ? $boostsUsed : null);

		return $scoreEntity;
	}

	public function testSaveScoreCreatesNewScore(): void {
		$this->scoreMapper->expects($this->once())
			->method('insert')
			->willReturn($this->makeScore(150));

		// No house_user row → no coin award, transaction still runs.
		$this->houseUserMapper->method('findByHouseIdAndNextcloudUserId')->willReturn(null);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');

		$result = $this->service->saveScore(1, 'user1', 'snake', 150);

		$this->assertEquals(150, $result['score']->getScore());
		$this->assertEquals('snake', $result['score']->getGameId());
	}

	/**
	 * SaveScore_NegativeScore_RejectedBeforeInsertOrCoinAward
	 *
	 * CR-SVC-05: a negative client score (coin drain) must be rejected before
	 * any persistence or coin mutation.
	 */
	public function testSaveScore_NegativeScore_RejectedBeforeInsertOrCoinAward(): void {
		$this->scoreMapper->expects($this->never())->method('insert');
		$this->houseUserMapper->expects($this->never())->method('update');
		$this->db->expects($this->never())->method('beginTransaction');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Score must be between 0 and %1$d');

		$this->service->saveScore(1, 'user1', 'snake', -50);
	}

	/**
	 * SaveScore_OverCapScore_RejectedBeforeInsertOrCoinAward
	 *
	 * CR-SVC-05: an over-cap score (coin farming via floor(score/10)) must be
	 * rejected before any persistence or coin mutation.
	 */
	public function testSaveScore_OverCapScore_RejectedBeforeInsertOrCoinAward(): void {
		$this->scoreMapper->expects($this->never())->method('insert');
		$this->houseUserMapper->expects($this->never())->method('update');
		$this->db->expects($this->never())->method('beginTransaction');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Score must be between 0 and %1$d');

		$this->service->saveScore(1, 'user1', 'snake', MinigameService::MAX_SCORE + 1);
	}

	/**
	 * SaveScore_AtMaxScore_Accepted
	 *
	 * CR-SVC-05: the cap is inclusive — exactly MAX_SCORE is a valid score.
	 */
	public function testSaveScore_AtMaxScore_Accepted(): void {
		$this->scoreMapper->expects($this->once())->method('insert')->willReturn($this->makeScore(MinigameService::MAX_SCORE));
		$this->houseUserMapper->method('findByHouseIdAndNextcloudUserId')->willReturn(null);
		$this->houseUserMapper->expects($this->never())->method('update');

		$result = $this->service->saveScore(1, 'user1', 'snake', MinigameService::MAX_SCORE);

		$this->assertSame(MinigameService::MAX_SCORE, $result['score']->getScore());
	}

	/**
	 * SaveScore_AwardsCoinsOnLockedRow_InOneTransaction
	 *
	 * CR-SVC-54: the coin increment must run inside a transaction behind
	 * findForUpdate and build on the LOCKED row's balance — the pre-read
	 * entity's balance must never be written back (lost-update class).
	 */
	public function testSaveScore_AwardsCoinsOnLockedRow_InOneTransaction(): void {
		// Pre-read: stale balance 5 (a concurrent winner already committed 100).
		$preReadUser = new HouseUser();
		$preReadUser->setId(7);
		$preReadUser->setNextcloudUserId('user1');
		$preReadUser->setCoins(5);

		// Locked row: fresh balance 100 — the increment must build on this.
		$lockedUser = new HouseUser();
		$lockedUser->setId(7);
		$lockedUser->setNextcloudUserId('user1');
		$lockedUser->setCoins(100);

		$this->scoreMapper->expects($this->once())
			->method('insert')
			->willReturn($this->makeScore(120));

		$this->houseUserMapper->expects($this->once())
			->method('findByHouseIdAndNextcloudUserId')
			->with(1, 'user1')
			->willReturn($preReadUser);

		$this->houseUserMapper->expects($this->once())
			->method('findForUpdate')
			->with(7)
			->willReturn($lockedUser);

		$this->houseUserMapper->expects($this->once())
			->method('update')
			->with($this->callback(static function (HouseUser $updatedUser): bool {
				// 100 (locked) + 12 (score 120) — NOT 5 + 12 (stale pre-read).
				return $updatedUser->getCoins() === 112;
			}))
			->willReturnCallback(static fn (HouseUser $updatedUser): HouseUser => $updatedUser);

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$result = $this->service->saveScore(1, 'user1', 'snake', 120);

		$this->assertSame(12, $result['coinsEarned']);
	}

	/**
	 * SaveScore_MissingHouseUserRow_SavesScoreWithZeroCoins
	 *
	 * CR-SVC-54/51: with no house_user row the score is still saved, 0 coins
	 * are awarded, and the service reports coinsEarned = 0 honestly.
	 */
	public function testSaveScore_MissingHouseUserRow_SavesScoreWithZeroCoins(): void {
		$this->scoreMapper->expects($this->once())
			->method('insert')
			->willReturn($this->makeScore(120));

		$this->houseUserMapper->expects($this->once())
			->method('findByHouseIdAndNextcloudUserId')
			->with(1, 'user1')
			->willReturn(null);

		$this->houseUserMapper->expects($this->never())->method('findForUpdate');
		$this->houseUserMapper->expects($this->never())->method('update');

		$result = $this->service->saveScore(1, 'user1', 'snake', 120);

		$this->assertSame(120, $result['score']->getScore());
		$this->assertSame(0, $result['coinsEarned']);
	}

	/**
	 * SaveScore_InsertFailure_RollsBackAndReThrows
	 *
	 * CR-SVC-54: a failure between insert and coin award must roll the
	 * transaction back and propagate — no partial score-without-coins state.
	 */
	public function testSaveScore_InsertFailure_RollsBackAndReThrows(): void {
		$preReadUser = new HouseUser();
		$preReadUser->setId(7);
		$preReadUser->setNextcloudUserId('user1');
		$preReadUser->setCoins(5);

		$this->houseUserMapper->method('findByHouseIdAndNextcloudUserId')->willReturn($preReadUser);
		$this->scoreMapper->method('insert')->willThrowException(new \RuntimeException('db down'));

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->houseUserMapper->expects($this->never())->method('update');

		// CS-PLAN-052: the rollback boundary must log the failure before
		// rethrowing (stable event + service context keys).
		$this->appLogger->expects($this->once())
			->method('logException')
			->with(
				'MinigameService: score save rolled back',
				$this->callback(fn (\Throwable $e) => $e->getMessage() === 'db down'),
				$this->callback(function (array $context): bool {
					return $context['event'] === 'minigame_score_rollback'
						&& $context['service'] === 'MinigameService'
						&& $context['houseId'] === 1
						&& $context['userId'] === 'user1'
						&& $context['gameId'] === 'snake';
				})
			);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('db down');

		$this->service->saveScore(1, 'user1', 'snake', 120);
	}

	public function testSaveScoreWithBoosts(): void {
		$this->scoreMapper->expects($this->once())
			->method('insert')
			->willReturn($this->makeScore(200, ['slow_motion', 'ghost_mode']));

		$this->houseUserMapper->method('findByHouseIdAndNextcloudUserId')->willReturn(null);

		$result = $this->service->saveScore(1, 'user1', 'snake', 200, ['slow_motion', 'ghost_mode']);

		$this->assertEquals(200, $result['score']->getScore());
		$this->assertEquals(['slow_motion', 'ghost_mode'], $result['score']->getBoostsUsed());
	}

	public function testGetLeaderboardReturnsScoresWithDisplayNames(): void {
		$score1 = new MinigameScore();
		$score1->setId(1);
		$score1->setUserId('user1');
		$score1->setScore(500);

		$score2 = new MinigameScore();
		$score2->setId(2);
		$score2->setUserId('user2');
		$score2->setScore(300);

		$user1 = new HouseUser();
		$user1->setNextcloudUserId('user1');
		$user1->setDisplayName('Alice');

		$user2 = new HouseUser();
		$user2->setNextcloudUserId('user2');
		$user2->setDisplayName('Bob');

		$this->scoreMapper->expects($this->once())
			->method('getLeaderboard')
			->with(1, 'snake', 10)
			->willReturn([$score1, $score2]);

		// CR-SVC-56: ONE batch lookup for both members instead of per-row
		// membership fetches.
		$this->houseUserMapper->expects($this->once())
			->method('findByHouseIdAndNextcloudUserIds')
			->with(1, ['user1', 'user2'])
			->willReturn(['user1' => $user1, 'user2' => $user2]);

		$result = $this->service->getLeaderboard(1, 'snake', 10);

		$this->assertCount(2, $result);
		$this->assertEquals('Alice', $result[0]['displayName']);
		$this->assertEquals(500, $result[0]['score']);
		$this->assertEquals('Bob', $result[1]['displayName']);
		$this->assertEquals(300, $result[1]['score']);
	}

	/**
	 * GetLeaderboard_MissingMember_FallsBackToUserId
	 *
	 * A score row whose submitter has no house_users row (removed member)
	 * falls back to the raw userId as displayName.
	 */
	public function testGetLeaderboard_MissingMember_FallsBackToUserId(): void {
		$score = new MinigameScore();
		$score->setId(1);
		$score->setUserId('ghost');
		$score->setScore(120);

		$this->scoreMapper->method('getLeaderboard')->willReturn([$score]);
		$this->houseUserMapper->method('findByHouseIdAndNextcloudUserIds')->willReturn([]);

		$result = $this->service->getLeaderboard(1, 'snake', 10);

		$this->assertCount(1, $result);
		$this->assertEquals('ghost', $result[0]['displayName']);
	}

	/**
	 * GetWeeklyLeaderboard_EnrichesScoresWithDisplayNames
	 *
	 * The weekly variant shares the batch enrichment (CR-SVC-56) — one
	 * findByHouseIdAndNextcloudUserIds call for the period's scores.
	 */
	public function testGetWeeklyLeaderboard_EnrichesScoresWithDisplayNames(): void {
		$score1 = new MinigameScore();
		$score1->setId(1);
		$score1->setUserId('user1');
		$score1->setScore(500);

		$score2 = new MinigameScore();
		$score2->setId(2);
		$score2->setUserId('user2');
		$score2->setScore(300);

		$user1 = new HouseUser();
		$user1->setNextcloudUserId('user1');
		$user1->setDisplayName('Alice');

		$this->scoreMapper->expects($this->once())
			->method('getLeaderboardForPeriod')
			->with(1, 'snake', $this->callback(fn ($date) => $date instanceof \DateTime), 10)
			->willReturn([$score1, $score2]);

		$this->houseUserMapper->expects($this->once())
			->method('findByHouseIdAndNextcloudUserIds')
			->with(1, ['user1', 'user2'])
			->willReturn(['user1' => $user1]);

		$result = $this->service->getWeeklyLeaderboard(1, 'snake', 10);

		$this->assertCount(2, $result);
		$this->assertEquals('Alice', $result[0]['displayName']);
		// user2 has no house_user row → userId fallback.
		$this->assertEquals('user2', $result[1]['displayName']);
	}

	public function testGetPersonalBestReturnsHighestScore(): void {
		$score = new MinigameScore();
		$score->setId(1);
		$score->setUserId('user1');
		$score->setScore(500);

		$this->scoreMapper->expects($this->once())
			->method('getPersonalBest')
			->with(1, 'user1', 'snake')
			->willReturn($score);

		$result = $this->service->getPersonalBest(1, 'user1', 'snake');

		$this->assertNotNull($result);
		$this->assertEquals(500, $result['score']);
	}

	public function testGetPersonalBestReturnsNullWhenNoScores(): void {
		$this->scoreMapper->expects($this->once())
			->method('getPersonalBest')
			->with(1, 'user1', 'snake')
			->willReturn(null);

		$result = $this->service->getPersonalBest(1, 'user1', 'snake');

		$this->assertNull($result);
	}

	public function testGetUserRankReturnsCorrectPosition(): void {
		$this->scoreMapper->expects($this->once())
			->method('getUserRank')
			->with(1, 'user1', 'snake')
			->willReturn(3);

		$result = $this->service->getUserRank(1, 'user1', 'snake');

		$this->assertEquals(3, $result);
	}

	/**
	 * PurchaseBoost_ChecksLockedRowBalance_AndDeductsAtomically
	 *
	 * CR-SVC-23: the sufficiency check and deduction run on the LOCKED row.
	 * The pre-read balance may be stale (a concurrent purchase may have
	 * spent the coins between the pre-read and the lock) — the locked
	 * balance is authoritative.
	 */
	public function testPurchaseBoost_ChecksLockedRowBalance_AndDeductsAtomically(): void {
		// Pre-read: balance 50 (stale — a concurrent purchase spent 45).
		$preReadUser = new HouseUser();
		$preReadUser->setId(7);
		$preReadUser->setNextcloudUserId('user1');
		$preReadUser->setCoins(50);

		// Locked row: fresh balance 50 here, deduction lands on it.
		$lockedUser = new HouseUser();
		$lockedUser->setId(7);
		$lockedUser->setNextcloudUserId('user1');
		$lockedUser->setCoins(50);

		$this->houseUserMapper->expects($this->once())
			->method('findByHouseIdAndNextcloudUserId')
			->with(1, 'user1')
			->willReturn($preReadUser);

		$this->houseUserMapper->expects($this->once())
			->method('findForUpdate')
			->with(7)
			->willReturn($lockedUser);

		$this->houseUserMapper->expects($this->once())
			->method('update')
			->with($this->callback(static function (HouseUser $updatedUser): bool {
				return $updatedUser->getCoins() === 40;
			}))
			->willReturnCallback(static fn (HouseUser $updatedUser): HouseUser => $updatedUser);

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$result = $this->service->purchaseBoost(1, 'user1', 'slow_motion');

		$this->assertTrue($result['success']);
		$this->assertEquals(10, $result['cost']);
		$this->assertEquals(40, $result['remainingCoins']);
	}

	/**
	 * PurchaseBoost_RejectsOnLockedRowBalance_EvenWhenPreReadSuffices
	 *
	 * CR-SVC-23 regression: the pre-read balance (50) would pass the check,
	 * but the LOCKED balance (5) is authoritative — a concurrent purchase
	 * already spent the coins. Fails on the lock-less pre-fix code, which
	 * checked only the pre-read balance and bought the boost anyway.
	 */
	public function testPurchaseBoost_RejectsOnLockedRowBalance_EvenWhenPreReadSuffices(): void {
		$preReadUser = new HouseUser();
		$preReadUser->setId(7);
		$preReadUser->setNextcloudUserId('user1');
		$preReadUser->setCoins(50);

		$lockedUser = new HouseUser();
		$lockedUser->setId(7);
		$lockedUser->setNextcloudUserId('user1');
		$lockedUser->setCoins(5);

		$this->houseUserMapper->expects($this->once())
			->method('findByHouseIdAndNextcloudUserId')
			->with(1, 'user1')
			->willReturn($preReadUser);

		$this->houseUserMapper->expects($this->once())
			->method('findForUpdate')
			->with(7)
			->willReturn($lockedUser);

		$this->houseUserMapper->expects($this->never())->method('update');
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(\InvalidArgumentException::class);
		// Identity l10n mock: the source string is returned verbatim (the
		// %1$d/%2$d parameters are substituted only by the real server-side
		// t()).
		$this->expectExceptionMessage('Insufficient coins. Need %1$d, have %2$d');

		$this->service->purchaseBoost(1, 'user1', 'slow_motion');
	}

	public function testPurchaseBoostFailsWithInsufficientCoins(): void {
		$user = new HouseUser();
		$user->setId(7);
		$user->setNextcloudUserId('user1');
		$user->setCoins(5);

		$this->houseUserMapper->expects($this->once())
			->method('findByHouseIdAndNextcloudUserId')
			->with(1, 'user1')
			->willReturn($user);

		$this->houseUserMapper->expects($this->once())
			->method('findForUpdate')
			->with(7)
			->willReturn($user);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Insufficient coins');

		$this->service->purchaseBoost(1, 'user1', 'slow_motion');
	}

	public function testPurchaseBoostFailsWithUnknownBoost(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown boost');

		$this->service->purchaseBoost(1, 'user1', 'unknown_boost');
	}

	/**
	 * PurchaseBoost_DeductFailure_RollsBackAndReThrows
	 *
	 * CR-SVC-23: a failure during the locked deduction must roll the
	 * transaction back and propagate.
	 */
	public function testPurchaseBoost_DeductFailure_RollsBackAndReThrows(): void {
		$user = new HouseUser();
		$user->setId(7);
		$user->setNextcloudUserId('user1');
		$user->setCoins(50);

		$this->houseUserMapper->method('findByHouseIdAndNextcloudUserId')->willReturn($user);
		$this->houseUserMapper->method('findForUpdate')->willReturn($user);
		$this->houseUserMapper->method('update')->willThrowException(new \RuntimeException('db down'));

		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		// CS-PLAN-052: the rollback boundary must log the failure before
		// rethrowing (stable event + service context keys).
		$this->appLogger->expects($this->once())
			->method('logException')
			->with(
				'MinigameService: boost purchase rolled back',
				$this->callback(fn (\Throwable $e) => $e->getMessage() === 'db down'),
				$this->callback(function (array $context): bool {
					return $context['event'] === 'minigame_boost_purchase_rollback'
						&& $context['service'] === 'MinigameService'
						&& $context['houseId'] === 1
						&& $context['userId'] === 'user1'
						&& $context['boostId'] === 'slow_motion';
				})
			);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('db down');

		$this->service->purchaseBoost(1, 'user1', 'slow_motion');
	}

	public function testGetAvailableBoostsReturnsAllBoosts(): void {
		$boosts = $this->service->getAvailableBoosts();

		$this->assertArrayHasKey('slow_motion', $boosts);
		$this->assertArrayHasKey('ghost_mode', $boosts);
		$this->assertArrayHasKey('magnet', $boosts);
		$this->assertArrayHasKey('shrink', $boosts);
		$this->assertArrayHasKey('no_obstacles', $boosts);
		$this->assertEquals(10, $boosts['slow_motion']);
		$this->assertEquals(20, $boosts['ghost_mode']);
	}

	public function testGetUserCoinsReturnsCurrentBalance(): void {
		$user = new HouseUser();
		$user->setNextcloudUserId('user1');
		$user->setCoins(75);

		$this->houseUserMapper->expects($this->once())
			->method('findByHouseIdAndNextcloudUserId')
			->with(1, 'user1')
			->willReturn($user);

		$result = $this->service->getUserCoins(1, 'user1');

		$this->assertEquals(75, $result);
	}

	public function testGetUserCoinsReturnsZeroForUnknownUser(): void {
		$this->houseUserMapper->expects($this->once())
			->method('findByHouseIdAndNextcloudUserId')
			->with(1, 'unknown')
			->willReturn(null);

		$result = $this->service->getUserCoins(1, 'unknown');

		$this->assertEquals(0, $result);
	}

	public function testGetRecentScoresReturnsUserScores(): void {
		$score1 = new MinigameScore();
		$score1->setId(1);
		$score1->setScore(100);

		$score2 = new MinigameScore();
		$score2->setId(2);
		$score2->setScore(80);

		$this->scoreMapper->expects($this->once())
			->method('findByUserAndGame')
			->with(1, 'user1', 'snake', 5)
			->willReturn([$score1, $score2]);

		$result = $this->service->getRecentScores(1, 'user1', 'snake', 5);

		$this->assertCount(2, $result);
		$this->assertEquals(100, $result[0]['score']);
		$this->assertEquals(80, $result[1]['score']);
	}
}
