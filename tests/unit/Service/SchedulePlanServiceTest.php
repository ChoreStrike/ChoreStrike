<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\SchedulePlanService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the SchedulePlanService preview (CS-PLAN-065 §4.1):
 * deterministic real-task proposals, legal-slot rules, eligibility before
 * capacity/child limits, and the zero-write contract.
 */
class SchedulePlanServiceTest extends TestCase {
	private RoomTaskMapper|MockObject $roomTaskMapper;
	private TaskMapper|MockObject $taskMapper;
	private RoomMapper|MockObject $roomMapper;
	private HouseMapper|MockObject $houseMapper;
	private HouseUserMapper|MockObject $houseUserMapper;
	private TaskAssigneeMapper|MockObject $taskAssigneeMapper;
	private CompletedTaskMapper|MockObject $completedTaskMapper;
	private TaskAccessPolicy|MockObject $taskAccessPolicy;
	private SchedulePlanService $service;
	private string $todayKey;
	private HouseUser $owner;
	/** Toggled by visibility-denial tests (the stub reads this flag). */
	private bool $allowView = true;

	protected function setUp(): void {
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->taskAccessPolicy = $this->createMock(TaskAccessPolicy::class);
		$this->taskAccessPolicy->method('canView')->willReturnCallback(fn (): bool => $this->allowView);

		$utcHouse = new House();
		$utcHouse->setTimezone('UTC');
		$this->houseMapper->method('find')->willReturn($utcHouse);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->service = new SchedulePlanService(
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->roomMapper,
			$this->houseMapper,
			$this->houseUserMapper,
			$this->taskAssigneeMapper,
			$this->completedTaskMapper,
			new TransactionExecutor($this->createMock(\OCP\IDBConnection::class)),
			new CalendarService(),
			$this->taskAccessPolicy,
			$l10n,
		);

		$this->todayKey = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
		$this->owner = $this->member('dad', 1, 'owner', ['monday' => 10, 'tuesday' => 10, 'wednesday' => 10, 'thursday' => 10, 'friday' => 10, 'saturday' => 10, 'sunday' => 10]);
	}

	// -----------------------------------------------------------------
	// Fixture helpers
	// -----------------------------------------------------------------

	private function member(string $userId, int $rowId, string $role = 'member', array $budget = [], array $extraPreferences = []): HouseUser {
		$member = new HouseUser();
		$member->setId($rowId);
		$member->setHouseId(7);
		$member->setNextcloudUserId($userId);
		$member->setDisplayName($userId);
		$member->setRole($role);
		$member->setSchedulingPreferences(array_merge($budget === [] ? [] : ['energyBudget' => $budget], $extraPreferences));
		return $member;
	}

	private function definition(int $taskId, array $overrides = []): Task {
		$task = new Task();
		$task->setId($taskId);
		$task->setName('Task ' . $taskId);
		$task->setEnergyCost($overrides['energyCost'] ?? 2);
		$task->setPriority($overrides['priority'] ?? 2);
		$task->setFrequencyType($overrides['frequencyType'] ?? 'daily');
		$task->setFrequencyValue($overrides['frequencyValue'] ?? 1);
		$task->setRecurrenceMode($overrides['recurrenceMode'] ?? Task::RECURRENCE_RECURRING);
		$task->setSchedulingMode($overrides['schedulingMode'] ?? Task::SCHEDULING_DYNAMIC);
		$task->setFlexDays($overrides['flexDays'] ?? 0);
		$task->setVisibilityType($overrides['visibilityType'] ?? Task::VISIBILITY_PUBLIC);
		if (isset($overrides['fixedScheduleDays'])) {
			$task->setFixedScheduleDays($overrides['fixedScheduleDays']);
		}
		if (isset($overrides['activeUntil'])) {
			$task->setActiveUntil($overrides['activeUntil']);
		}
		return $task;
	}

	private function roomTask(int $id, int $taskId, ?string $nextDueKey, array $overrides = []): RoomTask {
		$row = new RoomTask();
		$row->setId($id);
		$row->setTaskId($taskId);
		$row->setRoomId(50 + $id);
		$row->setIsActive($overrides['isActive'] ?? true);
		if ($nextDueKey !== null) {
			$row->setNextDue(new \DateTime($nextDueKey . ' 00:00:00', new \DateTimeZone('UTC')));
		}
		if (isset($overrides['claimedByUser'])) {
			$row->setClaimedByUser($overrides['claimedByUser']);
		}
		return $row;
	}

	private function room(int $id): Room {
		$room = new Room();
		$room->setId($id);
		$room->setName('Room ' . $id);
		return $room;
	}

	/**
	 * Wire the default mock plumbing for a plan over the given rows.
	 *
	 * @param array<int, RoomTask> $rowsById
	 * @param array<int, Task> $tasksById
	 * @param array<string, HouseUser> $members
	 * @param array<int, string[]> $assigneesByRoomTask
	 * @param array<int, RoomTask> $baseRows
	 */
	private function wire(
		array $rowsById,
		array $tasksById,
		array $members,
		array $assigneesByRoomTask = [],
		array $baseRows = [],
	): void {
		$this->roomTaskMapper->method('findByIdsScopedToHouse')->willReturnCallback(
			fn (array $ids): array => array_values(array_intersect_key($rowsById + $baseRows, array_flip(array_map('intval', $ids))))
		);
		$this->roomTaskMapper->method('findActiveDueUpToByHouse')->willReturn(
			array_values(array_filter($baseRows + $rowsById, static fn (RoomTask $row): bool => $row->getIsActive()))
		);
		$this->taskMapper->method('findByIds')->willReturnCallback(
			fn (array $ids): array => array_intersect_key($tasksById, array_flip($ids))
		);
		$this->roomMapper->method('findByIds')->willReturnCallback(
			fn (array $ids): array => array_combine($ids, array_map(fn (int $id): Room => $this->room($id), $ids))
		);
		$this->houseUserMapper->method('findByHouseId')->willReturn(array_values($members));
		$this->taskAssigneeMapper->method('findUserIdsByRoomTasks')->willReturnCallback(
			static fn (array $ids): array => array_intersect_key($assigneesByRoomTask, array_flip($ids)) + array_fill_keys($ids, [])
		);
		$this->completedTaskMapper->method('findEnergyUsageBetweenByHouse')->willReturn([]);
		$this->completedTaskMapper->method('findUndoStateByRoomTasks')->willReturn([]);
	}

	private function dayOffset(int $days): string {
		return (new \DateTime($this->todayKey))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
	}

	// -----------------------------------------------------------------
	// Zero-write and fingerprint contract
	// -----------------------------------------------------------------

	public function testPreview_RealIds_PerformsZeroWrites(): void {
		$row = $this->roomTask(5, 10, $this->dayOffset(-1));
		$task = $this->definition(10, ['flexDays' => 0]);
		$this->wire([5 => $row], [10 => $task], ['dad' => $this->owner]);

		// No write primitive of any owning mapper may fire during a preview.
		$this->roomTaskMapper->expects($this->never())->method('update');
		$this->roomTaskMapper->expects($this->never())->method('updateNextDue');
		$this->roomTaskMapper->expects($this->never())->method('insert');
		$this->taskAssigneeMapper->expects($this->never())->method('addAssignee');
		$this->taskAssigneeMapper->expects($this->never())->method('deleteByRoomTask');

		$this->service->preview(7, $this->owner, $this->todayKey, [5], []);
	}

	public function testPreview_OverdueFlexTask_PlacesNearestLegalDateWithMostCapacity(): void {
		$row = $this->roomTask(5, 10, $this->dayOffset(-1));
		$row->setIdealDueDate(new \DateTime($this->dayOffset(-1) . ' 00:00:00', new \DateTimeZone('UTC')));
		$task = $this->definition(10, ['flexDays' => 3]);
		$task->setFlexDays(3);
		$this->wire([5 => $row], [10 => $task], ['dad' => $this->owner]);

		$plan = $this->service->preview(7, $this->owner, $this->todayKey, [5], []);

		$this->assertCount(1, $plan['changes']);
		$change = $plan['changes'][0];
		$this->assertSame(5, $change['roomTaskId']);
		$this->assertSame($this->dayOffset(-1), $change['before']['dueDate']);
		// Nearest legal date at/after the window start.
		$this->assertSame($this->todayKey, $change['proposed']['dueDate']);
		$this->assertSame(['dad'], $change['proposed']['assignees']);
		$this->assertSame(['DATE_MOVED', 'ASSIGNEES_CHANGED'], $change['warnings']);
		$this->assertSame('UTC', $plan['timezone']);
		$this->assertSame($this->todayKey, $plan['startDate']);
		$this->assertSame($this->dayOffset(7), $plan['endDateExclusive']);
		$this->assertSame([], $plan['unchanged']);
		$this->assertSame([], $plan['unplaced']);
	}

	public function testPreview_ReorderedInput_ProducesIdenticalVersion(): void {
		$rowA = $this->roomTask(5, 10, $this->dayOffset(-1));
		$rowA->setIdealDueDate(new \DateTime($this->dayOffset(-1) . ' 00:00:00', new \DateTimeZone('UTC')));
		$rowB = $this->roomTask(6, 11, $this->dayOffset(-2));
		$rowB->setIdealDueDate(new \DateTime($this->dayOffset(-2) . ' 00:00:00', new \DateTimeZone('UTC')));
		$taskA = $this->definition(10, ['flexDays' => 3, 'priority' => 3]);
		$taskA->setFlexDays(3);
		$taskB = $this->definition(11, ['flexDays' => 3]);
		$taskB->setFlexDays(3);
		$rows = [5 => $rowA, 6 => $rowB];
		$tasks = [10 => $taskA, 11 => $taskB];
		$this->wire($rows, $tasks, ['dad' => $this->owner]);

		$forward = $this->service->preview(7, $this->owner, $this->todayKey, [5, 6], []);
		$reversed = $this->service->preview(7, $this->owner, $this->todayKey, [6, 5], []);

		$this->assertSame($forward['previewVersion'], $reversed['previewVersion']);
		$this->assertSame(
			array_map(static fn (array $change): int => $change['roomTaskId'], $forward['changes']),
			array_map(static fn (array $change): int => $change['roomTaskId'], $reversed['changes'])
		);
		// Urgency first: the row overdue by two days is proposed first.
		$this->assertSame(6, $forward['changes'][0]['roomTaskId']);
	}

	public function testPreview_ChangedRelevantState_ChangesVersion(): void {
		$row = $this->roomTask(5, 10, $this->dayOffset(-1));
		$row->setIdealDueDate(new \DateTime($this->dayOffset(-1) . ' 00:00:00', new \DateTimeZone('UTC')));
		$task = $this->definition(10, ['flexDays' => 3]);
		$task->setFlexDays(3);

		$this->wire([5 => $row], [10 => $task], ['dad' => $this->owner]);
		$before = $this->service->preview(7, $this->owner, $this->todayKey, [5], []);

		$row->setNextDue(new \DateTime($this->dayOffset(-2) . ' 00:00:00', new \DateTimeZone('UTC')));
		$row->setIdealDueDate(new \DateTime($this->dayOffset(-2) . ' 00:00:00', new \DateTimeZone('UTC')));
		$after = $this->service->preview(7, $this->owner, $this->todayKey, [5], []);

		$this->assertNotSame($before['previewVersion'], $after['previewVersion']);
	}

	// -----------------------------------------------------------------
	// Request validation
	// -----------------------------------------------------------------

	/**
	 * @dataProvider invalidRequestProvider
	 */
	public function testPreview_InvalidRequest_RejectedWithoutWrites(callable $run, string $expectedMessage): void {
		$this->roomTaskMapper->expects($this->never())->method('findByIdsScopedToHouse');
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($expectedMessage);
		$run($this->service, $this->owner, $this->todayKey);
	}

	/**
	 * @return array<string, array{0: callable, 1: string}>
	 */
	public static function invalidRequestProvider(): array {
		$today = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
		return [
			'malformed date' => [
				fn (SchedulePlanService $s, HouseUser $o) => $s->preview(7, $o, '2026-02-30', [1], []),
				'startDate must be a valid Y-m-d date',
			],
			'wrong type date' => [
				fn (SchedulePlanService $s, HouseUser $o) => $s->preview(7, $o, 'not-a-date', [1], []),
				'startDate must be a valid Y-m-d date',
			],
			'past window' => [
				fn (SchedulePlanService $s, HouseUser $o) => $s->preview(7, $o, '2020-01-01', [1], []),
				'startDate must be today or later',
			],
			'empty ids' => [
				fn (SchedulePlanService $s, HouseUser $o) => $s->preview(7, $o, $today, [], []),
				'roomTaskIds must not be empty',
			],
			'duplicate ids' => [
				fn (SchedulePlanService $s, HouseUser $o) => $s->preview(7, $o, $today, [3, 3], []),
				'roomTaskIds must not contain duplicates',
			],
			'non-positive id' => [
				fn (SchedulePlanService $s, HouseUser $o) => $s->preview(7, $o, $today, [0], []),
				'roomTaskIds must contain positive integers',
			],
			'oversized batch' => [
				fn (SchedulePlanService $s, HouseUser $o) => $s->preview(7, $o, $today, range(1, SchedulePlanService::MAX_BATCH_SIZE + 1), []),
				'A plan may contain at most %1$s',
			],
			'override for unselected row' => [
				fn (SchedulePlanService $s, HouseUser $o) => $s->preview(7, $o, $today, [1], [2 => ['date' => $today]]),
				'Overrides must reference a selected room task',
			],
			'override with unknown field' => [
				fn (SchedulePlanService $s, HouseUser $o) => $s->preview(7, $o, $today, [1], [1 => ['energy' => 3]]),
				'Unknown override field',
			],
			'empty override' => [
				fn (SchedulePlanService $s, HouseUser $o) => $s->preview(7, $o, $today, [1], [1 => []]),
				'Each override must set assigneeUserIds or date',
			],
			'override with duplicate assignees' => [
				fn (SchedulePlanService $s, HouseUser $o) => $s->preview(7, $o, $today, [1], [1 => ['assigneeUserIds' => ['dad', 'dad']]]),
				'assigneeUserIds must not contain duplicates',
			],
			'override with malformed date' => [
				fn (SchedulePlanService $s, HouseUser $o) => $s->preview(7, $o, $today, [1], [1 => ['date' => '31-12-2026']]),
				'Override date must be a valid Y-m-d date',
			],
		];
	}

	public function testPreview_UnknownId_RejectedAsNotFound(): void {
		$this->wire([5 => $this->roomTask(5, 10, $this->dayOffset(-1))], [10 => $this->definition(10)], ['dad' => $this->owner]);

		$this->expectException(NotFoundException::class);
		$this->service->preview(7, $this->owner, $this->todayKey, [5, 99], []);
	}

	public function testPreview_InactiveRow_RejectedAsConflict(): void {
		$this->wire(
			[5 => $this->roomTask(5, 10, $this->dayOffset(-1), ['isActive' => false])],
			[10 => $this->definition(10)],
			['dad' => $this->owner],
		);

		$this->expectException(ConflictException::class);
		$this->service->preview(7, $this->owner, $this->todayKey, [5], []);
	}

	public function testPreview_InvisibleTask_RejectedAsNotFound(): void {
		$this->allowView = false;
		$this->wire([5 => $this->roomTask(5, 10, $this->dayOffset(-1))], [10 => $this->definition(10)], ['dad' => $this->owner]);

		$this->expectException(NotFoundException::class);
		$this->service->preview(7, $this->owner, $this->todayKey, [5], []);
	}

	public function testPreview_NonOwnerActor_Rejected(): void {
		$member = $this->member('mom', 2, 'member');

		$this->expectException(ForbiddenException::class);
		$this->service->preview(7, $member, $this->todayKey, [5], []);
	}

	// -----------------------------------------------------------------
	// Legal-slot rules
	// -----------------------------------------------------------------

	public function testPreview_ZeroFlexOverdueTask_StaysUnplaced(): void {
		$row = $this->roomTask(5, 10, $this->dayOffset(-1));
		$task = $this->definition(10, ['flexDays' => 0]);
		$this->wire([5 => $row], [10 => $task], ['dad' => $this->owner]);

		$plan = $this->service->preview(7, $this->owner, $this->todayKey, [5], []);

		$this->assertSame([], $plan['changes']);
		$this->assertCount(1, $plan['unplaced']);
		$this->assertSame('NO_LEGAL_SLOT', $plan['unplaced'][0]['reason']);
	}

	public function testPreview_StaticWeeklyTask_MovesOnlyOntoConfiguredWeekday(): void {
		// Date-independent expectation: when today IS the configured weekday,
		// today is the legal slot; otherwise the next occurrence of it.
		$isSaturdayToday = (int)(new \DateTime($this->todayKey))->format('w') === 6;
		$saturdayKey = $isSaturdayToday
			? $this->todayKey
			: (new \DateTime($this->todayKey))->modify('next saturday')->format('Y-m-d');
		$row = $this->roomTask(5, 10, $this->dayOffset(0));
		$task = $this->definition(10, [
			'schedulingMode' => Task::SCHEDULING_STATIC,
			'frequencyType' => 'weekly',
			'fixedScheduleDays' => json_encode(['saturday']),
			'flexDays' => 0,
		]);
		$this->wire([5 => $row], [10 => $task], ['dad' => $this->owner]);

		$plan = $this->service->preview(7, $this->owner, $this->todayKey, [5], []);

		$this->assertCount(1, $plan['changes']);
		$this->assertSame($saturdayKey, $plan['changes'][0]['proposed']['dueDate']);
		$this->assertSame([$saturdayKey], $plan['changes'][0]['legalDates']);
	}

	public function testPreview_OnceTask_PlacesWithinFlexAroundNextDue(): void {
		$row = $this->roomTask(5, 10, $this->dayOffset(1));
		$task = $this->definition(10, ['recurrenceMode' => Task::RECURRENCE_ONCE, 'flexDays' => 1]);
		$this->wire([5 => $row], [10 => $task], ['dad' => $this->owner]);

		$plan = $this->service->preview(7, $this->owner, $this->todayKey, [5], []);

		// Already in the window on a legal day with an assignee proposal
		// pending (unassigned) — placed nearest to the current day.
		$this->assertCount(1, $plan['changes']);
		$this->assertSame($this->dayOffset(1), $plan['changes'][0]['proposed']['dueDate']);
		$this->assertContains($this->dayOffset(0), $plan['changes'][0]['legalDates']);
		$this->assertContains($this->dayOffset(2), $plan['changes'][0]['legalDates']);
	}

	public function testPreview_ActiveUntilClampsLegalDates(): void {
		$row = $this->roomTask(5, 10, $this->dayOffset(-1));
		$task = $this->definition(10, [
			'flexDays' => 3,
			'activeUntil' => new \DateTime($this->todayKey . ' 23:59:59', new \DateTimeZone('UTC')),
		]);
		$this->wire([5 => $row], [10 => $task], ['dad' => $this->owner]);

		$plan = $this->service->preview(7, $this->owner, $this->todayKey, [5], []);

		$this->assertSame([$this->todayKey], $plan['changes'][0]['legalDates']);
	}

	// -----------------------------------------------------------------
	// Eligibility, availability, capacity and child limits
	// -----------------------------------------------------------------

	public function testPreview_ClaimedTask_StaysUnchanged(): void {
		$row = $this->roomTask(5, 10, $this->dayOffset(-1), ['claimedByUser' => 'dad']);
		$this->wire([5 => $row], [10 => $this->definition(10)], ['dad' => $this->owner], [5 => ['dad']]);

		$plan = $this->service->preview(7, $this->owner, $this->todayKey, [5], []);

		$this->assertSame([], $plan['changes']);
		$this->assertCount(1, $plan['unchanged']);
		$this->assertSame('CLAIMED', $plan['unchanged'][0]['reason']);
	}

	public function testPreview_InWindowAssignedTask_UpToDate(): void {
		$row = $this->roomTask(5, 10, $this->todayKey);
		$this->wire([5 => $row], [10 => $this->definition(10)], ['dad' => $this->owner], [5 => ['dad']]);

		$plan = $this->service->preview(7, $this->owner, $this->todayKey, [5], []);

		$this->assertSame([], $plan['changes']);
		$this->assertCount(1, $plan['unchanged']);
		$this->assertSame('UP_TO_DATE', $plan['unchanged'][0]['reason']);
	}

	public function testPreview_NoCapacityAnywhere_UnplacedWithReason(): void {
		$broke = $this->member('dad', 1, 'owner', ['monday' => 1, 'tuesday' => 1, 'wednesday' => 1, 'thursday' => 1, 'friday' => 1, 'saturday' => 1, 'sunday' => 1]);
		$row = $this->roomTask(5, 10, $this->dayOffset(-1));
		$task = $this->definition(10, ['flexDays' => 3, 'energyCost' => 3]);
		$this->wire([5 => $row], [10 => $task], ['dad' => $broke]);

		$plan = $this->service->preview(7, $broke, $this->todayKey, [5], []);

		$this->assertSame([], $plan['changes']);
		$this->assertCount(1, $plan['unplaced']);
		$this->assertSame('NO_CAPACITY', $plan['unplaced'][0]['reason']);
	}

	public function testPreview_UnavailableMember_IsNotUsed(): void {
		$availability = [
			'monday' => true, 'tuesday' => true, 'wednesday' => true,
			'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true,
		];
		$availability[strtolower($this->dayName($this->todayKey))] = false;
		$availability[strtolower($this->dayName($this->dayOffset(1)))] = false;
		$unavailableTodayAndTomorrow = $this->member('dad', 1, 'owner', [], ['weekdayAvailability' => $availability]);
		$row = $this->roomTask(5, 10, $this->dayOffset(-1));
		$task = $this->definition(10, ['flexDays' => 1]);
		$this->wire([5 => $row], [10 => $task], ['dad' => $unavailableTodayAndTomorrow]);

		// The only legal days (today/tomorrow within the flex window) are
		// unavailable → unplaced, never an unavailable assignment.
		$plan = $this->service->preview(7, $unavailableTodayAndTomorrow, $this->todayKey, [5], []);

		$this->assertSame([], $plan['changes']);
		$this->assertSame('NO_CAPACITY', $plan['unplaced'][0]['reason']);
	}

	private function dayName(string $dayKey): string {
		return \DateTime::createFromFormat('Y-m-d', $dayKey)->format('l');
	}

	public function testPreview_ChildLimit_BlocksPlacement(): void {
		$kid = $this->member('kid', 2, 'child', ['monday' => 10, 'tuesday' => 10, 'wednesday' => 10, 'thursday' => 10, 'friday' => 10, 'saturday' => 10, 'sunday' => 10], ['maxDailyTasks' => 1]);
		// Unassigned row due today: its zero-flex legal slot is today, where
		// the child's single slot is already consumed by the blocker.
		$row = $this->roomTask(5, 10, $this->todayKey);
		$task = $this->definition(10, ['flexDays' => 0, 'energyCost' => 1]);
		$blocker = $this->roomTask(6, 11, $this->todayKey);
		$blockerTask = $this->definition(11, ['energyCost' => 1, 'flexDays' => 0]);
		$this->wire([5 => $row, 6 => $blocker], [10 => $task, 11 => $blockerTask], ['kid' => $kid], [6 => ['kid']]);

		$plan = $this->service->preview(7, $this->owner, $this->todayKey, [5], []);

		$this->assertSame([], $plan['changes']);
		$this->assertSame('NO_CAPACITY', $plan['unplaced'][0]['reason']);
	}

	public function testPreview_ExistingOverload_ReportedNotFixed(): void {
		$tight = $this->member('dad', 1, 'owner', ['monday' => 4, 'tuesday' => 4, 'wednesday' => 4, 'thursday' => 4, 'friday' => 4, 'saturday' => 4, 'sunday' => 4]);
		$row = $this->roomTask(5, 10, $this->todayKey);
		$task = $this->definition(10, ['energyCost' => 5]);
		$this->wire([5 => $row], [10 => $task], ['dad' => $tight], [5 => ['dad']]);

		$plan = $this->service->preview(7, $tight, $this->todayKey, [5], []);

		$this->assertSame([], $plan['changes']);
		$this->assertCount(1, $plan['unchanged']);
		$this->assertSame('UP_TO_DATE', $plan['unchanged'][0]['reason']);
		$this->assertSame(['EXISTING_OVERLOAD'], $plan['unchanged'][0]['warnings']);
		$this->assertSame(['EXISTING_OVERLOAD'], $plan['warnings']);
	}

	public function testPreview_MultiAssigneeSet_CountsFullEffortPerAssignee(): void {
		$dad = $this->member('dad', 1, 'owner', ['monday' => 6, 'tuesday' => 6, 'wednesday' => 6, 'thursday' => 6, 'friday' => 6, 'saturday' => 6, 'sunday' => 6]);
		$mom = $this->member('mom', 2, 'member', ['monday' => 4, 'tuesday' => 4, 'wednesday' => 4, 'thursday' => 4, 'friday' => 4, 'saturday' => 4, 'sunday' => 4]);
		$row = $this->roomTask(5, 10, $this->todayKey);
		$task = $this->definition(10, ['energyCost' => 5]);
		$this->wire([5 => $row], [10 => $task], ['dad' => $dad, 'mom' => $mom], [5 => ['dad', 'mom']]);

		$plan = $this->service->preview(7, $this->owner, $this->todayKey, [5], []);

		// mom's budget (4) < full effort (5) → existing unavoidable overload.
		$this->assertSame(['EXISTING_OVERLOAD'], $plan['unchanged'][0]['warnings']);
		$today = array_values(array_filter($plan['capacity'], fn (array $day): bool => $day['date'] === $this->todayKey))[0];
		$this->assertSame(5, $today['members']['dad']['planned']);
		$this->assertSame(5, $today['members']['mom']['planned']);
	}

	public function testPreview_AdminOnlyTask_MemberAssigneeRejectedOwnerEligible(): void {
		$mom = $this->member('mom', 2, 'member');
		$row = $this->roomTask(5, 10, $this->dayOffset(-1));
		$task = $this->definition(10, ['flexDays' => 3, 'visibilityType' => Task::VISIBILITY_ADMIN_ONLY]);
		$this->wire([5 => $row], [10 => $task], ['dad' => $this->owner, 'mom' => $mom]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('User is not eligible for this task: %1$s');
		$this->service->preview(7, $this->owner, $this->todayKey, [5], [5 => ['assigneeUserIds' => ['mom']]]);
	}

	public function testPreview_AdminOnlyTask_ProposesOwnerOnly(): void {
		$mom = $this->member('mom', 2, 'member');
		$row = $this->roomTask(5, 10, $this->dayOffset(-1));
		$task = $this->definition(10, ['flexDays' => 3, 'visibilityType' => Task::VISIBILITY_ADMIN_ONLY]);
		$this->wire([5 => $row], [10 => $task], ['dad' => $this->owner, 'mom' => $mom]);

		$plan = $this->service->preview(7, $this->owner, $this->todayKey, [5], []);

		$this->assertSame(['dad'], $plan['changes'][0]['proposed']['assignees']);
	}

	public function testPreview_OverrideToNonMember_Rejected(): void {
		$row = $this->roomTask(5, 10, $this->dayOffset(-1));
		$task = $this->definition(10, ['flexDays' => 3]);
		$this->wire([5 => $row], [10 => $task], ['dad' => $this->owner]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('User is not a member of this house: %1$s');
		$this->service->preview(7, $this->owner, $this->todayKey, [5], [5 => ['assigneeUserIds' => ['stranger']]]);
	}

	public function testPreview_IllegalOverrideDate_LandsUnplaced(): void {
		$row = $this->roomTask(5, 10, $this->dayOffset(-1));
		$task = $this->definition(10, ['flexDays' => 0]);
		$this->wire([5 => $row], [10 => $task], ['dad' => $this->owner]);

		$plan = $this->service->preview(7, $this->owner, $this->todayKey, [5], [5 => ['date' => $this->dayOffset(2)]]);

		$this->assertSame([], $plan['changes']);
		$this->assertCount(1, $plan['unplaced']);
		$this->assertSame('ILLEGAL_OVERRIDE', $plan['unplaced'][0]['reason']);
	}

	public function testPreview_ExplicitOverrideAssignee_AppliedExactly(): void {
		$mom = $this->member('mom', 2, 'member');
		$row = $this->roomTask(5, 10, $this->dayOffset(-1));
		$task = $this->definition(10, ['flexDays' => 3]);
		$this->wire([5 => $row], [10 => $task], ['dad' => $this->owner, 'mom' => $mom], [5 => ['dad']]);

		$plan = $this->service->preview(7, $this->owner, $this->todayKey, [5], [5 => ['assigneeUserIds' => ['mom']]]);

		$this->assertSame(['mom'], $plan['changes'][0]['proposed']['assignees']);
		$this->assertSame(['dad'], $plan['changes'][0]['before']['assignees']);
		// The overdue row also moves into the window start.
		$this->assertSame(['DATE_MOVED', 'ASSIGNEES_CHANGED'], $plan['changes'][0]['warnings']);
	}

	public function testPreview_PlacementDoesNotDoubleCountReplacedOccurrence(): void {
		$dad = $this->member('dad', 1, 'owner', ['monday' => 4, 'tuesday' => 4, 'wednesday' => 4, 'thursday' => 4, 'friday' => 4, 'saturday' => 4, 'sunday' => 4]);
		$row = $this->roomTask(5, 10, $this->todayKey);
		$row->setIdealDueDate(new \DateTime($this->todayKey . ' 00:00:00', new \DateTimeZone('UTC')));
		// A ONCE task only occupies its own day — a recurring daily task
		// would legitimately book every day of the window.
		$task = $this->definition(10, ['energyCost' => 3, 'flexDays' => 1, 'recurrenceMode' => Task::RECURRENCE_ONCE]);
		$this->wire([5 => $row], [10 => $task], ['dad' => $dad], [5 => ['dad']]);

		// Explicit date override within the flex window: the current
		// occurrence is replaced, not added again.
		$plan = $this->service->preview(7, $this->owner, $this->todayKey, [5], [5 => ['date' => $this->dayOffset(1)]]);

		$this->assertCount(1, $plan['changes']);
		$today = array_values(array_filter($plan['capacity'], fn (array $day): bool => $day['date'] === $this->todayKey))[0];
		$tomorrow = array_values(array_filter($plan['capacity'], fn (array $day): bool => $day['date'] === $this->dayOffset(1)))[0];
		$this->assertSame(0, $today['members']['dad']['planned'], 'the replaced occurrence must not stay booked on the old day');
		$this->assertSame(3, $tomorrow['members']['dad']['planned']);
	}
}
