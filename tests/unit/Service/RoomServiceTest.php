<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\GameLogicService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\NotificationService;
use OCA\ChoreStrike\Service\RoomService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomService
 */
class RoomServiceTest extends TestCase {
	private RoomService $service;
	private RoomMapper $roomMapper;
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private HouseUserMapper $houseUserMapper;
	private FloorMapper $floorMapper;
	private GameLogicService $gameLogicService;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private RoomTaskChecklistProgressMapper $checklistProgressMapper;
	private HouseService $houseService;
	private HouseMapper $houseMapper;
	private NotificationService $notificationService;
	private IDBConnection $db;

	/**
	 * Per-test override for the weekly goal snapshot sequence.
	 *
	 * Each entry is returned on the corresponding getWeeklyGoalProgress()
	 * call. Tests that don't care about the transition leave this empty
	 * and the default "not achieved" snapshot is returned on every call.
	 * Tests that exercise the transition push two snapshots here.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $weeklyGoalSequence = [];

	protected function setUp(): void {
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->floorMapper = $this->createMock(FloorMapper::class);
		$this->gameLogicService = $this->createMock(GameLogicService::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->checklistProgressMapper = $this->createMock(RoomTaskChecklistProgressMapper::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->db = $this->createMock(IDBConnection::class);

		// Default weekly goal snapshot: not achieved, no activity. Tests that
		// exercise the not-achieved → achieved transition populate
		// $weeklyGoalSequence with two snapshots; otherwise this default is
		// returned on every call (so no notification fires).
		$defaultSnapshot = [
			'dueCount' => 0,
			'completedCount' => 0,
			'percent' => 0,
			'targetPercent' => 80,
			'achieved' => false,
			'weekStart' => '2026-07-14T00:00:00+00:00',
			'weekEnd' => '2026-07-20T23:59:59+00:00',
		];
		$this->houseService->method('getWeeklyGoalProgress')->willReturnCallback(function () use (&$defaultSnapshot) {
			if (!empty($this->weeklyGoalSequence)) {
				return array_shift($this->weeklyGoalSequence);
			}
			return $defaultSnapshot;
		});

		$this->service = new RoomService(
			$this->roomMapper,
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->houseUserMapper,
			$this->floorMapper,
			$this->gameLogicService,
			$this->taskAssigneeMapper,
			$this->completedTaskMapper,
			$this->checklistProgressMapper,
			$this->houseService,
			$this->houseMapper,
			$this->notificationService,
			$this->db
		);
	}

	/**
	 * GetRooms_ExistingFloor_ReturnsRooms
	 */
	public function testGetRooms_ExistingFloor_ReturnsRooms(): void {
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
	public function testGetRoom_ExistingId_ReturnsRoom(): void {
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
	public function testCreateRoom_ValidData_CreatesRoom(): void {
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
	public function testUpdateRoomName_ValidName_ReturnsUpdatedRoom(): void {
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
	 * DeleteRoom_WithTasks_CascadesDependentsInOneTransaction
	 *
	 * CS-PLAN-037 (D1/D2): room deletion is an explicit, orphan-free
	 * cascade in dependency order (checklist progress → assignees →
	 * completions → room tasks → room), wrapped in one transaction.
	 */
	public function testDeleteRoom_WithTasks_CascadesDependentsInOneTransaction(): void {
		$roomId = 1;
		$roomTaskIds = [10, 11];

		$room = new Room();
		$room->setId($roomId);

		$this->roomMapper
			->expects($this->once())
			->method('find')
			->with($roomId)
			->willReturn($room);

		$this->roomTaskMapper
			->expects($this->once())
			->method('findIdsByRoomId')
			->with($roomId)
			->willReturn($roomTaskIds);

		$this->checklistProgressMapper
			->expects($this->once())
			->method('deleteByRoomTaskIds')
			->with($roomTaskIds);

		$this->taskAssigneeMapper
			->expects($this->once())
			->method('deleteByRoomTasks')
			->with($roomTaskIds);

		$this->completedTaskMapper
			->expects($this->once())
			->method('deleteByRoomTaskIds')
			->with($roomTaskIds);

		$this->roomTaskMapper
			->expects($this->once())
			->method('deleteByRoomId')
			->with($roomId);

		$this->roomMapper
			->expects($this->once())
			->method('delete')
			->with($room);

		// The whole cascade is all-or-nothing.
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$this->service->deleteRoom($roomId);
	}

	/**
	 * DeleteRoom_DependentFailure_RollsBackAndReThrows
	 *
	 * A failure anywhere in the cascade must leave no partial deletion.
	 */
	public function testDeleteRoom_DependentFailure_RollsBackAndReThrows(): void {
		$roomId = 1;

		$room = new Room();
		$room->setId($roomId);

		$this->roomMapper->method('find')->willReturn($room);
		$this->roomTaskMapper->method('findIdsByRoomId')->willReturn([10]);
		$this->taskAssigneeMapper->method('deleteByRoomTasks')
			->willThrowException(new \RuntimeException('db down'));

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->roomMapper->expects($this->never())->method('delete');
		$this->roomTaskMapper->expects($this->never())->method('deleteByRoomId');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('db down');

		$this->service->deleteRoom($roomId);
	}

	/**
	 * UpdateRoomCleanliness_PositiveChange_UpdatesCleanliness
	 */
	public function testUpdateRoomCleanliness_PositiveChange_UpdatesCleanliness(): void {
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
	 *
	 * CS-PLAN-036: task details come from one findByIds batch fetch; a
	 * missing task id is skipped (map absence = skip).
	 */
	public function testGetRoomWithTasks_WithTasks_ReturnsRoomAndTasks(): void {
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

		$orphanedRoomTask = new RoomTask();
		$orphanedRoomTask->setId(2);
		$orphanedRoomTask->setTaskId(999);
		$orphanedRoomTask->setNextDue(new \DateTime('+1 day'));
		$orphanedRoomTask->setIsActive(true);

		$this->roomMapper
			->expects($this->once())
			->method('find')
			->with($roomId)
			->willReturn($room);

		$this->roomTaskMapper
			->expects($this->once())
			->method('findByRoomId')
			->with($roomId)
			->willReturn([$roomTask, $orphanedRoomTask]);

		// One batch call; task 999 is absent from the map → skipped.
		$this->taskMapper
			->expects($this->once())
			->method('findByIds')
			->with([1, 999])
			->willReturn([1 => $task]);

		$result = $this->service->getRoomWithTasks($roomId);

		$this->assertSame($room, $result['room']);
		$this->assertCount(1, $result['tasks']);
		$this->assertSame($task, $result['tasks'][0]['task']);
		$this->assertEquals(80, $result['stats']['cleanliness']);
	}

	/**
	 * GetRoomsNeedingCleaning_ReturnsFilteredRooms
	 */
	public function testGetRoomsNeedingCleaning_ReturnsFilteredRooms(): void {
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
	 * GetRoomTask_WithData_ReturnsRoomTaskDetails
	 */
	public function testGetRoomTask_WithData_ReturnsRoomTaskDetails(): void {
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
	public function testCompleteRoomTask_ValidTask_ReturnsCompletionData(): void {
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

		$this->roomTaskMapper
			->method('lockForUpdate')
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
			->expects($this->exactly(2))
			->method('findByHouseAndUser')
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
	 * CompleteRoomTask_WithNextcloudTaskId_ThreadsIdToGameLogic
	 *
	 * CR-SVC-46: sync completions pass the Nextcloud task id through the
	 * canonical completion so the completion row is stamped with it.
	 */
	public function testCompleteRoomTask_WithNextcloudTaskId_ThreadsIdToGameLogic(): void {
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

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->roomTaskMapper->method('lockForUpdate')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);
		$this->houseUserMapper->method('findByHouseAndUser')->with(1, $userId)->willReturn($houseUser);
		$this->roomMapper->method('update')->willReturn($updatedRoom);

		$this->gameLogicService
			->expects($this->once())
			->method('handleTaskCompletion')
			->with($this->callback(function ($rt) use ($roomTask): bool {
				// CS-PLAN-036: the locked entity is threaded in directly.
				return $rt === $roomTask;
			}), $houseUser->getId(), $task, 4242)
			->willReturn([
				'contributionScore' => 10,
				'coins' => 50,
				'isCombo' => false,
				'currentStreak' => 1
			]);

		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->completedTaskMapper->method('countByRoomTaskAndUserOnDate')->willReturn(0);

		$this->service->completeRoomTask($roomTaskId, $userId, 4242);
	}

	/**
	 * CompleteRoomTask_OverdueTakeover_SetsClaimedBy
	 *
	 * When a member completes an overdue task assigned to someone else, the
	 * service must record the takeover (claimedByUser / originalAssignee /
	 * wasClaimed) so the original assignee's streak stays unaffected.
	 */
	public function testCompleteRoomTask_OverdueTakeover_SetsClaimedBy(): void {
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
		$houseUser->setDisplayName('Claimer');

		$updatedRoom = new Room();
		$updatedRoom->setId(1);
		$updatedRoom->setCleanliness(80);

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->roomTaskMapper->method('lockForUpdate')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);
		// Claimer resolution, in-transaction re-check, and the post-commit
		// recipient membership re-check all use findByHouseAndUser (the
		// recipient is a different uid than the completer).
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($houseUser);
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
	 * CompleteRoomTask_FreshEntityAfterLock_NoSpuriousTakeover
	 *
	 * CR-SVC-53: every post-lock decision must run on the entity returned
	 * by lockForUpdate(), not the pre-lock snapshot. Here the pre-lock fetch
	 * sees an overdue task while a concurrent winner has already advanced
	 * next_due — the locked entity is NOT overdue anymore, so no takeover
	 * may be recorded and no notification may fire.
	 */
	public function testCompleteRoomTask_FreshEntityAfterLock_NoSpuriousTakeover(): void {
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

		// Pre-lock snapshot: overdue and assigned to someone else — would
		// trigger a takeover if the stale entity were used post-lock.
		$staleRoomTask = new RoomTask();
		$staleRoomTask->setId($roomTaskId);
		$staleRoomTask->setTaskId(1);
		$staleRoomTask->setRoomId(1);
		$staleRoomTask->setIsActive(true);
		$staleRoomTask->setAssignedToUserId($originalAssignee);
		$staleRoomTask->setNextDue(new \DateTime('yesterday'));

		// Fresh post-lock entity: the concurrent winner's completion
		// advanced next_due past now — no longer overdue.
		$freshRoomTask = new RoomTask();
		$freshRoomTask->setId($roomTaskId);
		$freshRoomTask->setTaskId(1);
		$freshRoomTask->setRoomId(1);
		$freshRoomTask->setIsActive(true);
		$freshRoomTask->setAssignedToUserId($originalAssignee);
		$freshRoomTask->setNextDue(new \DateTime('tomorrow'));

		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(1);

		$houseUser = new HouseUser();
		$houseUser->setId(2);
		$houseUser->setNextcloudUserId($claimer);
		$houseUser->setDisplayName('Claimer Name');

		$updatedRoom = new Room();
		$updatedRoom->setId(1);
		$updatedRoom->setCleanliness(80);

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($staleRoomTask);
		$this->roomTaskMapper->method('lockForUpdate')->with($roomTaskId)->willReturn($freshRoomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);
		$this->houseUserMapper->method('findByHouseAndUser')->with(1, $claimer)->willReturn($houseUser);
		// Every persisted mutation must target the FRESH entity.
		$this->roomTaskMapper->expects($this->once())->method('update')->with($freshRoomTask);
		$this->roomMapper->method('update')->willReturn($updatedRoom);
		$this->gameLogicService->method('handleTaskCompletion')->willReturn([
			'contributionScore' => 10,
			'coins' => 50,
			'isCombo' => false,
			'currentStreak' => 1,
		]);

		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->completedTaskMapper->method('countByRoomTaskAndUserOnDate')->willReturn(0);

		$this->notificationService->expects($this->never())->method('notifyOverdueTakenOver');

		$result = $this->service->completeRoomTask($roomTaskId, $claimer);

		$this->assertSame($freshRoomTask, $result['roomTask']);
		$this->assertFalse($freshRoomTask->getWasClaimed(), 'stale overdue snapshot must not produce a takeover');
		$this->assertNull($freshRoomTask->getClaimedByUser());
	}

	/**
	 * CompleteRoomTask_CoAssigneeOverdue_DoesNotMarkTakeover
	 *
	 * A co-assignee (present only in the join table) completing an overdue task
	 * that also has a legacy assignee must NOT be recorded as a takeover — they
	 * are themselves an assignee of the shared task.
	 */
	public function testCompleteRoomTask_CoAssigneeOverdue_DoesNotMarkTakeover(): void {
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
		$this->roomTaskMapper->method('lockForUpdate')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);
		$this->houseUserMapper->method('findByHouseAndUser')->with(1, $coAssignee)->willReturn($houseUser);
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
	 * CompleteRoomTask_NewTitleInGameResult_DispatchesTitleNotificationAfterCommit
	 *
	 * CR-SVC-12: the title-unlock notification must dispatch AFTER commit
	 * (never inside the transaction), using the newTitle payload returned by
	 * GameLogicService::handleTaskCompletion.
	 */
	public function testCompleteRoomTask_NewTitleInGameResult_DispatchesTitleNotificationAfterCommit(): void {
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

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setTaskId(1);
		$roomTask->setRoomId(1);
		$roomTask->setIsActive(true);
		$roomTask->setNextDue(new \DateTime('tomorrow'));

		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(1);

		$houseUser = new HouseUser();
		$houseUser->setId(2);

		$updatedRoom = new Room();
		$updatedRoom->setId(1);
		$updatedRoom->setCleanliness(80);

		$newTitle = [
			'pack' => 'home_helpers',
			'packLabelKey' => 'titles.pack.home_helpers',
			'tierIndex' => 0,
			'labelKey' => 'titles.home_helpers.helper',
		];

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->roomTaskMapper->method('lockForUpdate')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);
		$this->houseUserMapper->method('findByHouseAndUser')->with(1, $userId)->willReturn($houseUser);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->completedTaskMapper->method('countByRoomTaskAndUserOnDate')->willReturn(0);
		$this->roomTaskMapper->expects($this->once())->method('update')->with($roomTask);
		$this->roomMapper->method('update')->willReturn($updatedRoom);
		$this->gameLogicService->method('handleTaskCompletion')->willReturn([
			'contributionScore' => 10,
			'coins' => 50,
			'isCombo' => false,
			'currentStreak' => 1,
			'newTitle' => $newTitle,
		]);

		$callOrder = [];

		$this->notificationService->expects($this->once())
			->method('notifyTitleUnlocked')
			->with($userId, 'home_helpers', 'titles.home_helpers.helper', 'titles.pack.home_helpers', 0)
			->willReturnCallback(function () use (&$callOrder): void {
				$callOrder[] = 'notify';
			});

		// CR-SVC-52: record the shared call sequence so the dispatch-after-
		// commit ordering becomes observable — a plain "once" expectation
		// would also pass if the dispatch ran inside the transaction.
		$this->db->method('commit')
			->willReturnCallback(function () use (&$callOrder): void {
				$callOrder[] = 'commit';
			});

		$this->service->completeRoomTask($roomTaskId, $userId);

		$this->assertContains('commit', $callOrder);
		$this->assertContains('notify', $callOrder);
		$this->assertGreaterThan(
			(int)array_search('commit', $callOrder, true),
			(int)array_search('notify', $callOrder, true),
			'The title-unlock notification must be dispatched AFTER the transaction commit'
		);
	}

	/**
	 * CompleteRoomTask_NoNewTitle_DoesNotDispatchTitleNotification
	 *
	 * CR-SVC-12: a completion that unlocks no title must not dispatch.
	 */
	public function testCompleteRoomTask_NoNewTitle_DoesNotDispatchTitleNotification(): void {
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

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setTaskId(1);
		$roomTask->setRoomId(1);
		$roomTask->setIsActive(true);
		$roomTask->setNextDue(new \DateTime('tomorrow'));

		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(1);

		$houseUser = new HouseUser();
		$houseUser->setId(2);

		$updatedRoom = new Room();
		$updatedRoom->setId(1);
		$updatedRoom->setCleanliness(80);

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->roomTaskMapper->method('lockForUpdate')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);
		$this->houseUserMapper->method('findByHouseAndUser')->with(1, $userId)->willReturn($houseUser);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->completedTaskMapper->method('countByRoomTaskAndUserOnDate')->willReturn(0);
		$this->roomTaskMapper->expects($this->once())->method('update')->with($roomTask);
		$this->roomMapper->method('update')->willReturn($updatedRoom);
		$this->gameLogicService->method('handleTaskCompletion')->willReturn([
			'contributionScore' => 10,
			'coins' => 50,
			'isCombo' => false,
			'currentStreak' => 1,
			'newTitle' => null,
		]);

		$this->notificationService->expects($this->never())->method('notifyTitleUnlocked');

		$this->service->completeRoomTask($roomTaskId, $userId);
	}

	/**
	 * CompleteRoomTask_AssignedToCompleter_DoesNotMarkTakeover
	 */
	public function testCompleteRoomTask_AssignedToCompleter_DoesNotMarkTakeover(): void {
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
		$this->roomTaskMapper->method('lockForUpdate')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);
		$this->houseUserMapper->method('findByHouseAndUser')->with(1, $userId)->willReturn($houseUser);
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
	public function testCompleteRoomTask_PrivateTask_NonAssigneeRejected(): void {
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
		$this->roomTaskMapper->method('lockForUpdate')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);
		$this->houseUserMapper->method('findByHouseAndUser')->with(1, $completer)->willReturn($houseUser);
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
	public function testCompleteRoomTask_PrivateTask_AssigneeAllowed(): void {
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
		$this->roomTaskMapper->method('lockForUpdate')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);
		$this->houseUserMapper->method('findByHouseAndUser')->with(1, $completer)->willReturn($houseUser);
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
	public function testCompleteRoomTask_AlreadyCompletedToday_Rejected(): void {
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
		$this->roomTaskMapper->method('lockForUpdate')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);
		$this->houseUserMapper->method('findByHouseAndUser')->with(1, $completer)->willReturn($houseUser);
		// Already completed today
		$this->completedTaskMapper->method('countByRoomTaskAndUserOnDate')->willReturn(1);

		$this->expectException(ForbiddenException::class);
		$this->service->completeRoomTask($roomTaskId, $completer);
	}

	/**
	 * CompleteRoomTask_OverdueTakeover_DispatchesTakeoverNotification
	 *
	 * The original assignee must receive a server-side notification that
	 * another member took over their overdue task. Best-effort: the
	 * completion must succeed regardless of notification outcome.
	 */
	public function testCompleteRoomTask_OverdueTakeover_DispatchesTakeoverNotification(): void {
		$roomTaskId = 1;
		$originalAssignee = 'original-user';
		$claimer = 'claimer-user';

		$task = new Task();
		$task->setId(1);
		$task->setName('Dishes');
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
		$houseUser->setNextcloudUserId($claimer);
		$houseUser->setDisplayName('Claimer Name');

		$updatedRoom = new Room();
		$updatedRoom->setId(1);
		$updatedRoom->setCleanliness(80);

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->roomTaskMapper->method('lockForUpdate')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);
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

		// Completer resolution, the in-transaction re-check, and the
		// recipient membership re-check all resolve via findByHouseAndUser;
		// both the claimer and the original assignee are members here.
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($houseUser);

		// Takeover notification dispatched post-commit.
		$this->notificationService->expects($this->once())
			->method('notifyOverdueTakenOver')
			->with($originalAssignee, 'Dishes', 'Claimer Name', $roomTaskId);

		// No goal transition (default mock → not achieved), so no goal notify.
		$this->notificationService->expects($this->never())->method('notifyWeeklyGoalReachedForHouse');

		$this->service->completeRoomTask($roomTaskId, $claimer);
	}

	/**
	 * CompleteRoomTask_OverdueTakeover_FormerMemberSkipsDispatch
	 *
	 * If the original assignee has been removed from the house (house_users
	 * row deleted) but their legacy assigned_to_user_id is still on the room
	 * task, the takeover notification must NOT be dispatched to them.
	 */
	public function testCompleteRoomTask_OverdueTakeover_FormerMemberSkipsDispatch(): void {
		$roomTaskId = 1;
		$originalAssignee = 'former-member';
		$claimer = 'claimer-user';

		$task = new Task();
		$task->setId(1);
		$task->setName('Dishes');
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
		$roomTask->setNextDue(new \DateTime('yesterday'));

		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(1);

		$houseUser = new HouseUser();
		$houseUser->setId(2);
		$houseUser->setNextcloudUserId($claimer);
		$houseUser->setDisplayName('Claimer Name');

		$updatedRoom = new Room();
		$updatedRoom->setId(1);
		$updatedRoom->setCleanliness(80);

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->roomTaskMapper->method('lockForUpdate')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);
		// The claimer resolves (pre-transaction and in-transaction re-check),
		// but the former member recipient does not — keyed on the uid so the
		// same stub serves all three findByHouseAndUser calls.
		$this->houseUserMapper->method('findByHouseAndUser')
			->willReturnCallback(fn (int $houseId, string $uid) => $uid === $claimer ? $houseUser : null);
		$this->roomMapper->method('update')->willReturn($updatedRoom);
		$this->gameLogicService->method('handleTaskCompletion')->willReturn([
			'contributionScore' => 10,
			'coins' => 50,
			'isCombo' => false,
			'currentStreak' => 1,
		]);

		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->completedTaskMapper->method('countByRoomTaskAndUserOnDate')->willReturn(0);

		// No takeover notification dispatched.
		$this->notificationService->expects($this->never())->method('notifyOverdueTakenOver');

		// Takeover is still recorded on the room task.
		$this->service->completeRoomTask($roomTaskId, $claimer);

		$this->assertTrue($roomTask->getWasClaimed());
		$this->assertSame($claimer, $roomTask->getClaimedByUser());
	}

	/**
	 * CompleteRoomTask_NoTakeover_DoesNotDispatchTakeoverNotification
	 *
	 * Completing one's own task (assignee == completer, not overdue, or
	 * co-assignee) must NOT fire a takeover notification.
	 */
	public function testCompleteRoomTask_NoTakeover_DoesNotDispatchTakeoverNotification(): void {
		$roomTaskId = 1;
		$userId = 'user123';

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
		$roomTask->setAssignedToUserId($userId);
		$roomTask->setNextDue((new \DateTime('tomorrow'))->setTime(12, 0)); // not overdue

		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(1);

		$houseUser = new HouseUser();
		$houseUser->setId(2);
		$houseUser->setNextcloudUserId($userId);

		$updatedRoom = new Room();
		$updatedRoom->setId(1);
		$updatedRoom->setCleanliness(80);

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->roomTaskMapper->method('lockForUpdate')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);
		$this->houseUserMapper->method('findByHouseAndUser')->with(1, $userId)->willReturn($houseUser);
		$this->roomMapper->method('update')->willReturn($updatedRoom);
		$this->gameLogicService->method('handleTaskCompletion')->willReturn([
			'contributionScore' => 10,
			'coins' => 50,
			'isCombo' => false,
			'currentStreak' => 1,
		]);

		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->completedTaskMapper->method('countByRoomTaskAndUserOnDate')->willReturn(0);

		$this->notificationService->expects($this->never())->method('notifyOverdueTakenOver');

		$this->service->completeRoomTask($roomTaskId, $userId);
	}

	/**
	 * CompleteRoomTask_GoalAchievedFirstClaim_DispatchesHouseNotification
	 *
	 * When a completion pushes the weekly household goal to "achieved" and
	 * this caller is the first to claim dispatch for the week (the house row
	 * conditional UPDATE affects 1 row), the server notifies all house
	 * members (the Nextcloud bell + mobile feed).
	 */
	public function testCompleteRoomTask_GoalAchievedFirstClaim_DispatchesHouseNotification(): void {
		$roomTaskId = 1;
		$userId = 'user123';

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

		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(1);

		$houseUser = new HouseUser();
		$houseUser->setId(2);

		$updatedRoom = new Room();
		$updatedRoom->setId(1);
		$updatedRoom->setCleanliness(80);

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->roomTaskMapper->method('lockForUpdate')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);
		$this->houseUserMapper->method('findByHouseAndUser')->with(1, $userId)->willReturn($houseUser);
		$this->roomMapper->method('update')->willReturn($updatedRoom);
		$this->gameLogicService->method('handleTaskCompletion')->willReturn([
			'contributionScore' => 10,
			'coins' => 50,
			'isCombo' => false,
			'currentStreak' => 1,
		]);

		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->completedTaskMapper->method('countByRoomTaskAndUserOnDate')->willReturn(0);

		// Post-commit snapshot: achieved.
		$this->weeklyGoalSequence = [
			[
				'dueCount' => 5,
				'completedCount' => 4,
				'percent' => 80,
				'targetPercent' => 80,
				'achieved' => true,
				'weekStart' => '2026-07-14T00:00:00+00:00',
				'weekEnd' => '2026-07-20T23:59:59+00:00',
			],
		];

		// This caller is the first to claim dispatch for the week.
		$this->houseMapper->expects($this->once())
			->method('claimWeeklyGoalNotification')
			->with(1, '2026-07-14')
			->willReturn(true);

		$this->notificationService->expects($this->once())
			->method('notifyWeeklyGoalReachedForHouse')
			->with(1, 80, 80, '2026-07-14T00:00:00+00:00');

		$this->service->completeRoomTask($roomTaskId, $userId);
	}

	/**
	 * CompleteRoomTask_GoalAlreadyClaimed_NoDispatch
	 *
	 * A second concurrent completion in a week where the goal is already
	 * achieved AND already claimed: the conditional UPDATE affects 0 rows,
	 * so the dispatch is suppressed (no duplicate notification per house
	 * per week).
	 */
	public function testCompleteRoomTask_GoalAlreadyClaimed_NoDispatch(): void {
		$roomTaskId = 1;
		$userId = 'user123';

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

		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(1);

		$houseUser = new HouseUser();
		$houseUser->setId(2);

		$updatedRoom = new Room();
		$updatedRoom->setId(1);
		$updatedRoom->setCleanliness(80);

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->roomTaskMapper->method('lockForUpdate')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);
		$this->houseUserMapper->method('findByHouseAndUser')->with(1, $userId)->willReturn($houseUser);
		$this->roomMapper->method('update')->willReturn($updatedRoom);
		$this->gameLogicService->method('handleTaskCompletion')->willReturn([
			'contributionScore' => 10,
			'coins' => 50,
			'isCombo' => false,
			'currentStreak' => 1,
		]);

		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->completedTaskMapper->method('countByRoomTaskAndUserOnDate')->willReturn(0);

		// Post-commit snapshot: achieved.
		$this->weeklyGoalSequence = [
			[
				'dueCount' => 5,
				'completedCount' => 5,
				'percent' => 100,
				'targetPercent' => 80,
				'achieved' => true,
				'weekStart' => '2026-07-14T00:00:00+00:00',
				'weekEnd' => '2026-07-20T23:59:59+00:00',
			],
		];

		// Another caller already claimed dispatch.
		$this->houseMapper->expects($this->once())
			->method('claimWeeklyGoalNotification')
			->willReturn(false);

		$this->notificationService->expects($this->never())->method('notifyWeeklyGoalReachedForHouse');

		$this->service->completeRoomTask($roomTaskId, $userId);
	}

	/**
	 * CompleteRoomTask_UnknownUser_ThrowsForbidden
	 *
	 * CR-SVC-10: a user with no house membership must get a clean
	 * ForbiddenException (403), not an uncaught DoesNotExistException from
	 * the throwing lookup (500).
	 */
	public function testCompleteRoomTask_UnknownUser_ThrowsForbidden(): void {
		$roomTaskId = 1;
		$stranger = 'stranger-user';

		$task = new Task();
		$task->setId(1);

		$room = new Room();
		$room->setId(1);
		$room->setFloorId(1);

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setTaskId(1);
		$roomTask->setRoomId(1);

		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(1);

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);

		// No house_users row for the completer.
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn(null);

		$this->roomTaskMapper->expects($this->never())->method('lockForUpdate');

		$this->expectException(ForbiddenException::class);
		$this->service->completeRoomTask($roomTaskId, $stranger);
	}

	/**
	 * CompleteRoomTask_MemberRemovedBeforeLock_ThrowsForbidden
	 *
	 * CR-SVC-10 regression: a member removed between the pre-transaction
	 * fetch and the in-transaction re-check (membership disappeared under
	 * the lock) previously crashed on the stale entity → 500. Must throw a
	 * clean ForbiddenException with no partial writes.
	 */
	public function testCompleteRoomTask_MemberRemovedBeforeLock_ThrowsForbidden(): void {
		$roomTaskId = 1;
		$userId = 'user123';

		$task = new Task();
		$task->setId(1);
		$task->setEnergyCost(3);

		$room = new Room();
		$room->setId(1);
		$room->setFloorId(1);

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setTaskId(1);
		$roomTask->setRoomId(1);

		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(1);

		$houseUser = new HouseUser();
		$houseUser->setId(2);

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->roomTaskMapper->method('lockForUpdate')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);

		// Pre-transaction resolution succeeds, the in-transaction re-check
		// finds the membership deleted.
		$this->houseUserMapper->method('findByHouseAndUser')
			->willReturnOnConsecutiveCalls($houseUser, null);

		// No completion may be persisted once membership is gone.
		$this->roomTaskMapper->expects($this->never())->method('update');
		$this->roomMapper->expects($this->never())->method('update');
		$this->gameLogicService->expects($this->never())->method('handleTaskCompletion');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Only house members can complete tasks');
		$this->service->completeRoomTask($roomTaskId, $userId);
	}
}
