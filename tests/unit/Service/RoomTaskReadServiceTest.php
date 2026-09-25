<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\Floor;
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
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCP\IL10N;
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
	private \OCA\ChoreStrike\Service\OccurrenceService|MockObject $occurrenceService;

	protected function setUp(): void {
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->floorMapper = $this->createMock(FloorMapper::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->taskAccessPolicy = $this->createMock(TaskAccessPolicy::class);
		$this->occurrenceService = $this->createMock(\OCA\ChoreStrike\Service\OccurrenceService::class);

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
			$this->occurrenceService,
			$this->createMock(\OCA\ChoreStrike\Service\PauseProjectionService::class),
			$this->createIdentityL10n()
		);
	}

	private function createIdentityL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		return $l10n;
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

		$result = $this->service->getHouseRoomTasks($houseId, $this->createHouseUser());

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

		$result = $this->service->getHouseRoomTasks(1, $this->createHouseUser(), false);

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

		$this->assertSame([], $this->service->getHouseRoomTasks(1, $this->createHouseUser()));
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

		$result = $this->service->getRoomTasks($roomId, $this->createHouseUser(), true);

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

		$result = $this->service->getRoomTasks($roomId, $this->createHouseUser());

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

		$result = $this->service->getScopeTasksScopedToHouse($scopeId, $houseId, $this->createHouseUser());

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

		$this->assertSame([], $this->service->getScopeTasksScopedToHouse(9, 1, $this->createHouseUser()));
	}

	/**
	 * GetOverdueTasks_ReturnsOverdueTasks — occurrence-authority edition
	 * (CS-PLAN-072 WP1): a task is overdue when its canonical selection is
	 * a MISSED occurrence (the catch-up card), not when next_due < now.
	 */
	public function testGetOverdueTasks_ReturnsOverdueTasks(): void {
		$houseId = 1;
		$roomId = 2;

		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setTaskId(1);
		$roomTask->setRoomId($roomId);
		$roomTask->setNextDue(new \DateTime('-1 day'));

		$this->roomTaskMapper
			->expects($this->once())
			->method('findByHouse')
			->with($houseId, true)
			->willReturn([$roomTask]);

		$this->occurrenceService->method('selectionsForHouseTasks')
			->willReturnCallback(static fn (int $houseId, array $tasks, \DateTime $now): array => [1 => ['occurrence' => null, 'revision' => null, 'classification' => 'late']]);

		$this->taskMapper->method('findByIds')->willReturn([]);
		$this->stubPolicyPassthrough();

		$result = $this->service->getOverdueTasks($houseId, $this->createHouseUser(), $roomId);

		$this->assertCount(1, $result);
	}

	/**
	 * GetDueTodayTasks_ReturnsDueTodayTasks — occurrence-authority edition:
	 * a task is due today when an AGREED occurrence is planned on the
	 * current house-local day.
	 */
	public function testGetDueTodayTasks_ReturnsDueTodayTasks(): void {
		$houseId = 1;
		$roomId = 2;

		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setTaskId(1);
		$roomTask->setRoomId($roomId);
		$roomTask->setNextDue(new \DateTime());

		$this->roomTaskMapper
			->expects($this->once())
			->method('findByHouse')
			->with($houseId, true)
			->willReturn([$roomTask]);

		$this->occurrenceService->method('agreedOccurrencesForHouseTasks')
			->willReturnCallback(static fn (int $houseId, array $tasks, ?string $fromKey, ?string $toKey): array => [1 => [['occurrence' => null, 'revision' => null]]]);

		$this->taskMapper->method('findByIds')->willReturn([]);
		$this->stubPolicyPassthrough();

		$result = $this->service->getDueTodayTasks($houseId, $this->createHouseUser(), $roomId);

		$this->assertCount(1, $result);
	}

	/**
	 * GetDueWithinDaysTasks_ReturnsTasksWithinRange
	 */
	public function testGetDueWithinDaysTasks_ReturnsTasksWithinRange(): void {
		$houseId = 1;
		$days = 7;
		$roomId = 2;

		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setTaskId(1);
		$roomTask->setNextDue(new \DateTime('+3 days'));

		$this->roomTaskMapper
			->expects($this->once())
			->method('findDueWithinDaysByHouse')
			->with($houseId, $days, $roomId)
			->willReturn([$roomTask]);

		$this->taskMapper->method('findByIds')->willReturn([]);
		$this->stubPolicyPassthrough();

		$result = $this->service->getDueWithinDaysTasks($houseId, $days, $this->createHouseUser(), $roomId);

		$this->assertCount(1, $result);
	}

	/**
	 * GetRoomTaskWithDetails_WithData_ReturnsCompleteDetails
	 */
	public function testGetRoomTaskWithDetails_WithData_ReturnsCompleteDetails(): void {
		$roomTaskId = 1;

		$task = new Task();
		$task->setId(1);
		$task->setName('Test Task');

		$room = new Room();
		$room->setId(1);
		$room->setName('Test Room');
		$room->setFloorId(7);

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(99);
		$this->floorMapper->method('find')->willReturn($floor);

		$house = new House();
		$house->setTimezone('UTC');
		$this->houseMapper->method('find')->willReturn($house);

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setTaskId(1);
		$roomTask->setRoomId(1);
		$roomTask->setNextDue(new \DateTime('+1 day'));

		$this->roomTaskMapper
			->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->taskMapper
			->expects($this->once())
			->method('find')
			->with(1)
			->willReturn($task);

		$this->roomMapper
			->expects($this->atMost(2))
			->method('find')
			->with(1)
			->willReturn($room);

		$result = $this->service->getRoomTaskWithDetails($roomTaskId);

		$this->assertSame($roomTask, $result['roomTask']);
		$this->assertSame($task, $result['task']);
		$this->assertSame($room, $result['room']);
		$this->assertEquals('due_soon', $result['status']);
		// CS-PLAN-072 WP1: the detail row carries the canonical occurrence
		// target block (null when no target is completable).
		$this->assertArrayHasKey('occurrence', $result);
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
}
