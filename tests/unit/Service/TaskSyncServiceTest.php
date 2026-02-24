<?php

declare(strict_types=1);

namespace OCA\ChoreWars\Tests\Unit\Service;

use OCA\ChoreWars\Db\CompletedTask;
use OCA\ChoreWars\Db\CompletedTaskMapper;
use OCA\ChoreWars\Db\HouseUser;
use OCA\ChoreWars\Db\HouseUserMapper;
use OCA\ChoreWars\Db\Room;
use OCA\ChoreWars\Db\RoomMapper;
use OCA\ChoreWars\Db\RoomTask;
use OCA\ChoreWars\Db\RoomTaskMapper;
use OCA\ChoreWars\Db\Task;
use OCA\ChoreWars\Db\TaskMapper;
use OCA\ChoreWars\Service\TaskSyncService;
use OCP\ITimeFactory;
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
	private HouseUserMapper $houseUserMapper;
	private ITimeFactory $timeFactory;

	protected function setUp(): void {
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);

		$this->service = new TaskSyncService(
			$this->roomTaskMapper,
			$this->completedTaskMapper,
			$this->taskMapper,
			$this->roomMapper,
			$this->houseUserMapper,
			$this->timeFactory
		);
	}

	/**
	 * CompleteTask_ValidTask_ReturnsCompletionData
	 */
	public function testCompleteTask_ValidTask_ReturnsCompletionData(): void {
		$roomTaskId = 1;
		$userId = 1;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setTaskId(1);
		$roomTask->setIsActive(true);

		$task = new Task();
		$task->setId(1);
		$task->setName('Test Task');
		$task->setFrequencyType('weekly');
		$task->setFrequencyValue(1);

		$user = new HouseUser();
		$user->setId($userId);

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

		$this->houseUserMapper
			->expects($this->once())
			->method('find')
			->with($userId)
			->willReturn($user);

		$this->roomTaskMapper->expects($this->exactly(2))->method('update');
		$this->completedTaskMapper->expects($this->once())->method('insert');

		$result = $this->service->completeTask($roomTaskId, $userId);

		$this->assertEquals($roomTaskId, $result['roomTaskId']);
		$this->assertEquals('Test Task', $result['taskName']);
		$this->assertFalse($result['synced']);
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
		$roomTask->setIsActive(true);

		$task = new Task();
		$task->setId(1);
		$task->setFrequencyType('daily');
		$task->setFrequencyValue(1);

		$this->roomTaskMapper
			->expects($this->exactly(2))
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

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
	public function testSyncCompletionFromNextcloud_ConflictLocalWins_ReturnsNull(): void {
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
	 * GetPendingSyncs_CallsMapperWithLimit
	 */
	public function testGetPendingSyncs_CallsMapperWithLimit(): void {
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
	 * MarkAsSynced_UpdatesCompletedTask
	 */
	public function testMarkAsSynced_UpdatesCompletedTask(): void {
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
	public function testLinkTask_UpdatesRoomTask(): void {
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
