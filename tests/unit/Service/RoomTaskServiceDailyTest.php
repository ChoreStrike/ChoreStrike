<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Service\RoomService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RoomTaskServiceDailyTest extends TestCase
{
    private RoomTaskService $service;
    private RoomTaskMapper|MockObject $roomTaskMapper;
    private TaskMapper|MockObject $taskMapper;
    private RoomMapper|MockObject $roomMapper;
    private FloorMapper|MockObject $floorMapper;
    private RoomService|MockObject $roomService;

    protected function setUp(): void
    {
        $this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
        $this->taskMapper = $this->createMock(TaskMapper::class);
        $this->roomMapper = $this->createMock(RoomMapper::class);
        $this->floorMapper = $this->createMock(FloorMapper::class);
        $this->roomService = $this->createMock(RoomService::class);

        $this->service = new RoomTaskService(
            $this->roomTaskMapper,
            $this->taskMapper,
            $this->roomMapper,
            $this->floorMapper,
            $this->roomService
        );
    }

    public function testGetDailyTasksReturnsTasks(): void
    {
        $houseId = 1;
        $date = new \DateTime('2026-03-02');

        $roomTask = $this->createMock(RoomTask::class);
        $roomTask->method('getId')->willReturn(1);

        $this->roomTaskMapper->expects($this->once())
            ->method('findDailyTasks')
            ->with($houseId, $date)
            ->willReturn([$roomTask]);

        $result = $this->service->getDailyTasks($houseId, $date);

        $this->assertCount(1, $result);
        $this->assertSame($roomTask, $result[0]);
    }

    public function testGetDailyTasksWithDetailsCachesTaskAndRoomLookups(): void
    {
        $houseId = 1;
        $date = new \DateTime('2026-03-02');

        $firstRoomTask = new RoomTask();
        $firstRoomTask->setId(1);
        $firstRoomTask->setTaskId(5);
        $firstRoomTask->setRoomId(2);

        $secondRoomTask = new RoomTask();
        $secondRoomTask->setId(2);
        $secondRoomTask->setTaskId(5);
        $secondRoomTask->setRoomId(2);

        $task = new Task();
        $task->setId(5);
        $task->setName('Shared Task');

        $room = new Room();
        $room->setId(2);
        $room->setName('Kitchen');

        $this->roomTaskMapper->expects($this->once())
            ->method('findDailyTasks')
            ->with($houseId, $date)
            ->willReturn([$firstRoomTask, $secondRoomTask]);

        $this->taskMapper->expects($this->once())
            ->method('find')
            ->with(5)
            ->willReturn($task);

        $this->roomMapper->expects($this->once())
            ->method('find')
            ->with(2)
            ->willReturn($room);

        $result = $this->service->getDailyTasksWithDetails($houseId, $date);

        $this->assertCount(2, $result);
        $this->assertSame($task, $result[0]['task']);
        $this->assertSame($task, $result[1]['task']);
        $this->assertSame($room, $result[0]['room']);
        $this->assertSame($room, $result[1]['room']);
    }

    public function testCompleteTaskSimpleUpdatesTask(): void
    {
        $roomTaskId = 1;
        $roomTask = new RoomTask();
        $roomTask->setId($roomTaskId);

        $this->roomTaskMapper->expects($this->once())
            ->method('find')
            ->with($roomTaskId)
            ->willReturn($roomTask);

        $this->roomTaskMapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (RoomTask $updatedTask): bool {
                return $updatedTask->getLastCompleted() !== null;
            }))
            ->willReturn($roomTask);

        $result = $this->service->completeTaskSimple($roomTaskId);

        $this->assertSame($roomTask, $result);
        $this->assertNotNull($result->getLastCompleted());
    }

    public function testUncompleteTaskRemovesCompletion(): void
    {
        $roomTaskId = 1;
        $roomTask = new RoomTask();
        $roomTask->setId($roomTaskId);
        $roomTask->setLastCompleted(new \DateTime('2026-03-01'));

        $this->roomTaskMapper->expects($this->once())
            ->method('find')
            ->with($roomTaskId)
            ->willReturn($roomTask);

        $this->roomTaskMapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (RoomTask $updatedTask): bool {
                return $updatedTask->getLastCompleted() === null;
            }))
            ->willReturn($roomTask);

        $result = $this->service->uncompleteTask($roomTaskId);

        $this->assertSame($roomTask, $result);
        $this->assertNull($result->getLastCompleted());
    }

    public function testSkipTaskMovesDueDateForward(): void
    {
        $roomTaskId = 1;
        $originalDue = new \DateTime('2026-03-02');

        $roomTask = new RoomTask();
        $roomTask->setId($roomTaskId);
        $roomTask->setNextDue(clone $originalDue);

        $this->roomTaskMapper->expects($this->once())
            ->method('find')
            ->with($roomTaskId)
            ->willReturn($roomTask);

        $this->roomTaskMapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (RoomTask $updatedTask): bool {
                return $updatedTask->getNextDue()->format('Y-m-d') === '2026-03-03';
            }))
            ->willReturn($roomTask);

        $result = $this->service->skipTask($roomTaskId);

        $this->assertSame($roomTask, $result);
        $this->assertSame('2026-03-03', $result->getNextDue()->format('Y-m-d'));
    }

    public function testRescheduleTaskUpdatesDueDate(): void
    {
        $roomTaskId = 1;
        $newDate = new \DateTime('2026-03-05');

        $roomTask = new RoomTask();
        $roomTask->setId($roomTaskId);
        $roomTask->setNextDue(new \DateTime('2026-03-02'));

        $this->roomTaskMapper->expects($this->once())
            ->method('find')
            ->with($roomTaskId)
            ->willReturn($roomTask);

        $this->roomTaskMapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (RoomTask $updatedTask): bool {
                return $updatedTask->getNextDue()->format('Y-m-d') === '2026-03-05';
            }))
            ->willReturn($roomTask);

        $result = $this->service->rescheduleTask($roomTaskId, $newDate);

        $this->assertSame($roomTask, $result);
        $this->assertSame('2026-03-05', $result->getNextDue()->format('Y-m-d'));
    }

    public function testCompleteTaskSimpleThrowsExceptionWhenTaskNotFound(): void
    {
        $roomTaskId = 999;

        $this->roomTaskMapper->expects($this->once())
            ->method('find')
            ->with($roomTaskId)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Task not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->service->completeTaskSimple($roomTaskId);
    }

    public function testUncompleteTaskThrowsExceptionWhenTaskNotFound(): void
    {
        $roomTaskId = 999;

        $this->roomTaskMapper->expects($this->once())
            ->method('find')
            ->with($roomTaskId)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Task not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->service->uncompleteTask($roomTaskId);
    }

    public function testSkipTaskThrowsExceptionWhenTaskNotFound(): void
    {
        $roomTaskId = 999;

        $this->roomTaskMapper->expects($this->once())
            ->method('find')
            ->with($roomTaskId)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Task not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->service->skipTask($roomTaskId);
    }

    public function testRescheduleTaskThrowsExceptionWhenTaskNotFound(): void
    {
        $roomTaskId = 999;
        $newDate = new \DateTime('2026-03-05');

        $this->roomTaskMapper->expects($this->once())
            ->method('find')
            ->with($roomTaskId)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Task not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->service->rescheduleTask($roomTaskId, $newDate);
    }

    public function testGetFilteredDailyTasksForUserReturnsAllTasksForNonChildUser(): void
    {
        $houseId = 1;
        $nextcloudUserId = 'parent';
        $date = new \DateTime('2026-03-07'); // Saturday

        // Create room tasks
        $roomTask1 = new RoomTask();
        $roomTask1->setId(1);
        $roomTask1->setTaskId(1);
        $roomTask1->setRoomId(1);

        $roomTask2 = new RoomTask();
        $roomTask2->setId(2);
        $roomTask2->setTaskId(2);
        $roomTask2->setRoomId(1);

        $task = new Task();
        $task->setId(1);
        $task->setName('Test Task');

        $room = new Room();
        $room->setId(1);
        $room->setName('Kitchen');

        // Mock daily tasks
        $this->roomTaskMapper->expects($this->once())
            ->method('findDailyTasks')
            ->with($houseId, $date)
            ->willReturn([$roomTask1, $roomTask2]);

        $this->taskMapper->expects($this->any())
            ->method('find')
            ->willReturn($task);

        $this->roomMapper->expects($this->any())
            ->method('find')
            ->willReturn($room);

        // Create real HouseUser entity (non-child)
        $houseUser = new \OCA\ChoreStrike\Db\HouseUser();
        $houseUser->setId(1);
        $houseUser->setRole('owner');

        $houseUserMapper = $this->createMock(\OCA\ChoreStrike\Db\HouseUserMapper::class);
        $houseUserMapper->expects($this->once())
            ->method('findByNextcloudUserId')
            ->with($houseId, $nextcloudUserId)
            ->willReturn($houseUser);

        $result = $this->service->getFilteredDailyTasksForUser(
            $houseId,
            $nextcloudUserId,
            $date,
            $houseUserMapper
        );

        $this->assertCount(2, $result);
    }

    public function testGetFilteredDailyTasksForUserFiltersByWeekdayAvailabilityForChild(): void
    {
        $houseId = 1;
        $nextcloudUserId = 'teen';
        $date = new \DateTime('2026-03-06'); // Friday (weekday)

        // Create room tasks
        $roomTask1 = new RoomTask();
        $roomTask1->setId(1);
        $roomTask1->setTaskId(1);
        $roomTask1->setRoomId(1);

        $task = new Task();
        $task->setId(1);
        $task->setName('Test Task');

        $room = new Room();
        $room->setId(1);
        $room->setName('Kitchen');

        // Mock daily tasks
        $this->roomTaskMapper->expects($this->once())
            ->method('findDailyTasks')
            ->with($houseId, $date)
            ->willReturn([$roomTask1]);

        // Create real HouseUser entity (child with weekend-only availability)
        $houseUser = new \OCA\ChoreStrike\Db\HouseUser();
        $houseUser->setId(2);
        $houseUser->setRole('child');
        $houseUser->setSchedulingPreferences([
            'weekdayAvailability' => [
                'monday' => false,
                'tuesday' => false,
                'wednesday' => false,
                'thursday' => false,
                'friday' => false,
                'saturday' => true,
                'sunday' => true,
            ],
            'maxDailyTasks' => 3,
        ]);

        $houseUserMapper = $this->createMock(\OCA\ChoreStrike\Db\HouseUserMapper::class);
        $houseUserMapper->expects($this->once())
            ->method('findByNextcloudUserId')
            ->with($houseId, $nextcloudUserId)
            ->willReturn($houseUser);

        $result = $this->service->getFilteredDailyTasksForUser(
            $houseId,
            $nextcloudUserId,
            $date,
            $houseUserMapper
        );

        // Friday is not available, so result should be empty
        $this->assertCount(0, $result);
    }

    public function testGetFilteredDailyTasksForUserLimitsByMaxDailyTasksForChild(): void
    {
        $houseId = 1;
        $nextcloudUserId = 'kid';
        $date = new \DateTime('2026-03-07'); // Saturday (available day)

        // Create 5 room tasks
        $roomTasks = [];
        for ($i = 1; $i <= 5; $i++) {
            $roomTask = new RoomTask();
            $roomTask->setId($i);
            $roomTask->setTaskId($i);
            $roomTask->setRoomId(1);
            $roomTasks[] = $roomTask;
        }

        $task = new Task();
        $task->setId(1);
        $task->setName('Test Task');

        $room = new Room();
        $room->setId(1);
        $room->setName('Kitchen');

        // Mock daily tasks
        $this->roomTaskMapper->expects($this->once())
            ->method('findDailyTasks')
            ->with($houseId, $date)
            ->willReturn($roomTasks);

        $this->taskMapper->expects($this->any())
            ->method('find')
            ->willReturn($task);

        $this->roomMapper->expects($this->any())
            ->method('find')
            ->willReturn($room);

        // Create real HouseUser entity (child with maxDailyTasks=2)
        $houseUser = new \OCA\ChoreStrike\Db\HouseUser();
        $houseUser->setId(3);
        $houseUser->setRole('child');
        $houseUser->setSchedulingPreferences([
            'weekdayAvailability' => [
                'monday' => false,
                'tuesday' => false,
                'wednesday' => false,
                'thursday' => false,
                'friday' => false,
                'saturday' => true,
                'sunday' => true,
            ],
            'maxDailyTasks' => 2,
        ]);

        $houseUserMapper = $this->createMock(\OCA\ChoreStrike\Db\HouseUserMapper::class);
        $houseUserMapper->expects($this->once())
            ->method('findByNextcloudUserId')
            ->with($houseId, $nextcloudUserId)
            ->willReturn($houseUser);

        $result = $this->service->getFilteredDailyTasksForUser(
            $houseId,
            $nextcloudUserId,
            $date,
            $houseUserMapper
        );

        // Saturday is available, but maxDailyTasks=2 limits to 2 tasks
        $this->assertCount(2, $result);
    }

    public function testGetFilteredDailyTasksForChildReturnsTasksOnAvailableDay(): void
    {
        $houseId = 1;
        $nextcloudUserId = 'teen';
        $date = new \DateTime('2026-03-07'); // Saturday (available day)

        // Create room tasks
        $roomTask1 = new RoomTask();
        $roomTask1->setId(1);
        $roomTask1->setTaskId(1);
        $roomTask1->setRoomId(1);

        $roomTask2 = new RoomTask();
        $roomTask2->setId(2);
        $roomTask2->setTaskId(2);
        $roomTask2->setRoomId(1);

        $task = new Task();
        $task->setId(1);
        $task->setName('Test Task');

        $room = new Room();
        $room->setId(1);
        $room->setName('Kitchen');

        // Mock daily tasks
        $this->roomTaskMapper->expects($this->once())
            ->method('findDailyTasks')
            ->with($houseId, $date)
            ->willReturn([$roomTask1, $roomTask2]);

        $this->taskMapper->expects($this->any())
            ->method('find')
            ->willReturn($task);

        $this->roomMapper->expects($this->any())
            ->method('find')
            ->willReturn($room);

        // Create real HouseUser entity (child with weekend-only availability and maxDailyTasks=3)
        $houseUser = new \OCA\ChoreStrike\Db\HouseUser();
        $houseUser->setId(4);
        $houseUser->setRole('child');
        $houseUser->setSchedulingPreferences([
            'weekdayAvailability' => [
                'monday' => false,
                'tuesday' => false,
                'wednesday' => false,
                'thursday' => false,
                'friday' => false,
                'saturday' => true,
                'sunday' => true,
            ],
            'maxDailyTasks' => 3,
        ]);

        $houseUserMapper = $this->createMock(\OCA\ChoreStrike\Db\HouseUserMapper::class);
        $houseUserMapper->expects($this->once())
            ->method('findByNextcloudUserId')
            ->with($houseId, $nextcloudUserId)
            ->willReturn($houseUser);

        $result = $this->service->getFilteredDailyTasksForUser(
            $houseId,
            $nextcloudUserId,
            $date,
            $houseUserMapper
        );

        // Saturday is available and maxDailyTasks=3 allows all 2 tasks
        $this->assertCount(2, $result);
    }
}
