<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskChecklistItemMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\RecurrenceCalculator;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCA\ChoreStrike\Service\WindowService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomTaskReadService (CS-PLAN-067 §3.1) — the batched
 * house/room/scope/daily READ cluster moved out of RoomTaskService.
 */
class RoomTaskReadServiceTest extends TestCase {
	private RoomTaskReadService $service;
	private RoomTaskMapper|MockObject $roomTaskMapper;
	private TaskMapper|MockObject $taskMapper;
	private RoomMapper|MockObject $roomMapper;
	private FloorMapper|MockObject $floorMapper;
	private HouseMapper|MockObject $houseMapper;
	private TaskAssigneeMapper|MockObject $taskAssigneeMapper;
	private TaskAccessPolicy|MockObject $taskAccessPolicy;
	private CompletedTaskMapper|MockObject $completions;
	private TaskChecklistItemMapper|MockObject $checklistItems;
	private \OCA\ChoreStrike\Service\CreditPolicy|MockObject $creditPolicy;
	private \OCA\ChoreStrike\Service\AppLogger|MockObject $logger;

	protected function setUp(): void {
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->floorMapper = $this->createMock(FloorMapper::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->taskAccessPolicy = $this->createMock(TaskAccessPolicy::class);
		$this->completions = $this->createMock(CompletedTaskMapper::class);
		$this->checklistItems = $this->createMock(TaskChecklistItemMapper::class);
		$this->creditPolicy = $this->createMock(\OCA\ChoreStrike\Service\CreditPolicy::class);
		$this->logger = $this->createMock(\OCA\ChoreStrike\Service\AppLogger::class);

		$utcHouse = new House();
		$utcHouse->setTimezone('UTC');
		$this->houseMapper->method('find')->willReturn($utcHouse);

		$this->service = new RoomTaskReadService(
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->roomMapper,
			$this->floorMapper,
			$this->houseMapper,
			$this->taskAssigneeMapper,
			$this->taskAccessPolicy,
			new CalendarService(),
			// The real derivation over a mocked completion read.
			new WindowService(new RecurrenceCalculator(), $this->completions),
			$this->logger,
			$this->checklistItems,
			$this->creditPolicy,
		);
	}

	private function createHouseUser(string $userId = 'user123', string $role = 'member'): HouseUser {
		$houseUser = new HouseUser();
		$houseUser->setNextcloudUserId($userId);
		$houseUser->setRole($role);
		return $houseUser;
	}

	private function stubPolicyPassthrough(): void {
		$this->taskAccessPolicy->method('filterVisibleRoomTasks')
			->willReturnCallback(static fn (array $roomTasks): array => $roomTasks);
	}

	/**
	 * GetHouseRoomTasks_DefaultActiveOnly
	 *
	 * CS-PLAN-067 D1: the house-wide read is ONE batched mapper fetch plus
	 * the visibility filter — the backend primitive behind the TaskManager/
	 * SchedulingView fan-out removal.
	 */
	public function testGetHouseRoomTasks_DefaultActiveOnly(): void {
		$houseId = 1;

		$active = new RoomTask();
		$active->setId(1);
		$active->setTaskId(1);
		$active->setIsActive(true);

		$this->roomTaskMapper
			->expects($this->once())
			->method('findByHouse')
			->with($houseId, true)
			->willReturn([$active]);

		$this->taskMapper->method('findByIds')->willReturn([]);
		$this->stubPolicyPassthrough();

		$result = $this->service->getHouseRoomTasks($houseId, $this->createHouseUser())->roomTasks;

		$this->assertSame([$active], $result);
	}

	/**
	 * GetHouseRoomTasks_InactiveIncludedWhenRequested
	 */
	public function testGetHouseRoomTasks_InactiveIncludedWhenRequested(): void {
		$inactive = new RoomTask();
		$inactive->setId(2);
		$inactive->setTaskId(1);
		$inactive->setIsActive(false);

		$this->roomTaskMapper
			->expects($this->once())
			->method('findByHouse')
			->with(1, false)
			->willReturn([$inactive]);

		$this->taskMapper->method('findByIds')->willReturn([]);
		$this->stubPolicyPassthrough();

		$result = $this->service->getHouseRoomTasks(1, $this->createHouseUser(), false)->roomTasks;

		$this->assertSame([$inactive], $result);
	}

	/**
	 * GetHouseRoomTasks_EmptySet_SkipsEnrichmentQueries
	 *
	 * CS-PLAN-067 §3.1: an empty ID set issues no enrichment query — the
	 * empty house read is exactly one mapper query.
	 */
	public function testGetHouseRoomTasks_EmptySet_SkipsEnrichmentQueries(): void {
		$this->roomTaskMapper
			->expects($this->once())
			->method('findByHouse')
			->willReturn([]);
		$this->taskMapper->expects($this->never())->method('findByIds');
		$this->taskAssigneeMapper->expects($this->never())->method('findUserIdsByRoomTasks');
		$this->taskAccessPolicy->expects($this->never())->method('filterVisibleRoomTasks');

		$this->assertSame([], $this->service->getHouseRoomTasks(1, $this->createHouseUser())->roomTasks);
	}

	/**
	 * GetRoomTasks_WithActiveFilter_ReturnsActiveTasks
	 */
	public function testGetRoomTasks_WithActiveFilter_ReturnsActiveTasks(): void {
		$roomId = 1;

		$roomTask1 = new RoomTask();
		$roomTask1->setId(1);
		$roomTask1->setTaskId(1);
		$roomTask1->setIsActive(true);

		$roomTask2 = new RoomTask();
		$roomTask2->setId(2);
		$roomTask2->setTaskId(1);
		$roomTask2->setIsActive(false);

		$this->roomTaskMapper
			->expects($this->once())
			->method('findByRoomId')
			->with($roomId, true)
			->willReturn([$roomTask1]);

		$this->taskMapper->method('findByIds')->willReturn([]);
		$this->stubPolicyPassthrough();

		$result = $this->service->getRoomTasks($roomId, $this->createHouseUser(), true)->roomTasks;

		$this->assertCount(1, $result);
		$this->assertTrue($result[0]->getIsActive());
	}

	/**
	 * GetRoomTasks_AppliesVisibilityMatrix
	 *
	 * CS-PLAN-061 §3.3: the room-task read path filters through
	 * TaskAccessPolicy::filterVisibleRoomTasks — an invisible room task is
	 * absent from the result.
	 */
	public function testGetRoomTasks_AppliesVisibilityMatrix(): void {
		$roomId = 1;

		$visible = new RoomTask();
		$visible->setId(1);
		$visible->setTaskId(1);
		$visible->setIsActive(true);

		$invisible = new RoomTask();
		$invisible->setId(2);
		$invisible->setTaskId(2);
		$invisible->setIsActive(true);

		$this->roomTaskMapper->method('findByRoomId')->willReturn([$visible, $invisible]);
		$this->taskMapper->method('findByIds')->willReturn([]);

		$this->taskAccessPolicy->method('filterVisibleRoomTasks')->willReturn([$visible]);

		$result = $this->service->getRoomTasks($roomId, $this->createHouseUser())->roomTasks;

		$this->assertSame([$visible], $result);
	}

	/**
	 * GetScopeTasksScopedToHouse_FiltersCrossHouseEntries
	 *
	 * CR-053-02: skip-on-missing intersection — only scope tasks whose ids
	 * survive the house-scoped batch fetch are returned, in the original
	 * fetch order.
	 */
	public function testGetScopeTasksScopedToHouse_FiltersCrossHouseEntries(): void {
		$scopeId = 5;
		$houseId = 1;

		$own = new RoomTask();
		$own->setId(1);
		$own->setTaskId(1);
		$foreign = new RoomTask();
		$foreign->setId(2);
		$foreign->setTaskId(1);

		$this->roomTaskMapper
			->expects($this->once())
			->method('findByScopeId')
			->with($scopeId, true)
			->willReturn([$own, $foreign]);

		$this->roomTaskMapper
			->expects($this->once())
			->method('findByIdsScopedToHouse')
			->with([1, 2], $houseId)
			->willReturn([$own]);

		$this->taskMapper->method('findByIds')->willReturn([]);
		$this->stubPolicyPassthrough();

		$result = $this->service->getScopeTasksScopedToHouse($scopeId, $houseId, $this->createHouseUser())->roomTasks;

		$this->assertSame([$own], $result);
	}

	/**
	 * GetScopeTasksScopedToHouse_EmptyScope_SkipsBatchFetch
	 */
	public function testGetScopeTasksScopedToHouse_EmptyScope_SkipsBatchFetch(): void {
		$this->roomTaskMapper
			->expects($this->once())
			->method('findByScopeId')
			->willReturn([]);
		$this->roomTaskMapper
			->expects($this->never())
			->method('findByIdsScopedToHouse');

		$this->assertSame([], $this->service->getScopeTasksScopedToHouse(9, 1, $this->createHouseUser())->roomTasks);
	}

	/**
	 * AssigneesByRoomTasks_BatchesInOneQuery
	 */
	public function testAssigneesByRoomTasks_BatchesInOneQuery(): void {
		$a = new RoomTask();
		$a->setId(1);
		$b = new RoomTask();
		$b->setId(2);

		$this->taskAssigneeMapper
			->expects($this->once())
			->method('findUserIdsByRoomTasks')
			->with([1, 2])
			->willReturn([1 => ['alice'], 2 => []]);

		$result = $this->service->assigneesByRoomTasks([$a, $b]);

		$this->assertSame(['alice'], $result[1]);
		$this->assertSame([], $result[2]);
	}

	// ------------------------------------------------------------------
	// Cycle blocks and status (CS-PLAN-091 WP-B1, ADR-021)
	// ------------------------------------------------------------------

	/** A weekly after-completion definition. */
	private function definition(int $id = 9, int $flexDays = 0, bool $bonus = false): Task {
		$task = new Task();
		$task->setId($id);
		$task->setFlexDays($flexDays);
		$task->setIsVoluntary($bonus);
		return $task;
	}

	/** An active room task whose first date lies `$anchor` from today (UTC house). */
	private function roomTaskAnchored(int $id, string $anchor, int $taskId = 9): RoomTask {
		$roomTask = new RoomTask();
		$roomTask->setId($id);
		$roomTask->setTaskId($taskId);
		$roomTask->setRoomId(4);
		$roomTask->setIsActive(true);
		$roomTask->setCycleVersion(3);
		$roomTask->setAnchorOn(new \DateTime($anchor, new \DateTimeZone('UTC')));
		$roomTask->setNextDue(new \DateTime($anchor, new \DateTimeZone('UTC')));
		$roomTask->setCreatedAt(new \DateTime('2026-01-01 08:00:00 UTC'));
		return $roomTask;
	}

	private function day(string $relative): string {
		return (new \DateTime($relative, new \DateTimeZone('UTC')))->format('Y-m-d');
	}

	public function testCycleFacts_DerivesEveryRowFromOneBatchedCompletionRead(): void {
		$open = $this->roomTaskAnchored(1, 'today');
		$overdue = $this->roomTaskAnchored(2, '-3 days');
		$upcoming = $this->roomTaskAnchored(3, '+5 days');
		$done = $this->roomTaskAnchored(4, '-10 days');
		$this->taskMapper->expects($this->once())->method('findByIds')->with([9])->willReturn([9 => $this->definition()]);
		$this->completions->expects($this->once())->method('findCycleAnchorsByRoomTasks')->with([1, 2, 3, 4])->willReturn([
			4 => ['id' => 77, 'workDay' => $this->day('-2 days'), 'cycleKey' => 'd' . $this->day('-10 days'), 'fixedCentre' => null],
		]);

		$facts = $this->service->cycleFacts(1, [$open, $overdue, $upcoming, $done]);

		$this->assertSame(
			[1 => 'open', 2 => 'overdue', 3 => 'upcoming', 4 => 'upcoming'],
			array_map(static fn (array $fact): string => $fact['block']['state'], $facts)
		);
		$this->assertSame([
			'cycleKey' => 'd' . $this->day('today'),
			'expectedVersion' => 3,
			'windowStartOn' => $this->day('today'),
			'windowEndOn' => $this->day('today'),
			'centreOn' => $this->day('today'),
			'state' => 'open',
			'postponeCount' => 0,
			'postponeLimit' => 3,
			'canPostpone' => false,
		], $facts[1]['block']);
		$this->assertSame(['c77', $this->day('+5 days')], [$facts[4]['block']['cycleKey'], $facts[4]['block']['centreOn']], 'the next window follows the latest completion');
		$this->assertSame('c77', $facts[4]['cycle']->key);
	}

	public function testCycleFacts_EmptySet_IssuesNoQuery(): void {
		$this->houseMapper->expects($this->never())->method('find');
		$this->completions->expects($this->never())->method('findCycleAnchorsByRoomTasks');

		$this->assertSame([], $this->service->cycleFacts(1, []));
	}

	public function testCycleFacts_PostponedWindow_ShowsItsMovedCentreAndWhetherItCanMoveAgain(): void {
		$roomTask = $this->roomTaskAnchored(1, 'today');
		$roomTask->setCycleKey('d' . $this->day('today'));
		$roomTask->setPostponeCount(3);
		$roomTask->setPostponeDays(3);
		$flexible = $this->roomTaskAnchored(2, 'today');

		$block = $this->service->cycleFacts(1, [$roomTask, $flexible], [9 => $this->definition(9, 2)]);

		$this->assertSame([$this->day('+3 days'), $this->day('+1 day'), $this->day('+5 days'), 3, false], [
			$block[1]['block']['centreOn'], $block[1]['block']['windowStartOn'], $block[1]['block']['windowEndOn'],
			$block[1]['block']['postponeCount'], $block[1]['block']['canPostpone'],
		]);
		$this->assertTrue($block[2]['block']['canPostpone'], 'flex days and fewer than three postponements');
	}

	public function testCycleFacts_BonusSleepingAndEndedTasks_HaveNoWindow(): void {
		$bonus = $this->roomTaskAnchored(1, 'today', 10);
		$asleep = $this->roomTaskAnchored(2, 'today');
		$asleep->setSleepingSince(new \DateTime('-1 day'));
		$ended = $this->roomTaskAnchored(3, 'today');
		$ended->setIsActive(false);
		$orphan = $this->roomTaskAnchored(4, 'today', 404);

		$facts = $this->service->cycleFacts(1, [$bonus, $asleep, $ended, $orphan], [9 => $this->definition(), 10 => $this->definition(10, 0, true)]);

		$this->assertSame(['state' => 'bonus', 'expectedVersion' => 3], $facts[1]['block']);
		$this->assertSame(['state' => 'asleep', 'expectedVersion' => 3, 'wakeOn' => null], $facts[2]['block'], 'the version is the receipt of waking it');
		$this->assertNull($facts[3]['block']);
		$this->assertNull($facts[4]['block'], 'a row without its definition has no cycle');
	}

	public function testWithCycleBlocks_ReusesTheDefinitionsOfTheRows(): void {
		$this->taskMapper->expects($this->never())->method('findByIds');
		$rows = [['roomTask' => $this->roomTaskAnchored(1, '-3 days'), 'task' => $this->definition(), 'room' => new Room()]];

		$rows = $this->service->withCycleBlocks($rows, 1);

		$this->assertSame('overdue', $rows[0]['cycle']['state']);
	}

	/**
	 * API reference: `overdue` once the window has ended, `due_today`
	 * while today lies inside it — never overdue before that (D-084-08).
	 */
	public function testStatusFor_FollowsTheCycleState(): void {
		$roomTask = $this->roomTaskAnchored(1, '+10 days');
		$soon = $this->roomTaskAnchored(2, '+2 days');
		$inactive = $this->roomTaskAnchored(3, '-3 days');
		$inactive->setIsActive(false);

		$this->assertSame('overdue', $this->service->statusFor($roomTask, ['state' => 'overdue']));
		$this->assertSame('due_today', $this->service->statusFor($roomTask, ['state' => 'open']));
		$this->assertSame('upcoming', $this->service->statusFor($roomTask, ['state' => 'upcoming']));
		$this->assertSame('due_soon', $this->service->statusFor($soon, ['state' => 'upcoming']));
		$this->assertSame('upcoming', $this->service->statusFor($roomTask, ['state' => 'bonus']), 'a bonus task is never due');
		$this->assertSame('upcoming', $this->service->statusFor($roomTask, null));
		$this->assertSame('inactive', $this->service->statusFor($inactive, ['state' => 'overdue']));
	}

	public function testRoomTaskPayload_CarriesTheCycleStatus(): void {
		$utc = new \DateTimeZone('UTC');
		// The stored projection alone would read "overdue"; the open window decides.
		$roomTask = $this->roomTaskAnchored(7, '-1 day');

		$payload = $this->service->roomTaskPayload($roomTask, $utc, ['state' => 'open']);

		$this->assertSame('due_today', $payload['status']);
		$this->assertSame(7, $payload['id']);
	}

	public function testSelectedRoomTaskPayload_ResolvesTheCycleOfTheRow(): void {
		$this->taskMapper->method('findByIds')->willReturn([9 => $this->definition()]);

		$payload = $this->service->selectedRoomTaskPayload(3, $this->roomTaskAnchored(7, '-3 days'), new \DateTimeZone('UTC'));

		$this->assertSame('overdue', $payload['status']);
	}

	/**
	 * CR-084-03 review fix: after a committed write a failing status read
	 * degrades to the entity rule (logged) instead of an error that would
	 * invite the client to repeat persisted work.
	 */
	public function testCommittedRoomTaskPayload_DegradesToTheEntityStatusOnFailure(): void {
		$utc = new \DateTimeZone('UTC');
		$roomTask = $this->roomTaskAnchored(7, '-1 day');
		$this->taskMapper->method('findByIds')->willReturn([9 => $this->definition()]);
		$this->completions->method('findCycleAnchorsByRoomTasks')
			->willThrowException(new \RuntimeException('read failed'));
		$this->logger->expects($this->once())->method('warning')
			->with('Room task status read failed after a committed write', [
				'action' => 'create', 'roomTaskId' => 7, 'exception' => \RuntimeException::class,
			]);

		$payload = $this->service->committedRoomTaskPayload(3, $roomTask, $utc, 'create');

		$this->assertSame('overdue', $payload['status'], 'entity day rule fallback');
		$this->assertSame(7, $payload['id']);
	}

	// ------------------------------------------------------------------
	// Execution rows
	// ------------------------------------------------------------------

	private function wireHouseRows(array $roomTasks): void {
		$this->roomTaskMapper->method('findByHouse')->with(1, true)->willReturn($roomTasks);
		$this->taskMapper->method('findByIds')->willReturn([9 => $this->definition()]);
		$room = new Room();
		$room->setId(4);
		$this->roomMapper->method('findByIds')->willReturn([4 => $room]);
		$this->checklistItems->method('countByTaskIds')->willReturn([9 => 2]);
	}

	public function testGetOverdueTaskRows_OneRedEntryPerTaskWhoseWindowEnded(): void {
		$this->stubPolicyPassthrough();
		$this->wireHouseRows([$this->roomTaskAnchored(1, '-30 days'), $this->roomTaskAnchored(2, 'today'), $this->roomTaskAnchored(3, '+2 days')]);

		$rows = $this->service->getOverdueTaskRows(1, $this->createHouseUser());

		$this->assertCount(1, $rows, 'however many periods passed');
		$this->assertSame(1, $rows[0]['roomTask']->getId());
		$this->assertSame(['overdue', 2], [$rows[0]['cycle']['state'], $rows[0]['checklistItemCount']]);
	}

	public function testGetDueTodayTaskRows_EveryOpenWindowOfTheRoom(): void {
		$this->stubPolicyPassthrough();
		$other = $this->roomTaskAnchored(5, 'today');
		$other->setRoomId(8);
		$this->wireHouseRows([$this->roomTaskAnchored(1, '-30 days'), $this->roomTaskAnchored(2, 'today'), $other]);

		$rows = $this->service->getDueTodayTaskRows(1, $this->createHouseUser(), 4);

		$this->assertSame([2], array_map(static fn (array $row): int => $row['roomTask']->getId(), $rows));
		$this->assertSame('open', $rows[0]['cycle']['state']);
	}

	public function testGetOverdueTaskRows_InvisibleTasksNeverReachTheDerivation(): void {
		$this->taskAccessPolicy->method('filterVisibleRoomTasks')->willReturn([]);
		$this->wireHouseRows([$this->roomTaskAnchored(1, '-30 days')]);
		$this->completions->expects($this->never())->method('findCycleAnchorsByRoomTasks');

		$this->assertSame([], $this->service->getOverdueTaskRows(1, $this->createHouseUser()));
	}
}
