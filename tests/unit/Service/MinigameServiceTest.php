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
use OCA\ChoreStrike\Exception\GameLockedException;
use OCA\ChoreStrike\Exception\ImplausibleScoreException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\ArcadeAccessService;
use OCA\ChoreStrike\Service\ArcadeCatalog;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\MinigameService;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * CS-PLAN-070 WP3 (T10/D7d): the arcade is scores-only — saving a score
 * never writes wallet entries, coins or chore contribution, and the boost
 * purchase/coin-mint surface is gone.
 */
class MinigameServiceTest extends TestCase {
	/** A one-minute round: Snake accepts up to 100 + 20 * 60 = 1300 points. */
	private const ROUND_MS = 60000;
	/** The longest accepted Snake round. */
	private const LONG_ROUND_MS = 3600000;

	private MinigameService $service;
	private MinigameScoreMapper $scoreMapper;
	private HouseUserMapper $houseUserMapper;
	private IDBConnection $db;
	private AppLogger $appLogger;
	private HouseMapper $houseMapper;
	private ArcadeAccessService $arcadeAccess;
	private bool $memberPresent;
	/** @var callable(): void */
	private $onLock;

	protected function setUp(): void {
		parent::setUp();
		$this->scoreMapper = $this->createMock(MinigameScoreMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->appLogger = $this->createMock(AppLogger::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		// CS-PLAN-086 §3.2: unlocked unless a test says otherwise.
		$this->arcadeAccess = $this->createMock(ArcadeAccessService::class);
		$this->memberPresent = true;
		$this->onLock = static function (): void {
		};
		// CS-PLAN-057: the weekly leaderboard week resolves the house timezone.
		$utcHouse = new House();
		$utcHouse->setTimezone('UTC');
		$this->houseMapper->method('find')->willReturn($utcHouse);
		// CS-PLAN-071 WP0: the score write enters through the house row
		// lock (findForUpdate) — a stub is enough for these unit tests.
		$this->houseMapper->method('findForUpdate')->willReturnCallback(function () use ($utcHouse): House {
			($this->onLock)();
			return $utcHouse;
		});
		$this->houseUserMapper->method('findByHouseAndUser')->willReturnCallback(
			fn (): ?HouseUser => $this->memberPresent ? new HouseUser() : null
		);
		$l10n = $this->createMock(IL10N::class);
		// Identity mock: message assertions match the wrapped source text
		// (no parameter substitution), mirroring TemplateServiceTest.
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		// D7: below the per-user daily cap by default.
		$this->scoreMapper->method('countByUserSince')->willReturn(0);

		$this->service = new MinigameService($this->scoreMapper, $this->houseUserMapper, new \OCA\ChoreStrike\Db\TransactionExecutor($this->db), $l10n, $this->appLogger, $this->houseMapper, new CalendarService(), $this->arcadeAccess);
	}

	private static function snakeMaxScore(): int {
		return ArcadeCatalog::GAMES['snake']['maxScore'];
	}

	private function makeScore(int $score): MinigameScore {
		$scoreEntity = new MinigameScore();
		$scoreEntity->setId(1);
		$scoreEntity->setHouseId(1);
		$scoreEntity->setUserId('user1');
		$scoreEntity->setGameId('snake');
		$scoreEntity->setScore($score);
		return $scoreEntity;
	}

	public function testSaveScoreCreatesNewScore(): void {
		$this->scoreMapper->expects($this->once())
			->method('insert')
			->willReturn($this->makeScore(150));

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');

		$result = $this->service->saveScore(1, 'user1', 'snake', 150, self::ROUND_MS);

		$this->assertEquals(150, $result->getScore());
		$this->assertEquals('snake', $result->getGameId());
	}

	/**
	 * SaveScore_NeverWritesMemberOrContributionState (T10)
	 *
	 * Scores-only contract: a save touches no HouseUser row — no coins,
	 * no contribution, no streak side effects.
	 */
	public function testSaveScore_NeverWritesMemberOrContributionState(): void {
		$this->scoreMapper->method('insert')->willReturnArgument(0);
		$this->houseUserMapper->expects($this->once())->method('findByHouseAndUser')->with(1, 'user1');
		$this->houseUserMapper->expects($this->never())->method('findForUpdate');
		$this->houseUserMapper->expects($this->never())->method('update');

		$this->service->saveScore(1, 'user1', 'snake', 120, self::ROUND_MS);
	}

	/**
	 * SaveScore_MembershipRemovedBeforeHouseLock_DeniesWithoutUidInsert
	 */
	public function testSaveScore_MembershipRemovedBeforeHouseLock_DeniesWithoutUidInsert(): void {
		$this->onLock = function (): void {
			$this->memberPresent = false;
		};
		$this->houseMapper->expects($this->once())->method('findForUpdate')->with(1);
		$this->houseUserMapper->expects($this->once())->method('findByHouseAndUser')->with(1, 'user1');
		$this->scoreMapper->expects($this->never())->method('insert');
		$this->appLogger->expects($this->never())->method('logException');
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(\OCA\ChoreStrike\Exception\ForbiddenException::class);
		$this->service->saveScore(1, 'user1', 'snake', 150, self::ROUND_MS);
	}

	/**
	 * SaveScore_LockedGame_DeniedUnderTheHouseLockWithoutInsert
	 * (CS-PLAN-086 §3.2): the unlock check runs inside the transaction,
	 * after the house lock and the membership check, and a locked game
	 * rolls back without a rollback-error log.
	 */
	public function testSaveScore_LockedGame_DeniedUnderTheHouseLockWithoutInsert(): void {
		$locked = false;
		$this->onLock = function () use (&$locked): void {
			$locked = true;
		};
		$this->arcadeAccess->expects($this->once())
			->method('assertUnlocked')
			->with($this->isInstanceOf(HouseUser::class), 'snake')
			->willReturnCallback(function () use (&$locked): void {
				$this->assertTrue($locked, 'the unlock check runs under the house lock');
				throw new GameLockedException('This game is still locked.');
			});
		$this->scoreMapper->expects($this->never())->method('insert');
		$this->appLogger->expects($this->never())->method('logException');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(GameLockedException::class);
		$this->service->saveScore(1, 'user1', 'snake', 150, self::ROUND_MS);
	}

	/**
	 * SaveScore_NegativeScore_RejectedBeforeInsert
	 *
	 * CR-SVC-05: a negative client score must be rejected before any
	 * persistence.
	 */
	public function testSaveScore_NegativeScore_RejectedBeforeInsert(): void {
		$this->scoreMapper->expects($this->never())->method('insert');
		$this->db->expects($this->never())->method('beginTransaction');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Score must be between 0 and %1$d');

		$this->service->saveScore(1, 'user1', 'snake', -50, self::ROUND_MS);
	}

	/**
	 * SaveScore_OverCapScore_RejectedBeforeInsert
	 *
	 * CR-SVC-05: an over-cap score must be rejected before any persistence.
	 */
	public function testSaveScore_OverCapScore_RejectedBeforeInsert(): void {
		$this->scoreMapper->expects($this->never())->method('insert');
		$this->db->expects($this->never())->method('beginTransaction');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Score must be between 0 and %1$d');

		$this->service->saveScore(1, 'user1', 'snake', self::snakeMaxScore() + 1, self::LONG_ROUND_MS);
	}

	/**
	 * SaveScore_AtMaxScore_Accepted
	 *
	 * CR-SVC-05: the per-game cap is inclusive — exactly the ceiling is a
	 * valid score (CS-PLAN-086: the ceiling comes from ArcadeCatalog).
	 */
	public function testSaveScore_AtMaxScore_Accepted(): void {
		$this->scoreMapper->expects($this->once())->method('insert')->willReturn($this->makeScore(self::snakeMaxScore()));

		$result = $this->service->saveScore(1, 'user1', 'snake', self::snakeMaxScore(), self::LONG_ROUND_MS);

		$this->assertSame(self::snakeMaxScore(), $result->getScore());
	}

	/**
	 * SaveScore_UnknownGame_Rejected (D7 whitelist)
	 */
	public function testSaveScore_UnknownGame_Rejected(): void {
		$this->scoreMapper->expects($this->never())->method('insert');
		$this->db->expects($this->never())->method('beginTransaction');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown game');

		$this->service->saveScore(1, 'user1', '2048', 100, self::ROUND_MS);
	}

	/**
	 * SaveScore_DurationOutOfBounds_RejectedBeforeInsert (D-086-04)
	 *
	 * @dataProvider outOfBoundsDurations
	 */
	public function testSaveScore_DurationOutOfBounds_RejectedBeforeInsert(int $durationMs): void {
		$this->scoreMapper->expects($this->never())->method('insert');
		$this->db->expects($this->never())->method('beginTransaction');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Play time must be between 1 and %1$d milliseconds');

		$this->service->saveScore(1, 'user1', 'snake', 0, $durationMs);
	}

	public static function outOfBoundsDurations(): array {
		return [
			'zero' => [0],
			'negative' => [-1000],
			'above the game maximum' => [ArcadeCatalog::GAMES['snake']['maxDurationMs'] + 1],
		];
	}

	/**
	 * SaveScore_ImplausibleScore_RejectedAndLogged (D-086-04)
	 *
	 * One point above `burstPoints + pointsPerSecond * seconds` is rejected
	 * before any persistence, with a warning for the WP-G rate tuning.
	 */
	public function testSaveScore_ImplausibleScore_RejectedAndLogged(): void {
		$this->scoreMapper->expects($this->never())->method('insert');
		$this->scoreMapper->expects($this->never())->method('countByUserSince');
		$this->db->expects($this->never())->method('beginTransaction');
		$this->appLogger->expects($this->once())
			->method('warning')
			->with(
				'Minigame score save rejected: implausible score',
				$this->callback(static fn (array $context): bool => $context['event'] === 'minigame_implausible_score'
					&& $context['gameId'] === 'snake'
					&& $context['score'] === 1301
					&& $context['durationMs'] === self::ROUND_MS)
			);

		$this->expectException(ImplausibleScoreException::class);
		$this->expectExceptionMessage('This score is not possible in the time played.');

		$this->service->saveScore(1, 'user1', 'snake', 1301, self::ROUND_MS);
	}

	/**
	 * SaveScore_ScoreAtPlausibilityLimit_Accepted (D-086-04)
	 */
	public function testSaveScore_ScoreAtPlausibilityLimit_Accepted(): void {
		$this->scoreMapper->expects($this->once())->method('insert')->willReturn($this->makeScore(1300));

		$result = $this->service->saveScore(1, 'user1', 'snake', 1300, self::ROUND_MS);

		$this->assertSame(1300, $result->getScore());
	}

	/**
	 * Reads_UnknownGame_RejectedWithoutQuery (CS-PLAN-086: no empty board
	 * for an unknown id)
	 *
	 * @dataProvider unknownGameReads
	 */
	public function testReads_UnknownGame_RejectedWithoutQuery(string $method, array $arguments): void {
		foreach (['getLeaderboard', 'getLeaderboardForPeriod', 'getPersonalBest', 'getUserRank', 'findByUserAndGame'] as $query) {
			$this->scoreMapper->expects($this->never())->method($query);
		}

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown game');

		$this->service->$method(...$arguments);
	}

	public static function unknownGameReads(): array {
		return [
			'leaderboard' => ['getLeaderboard', [1, 'pacman', 10]],
			'weekly leaderboard' => ['getWeeklyLeaderboard', [1, 'pacman', 10]],
			'personal best' => ['getPersonalBest', [1, 'user1', 'pacman']],
			'rank' => ['getUserRank', [1, 'user1', 'pacman']],
			'recent scores' => ['getRecentScores', [1, 'user1', 'pacman', 5]],
		];
	}

	/**
	 * SaveScore_DailyCapReached_ThrowsRateLimited (D7)
	 */
	public function testSaveScore_DailyCapReached_ThrowsRateLimited(): void {
		// A FRESH mapper mock: PHPUnit keeps the first configured stub, so
		// the setUp default (0 saves) must not shadow the cap override.
		$this->scoreMapper = $this->createMock(MinigameScoreMapper::class);
		$this->scoreMapper->method('countByUserSince')
			->willReturn(MinigameService::DAILY_SCORE_SAVE_LIMIT);
		$this->scoreMapper->expects($this->never())->method('insert');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		$this->service = new MinigameService($this->scoreMapper, $this->houseUserMapper, new \OCA\ChoreStrike\Db\TransactionExecutor($this->db), $l10n, $this->appLogger, $this->houseMapper, new CalendarService(), $this->arcadeAccess);

		$this->db->expects($this->never())->method('beginTransaction');

		$this->appLogger->expects($this->once())
			->method('warning')
			->with(
				'Minigame score save rejected: daily cap reached',
				$this->callback(static function (array $context): bool {
					return $context['event'] === 'minigame_rate_limited'
						&& $context['service'] === 'MinigameService'
						&& $context['houseId'] === 1
						&& $context['userId'] === 'user1';
				})
			);

		$this->expectException(\OCA\ChoreStrike\Exception\RateLimitException::class);

		$this->service->saveScore(1, 'user1', 'snake', 100, self::ROUND_MS);
	}

	/**
	 * SaveScore_InsertFailure_RollsBackAndReThrows
	 */
	public function testSaveScore_InsertFailure_RollsBackAndReThrows(): void {
		$this->scoreMapper->method('insert')->willThrowException(new \RuntimeException('db down'));

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

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

		$this->service->saveScore(1, 'user1', 'snake', 120, self::ROUND_MS);
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
		// Scores-only: no coin fields in the leaderboard payload.
		$this->assertArrayNotHasKey('coinsEarned', $result[0]);
		$this->assertArrayNotHasKey('boostsUsed', $result[0]);
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

	public function testGetRecentScoresReturnsUserScores(): void {
		$score = new MinigameScore();
		$score->setId(5);
		$score->setUserId('user1');
		$score->setScore(220);
		$score->setCreatedAt(new \DateTime('2026-09-21 10:00:00'));

		$this->scoreMapper->expects($this->once())
			->method('findByUserAndGame')
			->with(1, 'user1', 'snake', 5)
			->willReturn([$score]);

		$result = $this->service->getRecentScores(1, 'user1', 'snake', 5);

		$this->assertCount(1, $result);
		$this->assertEquals(220, $result[0]['score']);
	}
}
