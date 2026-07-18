<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Service\GameLogicService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\ForbiddenException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomService
 */
class RoomServiceTest extends TestCase
{
    private RoomService $service;
    private RoomMapper $roomMapper;
    private RoomTaskMapper $roomTaskMapper;
    private TaskMapper $taskMapper;
    private HouseUserMapper $houseUserMapper;
    private FloorMapper $floorMapper;
    private GameLogicService $gameLogicService;
    private TaskAssigneeMapper $taskAssigneeMapper;
    private CompletedTaskMapper $completedTaskMapper;
    private HouseService $houseService;
    private IDBConnection $db;

    protected function setUp(): void
    {
        $this->roomMapper = $this->createMock(RoomMapper::class);
        $this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
        $this->taskMapper = $this->createMock(TaskMapper::class);
        $this->houseUserMapper = $this->createMock(HouseUserMapper::class);
        $this->floorMapper = $this->createMock(FloorMapper::class);
        $this->gameLogicService = $this->createMock(GameLogicService::class);
        $this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
        $this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
        $this->houseService = $this->createMock(HouseService::class);
        $this->db = $this->createMock(IDBConnection::class);

        $this->service = new RoomService(
            $this->roomMapper,
            $this->roomTaskMapper,
            $this->taskMapper,
            $this->houseUserMapper,
            $this->floorMapper,
            $this->gameLogicService,
            $this->taskAssigneeMapper,
            $this->completedTaskMapper,
            $this->houseService,
            $this->db
        );
    }

    /**
     * GetRooms_ExistingFloor_ReturnsRooms
     */
    public function testGetRooms_ExistingFloor_ReturnsRooms(): void
    {
        $floorId = 1;

        $room1 = new Room();
        $room1->setId(1);
        $room1->setName('Kitchen');

        $room2 = new Room();
        $room2->setId(2);
        $room2->setName('Living Room');

        $this->roomMapper
            ->expects($this->once())
            ->method('findByFloorId')
            ->with($floorId)
            ->willReturn([$room1, $room2]);

        $result = $this->service->getRooms($floorId);

        $this->assertCount(2, $result);
        $this->assertEquals('Kitchen', $result[0]->getName());
    }

    /**
     * GetRoom_ExistingId_ReturnsRoom
     */
    public function testGetRoom_ExistingId_ReturnsRoom(): void
    {
        $roomId = 1;

        $room = new Room();
        $room->setId($roomId);
        $room->setName('Test Room');

        $this->roomMapper
            ->expects($this->once())
            ->method('find')
            ->with($roomId)
            ->willReturn($room);

        $result = $this->service->getRoom($roomId);

        $this->assertSame($room, $result);
    }

    /**
     * CreateRoom_ValidData_CreatesRoom
     */
    public function testCreateRoom_ValidData_CreatesRoom(): void
    {
        $floorId = 1;
        $name = 'New Room';
        $icon = 'Bath';

        $room = new Room();
        $room->setId(1);
        $room->setName($name);
        $room->setIcon($icon);

        $this->roomMapper
            ->expects($this->once())
            ->method('create')
            ->with($floorId, $name, $icon)
            ->willReturn($room);

        $result = $this->service->createRoom($floorId, $name, $icon);

        $this->assertSame($room, $result);
        $this->assertSame($icon, $result->getIcon());
    }

    /**
     * UpdateRoomName_ValidName_ReturnsUpdatedRoom
     */
    public function testUpdateRoomName_ValidName_ReturnsUpdatedRoom(): void
    {
        $roomId = 1;
        $newName = 'Updated Room';

        $room = new Room();
        $room->setId($roomId);
        $room->setName('Old Name');

        $updatedRoom = new Room();
        $updatedRoom->setId($roomId);
        $updatedRoom->setName($newName);

        $this->roomMapper
            ->expects($this->once())
            ->method('find')
            ->with($roomId)
            ->willReturn($room);

        $this->roomMapper
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function ($r) use ($newName) {
                return $r->getName() === $newName;
            }))
            ->willReturn($updatedRoom);

        $result = $this->service->updateRoomName($roomId, $newName);

        $this->assertEquals($newName, $result->getName());
    }

    /**
     * DeleteRoom_WithTasks_DeletesRoomAndTasks
     */
    public function testDeleteRoom_WithTasks_DeletesRoomAndTasks(): void
    {
        $roomId = 1;

        $room = new Room();
        $room->setId($roomId);

        $roomTask = new RoomTask();
        $roomTask->setId(1);

        $this->roomMapper
            ->expects($this->once())
            ->method('find')
            ->with($roomId)
            ->willReturn($room);

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('findByRoomId')
            ->with($roomId)
            ->willReturn([$roomTask]);

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('delete')
            ->with($roomTask);

        $this->roomMapper
            ->expects($this->once())
            ->method('delete')
            ->with($room);

        $this->service->deleteRoom($roomId);

        // Assertions verified by mock expectations
        $this->assertTrue(true);
    }

    /**
     * UpdateRoomCleanliness_PositiveChange_UpdatesCleanliness
     */
    public function testUpdateRoomCleanliness_PositiveChange_UpdatesCleanliness(): void
    {
        $roomId = 1;
        $cleanlinessChange = 20;

        $room = new Room();
        $room->setId($roomId);
        $room->setCleanliness(50);

        $updatedRoom = new Room();
        $updatedRoom->setId($roomId);
        $updatedRoom->setCleanliness(70);

        $this->roomMapper
            ->expects($this->once())
            ->method('find')
            ->with($roomId)
            ->willReturn($room);

        $this->roomMapper
            ->expects($this->once())
            ->method('update')
            ->willReturn($updatedRoom);

        $result = $this->service->updateRoomCleanliness($roomId, $cleanlinessChange);

        $this->assertSame($updatedRoom, $result);
    }

    /**
     * GetRoomWithTasks_WithTasks_ReturnsRoomAndTasks
     */
    public function testGetRoomWithTasks_WithTasks_ReturnsRoomAndTasks(): void
    {
        $roomId = 1;

        $room = new Room();
        $room->setId($roomId);
        $room->setCleanliness(80);
        $room->setMonsterCount(1);

        $task = new Task();
        $task->setId(1);
        $task->setName('Test Task');

        $roomTask = new RoomTask();
        $roomTask->setId(1);
        $roomTask->setTaskId(1);
        $roomTask->setNextDue(new \DateTime('+1 day'));
        $roomTask->setIsActive(true);

        $this->roomMapper
            ->expects($this->once())
            ->method('find')
            ->with($roomId)
            ->willReturn($room);

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('findByRoomId')
            ->with($roomId)
            ->willReturn([$roomTask]);

        $this->taskMapper
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($task);

        $result = $this->service->getRoomWithTasks($roomId);

        $this->assertSame($room, $result['room']);
        $this->assertCount(1, $result['tasks']);
        $this->assertEquals(80, $result['stats']['cleanliness']);
    }

    /**
     * GetRoomsNeedingCleaning_ReturnsFilteredRooms
     */
    public function testGetRoomsNeedingCleaning_ReturnsFilteredRooms(): void
    {
        $floorId = 1;

        $room1 = new Room();
        $room1->setId(1);
        $room1->setCleanliness(30);

        $room2 = new Room();
        $room2->setId(2);
        $room2->setCleanliness(80);

        $this->roomMapper
            ->expects($this->once())
            ->method('findNeedingCleaning')
            ->with($floorId)
            ->willReturn([$room1]);

        $result = $this->service->getRoomsNeedingCleaning($floorId);

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]->getId());
    }

    /**
     * AddTaskToRoom_NewTask_CreatesRoomTask
     */
    public function testAddTaskToRoom_NewTask_CreatesRoomTask(): void
    {
        $roomId = 1;
        $taskId = 1;

        $task = new Task();
        $task->setId($taskId);

        $roomTask = new RoomTask();
        $roomTask->setId(1);
        $roomTask->setRoomId($roomId);
        $roomTask->setTaskId($taskId);

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('exists')
            ->with($roomId, $taskId)
            ->willReturn(false);

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('create')
            ->willReturn($roomTask);

        $result = $this->service->addTaskToRoom($roomId, $taskId);

        $this->assertSame($roomTask, $result);
    }

    /**
     * AddTaskToRoom_DuplicateTask_ThrowsException
     */
    public function testAddTaskToRoom_DuplicateTask_ThrowsException(): void
    {
        $roomId = 1;
        $taskId = 1;

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('exists')
            ->with($roomId, $taskId)
            ->willReturn(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task 1 already exists in room 1');

        $this->service->addTaskToRoom($roomId, $taskId);
    }

    /**
     * RemoveTaskFromRoom_ExistingTask_DeletesTask
     */
    public function testRemoveTaskFromRoom_ExistingTask_DeletesTask(): void
    {
        $roomId = 1;
        $taskId = 1;

        $roomTask = new RoomTask();
        $roomTask->setId(1);
        $roomTask->setTaskId($taskId);

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('findByRoomId')
            ->with($roomId)
            ->willReturn([$roomTask]);

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('delete')
            ->with($roomTask);

        $this->service->removeTaskFromRoom($roomId, $taskId);

        // Assertions verified by mock expectations
        $this->assertTrue(true);
    }

    /**
     * RemoveTaskFromRoom_NonExistingTask_ThrowsException
     */
    public function testRemoveTaskFromRoom_NonExistingTask_ThrowsException(): void
    {
        $roomId = 1;
        $taskId = 999;

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('findByRoomId')
            ->with($roomId)
            ->willReturn([]);

        $this->expectException(DoesNotExistException::class);

        $this->service->removeTaskFromRoom($roomId, $taskId);
    }

    /**
     * GetRoomTask_WithData_ReturnsRoomTaskDetails
     */
    public function testGetRoomTask_WithData_ReturnsRoomTaskDetails(): void
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

        $result = $this->service->getRoomTask($roomTaskId);

        $this->assertSame($roomTask, $result['roomTask']);
        $this->assertSame($task, $result['task']);
        $this->assertSame($room, $result['room']);
    }

    /**
     * CompleteRoomTask_ValidTask_ReturnsCompletionData
     */
    public function testCompleteRoomTask_ValidTask_ReturnsCompletionData(): void
    {
        $roomTaskId = 1;
        $userId = 'user123';

        $task = new Task();
        $task->setId(1);
        $task->setName('Test Task');
        $task->setEnergyCost(3);
        $task->setPriority(2);
        $task->setFrequencyType('weekly');
        $task->setFrequencyValue(1);
        $task->setRecurrenceMode('recurring');

        $room = new Room();
        $room->setId(1);
        $room->setFloorId(1);
        $room->setCleanliness(50);

        $roomTask = new RoomTask();
        $roomTask->setId($roomTaskId);
        $roomTask->setTaskId(1);
        $roomTask->setRoomId(1);
        $roomTask->setIsActive(true);

        $floor = new Floor();
        $floor->setId(1);
        $floor->setHouseId(1);

        $houseUser = new HouseUser();
        $houseUser->setId(1);

        $updatedRoom = new Room();
        $updatedRoom->setId(1);
        $updatedRoom->setCleanliness(80);

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
            ->expects($this->exactly(2))
            ->method('find')
            ->with(1)
            ->willReturn($room);

        $this->floorMapper
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($floor);

        $this->houseUserMapper
            ->expects($this->once())
            ->method('findByNextcloudUserId')
            ->with(1, $userId)
            ->willReturn($houseUser);

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('update')
            ->with($roomTask);

        $this->roomMapper
            ->expects($this->once())
            ->method('update')
            ->willReturn($updatedRoom);

        $this->gameLogicService
            ->expects($this->once())
            ->method('handleTaskCompletion')
            ->willReturn([
                'contributionScore' => 10,
                'coins' => 50,
                'isCombo' => false,
                'currentStreak' => 1
            ]);

        $this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
        $this->completedTaskMapper->method('countByRoomTaskAndUserOnDate')->willReturn(0);

        $result = $this->service->completeRoomTask($roomTaskId, $userId);

        $this->assertSame($roomTask, $result['roomTask']);
        $this->assertSame($updatedRoom, $result['room']);
        $this->assertEquals(30, $result['rewards']['cleanlinessChange']);
    }

    /**
     * CompleteRoomTask_OverdueTakeover_SetsClaimedBy
     *
     * When a member completes an overdue task assigned to someone else, the
     * service must record the takeover (claimedByUser / originalAssignee /
     * wasClaimed) so the original assignee's streak stays unaffected.
     */
    public function testCompleteRoomTask_OverdueTakeover_SetsClaimedBy(): void
    {
        $roomTaskId = 1;
        $originalAssignee = 'original-user';
        $claimer = 'claimer-user';

        $task = new Task();
        $task->setId(1);
        $task->setName('Test Task');
        $task->setEnergyCost(3);
        $task->setFrequencyType('weekly');
        $task->setFrequencyValue(1);
        $task->setRecurrenceMode('recurring');

        $room = new Room();
        $room->setId(1);
        $room->setFloorId(1);
        $room->setCleanliness(50);

        $roomTask = new RoomTask();
        $roomTask->setId($roomTaskId);
        $roomTask->setTaskId(1);
        $roomTask->setRoomId(1);
        $roomTask->setIsActive(true);
        $roomTask->setAssignedToUserId($originalAssignee);
        $roomTask->setNextDue(new \DateTime('yesterday')); // overdue

        $floor = new Floor();
        $floor->setId(1);
        $floor->setHouseId(1);

        $houseUser = new HouseUser();
        $houseUser->setId(2);

        $updatedRoom = new Room();
        $updatedRoom->setId(1);
        $updatedRoom->setCleanliness(80);

        $this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
        $this->taskMapper->method('find')->with(1)->willReturn($task);
        $this->roomMapper->method('find')->with(1)->willReturn($room);
        $this->floorMapper->method('find')->with(1)->willReturn($floor);
        $this->houseUserMapper->method('findByNextcloudUserId')->with(1, $claimer)->willReturn($houseUser);
        $this->roomTaskMapper->expects($this->once())->method('update')->with($roomTask);
        $this->roomMapper->method('update')->willReturn($updatedRoom);
        $this->gameLogicService->method('handleTaskCompletion')->willReturn([
            'contributionScore' => 10,
            'coins' => 50,
            'isCombo' => false,
            'currentStreak' => 1,
        ]);

        $this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
        $this->completedTaskMapper->method('countByRoomTaskAndUserOnDate')->willReturn(0);

        $this->service->completeRoomTask($roomTaskId, $claimer);

        // Takeover recorded
        $this->assertTrue($roomTask->getWasClaimed());
        $this->assertSame($claimer, $roomTask->getClaimedByUser());
        $this->assertSame($originalAssignee, $roomTask->getOriginalAssignee());
        $this->assertNotNull($roomTask->getClaimedAt());
    }

    /**
     * CompleteRoomTask_CoAssigneeOverdue_DoesNotMarkTakeover
     *
     * A co-assignee (present only in the join table) completing an overdue task
     * that also has a legacy assignee must NOT be recorded as a takeover — they
     * are themselves an assignee of the shared task.
     */
    public function testCompleteRoomTask_CoAssigneeOverdue_DoesNotMarkTakeover(): void
    {
        $roomTaskId = 1;
        $legacyAssignee = 'legacy-user';
        $coAssignee = 'co-user';

        $task = new Task();
        $task->setId(1);
        $task->setEnergyCost(3);
        $task->setFrequencyType('weekly');
        $task->setFrequencyValue(1);
        $task->setRecurrenceMode('recurring');

        $room = new Room();
        $room->setId(1);
        $room->setFloorId(1);
        $room->setCleanliness(50);

        $roomTask = new RoomTask();
        $roomTask->setId($roomTaskId);
        $roomTask->setTaskId(1);
        $roomTask->setRoomId(1);
        $roomTask->setIsActive(true);
        $roomTask->setAssignedToUserId($legacyAssignee);
        $roomTask->setNextDue(new \DateTime('yesterday')); // overdue

        $floor = new Floor();
        $floor->setId(1);
        $floor->setHouseId(1);

        $houseUser = new HouseUser();
        $houseUser->setId(2);

        $updatedRoom = new Room();
        $updatedRoom->setId(1);
        $updatedRoom->setCleanliness(80);

        $this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
        $this->taskMapper->method('find')->with(1)->willReturn($task);
        $this->roomMapper->method('find')->with(1)->willReturn($room);
        $this->floorMapper->method('find')->with(1)->willReturn($floor);
        $this->houseUserMapper->method('findByNextcloudUserId')->with(1, $coAssignee)->willReturn($houseUser);
        // Co-assignee exists in the join table only
        $this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([$coAssignee]);
        $this->completedTaskMapper->method('countByRoomTaskAndUserOnDate')->willReturn(0);
        $this->roomTaskMapper->expects($this->once())->method('update')->with($roomTask);
        $this->roomMapper->method('update')->willReturn($updatedRoom);
        $this->gameLogicService->method('handleTaskCompletion')->willReturn([
            'contributionScore' => 10,
            'coins' => 50,
            'isCombo' => false,
            'currentStreak' => 1,
        ]);

        $this->service->completeRoomTask($roomTaskId, $coAssignee);

        $this->assertFalse($roomTask->getWasClaimed());
        $this->assertNull($roomTask->getClaimedByUser());
    }

    /**
     * CompleteRoomTask_AssignedToCompleter_DoesNotMarkTakeover
     */
    public function testCompleteRoomTask_AssignedToCompleter_DoesNotMarkTakeover(): void
    {
        $roomTaskId = 1;
        $userId = 'user123';

        $task = new Task();
        $task->setId(1);
        $task->setEnergyCost(3);
        $task->setFrequencyType('weekly');
        $task->setFrequencyValue(1);
        $task->setRecurrenceMode('recurring');

        $room = new Room();
        $room->setId(1);
        $room->setFloorId(1);
        $room->setCleanliness(50);

        // Overdue but assigned to the completer themselves -> no takeover
        $roomTask = new RoomTask();
        $roomTask->setId($roomTaskId);
        $roomTask->setTaskId(1);
        $roomTask->setRoomId(1);
        $roomTask->setIsActive(true);
        $roomTask->setAssignedToUserId($userId);
        $roomTask->setNextDue(new \DateTime('yesterday'));

        $floor = new Floor();
        $floor->setId(1);
        $floor->setHouseId(1);

        $houseUser = new HouseUser();
        $houseUser->setId(1);

        $updatedRoom = new Room();
        $updatedRoom->setId(1);
        $updatedRoom->setCleanliness(80);

        $this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
        $this->taskMapper->method('find')->with(1)->willReturn($task);
        $this->roomMapper->method('find')->with(1)->willReturn($room);
        $this->floorMapper->method('find')->with(1)->willReturn($floor);
        $this->houseUserMapper->method('findByNextcloudUserId')->with(1, $userId)->willReturn($houseUser);
        $this->roomTaskMapper->expects($this->once())->method('update')->with($roomTask);
        $this->roomMapper->method('update')->willReturn($updatedRoom);
        $this->gameLogicService->method('handleTaskCompletion')->willReturn([
            'contributionScore' => 10,
            'coins' => 50,
            'isCombo' => false,
            'currentStreak' => 1,
        ]);

        $this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
        $this->completedTaskMapper->method('countByRoomTaskAndUserOnDate')->willReturn(0);

        $this->service->completeRoomTask($roomTaskId, $userId);

        $this->assertFalse($roomTask->getWasClaimed());
        $this->assertNull($roomTask->getClaimedByUser());
    }

    /**
     * CompleteRoomTask_PrivateTask_NonAssigneeRejected
     *
     * A private task may only be completed by an assigned user. A non-assignee
     * must be rejected with a ForbiddenException (mapped to 403 by the controller).
     */
    public function testCompleteRoomTask_PrivateTask_NonAssigneeRejected(): void
    {
        $roomTaskId = 1;
        $completer = 'completer-user';

        $task = new Task();
        $task->setId(1);
        $task->setIsPrivate(true);

        $room = new Room();
        $room->setId(1);
        $room->setFloorId(1);

        $roomTask = new RoomTask();
        $roomTask->setId($roomTaskId);
        $roomTask->setTaskId(1);
        $roomTask->setRoomId(1);
        $roomTask->setIsActive(true);

        $floor = new Floor();
        $floor->setId(1);
        $floor->setHouseId(1);

        $houseUser = new HouseUser();
        $houseUser->setId(2);

        $this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
        $this->taskMapper->method('find')->with(1)->willReturn($task);
        $this->roomMapper->method('find')->with(1)->willReturn($room);
        $this->floorMapper->method('find')->with(1)->willReturn($floor);
        $this->houseUserMapper->method('findByNextcloudUserId')->with(1, $completer)->willReturn($houseUser);
        // Completer is NOT among the assignees
        $this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn(['someone-else']);

        $this->expectException(ForbiddenException::class);
        $this->service->completeRoomTask($roomTaskId, $completer);
    }

    /**
     * CompleteRoomTask_PrivateTask_AssigneeAllowed
     *
     * An assigned user may complete a private task (no exception, completion proceeds).
     */
    public function testCompleteRoomTask_PrivateTask_AssigneeAllowed(): void
    {
        $roomTaskId = 1;
        $completer = 'assignee-user';

        $task = new Task();
        $task->setId(1);
        $task->setEnergyCost(3);
        $task->setFrequencyType('weekly');
        $task->setFrequencyValue(1);
        $task->setRecurrenceMode('recurring');
        $task->setIsPrivate(true);

        $room = new Room();
        $room->setId(1);
        $room->setFloorId(1);
        $room->setCleanliness(50);

        $roomTask = new RoomTask();
        $roomTask->setId($roomTaskId);
        $roomTask->setTaskId(1);
        $roomTask->setRoomId(1);
        $roomTask->setIsActive(true);

        $floor = new Floor();
        $floor->setId(1);
        $floor->setHouseId(1);

        $houseUser = new HouseUser();
        $houseUser->setId(2);

        $updatedRoom = new Room();
        $updatedRoom->setId(1);
        $updatedRoom->setCleanliness(80);

        $this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
        $this->taskMapper->method('find')->with(1)->willReturn($task);
        $this->roomMapper->method('find')->with(1)->willReturn($room);
        $this->floorMapper->method('find')->with(1)->willReturn($floor);
        $this->houseUserMapper->method('findByNextcloudUserId')->with(1, $completer)->willReturn($houseUser);
        // Completer IS an assignee
        $this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([$completer]);
        $this->roomTaskMapper->expects($this->once())->method('update')->with($roomTask);
        $this->roomMapper->method('update')->willReturn($updatedRoom);
        $this->gameLogicService->method('handleTaskCompletion')->willReturn([
            'contributionScore' => 10,
            'coins' => 50,
            'isCombo' => false,
            'currentStreak' => 1,
        ]);

        $result = $this->service->completeRoomTask($roomTaskId, $completer);

        $this->assertSame($roomTask, $result['roomTask']);
    }

    /**
     * CompleteRoomTask_AlreadyCompletedToday_Rejected
     *
     * The duplicate-completion guard prevents the same user from earning coins
     * twice for the same room task on the same calendar day.
     */
    public function testCompleteRoomTask_AlreadyCompletedToday_Rejected(): void
    {
        $roomTaskId = 1;
        $completer = 'user123';

        $task = new Task();
        $task->setId(1);

        $room = new Room();
        $room->setId(1);
        $room->setFloorId(1);

        $roomTask = new RoomTask();
        $roomTask->setId($roomTaskId);
        $roomTask->setTaskId(1);
        $roomTask->setRoomId(1);
        $roomTask->setIsActive(true);

        $floor = new Floor();
        $floor->setId(1);
        $floor->setHouseId(1);

        $houseUser = new HouseUser();
        $houseUser->setId(2);

        $this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
        $this->taskMapper->method('find')->with(1)->willReturn($task);
        $this->roomMapper->method('find')->with(1)->willReturn($room);
        $this->floorMapper->method('find')->with(1)->willReturn($floor);
        $this->houseUserMapper->method('findByNextcloudUserId')->with(1, $completer)->willReturn($houseUser);
        // Already completed today
        $this->completedTaskMapper->method('countByRoomTaskAndUserOnDate')->willReturn(1);

        $this->expectException(ForbiddenException::class);
        $this->service->completeRoomTask($roomTaskId, $completer);
    }
}
