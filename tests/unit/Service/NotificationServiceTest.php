<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\NotificationService;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NotificationService
 */
class NotificationServiceTest extends TestCase {
	private IManager&MockObject $manager;
	private HouseUserMapper&MockObject $houseUserMapper;
	private AppLogger&MockObject $appLogger;
	private NotificationService $service;

	/** @var array<string, mixed>|null */
	private ?array $lastSubject = null;
	private ?string $lastSubjectName = null;
	private ?string $lastObjectType = null;
	private ?string $lastObjectId = null;
	private ?string $lastUser = null;
	/** @var string[] Every dispatched objectId, in dispatch order. */
	private array $objectIds = [];

	protected function setUp(): void {
		$this->manager = $this->createMock(IManager::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->appLogger = $this->createMock(AppLogger::class);
		$this->service = new NotificationService(
			$this->manager,
			$this->houseUserMapper,
			$this->appLogger
		);

		$this->lastSubject = null;
		$this->lastSubjectName = null;
		$this->lastObjectType = null;
		$this->lastObjectId = null;
		$this->lastUser = null;
		$this->objectIds = [];
	}

	/**
	 * Wire a fluent INotification mock that records the dispatched shape.
	 */
	private function wireRecordingNotification(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnCallback(function (string $app) use ($notification) {
			return $notification;
		});
		$notification->method('setUser')->willReturnCallback(function (string $user) use ($notification) {
			$this->lastUser = $user;
			return $notification;
		});
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnCallback(
			function (string $type, string $id) use ($notification) {
				$this->lastObjectType = $type;
				$this->lastObjectId = $id;
				$this->objectIds[] = $id;
				return $notification;
			}
		);
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $params) use ($notification) {
				$this->lastSubjectName = $subject;
				$this->lastSubject = $params;
				return $notification;
			}
		);

		$this->manager->method('createNotification')->willReturn($notification);
		$this->manager->method('notify')->with($notification);
	}

	/**
	 * NotifyOverdueTakenOver_BuildsAndNotifies
	 */
	public function testNotifyOverdueTakenOver_BuildsAndNotifies(): void {
		$this->wireRecordingNotification();
		$this->manager->expects($this->once())->method('notify');

		$this->service->notifyOverdueTakenOver('alice', 'Dishes', 'Bob', 42);

		$this->assertSame('alice', $this->lastUser);
		$this->assertSame('overdue_taken_over', $this->lastSubjectName);
		$this->assertSame(['task' => 'Dishes', 'taskId' => 42, 'claimer' => 'Bob'], $this->lastSubject);
		$this->assertSame('room_task', $this->lastObjectType);
		$this->assertSame('42', $this->lastObjectId);
	}

	/**
	 * NotifyMedalAwarded_BuildsAndNotifies
	 */
	public function testNotifyMedalAwarded_BuildsAndNotifies(): void {
		$this->wireRecordingNotification();

		$this->service->notifyMedalAwarded('alice', 'team_anchor', 12, '2026-07-14');

		$this->assertSame('medal_awarded', $this->lastSubjectName);
		$this->assertSame(['medalType' => 'team_anchor', 'value' => 12], $this->lastSubject);
		$this->assertSame('medal', $this->lastObjectType);
	}

	/**
	 * NotifyWeeklyGoalReached_BuildsAndNotifies
	 */
	public function testNotifyWeeklyGoalReached_BuildsAndNotifies(): void {
		$this->wireRecordingNotification();

		$this->service->notifyWeeklyGoalReached('alice', 95, 80, '2026-07-14T00:00:00+00:00');

		$this->assertSame('weekly_goal_reached', $this->lastSubjectName);
		$this->assertSame(['percent' => 95, 'targetPercent' => 80], $this->lastSubject);
	}

	/**
	 * NotifyWeeklyGoalReachedForHouse_NotifiesAllMembers
	 */
	public function testNotifyWeeklyGoalReachedForHouse_NotifiesAllMembers(): void {
		$this->wireRecordingNotification();

		$u1 = new HouseUser();
		$u1->setNextcloudUserId('alice');
		$u2 = new HouseUser();
		$u2->setNextcloudUserId('bob');
		$u3 = new HouseUser();
		$u3->setNextcloudUserId('');

		$this->houseUserMapper->expects($this->once())
			->method('findByHouseId')
			->with(7)
			->willReturn([$u1, $u2, $u3]);

		// Two real notifications (alice + bob); the empty user is skipped.
		$this->manager->expects($this->exactly(2))->method('notify');

		$sent = $this->service->notifyWeeklyGoalReachedForHouse(7, 90, 80, '2026-07-14T00:00:00+00:00');

		$this->assertSame(2, $sent);
	}

	/**
	 * NotifyStreakAtRisk_BuildsAndNotifies
	 */
	public function testNotifyStreakAtRisk_BuildsAndNotifies(): void {
		$this->wireRecordingNotification();

		$this->service->notifyStreakAtRisk('alice', 5, new \DateTimeImmutable('2026-08-10 06:00:00'));

		$this->assertSame('streak_at_risk', $this->lastSubjectName);
		$this->assertSame(['currentStreak' => 5], $this->lastSubject);
		$this->assertSame('streak', $this->lastObjectType);
		// CR-SVC-27: day-qualified objectId (pass day + streak).
		$this->assertSame('2026-08-10:5', $this->lastObjectId);
	}

	/**
	 * NotifyStreakAtRisk_ConsecutiveDays_BuildDifferentObjectIds
	 *
	 * CR-SVC-27 regression: the bare streak number made Nextcloud's
	 * (app, user, objectType, objectId) dedup drop day 2's reminder whenever
	 * the streak value repeated. Day-qualified objectIds must differ.
	 */
	public function testNotifyStreakAtRisk_ConsecutiveDays_BuildDifferentObjectIds(): void {
		$this->wireRecordingNotification();

		$this->service->notifyStreakAtRisk('alice', 5, new \DateTimeImmutable('2026-08-09 06:00:00'));
		$this->service->notifyStreakAtRisk('alice', 5, new \DateTimeImmutable('2026-08-10 06:00:00'));

		$this->assertSame(['2026-08-09:5', '2026-08-10:5'], $this->objectIds);
	}

	/**
	 * NotifyTaskDue_DueToday_BuildsRunDateQualifiedObjectId
	 */
	public function testNotifyTaskDue_DueToday_BuildsRunDateQualifiedObjectId(): void {
		$this->wireRecordingNotification();

		$this->service->notifyTaskDue(
			'alice',
			'Dishes',
			42,
			new \DateTimeImmutable('2026-08-18 14:30:00'),
			false
		);

		$this->assertSame('alice', $this->lastUser);
		$this->assertSame('task_due_today', $this->lastSubjectName);
		$this->assertSame(
			['task' => 'Dishes', 'taskId' => 42, 'dueDate' => '2026-08-18'],
			$this->lastSubject
		);
		$this->assertSame('task_due', $this->lastObjectType);
		// Run-date keying: due today on the 18th → objectId day is the 18th.
		$this->assertSame('42:2026-08-18', $this->lastObjectId);
	}

	/**
	 * NotifyTaskDue_DueTomorrow_KeysObjectIdOnRunDateNotDueDate
	 *
	 * The "due tomorrow" pass runs one day before the due date, so the same
	 * task reminded on day D−1 (tomorrow) and day D (today) produces two
	 * distinct bell entries; a same-day re-run would collapse (same objectId).
	 */
	public function testNotifyTaskDue_DueTomorrow_KeysObjectIdOnRunDateNotDueDate(): void {
		$this->wireRecordingNotification();

		$this->service->notifyTaskDue(
			'alice',
			'Dishes',
			42,
			new \DateTimeImmutable('2026-08-18 14:30:00'),
			true
		);

		$this->assertSame('task_due_tomorrow', $this->lastSubjectName);
		// Run date = due date − 1 day for the tomorrow variant.
		$this->assertSame('42:2026-08-17', $this->lastObjectId);
	}

	/**
	 * Dispatch_EmptyRecipient_SkipsSilently
	 */
	public function testDispatch_EmptyRecipient_SkipsSilently(): void {
		$this->manager->expects($this->never())->method('createNotification');
		$this->manager->expects($this->never())->method('notify');

		$this->service->notifyOverdueTakenOver('', 'Dishes', 'Bob', 42);
	}

	/**
	 * Dispatch_ManagerThrows_LogsAndSwallows
	 *
	 * CS-PLAN-035: dispatch failures route through AppLogger::logException
	 * (error level + standardized exception context).
	 */
	public function testDispatch_ManagerThrows_LogsAndSwallows(): void {
		$this->manager->method('createNotification')->willThrowException(new \RuntimeException('boom'));
		$this->appLogger->expects($this->once())->method('logException');

		// Must not propagate the exception.
		$this->service->notifyOverdueTakenOver('alice', 'Dishes', 'Bob', 42);
	}
}
