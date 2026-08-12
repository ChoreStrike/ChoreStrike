<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\NotificationService;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for NotificationService
 */
class NotificationServiceTest extends TestCase {
	private IManager&MockObject $manager;
	private HouseUserMapper&MockObject $houseUserMapper;
	private LoggerInterface&MockObject $logger;
	private NotificationService $service;

	/** @var array<string, mixed>|null */
	private ?array $lastSubject = null;
	private ?string $lastSubjectName = null;
	private ?string $lastObjectType = null;
	private ?string $lastObjectId = null;
	private ?string $lastUser = null;

	protected function setUp(): void {
		$this->manager = $this->createMock(IManager::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new NotificationService(
			$this->manager,
			$this->houseUserMapper,
			$this->logger
		);

		$this->lastSubject = null;
		$this->lastSubjectName = null;
		$this->lastObjectType = null;
		$this->lastObjectId = null;
		$this->lastUser = null;
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

		$this->service->notifyStreakAtRisk('alice', 5);

		$this->assertSame('streak_at_risk', $this->lastSubjectName);
		$this->assertSame(['currentStreak' => 5], $this->lastSubject);
		$this->assertSame('streak', $this->lastObjectType);
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
	 */
	public function testDispatch_ManagerThrows_LogsAndSwallows(): void {
		$this->manager->method('createNotification')->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects($this->once())->method('warning');

		// Must not propagate the exception.
		$this->service->notifyOverdueTakenOver('alice', 'Dishes', 'Bob', 42);
	}
}
