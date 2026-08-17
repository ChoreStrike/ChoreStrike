<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Service\NotificationService;
use OCA\ChoreStrike\Service\TaskDueReminderService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for TaskDueReminderService (CS-PLAN-030 / FEAT-NOTIFY-001).
 *
 * Mirrors StreakAtRiskServiceTest: the service receives "today" as a
 * parameter, so every test pins a fixed calendar day (a Monday) and asserts
 * the behaviour as a pure function of that injected date. The is_active
 * filtering is part of RoomTaskMapper::findDueOnDate's SQL contract and is
 * covered by the real-DB regression in tests/integration/Db.
 */
class TaskDueReminderServiceTest extends TestCase {
	/** Fixed injected "today": a Monday, independent of the real clock. */
	private const TODAY = '2026-08-17 06:00:00';
	private const TOMORROW = '2026-08-18';

	private RoomTaskMapper&MockObject $roomTaskMapper;
	private TaskAssigneeMapper&MockObject $taskAssigneeMapper;
	private HouseUserMapper&MockObject $houseUserMapper;
	private TaskMapper&MockObject $taskMapper;
	private NotificationService&MockObject $notificationService;
	private LoggerInterface&MockObject $logger;
	private TaskDueReminderService $service;
	private \DateTime $today;

	protected function setUp(): void {
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->today = new \DateTime(self::TODAY);

		$this->service = new TaskDueReminderService(
			$this->roomTaskMapper,
			$this->taskAssigneeMapper,
			$this->houseUserMapper,
			$this->taskMapper,
			$this->notificationService,
			$this->logger,
		);
	}

	/**
	 * Process_DueTodayTask_NotifiesAssigneeAsDueToday
	 */
	public function testProcess_DueTodayTask_NotifiesAssigneeAsDueToday(): void {
		$user = $this->buildUser('alice');

		$this->roomTaskMapper->method('findDueOnDate')
			->willReturnCallback(function (\DateTimeInterface $day): array {
				return $day->format('Y-m-d') === '2026-08-17' ? [$this->buildRoomTask(11, 21, new \DateTime('2026-08-17 09:00:00'))] : [];
			});
		$this->taskAssigneeMapper->method('findUserIdsByRoomTasks')->willReturn([11 => ['alice']]);
		$this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
		$this->taskMapper->method('find')->with(21)->willReturn($this->buildTask('Dishes'));
		$this->notificationService->expects($this->once())
			->method('notifyTaskDue')
			->with(
				'alice',
				'Dishes',
				11,
				$this->callback(fn (\DateTimeInterface $due) => $due->format('Y-m-d') === '2026-08-17'),
				false
			);

		$stats = $this->service->process($this->today);

		$this->assertSame(1, $stats['processed']);
		$this->assertSame(1, $stats['notifiedToday']);
		$this->assertSame(0, $stats['notifiedTomorrow']);
	}

	/**
	 * Process_DueTomorrowTask_NotifiesAssigneeAsDueTomorrow
	 */
	public function testProcess_DueTomorrowTask_NotifiesAssigneeAsDueTomorrow(): void {
		$this->roomTaskMapper->method('findDueOnDate')
			->willReturnCallback(function (\DateTimeInterface $day): array {
				return $day->format('Y-m-d') === '2026-08-18' ? [$this->buildRoomTask(12, 22, new \DateTime('2026-08-18 09:00:00'))] : [];
			});
		$this->taskAssigneeMapper->method('findUserIdsByRoomTasks')->willReturn([12 => ['alice']]);
		$this->houseUserMapper->method('findAllForJobs')->willReturn([$this->buildUser('alice')]);
		$this->taskMapper->method('find')->with(22)->willReturn($this->buildTask('Laundry'));
		$this->notificationService->expects($this->once())
			->method('notifyTaskDue')
			->with(
				'alice',
				'Laundry',
				12,
				$this->callback(fn (\DateTimeInterface $due) => $due->format('Y-m-d') === '2026-08-18'),
				true
			);

		$stats = $this->service->process($this->today);

		$this->assertSame(1, $stats['notifiedTomorrow']);
		$this->assertSame(0, $stats['notifiedToday']);
	}

	/**
	 * Process_BothAssigneeSources_ReceiveTheReminder
	 *
	 * D1: recipients come from the join table AND the legacy single-assignee
	 * column, deduplicated.
	 */
	public function testProcess_BothAssigneeSources_ReceiveTheReminder(): void {
		$roomTask = $this->buildRoomTask(11, 21, new \DateTime('2026-08-17 09:00:00'));
		$roomTask->setAssignedToUserId('legacy-user');

		$this->roomTaskMapper->method('findDueOnDate')
			->willReturnCallback(fn (\DateTimeInterface $day): array => $day->format('Y-m-d') === '2026-08-17' ? [$roomTask] : []);
		// Join-table assignee already covered by the legacy column → merged set is unique.
		$this->taskAssigneeMapper->method('findUserIdsByRoomTasks')->willReturn([11 => ['join-user', 'legacy-user']]);
		$this->houseUserMapper->method('findAllForJobs')->willReturn([
			$this->buildUser('join-user'),
			$this->buildUser('legacy-user'),
		]);
		$this->taskMapper->method('find')->willReturn($this->buildTask('Dishes'));

		$this->notificationService->expects($this->exactly(2))->method('notifyTaskDue');

		$stats = $this->service->process($this->today);

		$this->assertSame(2, $stats['notifiedToday']);
	}

	/**
	 * Process_UnavailableOnDueWeekday_SkipsNotification
	 *
	 * D2: the due weekday (Monday) is marked unavailable → skip, counted.
	 */
	public function testProcess_UnavailableOnDueWeekday_SkipsNotification(): void {
		$user = $this->buildUser('alice');
		$user->setSchedulingPreferences(['weekdayAvailability' => ['monday' => false]]);

		$this->roomTaskMapper->method('findDueOnDate')
			->willReturnCallback(fn (\DateTimeInterface $day): array => $day->format('Y-m-d') === '2026-08-17'
				? [$this->buildRoomTask(11, 21, new \DateTime('2026-08-17 09:00:00'))]
				: []);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTasks')->willReturn([11 => ['alice']]);
		$this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
		$this->notificationService->expects($this->never())->method('notifyTaskDue');

		$stats = $this->service->process($this->today);

		$this->assertSame(1, $stats['skippedUnavailable']);
		$this->assertSame(0, $stats['notifiedToday']);
	}

	/**
	 * Process_UnassignedTask_SendsNothing
	 *
	 * D1: no assignees in either source → no notification, but the task
	 * still counts as processed.
	 */
	public function testProcess_UnassignedTask_SendsNothing(): void {
		$this->roomTaskMapper->method('findDueOnDate')
			->willReturnCallback(fn (\DateTimeInterface $day): array => $day->format('Y-m-d') === '2026-08-17'
				? [$this->buildRoomTask(11, 21, new \DateTime('2026-08-17 09:00:00'))]
				: []);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTasks')->willReturn([11 => []]);
		$this->houseUserMapper->method('findAllForJobs')->willReturn([$this->buildUser('alice')]);

		$this->notificationService->expects($this->never())->method('notifyTaskDue');

		$stats = $this->service->process($this->today);

		$this->assertSame(1, $stats['processed']);
		$this->assertSame(0, $stats['notifiedToday']);
	}

	/**
	 * Process_AssigneeOutsideHouseUserMap_Skipped
	 *
	 * A user whose membership vanished (no house_user row) gets no reminder.
	 */
	public function testProcess_AssigneeOutsideHouseUserMap_Skipped(): void {
		$this->roomTaskMapper->method('findDueOnDate')
			->willReturnCallback(fn (\DateTimeInterface $day): array => $day->format('Y-m-d') === '2026-08-17'
				? [$this->buildRoomTask(11, 21, new \DateTime('2026-08-17 09:00:00'))]
				: []);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTasks')->willReturn([11 => ['ghost']]);
		$this->houseUserMapper->method('findAllForJobs')->willReturn([$this->buildUser('alice')]);

		$this->notificationService->expects($this->never())->method('notifyTaskDue');

		$this->service->process($this->today);
	}

	/**
	 * Process_MapperThrows_ReturnsStatsWithoutPropagating
	 */
	public function testProcess_MapperThrows_ReturnsStatsWithoutPropagating(): void {
		$this->roomTaskMapper->method('findDueOnDate')->willThrowException(new \RuntimeException('db gone'));
		$this->logger->expects($this->once())->method('error');

		$stats = $this->service->process($this->today);

		$this->assertSame([
			'processed' => 0,
			'notifiedToday' => 0,
			'notifiedTomorrow' => 0,
			'skippedUnavailable' => 0,
		], $stats);
	}

	/**
	 * Process_NoDueTasks_ReturnsCompleteStats
	 */
	public function testProcess_NoDueTasks_ReturnsCompleteStats(): void {
		$this->roomTaskMapper->method('findDueOnDate')->willReturn([]);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTasks')->willReturn([]);
		$this->houseUserMapper->method('findAllForJobs')->willReturn([]);

		$stats = $this->service->process($this->today);

		$this->assertSame([
			'processed' => 0,
			'notifiedToday' => 0,
			'notifiedTomorrow' => 0,
			'skippedUnavailable' => 0,
		], $stats);
	}

	/**
	 * Process_SharedTaskDefinition_LookedUpOnce
	 *
	 * Two room tasks referencing the same task definition must share one
	 * taskMapper->find call (per-request cache pattern).
	 */
	public function testProcess_SharedTaskDefinition_LookedUpOnce(): void {
		$first = $this->buildRoomTask(11, 21, new \DateTime('2026-08-17 09:00:00'));
		$second = $this->buildRoomTask(12, 21, new \DateTime('2026-08-17 10:00:00'));

		$this->roomTaskMapper->method('findDueOnDate')
			->willReturnCallback(fn (\DateTimeInterface $day): array => $day->format('Y-m-d') === '2026-08-17' ? [$first, $second] : []);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTasks')->willReturn([11 => ['alice'], 12 => ['alice']]);
		$this->houseUserMapper->method('findAllForJobs')->willReturn([$this->buildUser('alice')]);
		$this->taskMapper->expects($this->once())->method('find')->with(21)->willReturn($this->buildTask('Dishes'));

		$stats = $this->service->process($this->today);

		$this->assertSame(2, $stats['notifiedToday']);
	}

	private function buildRoomTask(int $id, int $taskId, \DateTime $nextDue): RoomTask {
		$roomTask = new RoomTask();
		$roomTask->setId($id);
		$roomTask->setTaskId($taskId);
		$roomTask->setNextDue($nextDue);
		$roomTask->setIsActive(true);
		return $roomTask;
	}

	private function buildUser(string $nextcloudUserId): HouseUser {
		$user = new HouseUser();
		$user->setId($nextcloudUserId === 'alice' ? 1 : 2);
		$user->setNextcloudUserId($nextcloudUserId);
		return $user;
	}

	private function buildTask(string $name): Task {
		$task = new Task();
		$task->setId(21);
		$task->setName($name);
		return $task;
	}
}
