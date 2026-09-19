<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\StreakFreezeService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StreakFreezeService (CS-PLAN-023).
 *
 * The service receives "yesterday" as a parameter, so every test pins a
 * fixed calendar day (a Wednesday) and asserts the behaviour as a pure
 * function of that injected date — the regression that makes the
 * StreakFreezeJob refactor safe. Before the refactor these tests were not
 * possible: the job hardcoded `new \DateTime('yesterday')`.
 *
 * CS-PLAN-057: the pass buckets the day in the house timezone (UTC house
 * in these pins, so the day keys match the injected date).
 */
class StreakFreezeServiceTest extends TestCase {
	/** Fixed injected "yesterday": a Wednesday, independent of the real clock. */
	private const YESTERDAY = '2026-07-29 06:00:00';
	private const YESTERDAY_WEEKDAY = 'wednesday';

	private HouseUserMapper&MockObject $houseUserMapper;
	private CompletedTaskMapper&MockObject $completedTaskMapper;
	private AppLogger&MockObject $appLogger;
	private HouseMapper&MockObject $houseMapper;
	private StreakFreezeService $service;
	private \DateTime $yesterday;
	/** Registry for the under-lock re-read (CS-PLAN-071 findForUpdate). */
	private array $usersById = [];

	protected function setUp(): void {
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->appLogger = $this->createMock(AppLogger::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->yesterday = new \DateTime(self::YESTERDAY);

		$utcHouse = new House();
		$utcHouse->setId(1);
		$utcHouse->setTimezone('UTC');
		$this->houseMapper->method('findOneForJobs')->willReturn($utcHouse);
		// CS-PLAN-071 WP0: the destructive branch enters through the house
		// row lock (findForUpdate) — a stub is enough for these unit tests.
		$this->houseMapper->method('findForUpdate')->willReturn($utcHouse);
		// Under-lock membership re-read resolves the same entity by id.
		$this->usersById = [];
		$this->houseUserMapper->method('findForUpdate')->willReturnCallback(
			fn (int $id): HouseUser => $this->usersById[$id]
		);

		$this->service = new StreakFreezeService(
			$this->houseUserMapper,
			$this->completedTaskMapper,
			$this->appLogger,
			$this->houseMapper,
			new CalendarService(),
			new \OCA\ChoreStrike\Db\TransactionExecutor($this->createMock(\OCP\IDBConnection::class)),
		);
	}

	/**
	 * Process_NoCompletionsAndNoFreezes_ResetsStreak
	 */
	public function testProcess_NoCompletionsAndNoFreezes_ResetsStreak(): void {
		$user = $this->buildUser(1, streak: 5, freezes: 0);

		$this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
		$this->completedTaskMapper->method('countByUserOnDateGrouped')->willReturn([]);
		$this->houseUserMapper->expects($this->once())->method('update')
			->with($this->callback(function (HouseUser $u) {
				return $u->getCurrentStreak() === 0;
			}));

		$stats = $this->service->process($this->yesterday);

		$this->assertSame(1, $stats['reset']);
		$this->assertSame(0, $stats['frozen']);
	}

	/**
	 * Process_NoCompletionsWithFreeze_ConsumesFreezeAndKeepsStreak
	 */
	public function testProcess_NoCompletionsWithFreeze_ConsumesFreezeAndKeepsStreak(): void {
		$user = $this->buildUser(1, streak: 5, freezes: 1);

		$this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
		$this->completedTaskMapper->method('countByUserOnDateGrouped')->willReturn([]);
		$this->houseUserMapper->expects($this->once())->method('update')
			->with($this->callback(function (HouseUser $u) {
				return $u->getCurrentStreak() === 5 && $u->getStreakFreezes() === 0;
			}));

		$stats = $this->service->process($this->yesterday);

		$this->assertSame(1, $stats['frozen']);
		$this->assertSame(0, $stats['reset']);
	}

	/**
	 * Process_CompletionsOnInjectedDay_SkipsAndPassesDateThrough
	 *
	 * The completion counts must be queried for the injected date — the
	 * actual time seam under test (CR-SVC-55: one grouped query for
	 * everyone instead of per-user COUNTs).
	 */
	public function testProcess_CompletionsOnInjectedDay_SkipsAndPassesDateThrough(): void {
		$user = $this->buildUser(1, streak: 5, freezes: 1);

		$this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
		$this->completedTaskMapper->expects($this->once())
			->method('countByUserOnDateGrouped')
			->with(
				$this->callback(function (\DateTimeInterface $start) {
					return $start->format('Y-m-d') === '2026-07-29'
						&& $start->format('H:i:s') === '00:00:00';
				}),
				$this->callback(function (\DateTimeInterface $end) {
					return $end->format('Y-m-d') === '2026-07-30'
						&& $end->format('H:i:s') === '00:00:00';
				})
			)
			->willReturn([1 => 3]);
		$this->houseUserMapper->expects($this->never())->method('update');

		$stats = $this->service->process($this->yesterday);

		$this->assertSame(0, $stats['frozen']);
		$this->assertSame(0, $stats['reset']);
	}

	/**
	 * Process_ZeroStreak_Skips
	 */
	public function testProcess_ZeroStreak_Skips(): void {
		$user = $this->buildUser(1, streak: 0, freezes: 1);

		$this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
		// The grouped count fetch runs once up front (CR-SVC-55) even when
		// every user later skips on the streak gate.
		$this->completedTaskMapper->expects($this->once())->method('countByUserOnDateGrouped')->willReturn([]);
		$this->houseUserMapper->expects($this->never())->method('update');

		$this->service->process($this->yesterday);
	}

	/**
	 * Process_UnavailableOnInjectedWeekday_SkipsFreezeAndReset
	 *
	 * Fairness rule, now deterministic: the injected day is a Wednesday,
	 * so marking wednesday unavailable must skip the user regardless of
	 * the real clock.
	 */
	public function testProcess_UnavailableOnInjectedWeekday_SkipsFreezeAndReset(): void {
		$user = $this->buildUser(1, streak: 5, freezes: 0);
		$user->setSchedulingPreferences([
			'weekdayAvailability' => [self::YESTERDAY_WEEKDAY => false],
		]);

		$this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
		$this->completedTaskMapper->method('countByUserOnDateGrouped')->willReturn([]);
		$this->houseUserMapper->expects($this->never())->method('update');

		$stats = $this->service->process($this->yesterday);

		$this->assertSame(1, $stats['skippedUnavailable']);
	}

	/**
	 * Process_AvailableOnInjectedWeekday_ProcessesNormally
	 *
	 * Complement to the fairness test: an explicitly available Wednesday
	 * (the injected weekday) is processed.
	 */
	public function testProcess_AvailableOnInjectedWeekday_ProcessesNormally(): void {
		$user = $this->buildUser(1, streak: 5, freezes: 1);
		$user->setSchedulingPreferences([
			'weekdayAvailability' => [self::YESTERDAY_WEEKDAY => true],
		]);

		$this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
		$this->completedTaskMapper->method('countByUserOnDateGrouped')->willReturn([]);
		$this->houseUserMapper->expects($this->once())->method('update');

		$stats = $this->service->process($this->yesterday);

		$this->assertSame(1, $stats['frozen']);
		$this->assertSame(0, $stats['skippedUnavailable']);
	}

	/**
	 * Process_FirstRunForDay_StampsLastFreezeDate
	 *
	 * CR-SVC-03: a destructive pass stamps last_freeze_date so the next run
	 * for the same day is idempotent.
	 */
	public function testProcess_FirstRunForDay_StampsLastFreezeDate(): void {
		$user = $this->buildUser(1, streak: 5, freezes: 1);

		$this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
		$this->completedTaskMapper->method('countByUserOnDateGrouped')->willReturn([]);
		$this->houseUserMapper->expects($this->once())->method('update')
			->with($this->callback(function (HouseUser $u) {
				return $u->getLastFreezeDate() !== null
					&& $u->getLastFreezeDate()->format('Y-m-d') === '2026-07-29';
			}));

		$this->service->process($this->yesterday);
	}

	/**
	 * Process_AlreadyProcessedForYesterday_SkipsAsNoOp
	 *
	 * CR-SVC-03: a re-run for a day already processed is a no-op — no freeze
	 * is consumed and the (preserved) streak is untouched.
	 */
	public function testProcess_AlreadyProcessedForYesterday_SkipsAsNoOp(): void {
		$user = $this->buildUser(1, streak: 5, freezes: 2);
		// A previous run already handled this exact injected day.
		$user->setLastFreezeDate(new \DateTime('2026-07-29'));

		$this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
		$this->completedTaskMapper->method('countByUserOnDateGrouped')->willReturn([]);
		$this->houseUserMapper->expects($this->never())->method('update');

		$stats = $this->service->process($this->yesterday);

		$this->assertSame(0, $stats['frozen']);
		$this->assertSame(0, $stats['reset']);
		$this->assertSame(5, $user->getCurrentStreak());
		$this->assertSame(2, $user->getStreakFreezes());
	}

	/**
	 * Process_DifferentDayIsProcessed_EvenAfterAnEarlierRun
	 *
	 * CR-SVC-03: the guard is day-scoped — a user stamped for an earlier day
	 * is still processed for the new injected day.
	 */
	public function testProcess_DifferentDayIsProcessed_EvenAfterAnEarlierRun(): void {
		$user = $this->buildUser(1, streak: 5, freezes: 1);
		// Stamped for a DIFFERENT day than the injected yesterday.
		$user->setLastFreezeDate(new \DateTime('2026-07-20'));

		$this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
		$this->completedTaskMapper->method('countByUserOnDateGrouped')->willReturn([]);
		$this->houseUserMapper->expects($this->once())->method('update');

		$stats = $this->service->process($this->yesterday);

		$this->assertSame(1, $stats['frozen']);
	}

	/**
	 * Process_PerUserErrorIsolation_ContinuesAfterFailure
	 *
	 * A single user's freeze-write failure must not abort the loop — the
	 * second user should still be processed normally. Since CS-PLAN-036
	 * the completion counts come from one grouped query fetched up front,
	 * so per-user isolation concerns only the freeze write.
	 */
	public function testProcess_PerUserErrorIsolation_ContinuesAfterFailure(): void {
		// user1 has no freezes left → reset branch; user2 consumes a freeze.
		$user1 = $this->buildUser(1, streak: 5, freezes: 0);
		$user2 = $this->buildUser(2, streak: 3, freezes: 1);

		$this->houseUserMapper->method('findAllForJobs')->willReturn([$user1, $user2]);
		$this->completedTaskMapper->method('countByUserOnDateGrouped')->willReturn([]);

		// user1's write fails (exception caught), user2's write succeeds.
		$this->houseUserMapper->method('update')
			->willReturnCallback(function (HouseUser $user) {
				if ($user->getId() === 1) {
					throw new \RuntimeException('DB connection lost');
				}
				return $user;
			});

		$stats = $this->service->process($this->yesterday);

		$this->assertSame(2, $stats['processed']);
		$this->assertSame(1, $stats['errors']);
		// The loop continued: user2's freeze was consumed and persisted.
		$this->assertSame(1, $stats['frozen']);
	}

	/**
	 * Process_PerUserFailure_LogsExceptionViaAppLogger
	 *
	 * CS-PLAN-035: per-user failures route through AppLogger::logException,
	 * are counted in stats, and do not abort the loop (CR-SVC-30 pattern).
	 */
	public function testProcess_PerUserFailure_LogsExceptionViaAppLogger(): void {
		$user1 = $this->buildUser(1, streak: 5, freezes: 0);
		$user2 = $this->buildUser(2, streak: 3, freezes: 1);

		$this->houseUserMapper->method('findAllForJobs')->willReturn([$user1, $user2]);
		$this->completedTaskMapper->method('countByUserOnDateGrouped')->willReturn([]);
		$this->houseUserMapper->method('update')
			->willReturnCallback(function (HouseUser $user) {
				if ($user->getId() === 1) {
					throw new \RuntimeException('db gone');
				}
				return $user;
			});
		$this->appLogger->expects($this->once())->method('logException');

		$stats = $this->service->process($this->yesterday);

		$this->assertSame(2, $stats['processed']);
		$this->assertSame(1, $stats['errors']);
		// The loop continued: user2's freeze was consumed.
		$this->assertSame(1, $stats['frozen']);
	}

	/**
	 * Process_ReturnsCompleteStats
	 */
	public function testProcess_ReturnsCompleteStats(): void {
		$this->houseUserMapper->method('findAllForJobs')->willReturn([]);
		$this->completedTaskMapper->method('countByUserOnDateGrouped')->willReturn([]);

		$stats = $this->service->process($this->yesterday);

		$this->assertSame([
			'processed' => 0,
			'frozen' => 0,
			'reset' => 0,
			'skippedUnavailable' => 0,
			'errors' => 0,
		], $stats);
	}

	/**
	 * Process_GroupedCounts_UsersWithZeroCompletionsAbsentFromMap
	 *
	 * CR-SVC-55: users absent from the grouped count map are treated as
	 * zero completions — the freeze/reset decision is unchanged.
	 */
	public function testProcess_GroupedCounts_UsersWithZeroCompletionsAbsentFromMap(): void {
		$activeUser = $this->buildUser(1, streak: 5, freezes: 0);
		$idleUser = $this->buildUser(2, streak: 4, freezes: 2);

		$this->houseUserMapper->method('findAllForJobs')->willReturn([$activeUser, $idleUser]);
		// Only the active user appears in the grouped map; idle user → 0.
		$this->completedTaskMapper->method('countByUserOnDateGrouped')->willReturn([1 => 2]);

		$stats = $this->service->process($this->yesterday);

		// activeUser completed something → untouched; idleUser → freeze.
		$this->assertSame(0, $stats['reset']);
		$this->assertSame(1, $stats['frozen']);
	}

	private function buildUser(int $id, int $streak, int $freezes): HouseUser {
		$user = new HouseUser();
		$user->setId($id);
		$user->setCurrentStreak($streak);
		$user->setStreakFreezes($freezes);
		$this->usersById[$id] = $user;
		return $user;
	}
}
