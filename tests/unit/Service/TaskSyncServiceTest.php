<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\TaskSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaskSyncService
 */
class TaskSyncServiceTest extends TestCase
{
    private TaskSyncService $service;
    private RoomTaskMapper $roomTaskMapper;
    private CompletedTaskMapper $completedTaskMapper;
    private TaskMapper $taskMapper;
    private RoomMapper $roomMapper;
    private FloorMapper $floorMapper;
    private HouseUserMapper $houseUserMapper;
    private RoomService $roomService;

    protected function setUp(): void
    {
        $this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
        $this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
        $this->taskMapper = $this->createMock(TaskMapper::class);
        $this->roomMapper = $this->createMock(RoomMapper::class);
        $this->floorMapper = $this->createMock(FloorMapper::class);
        $this->houseUserMapper = $this->createMock(HouseUserMapper::class);
        $this->roomService = $this->createMock(RoomService::class);

        $this->service = new TaskSyncService(
            $this->roomTaskMapper,
            $this->completedTaskMapper,
            $this->taskMapper,
            $this->roomMapper,
            $this->floorMapper,
            $this->houseUserMapper,
            $this->roomService
        );
    }

    /**
     * CompleteTask_DelegatesToCanonicalCompletionAndSurfacesRewards
     *
     * CR-SVC-07: completeTask routes through RoomService::completeRoomTask so
     * rewards, locking, the daily duplicate guard, the cleanliness update, and
     * recurrence all apply uniformly — no reward-less parallel path. The
     * rewards from the canonical path flow through to the sync result.
     */
    public function testCompleteTask_DelegatesToCanonicalCompletionAndSurfacesRewards(): void
    {
        $roomTaskId = 1;
        $nextcloudUserId = 'testuser';

        $roomTask = new RoomTask();
        $roomTask->setId($roomTaskId);
        $roomTask->setLastCompleted(new \DateTime('2026-08-09T10:00:00+00:00'));
        $roomTask->setNextDue(new \DateTime('2026-08-16'));

        $task = new Task();
        $task->setId(1);
        $task->setName('Test Task');

        $rewards = ['coins' => 30, 'contributionScore' => 3, 'currentStreak' => 1];

        $this->roomService->expects($this->once())
            ->method('completeRoomTask')
            ->with($roomTaskId, $nextcloudUserId, null)
            ->willReturn([
                'roomTask' => $roomTask,
                'room' => new Room(),
                'task' => $task,
                'rewards' => $rewards,
            ]);

        // The divergent path (direct mapper writes + sparksEarned=0) must not run.
        $this->roomTaskMapper->expects($this->never())->method('update');
        $this->completedTaskMapper->expects($this->never())->method('insert');

        $result = $this->service->completeTask($roomTaskId, $nextcloudUserId);

        $this->assertEquals($roomTaskId, $result['roomTaskId']);
        $this->assertEquals('Test Task', $result['taskName']);
        $this->assertFalse($result['synced']);
        $this->assertSame($rewards, $result['rewards']);
    }

    /**
     * CompleteTask_WithNextcloudTaskId_LinksAfterCanonicalCompletion
     *
     * The Nextcloud task id is linked after the canonical completion so the
     * later sync can find the room task. CR-SVC-46: the id is also threaded
     * into the canonical completion so the completion row itself carries it
     * (findPendingSyncs filters on completed_tasks.nextcloud_task_id).
     */
    public function testCompleteTask_WithNextcloudTaskId_LinksAfterCanonicalCompletion(): void
    {
        $roomTask = new RoomTask();
        $roomTask->setId(1);
        $roomTask->setNextDue(new \DateTime('2026-08-16'));

        $task = new Task();
        $task->setName('Test Task');

        $this->roomService->expects($this->once())
            ->method('completeRoomTask')
            ->with(1, 'testuser', 4242)
            ->willReturn([
                'roomTask' => $roomTask,
                'room' => new Room(),
                'task' => $task,
                'rewards' => [],
            ]);

        $linked = new RoomTask();
        $linked->setId(1);
        $this->roomTaskMapper->method('find')->willReturn($linked);
        $this->roomTaskMapper->expects($this->once())->method('update')
            ->with($this->callback(function ($t) {
                return $t->getNextcloudTaskId() === 4242;
            }));

        $result = $this->service->completeTask(1, 'testuser', 4242);

        $this->assertSame(4242, $result['nextcloudTaskId']);
    }

    /**
     * SyncCompletionFromNextcloud_ValidSync_ReturnsSyncData
     */
    public function testSyncCompletionFromNextcloud_ValidSync_ReturnsSyncData(): void
    {
        $nextcloudTaskId = 123;
        $completedAt = '2025-03-15T10:00:00+00:00';
        $roomTaskId = 1;

        $roomTask = new RoomTask();
        $roomTask->setId($roomTaskId);
        $roomTask->setTaskId(1);
        $roomTask->setIsActive(true);

        $task = new Task();
        $task->setId(1);
        $task->setFrequencyType('daily');
        $task->setFrequencyValue(1);

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('find')
            ->with($roomTaskId)
            ->willReturn($roomTask);

        // CR-SVC-07: recurrence now uses the canonical RoomTask::markCompleted,
        // which needs the task's frequency settings.
        $this->taskMapper
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($task);

        $this->completedTaskMapper
            ->expects($this->once())
            ->method('findLatestByRoomTask')
            ->with($roomTaskId)
            ->willReturn(null);

        $this->completedTaskMapper
            ->expects($this->once())
            ->method('insert');

        $result = $this->service->syncCompletionFromNextcloud($nextcloudTaskId, $completedAt, $roomTaskId);

        $this->assertNotNull($result);
        $this->assertEquals($roomTaskId, $result['roomTaskId']);
    }

    /**
     * SyncCompletionFromNextcloud_ConflictLocalWins_ReturnsNull
     */
    public function testSyncCompletionFromNextcloud_ConflictLocalWins_ReturnsNull(): void
    {
        $nextcloudTaskId = 123;
        $completedAt = '2025-03-15T10:00:00+00:00';
        $roomTaskId = 1;

        $roomTask = new RoomTask();
        $roomTask->setId($roomTaskId);

        $localCompletion = new CompletedTask();
        $localCompletion->setCompletedAt(new \DateTime('2025-03-15T11:00:00+00:00')); // Later than remote

        $this->completedTaskMapper
            ->expects($this->once())
            ->method('findLatestByRoomTask')
            ->with($roomTaskId)
            ->willReturn($localCompletion);

        $result = $this->service->syncCompletionFromNextcloud($nextcloudTaskId, $completedAt, $roomTaskId);

        $this->assertNull($result);
    }

    /**
     * MapRemindersToNextcloud_DueReminder_ReturnsAbsoluteType
     */
    public function testMapRemindersToNextcloud_DueReminder_ReturnsAbsoluteType(): void
    {
        $roomTask = new RoomTask();
        $roomTask->setNextDue(new \DateTime('2025-03-20'));
        $roomTask->setTaskId(1);

        $task = new Task();
        $task->setId(1);
        $task->setReminderType('due');

        $this->taskMapper
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($task);

        $result = $this->service->mapRemindersToNextcloud($roomTask);

        $this->assertEquals('absolute', $result['type']);
    }

    /**
     * MapRemindersToNextcloud_BeforeReminder_ReturnsRelativeType
     */
    public function testMapRemindersToNextcloud_BeforeReminder_ReturnsRelativeType(): void
    {
        $roomTask = new RoomTask();
        $roomTask->setNextDue(new \DateTime('2025-03-20'));
        $roomTask->setTaskId(1);

        $task = new Task();
        $task->setId(1);
        $task->setReminderType('before');
        $task->setReminderMinutesBefore(120);

        $this->taskMapper
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($task);

        $result = $this->service->mapRemindersToNextcloud($roomTask);

        $this->assertEquals('relative', $result['type']);
        $this->assertEquals(120, $result['value']);
    }

    /**
     * GetPendingSyncs_CallsMapperWithLimit
     */
    public function testGetPendingSyncs_CallsMapperWithLimit(): void
    {
        $limit = 25;

        $pending = [
            new CompletedTask(),
            new CompletedTask(),
        ];

        $this->completedTaskMapper
            ->expects($this->once())
            ->method('findPendingSyncs')
            ->with($limit)
            ->willReturn($pending);

        $result = $this->service->getPendingSyncs($limit);

        $this->assertCount(2, $result);
    }

    /**
     * CompleteTaskForHouse_RoomTaskOutsideHouse_ThrowsInvalidArgumentException
     */
    public function testCompleteTaskForHouse_RoomTaskOutsideHouse_ThrowsInvalidArgumentException(): void
    {
        $roomTaskId = 10;
        $houseId = 1;

        $roomTask = new RoomTask();
        $roomTask->setId($roomTaskId);
        $roomTask->setRoomId(101);

        $room = new Room();
        $room->setId(101);
        $room->setFloorId(201);

        // House is resolved via the floor chain (Room has no house_id).
        $floor = new Floor();
        $floor->setId(201);
        $floor->setHouseId(2); // a different house

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('find')
            ->with($roomTaskId)
            ->willReturn($roomTask);

        $this->roomMapper
            ->expects($this->once())
            ->method('find')
            ->with(101)
            ->willReturn($room);

        $this->floorMapper
            ->expects($this->once())
            ->method('find')
            ->with(201)
            ->willReturn($floor);

        $this->roomService->expects($this->never())->method('completeRoomTask');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Room task does not belong to this house');

        $this->service->completeTaskForHouse($roomTaskId, 'testuser', $houseId);
    }

    /**
     * GetPendingSyncsForHouse_FiltersPendingResultsByHouseAndLimit
     */
    public function testGetPendingSyncsForHouse_FiltersPendingResultsByHouseAndLimit(): void
    {
        $houseId = 1;
        $limit = 2;

        $completedTaskOne = new CompletedTask();
        $completedTaskOne->setRoomTaskId(1001);

        $completedTaskTwo = new CompletedTask();
        $completedTaskTwo->setRoomTaskId(1002);

        $completedTaskThree = new CompletedTask();
        $completedTaskThree->setRoomTaskId(1003);

        $this->completedTaskMapper
            ->expects($this->once())
            ->method('findPendingSyncs')
            ->with($limit * 5)
            ->willReturn([$completedTaskOne, $completedTaskTwo, $completedTaskThree]);

        $roomTaskOne = new RoomTask();
        $roomTaskOne->setRoomId(201);
        $roomTaskTwo = new RoomTask();
        $roomTaskTwo->setRoomId(202);
        $roomTaskThree = new RoomTask();
        $roomTaskThree->setRoomId(203);

        $this->roomTaskMapper
            ->expects($this->exactly(3))
            ->method('find')
            ->willReturnMap([
                [1001, $roomTaskOne],
                [1002, $roomTaskTwo],
                [1003, $roomTaskThree],
            ]);

        // Rooms carry a floorId; the house is resolved via the floor chain.
        $roomOne = new Room();
        $roomOne->setId(201);
        $roomOne->setFloorId(301);
        $roomTwo = new Room();
        $roomTwo->setId(202);
        $roomTwo->setFloorId(302);
        $roomThree = new Room();
        $roomThree->setId(203);
        $roomThree->setFloorId(303);

        $this->roomMapper
            ->expects($this->exactly(3))
            ->method('find')
            ->willReturnMap([
                [201, $roomOne],
                [202, $roomTwo],
                [203, $roomThree],
            ]);

        $floorOne = new Floor();
        $floorOne->setId(301);
        $floorOne->setHouseId(1);
        $floorTwo = new Floor();
        $floorTwo->setId(302);
        $floorTwo->setHouseId(2);
        $floorThree = new Floor();
        $floorThree->setId(303);
        $floorThree->setHouseId(1);

        $this->floorMapper
            ->expects($this->exactly(3))
            ->method('find')
            ->willReturnMap([
                [301, $floorOne],
                [302, $floorTwo],
                [303, $floorThree],
            ]);

        $result = $this->service->getPendingSyncsForHouse($houseId, $limit);

        $this->assertCount(2, $result);
        $this->assertSame(1001, $result[0]->getRoomTaskId());
        $this->assertSame(1003, $result[1]->getRoomTaskId());
    }

    /**
     * MarkAsSynced_UpdatesCompletedTask
     */
    public function testMarkAsSynced_UpdatesCompletedTask(): void
    {
        $completedTaskId = 1;

        $completedTask = new CompletedTask();
        $completedTask->setId($completedTaskId);
        $completedTask->setSyncedToNextcloud(false);

        $this->completedTaskMapper
            ->expects($this->once())
            ->method('find')
            ->with($completedTaskId)
            ->willReturn($completedTask);

        $this->completedTaskMapper
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function ($t) {
                return $t->getSyncedToNextcloud() === true;
            }));

        $this->service->markAsSynced($completedTaskId);

        // Assertions verified by mock expectations
        $this->assertTrue(true);
    }

    /**
     * LinkTask_UpdatesRoomTask
     */
    public function testLinkTask_UpdatesRoomTask(): void
    {
        $roomTaskId = 1;
        $nextcloudTaskId = 123;

        $roomTask = new RoomTask();
        $roomTask->setId($roomTaskId);

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('find')
            ->with($roomTaskId)
            ->willReturn($roomTask);

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function ($t) use ($nextcloudTaskId) {
                return $t->getNextcloudTaskId() === $nextcloudTaskId;
            }));

        $this->service->linkTask($roomTaskId, $nextcloudTaskId);

        // Assertions verified by mock expectations
        $this->assertTrue(true);
    }
}
