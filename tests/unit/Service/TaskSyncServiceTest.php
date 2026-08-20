<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\TaskSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaskSyncService
 */
class TaskSyncServiceTest extends TestCase {
	private TaskSyncService $service;
	private RoomTaskMapper $roomTaskMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private FloorMapper $floorMapper;
	private HouseUserMapper $houseUserMapper;
	private RoomService $roomService;

	protected function setUp(): void {
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
	 * CompleteTaskForHouse_DelegatesToCanonicalCompletionAndSurfacesRewards
	 *
	 * CR-SVC-07: the completion routes through
	 * RoomService::completeRoomTask so rewards, locking, the daily duplicate
	 * guard, the cleanliness update, and recurrence all apply uniformly —
	 * no reward-less parallel path. CR-SVC-21: the unscoped completeTask is
	 * private; the house-scoped wrapper is the public surface, so the test
	 * goes through it (mocking the room → floor → house chain).
	 */
	public function testCompleteTaskForHouse_DelegatesToCanonicalCompletionAndSurfacesRewards(): void {
		$roomTaskId = 1;
		$nextcloudUserId = 'testuser';
		$houseId = 5;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setRoomId(2);
		$roomTask->setLastCompleted(new \DateTime('2026-08-09T10:00:00+00:00'));
		$roomTask->setNextDue(new \DateTime('2026-08-16'));

		$room = new Room();
		$room->setId(2);
		$room->setFloorId(3);

		$floor = new Floor();
		$floor->setId(3);
		$floor->setHouseId($houseId);

		$task = new Task();
		$task->setId(1);
		$task->setName('Test Task');

		$rewards = ['coins' => 30, 'contributionScore' => 3, 'currentStreak' => 1];

		$this->roomTaskMapper->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);
		$this->roomMapper->expects($this->once())->method('find')->with(2)->willReturn($room);
		$this->floorMapper->expects($this->once())->method('find')->with(3)->willReturn($floor);

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

		$result = $this->service->completeTaskForHouse($roomTaskId, $nextcloudUserId, $houseId);

		$this->assertEquals($roomTaskId, $result['roomTaskId']);
		$this->assertEquals('Test Task', $result['taskName']);
		$this->assertFalse($result['synced']);
		$this->assertSame($rewards, $result['rewards']);
	}

	/**
	 * CompleteTaskForHouse_WithNextcloudTaskId_LinksAfterCanonicalCompletion
	 *
	 * The Nextcloud task id is linked after the canonical completion so the
	 * later sync can find the room task. CR-SVC-46: the id is also threaded
	 * into the canonical completion so the completion row itself carries it
	 * (findPendingSyncs filters on completed_tasks.nextcloud_task_id).
	 */
	public function testCompleteTaskForHouse_WithNextcloudTaskId_LinksAfterCanonicalCompletion(): void {
		$houseId = 5;

		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setRoomId(2);
		$roomTask->setNextDue(new \DateTime('2026-08-16'));

		$room = new Room();
		$room->setId(2);
		$room->setFloorId(3);

		$floor = new Floor();
		$floor->setId(3);
		$floor->setHouseId($houseId);

		$task = new Task();
		$task->setName('Test Task');

		// find() runs for the scope check and again for the link.
		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->roomMapper->method('find')->willReturn($room);
		$this->floorMapper->method('find')->willReturn($floor);

		$this->roomService->expects($this->once())
			->method('completeRoomTask')
			->with(1, 'testuser', 4242)
			->willReturn([
				'roomTask' => $roomTask,
				'room' => new Room(),
				'task' => $task,
				'rewards' => [],
			]);

		$this->roomTaskMapper->expects($this->once())->method('update')
			->with($this->callback(function ($t) {
				return $t->getNextcloudTaskId() === 4242;
			}));

		$result = $this->service->completeTaskForHouse(1, 'testuser', $houseId, 4242);

		$this->assertSame(4242, $result['nextcloudTaskId']);
	}

	/**
	 * SyncCompletionFromNextcloud_ValidSync_ReturnsSyncData
	 */
	public function testSyncCompletionFromNextcloud_ValidSync_ReturnsSyncData(): void {
		$nextcloudTaskId = 123;
		$completedAt = '2025-03-15T10:00:00+00:00';
		$roomTaskId = 1;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setTaskId(1);
		$roomTask->setRoomId(2);
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
	 * SyncCompletionFromNextcloud_AppliesCanonicalCleanlinessBump
	 *
	 * CR-SVC-48: the reverse path bumps room cleanliness with the same
	 * formula as the canonical completion (energyCost * 10) — a
	 * Nextcloud-originated completion is a real completion.
	 */
	public function testSyncCompletionFromNextcloud_AppliesCanonicalCleanlinessBump(): void {
		$nextcloudTaskId = 123;
		$completedAt = '2025-03-15T10:00:00+00:00';
		$roomTaskId = 1;
		$roomId = 8;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setTaskId(1);
		$roomTask->setRoomId($roomId);

		$task = new Task();
		$task->setId(1);
		$task->setFrequencyType('daily');
		$task->setFrequencyValue(1);
		$task->setEnergyCost(3);

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->completedTaskMapper->method('findLatestByRoomTask')->willReturn(null);

		$this->roomService->expects($this->once())
			->method('updateRoomCleanliness')
			->with($roomId, 30); // energyCost 3 * 10

		$this->service->syncCompletionFromNextcloud($nextcloudTaskId, $completedAt, $roomTaskId);
	}

	/**
	 * SyncCompletionFromNextcloud_ConflictLocalWins_ReturnsNull
	 */
	public function testSyncCompletionFromNextcloud_ConflictLocalWins_ReturnsNull(): void {
		$nextcloudTaskId = 123;
		$completedAt = '2025-03-15T10:00:00+00:00';
		$roomTaskId = 1;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);

		$localCompletion = new CompletedTask();
		$localCompletion->setCompletedAt(new \DateTime('2025-03-15T11:00:00+00:00')); // Later than remote

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->completedTaskMapper
			->expects($this->once())
			->method('findLatestByRoomTask')
			->with($roomTaskId)
			->willReturn($localCompletion);

		// Conflict resolution prefers local state: no recurrence write, no
		// cleanliness bump.
		$this->roomTaskMapper->expects($this->never())->method('update');
		$this->roomService->expects($this->never())->method('updateRoomCleanliness');

		$result = $this->service->syncCompletionFromNextcloud($nextcloudTaskId, $completedAt, $roomTaskId);

		$this->assertNull($result);
	}

	/**
	 * MapRemindersToNextcloud_DueReminder_ReturnsAbsoluteType
	 */
	public function testMapRemindersToNextcloud_DueReminder_ReturnsAbsoluteType(): void {
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
	public function testMapRemindersToNextcloud_BeforeReminder_ReturnsRelativeType(): void {
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
	 * CompleteTaskForHouse_RoomTaskOutsideHouse_ThrowsInvalidArgumentException
	 */
	public function testCompleteTaskForHouse_RoomTaskOutsideHouse_ThrowsInvalidArgumentException(): void {
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
	 * GetPendingSyncsForHouse_DelegatesToHouseScopedQuery
	 *
	 * (CR-SVC-21 scope + CS-PLAN-036: the overfetch-and-filter loop with its
	 * per-row 3-query ownership chain is replaced by one house-scoped
	 * mapper query carrying house + limit directly.)
	 */
	public function testGetPendingSyncsForHouse_DelegatesToHouseScopedQuery(): void {
		$houseId = 1;
		$limit = 2;

		$completedTaskOne = new CompletedTask();
		$completedTaskOne->setRoomTaskId(1001);

		$completedTaskTwo = new CompletedTask();
		$completedTaskTwo->setRoomTaskId(1003);

		$this->completedTaskMapper
			->expects($this->once())
			->method('findPendingSyncsForHouse')
			->with($houseId, $limit)
			->willReturn([$completedTaskOne, $completedTaskTwo]);

		// No per-row ownership chain may run anymore.
		$this->roomTaskMapper->expects($this->never())->method('find');
		$this->roomMapper->expects($this->never())->method('find');
		$this->floorMapper->expects($this->never())->method('find');

		$result = $this->service->getPendingSyncsForHouse($houseId, $limit);

		$this->assertCount(2, $result);
		$this->assertSame(1001, $result[0]->getRoomTaskId());
		$this->assertSame(1003, $result[1]->getRoomTaskId());
	}

	/**
	 * MarkAsSyncedForHouse_UpdatesCompletedTaskAfterScopeCheck
	 *
	 * (CR-SVC-21: the unscoped markAsSynced is private — the wrapper
	 * enforces the house scope before the mutation.)
	 */
	public function testMarkAsSyncedForHouse_UpdatesCompletedTaskAfterScopeCheck(): void {
		$completedTaskId = 1;
		$houseId = 5;

		$completedTask = new CompletedTask();
		$completedTask->setId($completedTaskId);
		$completedTask->setRoomTaskId(10);
		$completedTask->setSyncedToNextcloud(false);

		$roomTask = new RoomTask();
		$roomTask->setId(10);
		$roomTask->setRoomId(2);

		$room = new Room();
		$room->setId(2);
		$room->setFloorId(3);

		$floor = new Floor();
		$floor->setId(3);
		$floor->setHouseId($houseId);

		$this->completedTaskMapper
			->method('find')
			->with($completedTaskId)
			->willReturn($completedTask);

		$this->roomTaskMapper->expects($this->once())->method('find')->with(10)->willReturn($roomTask);
		$this->roomMapper->expects($this->once())->method('find')->with(2)->willReturn($room);
		$this->floorMapper->expects($this->once())->method('find')->with(3)->willReturn($floor);

		$this->completedTaskMapper
			->expects($this->once())
			->method('update')
			->with($this->callback(function ($t) {
				return $t->getSyncedToNextcloud() === true;
			}));

		$this->service->markAsSyncedForHouse($completedTaskId, $houseId);

		// Assertions verified by mock expectations
		$this->assertTrue(true);
	}

	/**
	 * LinkTaskForHouse_UpdatesRoomTaskAfterScopeCheck
	 *
	 * (CR-SVC-21: the unscoped linkTask is private — the wrapper enforces
	 * the house scope before the mutation.)
	 */
	public function testLinkTaskForHouse_UpdatesRoomTaskAfterScopeCheck(): void {
		$roomTaskId = 1;
		$nextcloudTaskId = 123;
		$houseId = 5;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setRoomId(2);

		$room = new Room();
		$room->setId(2);
		$room->setFloorId(3);

		$floor = new Floor();
		$floor->setId(3);
		$floor->setHouseId($houseId);

		// find() runs for the scope check and again for the link itself.
		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->roomMapper->method('find')->willReturn($room);
		$this->floorMapper->method('find')->willReturn($floor);

		$this->roomTaskMapper
			->expects($this->once())
			->method('update')
			->with($this->callback(function ($t) use ($nextcloudTaskId) {
				return $t->getNextcloudTaskId() === $nextcloudTaskId;
			}));

		$this->service->linkTaskForHouse($roomTaskId, $nextcloudTaskId, $houseId);

		// Assertions verified by mock expectations
		$this->assertTrue(true);
	}

	/**
	 * UnscopedMutators_AreNotPubliclyCallable
	 *
	 * CR-SVC-21 regression guard: the unscoped mutators are an
	 * authorization-bypass hazard kept alive only as internal helpers —
	 * the *ForHouse wrappers are the only public surface.
	 */
	public function testUnscopedMutators_AreNotPubliclyCallable(): void {
		foreach (['completeTask', 'linkTask', 'markAsSynced', 'getPendingSyncs'] as $method) {
			$reflection = new \ReflectionMethod(TaskSyncService::class, $method);
			$this->assertFalse(
				$reflection->isPublic(),
				$method . ' must not be public — use its *ForHouse wrapper (CR-SVC-21)'
			);
		}
	}
}
