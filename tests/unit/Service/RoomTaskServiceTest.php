<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssignee;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomTaskService
 */
class RoomTaskServiceTest extends TestCase {
	private RoomTaskService $service;
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private FloorMapper $floorMapper;
	private RoomService $roomService;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private HouseUserMapper $houseUserMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private IDBConnection $db;

	protected function setUp(): void {
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->floorMapper = $this->createMock(FloorMapper::class);
		$this->roomService = $this->createMock(RoomService::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->db = $this->createMock(IDBConnection::class);

		$this->service = new RoomTaskService(
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->roomMapper,
			$this->floorMapper,
			$this->roomService,
			$this->taskAssigneeMapper,
			$this->houseUserMapper,
			$this->completedTaskMapper,
			$this->createMock(\OCA\ChoreStrike\Service\TemplateService::class),
			$this->db,
			$this->createIdentityL10n(),
			$this->createMock(AppLogger::class)
		);
	}

	private function createIdentityL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		return $l10n;
	}

	/**
	 * GetRoomTask_ExistingId_ReturnsRoomTask
	 */
	public function testGetRoomTask_ExistingId_ReturnsRoomTask(): void {
		$roomTaskId = 1;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);

		$this->roomTaskMapper
			->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$result = $this->service->getRoomTask($roomTaskId);

		$this->assertSame($roomTask, $result);
	}

	/**
	 * GetRoomTasks_WithActiveFilter_ReturnsActiveTasks
	 */
	public function testGetRoomTasks_WithActiveFilter_ReturnsActiveTasks(): void {
		$roomId = 1;

		$roomTask1 = new RoomTask();
		$roomTask1->setId(1);
		$roomTask1->setIsActive(true);

		$roomTask2 = new RoomTask();
		$roomTask2->setId(2);
		$roomTask2->setIsActive(false);

		$this->roomTaskMapper
			->expects($this->once())
			->method('findByRoomId')
			->with($roomId, true)
			->willReturn([$roomTask1]);

		$result = $this->service->getRoomTasks($roomId, true);

		$this->assertCount(1, $result);
		$this->assertTrue($result[0]->getIsActive());
	}

	/**
	 * GetScopeTasks_ReturnsTasksForScope
	 */
	public function testGetScopeTasks_ReturnsTasksForScope(): void {
		$scopeId = 1;

		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setScopeId($scopeId);

		$this->roomTaskMapper
			->expects($this->once())
			->method('findByScopeId')
			->with($scopeId, true)
			->willReturn([$roomTask]);

		$result = $this->service->getScopeTasks($scopeId);

		$this->assertCount(1, $result);
	}

	/**
	 * GetOverdueTasks_ReturnsOverdueTasks
	 */
	public function testGetOverdueTasks_ReturnsOverdueTasks(): void {
		$houseId = 1;
		$roomId = 2;

		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setNextDue(new \DateTime('-1 day'));

		$this->roomTaskMapper
			->expects($this->once())
			->method('findOverdueByHouse')
			->with($houseId, $roomId)
			->willReturn([$roomTask]);

		$result = $this->service->getOverdueTasks($houseId, $roomId);

		$this->assertCount(1, $result);
	}

	/**
	 * GetDueTodayTasks_ReturnsDueTodayTasks
	 */
	public function testGetDueTodayTasks_ReturnsDueTodayTasks(): void {
		$houseId = 1;
		$roomId = 2;

		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setNextDue(new \DateTime());

		$this->roomTaskMapper
			->expects($this->once())
			->method('findDueTodayByHouse')
			->with($houseId, $roomId)
			->willReturn([$roomTask]);

		$result = $this->service->getDueTodayTasks($houseId, $roomId);

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
		$roomTask->setNextDue(new \DateTime('+3 days'));

		$this->roomTaskMapper
			->expects($this->once())
			->method('findDueWithinDaysByHouse')
			->with($houseId, $days, $roomId)
			->willReturn([$roomTask]);

		$result = $this->service->getDueWithinDaysTasks($houseId, $days, $roomId);

		$this->assertCount(1, $result);
	}

	/**
	 * CreateRoomTask_NewTask_CreatesRoomTask
	 *
	 * The room resolves through the floor chain to the caller's house, so a
	 * same-house create succeeds.
	 */
	public function testCreateRoomTask_NewTask_CreatesRoomTask(): void {
		$houseId = 10;
		$roomId = 1;
		$taskId = 1;
		$floorId = 7;

		$room = new Room();
		$room->setId($roomId);
		$room->setFloorId($floorId);

		$floor = new Floor();
		$floor->setId($floorId);
		$floor->setHouseId($houseId);

		$task = new Task();
		$task->setId($taskId);

		$roomTask = new RoomTask();
		$roomTask->setId(1);

		$this->roomMapper
			->expects($this->once())
			->method('find')
			->with($roomId)
			->willReturn($room);

		$this->floorMapper
			->expects($this->once())
			->method('find')
			->with($floorId)
			->willReturn($floor);

		$this->taskMapper
			->expects($this->once())
			->method('find')
			->with($taskId)
			->willReturn($task);

		$this->roomTaskMapper
			->expects($this->once())
			->method('exists')
			->with($roomId, $taskId)
			->willReturn(false);

		$this->roomTaskMapper
			->expects($this->once())
			->method('create')
			->willReturn($roomTask);

		$result = $this->service->createRoomTask($houseId, $roomId, $taskId);

		$this->assertSame($roomTask, $result);
	}

	/**
	 * CreateRoomTask_DuplicateTask_ThrowsException
	 */
	public function testCreateRoomTask_DuplicateTask_ThrowsException(): void {
		$houseId = 10;
		$roomId = 1;
		$taskId = 1;
		$floorId = 7;

		$room = new Room();
		$room->setId($roomId);
		$room->setFloorId($floorId);

		$floor = new Floor();
		$floor->setId($floorId);
		$floor->setHouseId($houseId);

		$task = new Task();
		$task->setId($taskId);

		$this->roomMapper->method('find')->willReturn($room);
		$this->floorMapper->method('find')->willReturn($floor);
		$this->taskMapper->method('find')->willReturn($task);

		$this->roomTaskMapper
			->expects($this->once())
			->method('exists')
			->willReturn(true);

		$this->expectException(\InvalidArgumentException::class);

		$this->service->createRoomTask($houseId, $roomId, $taskId);
	}

	/**
	 * CreateRoomTask_CrossHouseRoom_ThrowsNotFound
	 *
	 * CR-SVC-01 regression: a room that resolves to another house must be
	 * rejected (NotFoundException) before any persistence. The room/floor
	 * existence boundary must not be leaked.
	 */
	public function testCreateRoomTask_CrossHouseRoom_ThrowsNotFound(): void {
		$houseId = 10;
		$roomId = 1;
		$floorId = 7;

		$room = new Room();
		$room->setId($roomId);
		$room->setFloorId($floorId);

		$floor = new Floor();
		$floor->setId($floorId);
		$floor->setHouseId(99); // a different house

		$this->roomMapper->method('find')->with($roomId)->willReturn($room);
		$this->floorMapper->method('find')->with($floorId)->willReturn($floor);
		$this->taskMapper->expects($this->never())->method('find');
		$this->roomTaskMapper->expects($this->never())->method('create');

		$this->expectException(NotFoundException::class);

		$this->service->createRoomTask($houseId, $roomId, 1);
	}

	/**
	 * CreateRoomTask_RoomNotFound_ThrowsNotFound
	 *
	 * A missing room (or floor) maps to NotFoundException, not a raw
	 * DoesNotExistException leak.
	 */
	public function testCreateRoomTask_RoomNotFound_ThrowsNotFound(): void {
		$this->roomMapper->method('find')
			->willThrowException(new DoesNotExistException('room gone'));

		$this->taskMapper->expects($this->never())->method('find');
		$this->roomTaskMapper->expects($this->never())->method('create');

		$this->expectException(NotFoundException::class);

		$this->service->createRoomTask(10, 1, 1);
	}

	/**
	 * CreateRoomTask_NonMemberAssignee_Throws
	 *
	 * CR-SVC-01 regression: an assignee stamped by the client who is not a
	 * member of the caller's house must be rejected before persistence.
	 */
	public function testCreateRoomTask_NonMemberAssignee_Throws(): void {
		$houseId = 10;
		$roomId = 1;
		$floorId = 7;

		$room = new Room();
		$room->setId($roomId);
		$room->setFloorId($floorId);

		$floor = new Floor();
		$floor->setId($floorId);
		$floor->setHouseId($houseId);

		$this->roomMapper->method('find')->willReturn($room);
		$this->floorMapper->method('find')->willReturn($floor);
		$this->houseUserMapper->expects($this->once())
			->method('findByHouseAndUser')
			->with($houseId, 'intruder')
			->willReturn(null);
		$this->taskMapper->expects($this->never())->method('find');
		$this->roomTaskMapper->expects($this->never())->method('create');

		$this->expectException(\InvalidArgumentException::class);

		$this->service->createRoomTask($houseId, $roomId, 1, null, null, 'intruder');
	}

	/**
	 * CreateRoomTask_ValidMemberAssignee_CreatesRoomTask
	 *
	 * CR-SVC-01 happy path: an assignee who IS a house member is accepted.
	 */
	public function testCreateRoomTask_ValidMemberAssignee_CreatesRoomTask(): void {
		$houseId = 10;
		$roomId = 1;
		$taskId = 1;
		$floorId = 7;

		$room = new Room();
		$room->setId($roomId);
		$room->setFloorId($floorId);

		$floor = new Floor();
		$floor->setId($floorId);
		$floor->setHouseId($houseId);

		$task = new Task();
		$task->setId($taskId);

		$roomTask = new RoomTask();
		$roomTask->setId(1);

		$this->roomMapper->method('find')->willReturn($room);
		$this->floorMapper->method('find')->willReturn($floor);
		$this->taskMapper->method('find')->willReturn($task);
		$this->houseUserMapper->expects($this->once())
			->method('findByHouseAndUser')
			->with($houseId, 'member-user')
			->willReturn(new HouseUser());
		$this->roomTaskMapper->method('exists')->willReturn(false);
		$this->roomTaskMapper->expects($this->once())->method('create')->willReturn($roomTask);

		$result = $this->service->createRoomTask($houseId, $roomId, $taskId, null, null, 'member-user');

		$this->assertSame($roomTask, $result);
	}

	/**
	 * AssignTask_ExistingTaskHouseScoped_AssignsToMember
	 *
	 * CR-SVC-09: assignTask validates room-task ownership (floor chain)
	 * and target-user membership internally before writing.
	 */
	public function testAssignTask_ExistingTaskHouseScoped_AssignsToMember(): void {
		$houseId = 1;
		$roomTaskId = 10;
		$roomId = 5;
		$floorId = 7;
		$userId = 'user123';

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setRoomId($roomId);

		$room = new Room();
		$room->setId($roomId);
		$room->setFloorId($floorId);

		$floor = new Floor();
		$floor->setId($floorId);
		$floor->setHouseId($houseId);

		$member = new HouseUser();
		$member->setNextcloudUserId($userId);

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->roomMapper->method('find')->with($roomId)->willReturn($room);
		$this->floorMapper->method('find')->with($floorId)->willReturn($floor);
		$this->houseUserMapper->method('findByHouseId')->with($houseId)->willReturn([$member]);

		$this->roomTaskMapper
			->expects($this->once())
			->method('assignToUser')
			->with($roomTaskId, $userId);

		$result = $this->service->assignTask($roomTaskId, $userId, $houseId);

		$this->assertSame($roomTask, $result);
	}

	/**
	 * AssignTask_NonMemberTarget_Throws
	 *
	 * CR-SVC-09: the target user must belong to the given house — an
	 * arbitrary-user write is rejected before any persistence.
	 */
	public function testAssignTask_NonMemberTarget_Throws(): void {
		$houseId = 1;
		$roomTaskId = 10;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setRoomId(5);

		$room = new Room();
		$room->setId(5);
		$room->setFloorId(7);

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId($houseId);

		$member = new HouseUser();
		$member->setNextcloudUserId('member-a');

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->roomMapper->method('find')->with(5)->willReturn($room);
		$this->floorMapper->method('find')->with(7)->willReturn($floor);
		$this->houseUserMapper->method('findByHouseId')->with($houseId)->willReturn([$member]);

		$this->roomTaskMapper->expects($this->never())->method('assignToUser');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('User is not a member of this house: %1$s');

		$this->service->assignTask($roomTaskId, 'intruder', $houseId);
	}

	/**
	 * AssignTask_ForeignHouseRoomTask_ThrowsNotFound
	 *
	 * CR-SVC-09: a room task belonging to another house is rejected with
	 * NotFoundException (no existence leak) before any write.
	 */
	public function testAssignTask_ForeignHouseRoomTask_ThrowsNotFound(): void {
		$roomTaskId = 10;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setRoomId(5);

		$room = new Room();
		$room->setId(5);
		$room->setFloorId(7);

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(99); // different house

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->roomMapper->method('find')->with(5)->willReturn($room);
		$this->floorMapper->method('find')->with(7)->willReturn($floor);

		$this->houseUserMapper->expects($this->never())->method('findByHouseId');
		$this->roomTaskMapper->expects($this->never())->method('assignToUser');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Room task not found');

		$this->service->assignTask($roomTaskId, 'user123', 1);
	}

	/**
	 * UnassignTask_ExistingTask_RemovesAssignment
	 */
	public function testUnassignTask_ExistingTask_RemovesAssignment(): void {
		$roomTaskId = 1;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setAssignedToUserId(null);

		$this->roomTaskMapper
			->expects($this->exactly(2))
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->roomTaskMapper
			->expects($this->once())
			->method('assignToUser')
			->with($roomTaskId, '');

		$result = $this->service->unassignTask($roomTaskId);

		$this->assertNull($result->getAssignedToUserId());
	}

	/**
	 * DeactivateTask_ExistingTask_Deactivates
	 */
	public function testDeactivateTask_ExistingTask_Deactivates(): void {
		$roomTaskId = 1;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setIsActive(false);

		$this->roomTaskMapper
			->expects($this->exactly(2))
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->roomTaskMapper
			->expects($this->once())
			->method('deactivate')
			->with($roomTaskId);

		$result = $this->service->deactivateTask($roomTaskId);

		$this->assertFalse($result->getIsActive());
	}

	/**
	 * ReactivateTask_ExistingTask_Reactivates
	 */
	public function testReactivateTask_ExistingTask_Reactivates(): void {
		$roomTaskId = 1;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setIsActive(true);

		$this->roomTaskMapper
			->expects($this->exactly(2))
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->roomTaskMapper
			->expects($this->once())
			->method('reactivate')
			->with($roomTaskId);

		$result = $this->service->reactivateTask($roomTaskId);

		$this->assertTrue($result->getIsActive());
	}

	/**
	 * UpdateDueDate_ExistingTask_UpdatesDueDate
	 */
	public function testUpdateDueDate_ExistingTask_UpdatesDueDate(): void {
		$roomTaskId = 1;
		$newDue = new \DateTime('+3 days');

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setNextDue($newDue);

		$this->roomTaskMapper
			->expects($this->exactly(2))
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->roomTaskMapper
			->expects($this->once())
			->method('updateNextDue')
			->with($roomTaskId, $newDue);

		$result = $this->service->updateDueDate($roomTaskId, $newDue);

		$this->assertSame($roomTask, $result);
	}

	/**
	 * CompleteTask_DelegatesToRoomService
	 */
	public function testCompleteTask_DelegatesToRoomService(): void {
		$roomTaskId = 1;
		$userId = 'user123';

		$completionData = [
			'roomTask' => new RoomTask(),
			'rewards' => ['cleanlinessChange' => 20]
		];

		$this->roomService
			->expects($this->once())
			->method('completeRoomTask')
			->with($roomTaskId, $userId)
			->willReturn($completionData);

		$result = $this->service->completeTask($roomTaskId, $userId);

		$this->assertSame($completionData, $result);
	}

	/**
	 * DeleteRoomTask_ExistingTask_CascadesDependentsInOneTransaction
	 *
	 * CS-PLAN-037 (D1/D2): single-task deletion is orphan-free — the
	 * room task's dependents (checklist progress, assignees,
	 * completions) are removed with it via the shared cascade helper.
	 */
	public function testDeleteRoomTask_ExistingTask_CascadesDependentsInOneTransaction(): void {
		$roomTaskId = 1;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);

		$this->roomTaskMapper
			->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->roomService
			->expects($this->once())
			->method('deleteRoomTaskDependents')
			->with([$roomTaskId]);

		$this->roomTaskMapper
			->expects($this->once())
			->method('delete')
			->with($roomTask);

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$this->service->deleteRoomTask($roomTaskId);
	}

	/**
	 * DeleteRoomTask_DependentFailure_RollsBackAndReThrows
	 */
	public function testDeleteRoomTask_DependentFailure_RollsBackAndReThrows(): void {
		$roomTaskId = 1;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);

		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->roomService->method('deleteRoomTaskDependents')
			->willThrowException(new \RuntimeException('db down'));

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->roomTaskMapper->expects($this->never())->method('delete');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('db down');

		$this->service->deleteRoomTask($roomTaskId);
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
			->expects($this->once())
			->method('find')
			->with(1)
			->willReturn($room);

		$result = $this->service->getRoomTaskWithDetails($roomTaskId);

		$this->assertSame($roomTask, $result['roomTask']);
		$this->assertSame($task, $result['task']);
		$this->assertSame($room, $result['room']);
		$this->assertEquals('due_soon', $result['status']);
	}

	/**
	 * GetRoomTaskStats_WithTasks_ReturnsStats
	 *
	 * CR-SVC-31 (CS-PLAN-040): completionRate counts REAL completions —
	 * distinct completed room tasks from CompletedTaskMapper — not
	 * `(total − active)/total`, which counted deactivated tasks as done.
	 */
	public function testGetRoomTaskStats_WithTasks_ReturnsStats(): void {
		$roomId = 1;

		$activeTask = new RoomTask();
		$activeTask->setId(1);
		$activeTask->setIsActive(true);
		$activeTask->setNextDue(new \DateTime('-1 day'));

		$inactiveTask = new RoomTask();
		$inactiveTask->setId(2);
		$inactiveTask->setIsActive(false);

		$this->roomTaskMapper
			->expects($this->once())
			->method('findByRoomId')
			->with($roomId, false)
			->willReturn([$activeTask, $inactiveTask]);

		// Only one of the two room tasks has a real completion row.
		$this->completedTaskMapper
			->expects($this->once())
			->method('countDistinctCompletedRoomTasksByRoom')
			->with($roomId)
			->willReturn(1);

		$result = $this->service->getRoomTaskStats($roomId);

		$this->assertEquals(2, $result['totalTasks']);
		$this->assertEquals(1, $result['activeTasks']);
		$this->assertEquals(1, $result['overdueTasks']);
		$this->assertEquals(50, $result['completionRate']);
	}

	/**
	 * GetRoomTaskStats_EmptyRoom_CompletionRateIsZero
	 *
	 * CR-SVC-31 regression: the old formula returned 100 for an empty set;
	 * with real completions an empty room has completed nothing → 0.
	 */
	public function testGetRoomTaskStats_EmptyRoom_CompletionRateIsZero(): void {
		$roomId = 1;

		$this->roomTaskMapper
			->method('findByRoomId')
			->willReturn([]);

		$this->completedTaskMapper
			->method('countDistinctCompletedRoomTasksByRoom')
			->willReturn(0);

		$result = $this->service->getRoomTaskStats($roomId);

		$this->assertEquals(0, $result['totalTasks']);
		$this->assertEquals(0, $result['completionRate']);
	}

	/**
	 * GetRoomTaskStats_DeactivatedTasksDoNotCountAsCompleted
	 *
	 * CR-SVC-31 regression: deactivating every task must NOT push the rate
	 * to 100 — only actual completion rows move it.
	 */
	public function testGetRoomTaskStats_DeactivatedTasksDoNotCountAsCompleted(): void {
		$roomId = 1;

		$inactiveTaskA = new RoomTask();
		$inactiveTaskA->setId(1);
		$inactiveTaskA->setIsActive(false);

		$inactiveTaskB = new RoomTask();
		$inactiveTaskB->setId(2);
		$inactiveTaskB->setIsActive(false);

		$this->roomTaskMapper
			->method('findByRoomId')
			->willReturn([$inactiveTaskA, $inactiveTaskB]);

		$this->completedTaskMapper
			->method('countDistinctCompletedRoomTasksByRoom')
			->willReturn(0);

		$result = $this->service->getRoomTaskStats($roomId);

		$this->assertEquals(2, $result['totalTasks']);
		$this->assertEquals(0, $result['activeTasks']);
		$this->assertEquals(0, $result['completionRate']);
	}

	/**
	 * BulkAssignTasks_MultipleTasks_AssignsAllWithBatchPreValidation
	 *
	 * CS-PLAN-037: ONE house-scoped fetch + ONE membership validation
	 * replace the per-item ownership chain (≈7N → 3 + N); the return
	 * payload comes from one re-fetch of the assigned ids, in input order.
	 */
	public function testBulkAssignTasks_MultipleTasks_AssignsAllWithBatchPreValidation(): void {
		$houseId = 1;
		$roomTaskIds = [1, 2, 3];
		$userId = 'user123';

		$scopedTask1 = new RoomTask();
		$scopedTask1->setId(1);

		$scopedTask2 = new RoomTask();
		$scopedTask2->setId(2);

		$scopedTask3 = new RoomTask();
		$scopedTask3->setId(3);

		$freshTask1 = new RoomTask();
		$freshTask1->setId(1);
		$freshTask1->setAssignedToUserId($userId);

		$freshTask2 = new RoomTask();
		$freshTask2->setId(2);
		$freshTask2->setAssignedToUserId($userId);

		$freshTask3 = new RoomTask();
		$freshTask3->setId(3);
		$freshTask3->setAssignedToUserId($userId);

		$member = new HouseUser();
		$member->setNextcloudUserId($userId);

		// One batch fetch up front, one re-fetch for the payload.
		$this->roomTaskMapper
			->expects($this->exactly(2))
			->method('findByIdsScopedToHouse')
			->willReturnOnConsecutiveCalls(
				[$scopedTask1, $scopedTask2, $scopedTask3],
				[$freshTask1, $freshTask2, $freshTask3]
			);

		$this->houseUserMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$member]);

		$this->roomTaskMapper
			->expects($this->exactly(3))
			->method('assignToUser');

		// The bulk path never runs the per-item ownership chain.
		$this->roomMapper->expects($this->never())->method('find');
		$this->floorMapper->expects($this->never())->method('find');

		$result = $this->service->bulkAssignTasks($roomTaskIds, $userId, $houseId);

		$this->assertCount(3, $result);
		$this->assertSame([1, 2, 3], array_map(fn ($rt) => $rt->getId(), $result));
	}

	/**
	 * BulkAssignTasks_ForeignOrMissingIdsSkipped_ReturnsOnlyAssigned
	 *
	 * Isolation regression (CR-SVC-09/CS-PLAN-037): ids absent from the
	 * house-scoped fetch (foreign house or missing) are skipped without
	 * aborting the batch — the write only ever targets owned ids.
	 */
	public function testBulkAssignTasks_ForeignOrMissingIdsSkipped_ReturnsOnlyAssigned(): void {
		$houseId = 1;
		$userId = 'user123';

		$ownedScoped = new RoomTask();
		$ownedScoped->setId(2);

		$freshOwned = new RoomTask();
		$freshOwned->setId(2);
		$freshOwned->setAssignedToUserId($userId);

		$member = new HouseUser();
		$member->setNextcloudUserId($userId);

		// id 1 (foreign house) and id 99 (missing) never come back from
		// the scoped fetch — only the owned id 2 does.
		$this->roomTaskMapper
			->expects($this->exactly(2))
			->method('findByIdsScopedToHouse')
			->willReturnOnConsecutiveCalls([$ownedScoped], [$freshOwned]);

		$this->houseUserMapper
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$member]);

		$this->roomTaskMapper
			->expects($this->once())
			->method('assignToUser')
			->with(2, $userId);

		$result = $this->service->bulkAssignTasks([1, 2, 99], $userId, $houseId);

		$this->assertCount(1, $result);
		$this->assertSame(2, $result[0]->getId());
	}

	/**
	 * BulkAssignTasks_NonMemberTarget_ThrowsUpFront
	 *
	 * The target user is a request-level parameter: a non-member fails
	 * the whole call (clean InvalidArgumentException) before any write
	 * instead of silently skipping every item.
	 */
	public function testBulkAssignTasks_NonMemberTarget_ThrowsUpFront(): void {
		$houseId = 1;
		$userId = 'stranger';

		$member = new HouseUser();
		$member->setNextcloudUserId('someone-else');

		$this->houseUserMapper
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$member]);

		$this->roomTaskMapper->expects($this->never())->method('findByIdsScopedToHouse');
		$this->roomTaskMapper->expects($this->never())->method('assignToUser');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('User is not a member of this house: %1$s');

		$this->service->bulkAssignTasks([1, 2], $userId, $houseId);
	}

	/**
	 * BulkAssignTasks_WriteFailureSkipped_DoesNotAbortBatch
	 *
	 * A failing assignToUser write is isolated per item: the batch
	 * continues and the failed id is absent from the payload.
	 */
	public function testBulkAssignTasks_WriteFailureSkipped_DoesNotAbortBatch(): void {
		$houseId = 1;
		$userId = 'user123';

		$scoped1 = new RoomTask();
		$scoped1->setId(1);

		$scoped2 = new RoomTask();
		$scoped2->setId(2);

		$fresh2 = new RoomTask();
		$fresh2->setId(2);
		$fresh2->setAssignedToUserId($userId);

		$member = new HouseUser();
		$member->setNextcloudUserId($userId);

		$this->roomTaskMapper
			->expects($this->exactly(2))
			->method('findByIdsScopedToHouse')
			->willReturnOnConsecutiveCalls([$scoped1, $scoped2], [$fresh2]);

		$this->houseUserMapper
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$member]);

		$this->roomTaskMapper
			->method('assignToUser')
			->willReturnCallback(function (int $roomTaskId) {
				if ($roomTaskId === 1) {
					throw new \RuntimeException('write failed');
				}
			});

		$result = $this->service->bulkAssignTasks([1, 2], $userId, $houseId);

		$this->assertCount(1, $result);
		$this->assertSame(2, $result[0]->getId());
	}

	/**
	 * BulkAssignTasks_EmptyInput_ReturnsEmptyWithoutQueries
	 */
	public function testBulkAssignTasks_EmptyInput_ReturnsEmptyWithoutQueries(): void {
		$houseId = 1;
		$userId = 'user123';

		$member = new HouseUser();
		$member->setNextcloudUserId($userId);

		$this->houseUserMapper
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$member]);

		// The mapper's empty-input early return means no query runs;
		// nothing is ever assigned.
		$this->roomTaskMapper
			->method('findByIdsScopedToHouse')
			->with([], $houseId)
			->willReturn([]);
		$this->roomTaskMapper->expects($this->never())->method('assignToUser');

		$this->assertSame([], $this->service->bulkAssignTasks([], $userId, $houseId));
	}

	/**
	 * SkipTask_NullNextDue_ThrowsInsteadOfCrashing
	 *
	 * Regression: skipTask() dereferenced getNextDue()->modify('+1 day')
	 * with no null check, causing a TypeError when a room task had no due
	 * date. It must throw a clean InvalidArgumentException instead.
	 */
	public function testSkipTask_NullNextDue_ThrowsInsteadOfCrashing(): void {
		$roomTaskId = 1;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setIsActive(true);

		$this->roomTaskMapper
			->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->roomTaskMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);

		$this->service->skipTask($roomTaskId);
	}

	/**
	 * SetAssignees_ValidMembers_ReplacesAssignees
	 */
	public function testSetAssignees_ValidMembers_ReplacesAssignees(): void {
		$roomTaskId = 1;
		$houseId = 1;
		$assignedBy = 'assigner';

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);

		$member1 = new HouseUser();
		$member1->setNextcloudUserId('user-a');
		$member2 = new HouseUser();
		$member2->setNextcloudUserId('user-b');

		$assignee = new TaskAssignee();
		$assignee->setRoomTaskId($roomTaskId);
		$assignee->setUserId('user-a');

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->houseUserMapper->method('findByHouseId')->with($houseId)->willReturn([$member1, $member2]);
		$this->taskAssigneeMapper->expects($this->once())->method('replaceAssignees')->with($roomTaskId, ['user-a', 'user-b'], $assignedBy);
		$this->taskAssigneeMapper->method('findByRoomTask')->with($roomTaskId)->willReturn([$assignee]);

		$result = $this->service->setAssignees($roomTaskId, ['user-a', 'user-b'], $houseId, $assignedBy);

		$this->assertCount(1, $result);
		$this->assertSame('user-a', $result[0]->getUserId());
	}

	/**
	 * SetAssignees_NonMember_Throws
	 *
	 * Client-supplied assignee lists are never trusted: a user who is not a
	 * house member must be rejected before any persistence.
	 */
	public function testSetAssignees_NonMember_Throws(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);

		$member = new HouseUser();
		$member->setNextcloudUserId('user-a');

		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$member]);
		$this->taskAssigneeMapper->expects($this->never())->method('replaceAssignees');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setAssignees(1, ['user-a', 'intruder'], 1, 'assigner');
	}

	/**
	 * AddAssignee_AlreadyAssigned_Throws
	 */
	public function testAddAssignee_AlreadyAssigned_Throws(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);

		$member = new HouseUser();
		$member->setNextcloudUserId('user-a');

		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$member]);
		$this->taskAssigneeMapper->method('isAssignee')->willReturn(true);
		$this->taskAssigneeMapper->expects($this->never())->method('addAssignee');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->addAssignee(1, 'user-a', 1, 'assigner');
	}

	/**
	 * RemoveAssignee_DelegatesToMapper
	 */
	public function testRemoveAssignee_DelegatesToMapper(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);

		$member = new HouseUser();
		$member->setNextcloudUserId('user-a');

		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$member]);
		$this->taskAssigneeMapper->expects($this->once())->method('removeAssignee')->with(1, 'user-a');

		$this->service->removeAssignee(1, 'user-a', 1);

		$this->assertTrue(true);
	}

	/**
	 * RemoveAssignee_NonMember_Throws
	 *
	 * CR-SVC-22 regression: like addAssignee, removal validates that the
	 * target user belongs to the house before touching persistence.
	 */
	public function testRemoveAssignee_NonMember_Throws(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);

		$member = new HouseUser();
		$member->setNextcloudUserId('user-a');

		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$member]);
		$this->taskAssigneeMapper->expects($this->never())->method('removeAssignee');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->removeAssignee(1, 'intruder', 1);
	}

	/**
	 * GetAssigneeUserIds_MergesLegacyAssignee
	 *
	 * The effective assignee set is the join-table rows plus the legacy single
	 * assignee column, deduplicated.
	 */
	public function testGetAssigneeUserIds_MergesLegacyAssignee(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setAssignedToUserId('legacy-user');

		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn(['join-user']);

		$result = $this->service->getAssigneeUserIds(1);

		$this->assertEqualsCanonicalizing(['join-user', 'legacy-user'], $result);
	}
}
