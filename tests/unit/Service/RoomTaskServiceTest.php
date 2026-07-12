<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssignee;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomTaskService
 */
class RoomTaskServiceTest extends TestCase
{
    private RoomTaskService $service;
    private RoomTaskMapper $roomTaskMapper;
    private TaskMapper $taskMapper;
    private RoomMapper $roomMapper;
    private FloorMapper $floorMapper;
    private RoomService $roomService;
    private TaskAssigneeMapper $taskAssigneeMapper;
    private HouseUserMapper $houseUserMapper;

    protected function setUp(): void
    {
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
            $this->houseUserMapper
        );
    }

    /**
     * GetRoomTask_ExistingId_ReturnsRoomTask
     */
    public function testGetRoomTask_ExistingId_ReturnsRoomTask(): void
    {
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
    public function testGetRoomTasks_WithActiveFilter_ReturnsActiveTasks(): void
    {
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
    public function testGetScopeTasks_ReturnsTasksForScope(): void
    {
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
    public function testGetUserTasks_ReturnsTasksForUser(): void
    {
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
    public function testGetOverdueTasks_ReturnsOverdueTasks(): void
    {
        $roomId = 1;

        $roomTask = new RoomTask();
        $roomTask->setId(1);
        $roomTask->setNextDue(new \DateTime('-1 day'));

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('findOverdue')
            ->with($roomId)
            ->willReturn([$roomTask]);

        $result = $this->service->getOverdueTasks($roomId);

        $this->assertCount(1, $result);
    }

    /**
     * GetDueTodayTasks_ReturnsDueTodayTasks
     */
    public function testGetDueTodayTasks_ReturnsDueTodayTasks(): void
    {
        $roomId = 1;

        $roomTask = new RoomTask();
        $roomTask->setId(1);
        $roomTask->setNextDue(new \DateTime());

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('findDueToday')
            ->with($roomId)
            ->willReturn([$roomTask]);

        $result = $this->service->getDueTodayTasks($roomId);

        $this->assertCount(1, $result);
    }

    /**
     * GetDueWithinDaysTasks_ReturnsTasksWithinRange
     */
    public function testGetDueWithinDaysTasks_ReturnsTasksWithinRange(): void
    {
        $days = 7;
        $roomId = 1;

        $roomTask = new RoomTask();
        $roomTask->setId(1);
        $roomTask->setNextDue(new \DateTime('+3 days'));

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('findDueWithinDays')
            ->with($days, $roomId)
            ->willReturn([$roomTask]);

        $result = $this->service->getDueWithinDaysTasks($days, $roomId);

        $this->assertCount(1, $result);
    }

    /**
     * CreateRoomTask_NewTask_CreatesRoomTask
     */
    public function testCreateRoomTask_NewTask_CreatesRoomTask(): void
    {
        $roomId = 1;
        $taskId = 1;

        $room = new Room();
        $room->setId($roomId);

        $task = new Task();
        $task->setId($taskId);

        $roomTask = new RoomTask();
        $roomTask->setId(1);

        $this->roomMapper
            ->expects($this->once())
            ->method('find')
            ->with($roomId)
            ->willReturn($room);

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

        $result = $this->service->createRoomTask($roomId, $taskId);

        $this->assertSame($roomTask, $result);
    }

    /**
     * CreateRoomTask_DuplicateTask_ThrowsException
     */
    public function testCreateRoomTask_DuplicateTask_ThrowsException(): void
    {
        $roomId = 1;
        $taskId = 1;

        $room = new Room();
        $room->setId($roomId);

        $task = new Task();
        $task->setId($taskId);

        $this->roomMapper
            ->expects($this->once())
            ->method('find')
            ->willReturn($room);

        $this->taskMapper
            ->expects($this->once())
            ->method('find')
            ->willReturn($task);

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('exists')
            ->willReturn(true);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->createRoomTask($roomId, $taskId);
    }

    /**
     * AssignTask_ExistingTask_AssignsToUser
     */
    public function testAssignTask_ExistingTask_AssignsToUser(): void
    {
        $roomTaskId = 1;
        $userId = 'user123';

        $roomTask = new RoomTask();
        $roomTask->setId($roomTaskId);
        $roomTask->setAssignedToUserId($userId);

        $this->roomTaskMapper
            ->expects($this->exactly(2))
            ->method('find')
            ->with($roomTaskId)
            ->willReturn($roomTask);

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('assignToUser')
            ->with($roomTaskId, $userId);

        $result = $this->service->assignTask($roomTaskId, $userId);

        $this->assertSame($roomTask, $result);
    }

    /**
     * UnassignTask_ExistingTask_RemovesAssignment
     */
    public function testUnassignTask_ExistingTask_RemovesAssignment(): void
    {
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
    public function testDeactivateTask_ExistingTask_Deactivates(): void
    {
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
    public function testReactivateTask_ExistingTask_Reactivates(): void
    {
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
    public function testUpdateDueDate_ExistingTask_UpdatesDueDate(): void
    {
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
    public function testCompleteTask_DelegatesToRoomService(): void
    {
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
     * ClaimOverdueTask_PersistsClaimFields
     *
     * Regression test: claimOverdueTask previously set wasClaimed/originalAssignee/
     * claimedAt in-memory but never persisted them. It must call mapper->update()
     * and set claimedByUser on the persisted entity.
     */
    public function testClaimOverdueTask_PersistsClaimFields(): void
    {
        $roomTaskId = 1;
        $originalAssignee = 'original-user';
        $claimer = 'claimer-user';

        $roomTask = new RoomTask();
        $roomTask->setId($roomTaskId);
        $roomTask->setIsActive(true);
        $roomTask->setAssignedToUserId($originalAssignee);
        $roomTask->setNextDue(new \DateTime('yesterday'));

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('find')
            ->with($roomTaskId)
            ->willReturn($roomTask);

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('assignToUser')
            ->with($roomTaskId, $claimer);

        // The persistence bug fix: update() must be called to persist claim fields
        $this->roomTaskMapper
            ->expects($this->once())
            ->method('update')
            ->with($roomTask);

        $result = $this->service->claimOverdueTask($roomTaskId, $claimer);

        $this->assertTrue($roomTask->getWasClaimed());
        $this->assertSame($claimer, $roomTask->getClaimedByUser());
        $this->assertSame($originalAssignee, $roomTask->getOriginalAssignee());
        $this->assertNotNull($roomTask->getClaimedAt());
        $this->assertSame($claimer, $result['claimedBy']);
        $this->assertSame($originalAssignee, $result['originalAssignee']);
        $this->assertArrayHasKey('bonusCoins', $result);
    }

    /**
     * ClaimOverdueTask_NonOverdueTask_ThrowsException
     */
    public function testClaimOverdueTask_NonOverdueTask_ThrowsException(): void
    {
        $roomTaskId = 1;

        $roomTask = new RoomTask();
        $roomTask->setId($roomTaskId);
        $roomTask->setIsActive(true);
        $roomTask->setNextDue(new \DateTime('+2 days'));

        $this->roomTaskMapper
            ->method('find')
            ->with($roomTaskId)
            ->willReturn($roomTask);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->claimOverdueTask($roomTaskId, 'claimer');
    }

    /**
     * DeleteRoomTask_ExistingTask_DeletesTask
     */
    public function testDeleteRoomTask_ExistingTask_DeletesTask(): void
    {
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
    public function testGetRoomTaskWithDetails_WithData_ReturnsCompleteDetails(): void
    {
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
    public function testGetRoomTaskStats_WithTasks_ReturnsStats(): void
    {
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
    public function testGetUserTaskStats_WithTasks_ReturnsStats(): void
    {
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
     */
    public function testBulkAssignTasks_MultipleTasks_AssignsAll(): void
    {
        $roomTaskIds = [1, 2, 3];
        $userId = 'user123';

        $roomTask = new RoomTask();
        $roomTask->setId(1);
        $roomTask->setAssignedToUserId($userId);

        $this->roomTaskMapper
            ->expects($this->exactly(6))
            ->method('find')
            ->willReturn($roomTask);

        $this->roomTaskMapper
            ->expects($this->exactly(3))
            ->method('assignToUser');

        $result = $this->service->bulkAssignTasks($roomTaskIds, $userId);

        $this->assertCount(3, $result);
    }

    /**
     * GetUpcomingTasksForUser_ReturnsSortedTasks
     */
    public function testGetUpcomingTasksForUser_ReturnsSortedTasks(): void
    {
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
     * SetAssignees_ValidMembers_ReplacesAssignees
     */
    public function testSetAssignees_ValidMembers_ReplacesAssignees(): void
    {
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
    public function testSetAssignees_NonMember_Throws(): void
    {
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
    public function testAddAssignee_AlreadyAssigned_Throws(): void
    {
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
    public function testRemoveAssignee_DelegatesToMapper(): void
    {
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
    public function testGetAssigneeUserIds_MergesLegacyAssignee(): void
    {
        $roomTask = new RoomTask();
        $roomTask->setId(1);
        $roomTask->setAssignedToUserId('legacy-user');

        $this->roomTaskMapper->method('find')->willReturn($roomTask);
        $this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn(['join-user']);

        $result = $this->service->getAssigneeUserIds(1);

        $this->assertEqualsCanonicalizing(['join-user', 'legacy-user'], $result);
    }
}
