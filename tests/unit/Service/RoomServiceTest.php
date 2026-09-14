<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\RoomService;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomService
 *
 * The completion-path tests moved to CompletionServiceTest
 * (CS-PLAN-064 WP5, decision D2 — completeRoomTask was extracted).
 */
class RoomServiceTest extends TestCase {
	private RoomService $service;
	private RoomMapper $roomMapper;
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private FloorMapper $floorMapper;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private RoomTaskChecklistProgressMapper $checklistProgressMapper;
	private IDBConnection $db;
	private AppLogger $appLogger;
	private AuthorizationService $authorization;

	protected function setUp(): void {
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->floorMapper = $this->createMock(FloorMapper::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->checklistProgressMapper = $this->createMock(RoomTaskChecklistProgressMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->appLogger = $this->createMock(AppLogger::class);
		$this->authorization = $this->createMock(AuthorizationService::class);

		$this->service = new RoomService(
			$this->roomMapper,
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->floorMapper,
			$this->taskAssigneeMapper,
			$this->completedTaskMapper,
			$this->checklistProgressMapper,
			new \OCA\ChoreStrike\Db\TransactionExecutor($this->db),
			$this->createIdentityL10n(),
			$this->appLogger,
			$this->authorization
		);
	}

	private function createIdentityL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		return $l10n;
	}

	/**
	 * CreateRoom_ValidData_CreatesRoom
	 */
	public function testCreateRoom_ValidData_CreatesRoom(): void {
		$floorId = 1;
		$name = 'New Room';
		$icon = 'Bath';

		$floor = new Floor();
		$floor->setId($floorId);
		$floor->setHouseId(1);

		$room = new Room();
		$room->setId(1);
		$room->setName($name);
		$room->setIcon($icon);

		// CS-PLAN-061 §3.4: the floor is resolved here and the caller must
		// own the floor's house.
		$this->floorMapper
			->expects($this->once())
			->method('find')
			->with($floorId)
			->willReturn($floor);

		$this->authorization
			->expects($this->once())
			->method('requireHouseOwner')
			->with(1);

		$this->roomMapper
			->expects($this->once())
			->method('create')
			->with($floorId, $name, $icon, 'generic')
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
		$room->setFloorId(7);
		$room->setName('Old Name');

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(1);

		$updatedRoom = new Room();
		$updatedRoom->setId($roomId);
		$updatedRoom->setName($newName);

		$this->roomMapper
			->expects($this->once())
			->method('find')
			->with($roomId)
			->willReturn($room);

		$this->floorMapper
			->expects($this->once())
			->method('find')
			->with(7)
			->willReturn($floor);

		// CS-PLAN-061 §3.4: structure mutation is owner-only in the service.
		$this->authorization
			->expects($this->once())
			->method('requireHouseOwner')
			->with(1);

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
		$room->setFloorId(7);

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(1);

		$this->roomMapper
			->expects($this->once())
			->method('find')
			->with($roomId)
			->willReturn($room);

		$this->floorMapper
			->expects($this->once())
			->method('find')
			->with(7)
			->willReturn($floor);

		$this->authorization
			->expects($this->once())
			->method('requireHouseOwner')
			->with(1);

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
		$room->setFloorId(7);

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(1);

		$this->roomMapper->method('find')->willReturn($room);
		$this->floorMapper->method('find')->willReturn($floor);
		$this->roomTaskMapper->method('findIdsByRoomId')->willReturn([10]);
		$this->taskAssigneeMapper->method('deleteByRoomTasks')
			->willThrowException(new \RuntimeException('db down'));

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->roomMapper->expects($this->never())->method('delete');
		$this->roomTaskMapper->expects($this->never())->method('deleteByRoomId');

		// CS-PLAN-052: the rollback boundary must log the failure before
		// rethrowing (stable event + service context keys).
		$this->appLogger->expects($this->once())
			->method('logException')
			->with(
				'RoomService: room delete rolled back',
				$this->callback(fn (\Throwable $e) => $e->getMessage() === 'db down'),
				$this->callback(function (array $context): bool {
					return $context['event'] === 'room_delete_rollback'
						&& $context['service'] === 'RoomService'
						&& $context['roomId'] === 1;
				})
			);

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
		$room->setFloorId(7);
		$room->setCleanliness(50);

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(1);

		$updatedRoom = new Room();
		$updatedRoom->setId($roomId);
		$updatedRoom->setCleanliness(70);

		// RF-058-06: the service re-validates the room→floor→house chain
		// before the write.
		$this->roomMapper
			->expects($this->exactly(2))
			->method('find')
			->with($roomId)
			->willReturn($room);

		$this->floorMapper
			->method('find')
			->with(7)
			->willReturn($floor);

		$this->roomMapper
			->expects($this->once())
			->method('update')
			->willReturn($updatedRoom);

		$result = $this->service->updateRoomCleanliness($roomId, 1, $cleanlinessChange);

		$this->assertSame($updatedRoom, $result);
	}

	/**
	 * UpdateRoomCleanliness_ForeignHouseRoom_ThrowsNotFoundWithoutWrite (RF-058-06)
	 */
	public function testUpdateRoomCleanliness_ForeignHouseRoom_ThrowsNotFoundWithoutWrite(): void {
		$room = new Room();
		$room->setId(1);
		$room->setFloorId(7);

		$foreignFloor = new Floor();
		$foreignFloor->setId(7);
		$foreignFloor->setHouseId(2);

		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(7)->willReturn($foreignFloor);

		$this->roomMapper->expects($this->never())->method('update');

		$this->expectException(\OCA\ChoreStrike\Exception\NotFoundException::class);
		$this->service->updateRoomCleanliness(1, 1, 20);
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
}
