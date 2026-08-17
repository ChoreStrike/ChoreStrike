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
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCP\AppFramework\Db\DoesNotExistException;
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

	protected function setUp(): void {
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->floorMapper = $this->createMock(FloorMapper::class);
		$this->roomService = $this->createMock(RoomService::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);

		$this->service = new RoomTaskService(
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->roomMapper,
			$this->floorMapper,
			$this->roomService,
			$this->taskAssigneeMapper,
			$this->houseUserMapper,
			$this->createMock(CompletedTaskMapper::class),
			$this->createMock(\OCA\ChoreStrike\Service\TemplateService::class)
		);
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
	 * GetUserTasks_ReturnsTasksForUser
	 */
	public function testGetUserTasks_ReturnsTasksForUser(): void {
		$userId = 'user123';

		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setAssignedToUserId($userId);

		$this->roomTaskMapper
			->expects($this->once())
			->method('findByUserId')
			->with($userId, true)
			->willReturn([$roomTask]);

		$result = $this->service->getUserTasks($userId);

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
		$this->expectExceptionMessage('User is not a member of this house: intruder');

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
	 * DeleteRoomTask_ExistingTask_DeletesTask
	 */
	public function testDeleteRoomTask_ExistingTask_DeletesTask(): void {
		$roomTaskId = 1;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);

		$this->roomTaskMapper
			->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->roomTaskMapper
			->expects($this->once())
			->method('delete')
			->with($roomTask);

		$this->service->deleteRoomTask($roomTaskId);

		$this->assertTrue(true);
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

		$result = $this->service->getRoomTaskStats($roomId);

		$this->assertEquals(2, $result['totalTasks']);
		$this->assertEquals(1, $result['activeTasks']);
		$this->assertEquals(1, $result['overdueTasks']);
		$this->assertEquals(50, $result['completionRate']);
	}

	/**
	 * GetUserTaskStats_WithTasks_ReturnsStats
	 */
	public function testGetUserTaskStats_WithTasks_ReturnsStats(): void {
		$userId = 'user123';

		$activeTask = new RoomTask();
		$activeTask->setId(1);
		$activeTask->setIsActive(true);

		$inactiveTask = new RoomTask();
		$inactiveTask->setId(2);
		$inactiveTask->setIsActive(false);

		$this->roomTaskMapper
			->expects($this->once())
			->method('findByUserId')
			->with($userId, false)
			->willReturn([$activeTask, $inactiveTask]);

		$result = $this->service->getUserTaskStats($userId);

		$this->assertEquals(2, $result['totalTasks']);
		$this->assertEquals(1, $result['activeTasks']);
		$this->assertEquals(50, $result['completionRate']);
	}

	/**
	 * BulkAssignTasks_MultipleTasks_AssignsAll
	 *
	 * CR-SVC-09: bulkAssignTasks threads houseId through assignTask so
	 * ownership + membership validation apply per item.
	 */
	public function testBulkAssignTasks_MultipleTasks_AssignsAll(): void {
		$houseId = 1;
		$roomTaskIds = [1, 2, 3];
		$userId = 'user123';

		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setRoomId(5);
		$roomTask->setAssignedToUserId($userId);

		$room = new Room();
		$room->setId(5);
		$room->setFloorId(7);

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId($houseId);

		$member = new HouseUser();
		$member->setNextcloudUserId($userId);

		$this->roomTaskMapper
			->method('find')
			->willReturn($roomTask);

		$this->roomMapper->method('find')->with(5)->willReturn($room);
		$this->floorMapper->method('find')->with(7)->willReturn($floor);
		$this->houseUserMapper->method('findByHouseId')->with($houseId)->willReturn([$member]);

		$this->roomTaskMapper
			->expects($this->exactly(3))
			->method('assignToUser');

		$result = $this->service->bulkAssignTasks($roomTaskIds, $userId, $houseId);

		$this->assertCount(3, $result);
	}

	/**
	 * BulkAssignTasks_FailingItemSkipped_ReturnsOnlyAssigned
	 *
	 * CR-SVC-09: an item that fails validation (e.g. belongs to another
	 * house) is skipped without aborting the batch.
	 */
	public function testBulkAssignTasks_FailingItemSkipped_ReturnsOnlyAssigned(): void {
		$houseId = 1;

		$foreignRoomTask = new RoomTask();
		$foreignRoomTask->setId(1);
		$foreignRoomTask->setRoomId(5);

		$ownedRoomTask = new RoomTask();
		$ownedRoomTask->setId(2);
		$ownedRoomTask->setRoomId(6);

		$room = new Room();
		$room->setId(6);
		$room->setFloorId(7);

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId($houseId);

		$member = new HouseUser();
		$member->setNextcloudUserId('user123');

		$this->roomTaskMapper->method('find')->willReturnMap([
			[1, $foreignRoomTask],
			[2, $ownedRoomTask],
		]);

		// Both rooms resolve via floor 7; houseId of the floor decides:
		// room 5's floor is in house 99, room 6's in the caller's house.
		$foreignRoom = new Room();
		$foreignRoom->setId(5);
		$foreignRoom->setFloorId(8);
		$foreignFloor = new Floor();
		$foreignFloor->setId(8);
		$foreignFloor->setHouseId(99);

		$this->roomMapper->method('find')->willReturnMap([
			[5, $foreignRoom],
			[6, $room],
		]);
		$this->floorMapper->method('find')->willReturnMap([
			[8, $foreignFloor],
			[7, $floor],
		]);
		$this->houseUserMapper->method('findByHouseId')->with($houseId)->willReturn([$member]);

		$this->roomTaskMapper
			->expects($this->once())
			->method('assignToUser')
			->with(2, 'user123');

		$result = $this->service->bulkAssignTasks([1, 2], 'user123', $houseId);

		$this->assertCount(1, $result);
		$this->assertSame(2, $result[0]->getId());
	}

	/**
	 * GetUpcomingTasksForUser_ReturnsSortedTasks
	 */
	public function testGetUpcomingTasksForUser_ReturnsSortedTasks(): void {
		$userId = 'user123';

		$task1 = new RoomTask();
		$task1->setId(1);
		$task1->setNextDue(new \DateTime('+1 day'));
		$task1->setIsActive(true);

		$task2 = new RoomTask();
		$task2->setId(2);
		$task2->setNextDue(new \DateTime('+3 days'));
		$task2->setIsActive(true);

		$farTask = new RoomTask();
		$farTask->setId(3);
		$farTask->setNextDue(new \DateTime('+10 days'));
		$farTask->setIsActive(true);

		$this->roomTaskMapper
			->expects($this->once())
			->method('findByUserId')
			->with($userId, true)
			->willReturn([$task2, $task1, $farTask]);

		$result = $this->service->getUpcomingTasksForUser($userId);

		$this->assertCount(2, $result); // Only tasks within 7 days
		$this->assertEquals(1, $result[0]->getId()); // Sorted by due date
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

		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->taskAssigneeMapper->expects($this->once())->method('removeAssignee')->with(1, 'user-a');

		$this->service->removeAssignee(1, 'user-a');

		$this->assertTrue(true);
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
