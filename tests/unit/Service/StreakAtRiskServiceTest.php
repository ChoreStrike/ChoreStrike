<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\NotificationService;
use OCA\ChoreStrike\Service\StreakAtRiskService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StreakAtRiskService (CS-PLAN-023).
 *
 * The service receives "yesterday" as a parameter, so every test pins a
 * fixed calendar day (a Wednesday) and asserts the behaviour as a pure
 * function of that injected date — the regression that makes the
 * DailyNotificationJob refactor safe. Before the refactor these tests
 * were not possible: the job hardcoded `new \DateTime('yesterday')`.
 */
class StreakAtRiskServiceTest extends TestCase {
	/** Fixed injected "yesterday": a Wednesday, independent of the real clock. */
	private const YESTERDAY = '2026-07-29 06:00:00';
	private const YESTERDAY_WEEKDAY = 'wednesday';

	private HouseUserMapper&MockObject $houseUserMapper;
	private CompletedTaskMapper&MockObject $completedTaskMapper;
	private NotificationService&MockObject $notificationService;
	private AppLogger&MockObject $appLogger;
	private StreakAtRiskService $service;
	private \DateTime $yesterday;

	protected function setUp(): void {
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->appLogger = $this->createMock(AppLogger::class);
		$this->yesterday = new \DateTime(self::YESTERDAY);

		$this->service = new StreakAtRiskService(
			$this->houseUserMapper,
			$this->completedTaskMapper,
			$this->notificationService,
			$this->appLogger,
		);
	}

	/**
	 * Process_AtRiskUser_SendsNotification
	 */
	public function testProcess_AtRiskUser_SendsNotification(): void {
		$user = $this->buildUser(1, 'alice', streak: 5, freezes: 0);

		$this->houseUserMapper->expects($this->once())
			->method('findAllForJobs')
			->willReturn([$user]);
		$this->completedTaskMapper->expects($this->once())
			->method('countByUserOnDateGrouped')
			->willReturn([]); // alice absent → 0 completions
		$this->notificationService->expects($this->once())
			->method('notifyStreakAtRisk')
			->with('alice', 5);

		$stats = $this->service->process($this->yesterday);

		$this->assertSame(1, $stats['notified']);
	}

	/**
	 * Process_GroupedCountsQueriedForInjectedDate
	 *
	 * The batched completion query must run for the injected date — the
	 * actual time seam under test.
	 */
	public function testProcess_GroupedCountsQueriedForInjectedDate(): void {
		$this->houseUserMapper->method('findAllForJobs')->willReturn([]);
		$this->completedTaskMapper->expects($this->once())
			->method('countByUserOnDateGrouped')
			->with($this->callback(function (\DateTimeInterface $date) {
				return $date->format('Y-m-d') === '2026-07-29';
			}))
			->willReturn([]);

		$this->service->process($this->yesterday);
	}

	/**
	 * Process_UserWithFreeze_SkipsNotification
	 *
	 * The streak would be silently protected by a freeze — no nudge.
	 */
	public function testProcess_UserWithFreeze_SkipsNotification(): void {
		$user = $this->buildUser(1, 'alice', streak: 5, freezes: 1);

		$this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
		$this->completedTaskMapper->method('countByUserOnDateGrouped')->willReturn([]);
		$this->notificationService->expects($this->never())->method('notifyStreakAtRisk');

		$this->service->process($this->yesterday);
	}

	/**
	 * Process_UserWithCompletionOnInjectedDay_SkipsNotification
	 */
	public function testProcess_UserWithCompletionOnInjectedDay_SkipsNotification(): void {
		$user = $this->buildUser(1, 'alice', streak: 5, freezes: 0);

		$this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
		$this->completedTaskMapper->method('countByUserOnDateGrouped')
			->willReturn([1 => 2]); // alice (id=1) had 2 completions
		$this->notificationService->expects($this->never())->method('notifyStreakAtRisk');

		$this->service->process($this->yesterday);
	}

	/**
	 * Process_UserWithZeroStreak_SkipsNotification
	 */
	public function testProcess_UserWithZeroStreak_SkipsNotification(): void {
		$user = $this->buildUser(1, 'alice', streak: 0, freezes: 0);

		$this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
		$this->completedTaskMapper->method('countByUserOnDateGrouped')->willReturn([]);
		$this->notificationService->expects($this->never())->method('notifyStreakAtRisk');

		$this->service->process($this->yesterday);
	}

	/**
	 * Process_UserWithEmptyNextcloudId_SkipsNotification
	 */
	public function testProcess_UserWithEmptyNextcloudId_SkipsNotification(): void {
		$user = $this->buildUser(1, '', streak: 5, freezes: 0);

		$this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
		$this->completedTaskMapper->method('countByUserOnDateGrouped')->willReturn([]);
		$this->notificationService->expects($this->never())->method('notifyStreakAtRisk');

		$this->service->process($this->yesterday);
	}

	/**
	 * Process_UnavailableOnInjectedWeekday_SkipsNotification
	 *
	 * Fairness rule, now deterministic: the injected day is a Wednesday,
	 * so marking wednesday unavailable must skip the user regardless of
	 * the real clock.
	 */
	public function testProcess_UnavailableOnInjectedWeekday_SkipsNotification(): void {
		$user = $this->buildUser(1, 'alice', streak: 5, freezes: 0);
		$user->setSchedulingPreferences([
			'weekdayAvailability' => [self::YESTERDAY_WEEKDAY => false],
		]);

		$this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
		$this->completedTaskMapper->method('countByUserOnDateGrouped')->willReturn([]);
		$this->notificationService->expects($this->never())->method('notifyStreakAtRisk');

		$stats = $this->service->process($this->yesterday);

		$this->assertSame(1, $stats['skippedUnavailable']);
	}

	/**
	 * Process_MixedUserSet_OnlyNotifiesAtRisk
	 *
	 * The batched GROUP BY query returns counts for every user who
	 * completed anything on the injected day; users absent from the map
	 * are treated as 0.
	 */
	public function testProcess_MixedUserSet_OnlyNotifiesAtRisk(): void {
		$atRisk = $this->buildUser(1, 'alice', streak: 7, freezes: 0);
		$protected = $this->buildUser(2, 'bob', streak: 10, freezes: 1);
		$inactive = $this->buildUser(3, 'carol', streak: 0, freezes: 0);
		$activeYesterday = $this->buildUser(4, 'dan', streak: 4, freezes: 0);

		$this->houseUserMapper->method('findAllForJobs')
			->willReturn([$atRisk, $protected, $inactive, $activeYesterday]);
		// Single GROUP BY result: dan (id=4) completed on the injected day.
		$this->completedTaskMapper->expects($this->once())
			->method('countByUserOnDateGrouped')
			->willReturn([4 => 1]);

		$this->notificationService->expects($this->once())
			->method('notifyStreakAtRisk')
			->with('alice', 7);

		$stats = $this->service->process($this->yesterday);

		$this->assertSame(4, $stats['processed']);
		$this->assertSame(1, $stats['notified']);
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
			'notified' => 0,
			'skippedUnavailable' => 0,
		], $stats);
	}

	/**
	 * Process_MapperThrows_LogsExceptionAndReturnsZeroedStats
	 *
	 * CS-PLAN-035: pass failures route through AppLogger::logException and
	 * the pass still returns its zeroed stats instead of propagating.
	 */
	public function testProcess_MapperThrows_LogsExceptionAndReturnsZeroedStats(): void {
		$this->houseUserMapper->method('findAllForJobs')->willThrowException(new \RuntimeException('db gone'));
		$this->appLogger->expects($this->once())->method('logException');
		$this->notificationService->expects($this->never())->method('notifyStreakAtRisk');

		$stats = $this->service->process($this->yesterday);

		$this->assertSame([
			'processed' => 0,
			'notified' => 0,
			'skippedUnavailable' => 0,
		], $stats);
	}

	private function buildUser(int $id, string $nextcloudUserId, int $streak, int $freezes): HouseUser {
		$user = new HouseUser();
		$user->setId($id);
		$user->setNextcloudUserId($nextcloudUserId);
		$user->setCurrentStreak($streak);
		$user->setStreakFreezes($freezes);
		return $user;
	}
}
