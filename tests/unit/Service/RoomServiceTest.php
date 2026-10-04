<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CommandService;
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
	private \OCA\ChoreStrike\Db\HouseMapper $houseMapper;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private RoomTaskChecklistProgressMapper $checklistProgressMapper;
	private IDBConnection $db;
	private AppLogger $appLogger;
	private AuthorizationService $authorization;
	private \OCA\ChoreStrike\Service\RoomReplayService $roomReplayService;
	private CommandService $commandService;

	protected function setUp(): void {
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->floorMapper = $this->createMock(FloorMapper::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->checklistProgressMapper = $this->createMock(RoomTaskChecklistProgressMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->appLogger = $this->createMock(AppLogger::class);
		$this->authorization = $this->createMock(AuthorizationService::class);
		$actor = new HouseUser();
		$actor->setId(3);
		$this->authorization->method('assertHouseMember')->willReturn($actor);
		$this->commandService = $this->createMock(CommandService::class);
		$this->commandService->method('runCommand')->willReturnCallback(static function (int $houseId, int $actorId, string $uuid, string $action, array $payload, callable $fn): array {
			return ['result' => $fn(), 'replay' => false];
		});

		// CS-PLAN-071 WP0: structure mutations enter through the house row
		// lock (findForUpdate) — a stub is enough for these unit tests.
		$this->houseMapper = $this->createMock(\OCA\ChoreStrike\Db\HouseMapper::class);
		$this->houseMapper->method('findForUpdate')->willReturn(new \OCA\ChoreStrike\Db\House());

		// WP3 §3.5: room creations journal a baseline; the replay service
		// is mocked (no-op) except where a test asserts the journal seam.
		$this->roomReplayService = $this->createMock(\OCA\ChoreStrike\Service\RoomReplayService::class);

		$this->service = new RoomService(
			$this->roomMapper,
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->floorMapper,
			$this->houseMapper,
			$this->taskAssigneeMapper,
			$this->checklistProgressMapper,
			new \OCA\ChoreStrike\Db\TransactionExecutor($this->db),
			$this->roomReplayService,
			$this->commandService,
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

	/** @dataProvider ownerMutationProvider */
	public function testOwnerMutation_DemotedUnderLock_DeniesWithoutWriteOrAudit(string $method, array $args): void {
		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(1);
		$room = new Room();
		$room->setId(1);
		$room->setFloorId(1);
		$this->floorMapper->method('find')->willReturn($floor);
		$this->roomMapper->method('find')->willReturn($room);
		$checks = 0;
		$this->authorization->expects($this->exactly(2))->method('requireHouseOwner')->willReturnCallback(
			static function () use (&$checks): HouseUser {
				if (++$checks === 2) {
					throw new \OCA\ChoreStrike\Exception\ForbiddenException('Owner role required');
				}
				return new HouseUser();
			}
		);
		$this->roomMapper->expects($this->never())->method('create');
		$this->roomMapper->expects($this->never())->method('update');
		$this->roomTaskMapper->expects($this->never())->method('tombstoneByRoomIds');
		$this->appLogger->expects($this->never())->method('audit');
		$this->db->expects($this->once())->method('rollBack');
		$this->expectException(\OCA\ChoreStrike\Exception\ForbiddenException::class);
		$this->service->{$method}(...$args);
	}

	/** @dataProvider ownerMutationProvider */
	public function testOwnerMutation_AuditOccursOnlyAfterCommit(string $method, array $args): void {
		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(1);
		$room = new Room();
		$room->setId(1);
		$room->setFloorId(1);
		$this->floorMapper->method('find')->willReturn($floor);
		$this->roomMapper->method('find')->willReturn($room);
		$this->roomMapper->method('create')->willReturn($room);
		$this->roomMapper->method('update')->willReturn($room);
		$actor = new HouseUser();
		$actor->setId(7);
		$this->authorization->method('requireHouseOwner')->willReturn($actor);
		$committed = false;
		$this->db->method('commit')->willReturnCallback(static function () use (&$committed): void {
			$committed = true;
		});
		$this->appLogger->expects($this->once())->method('audit')->with($this->anything(),
			$this->callback(static function (array $context) use (&$committed): bool {
				return $committed && $context['houseId'] === 1 && $context['actorId'] === 7
					&& $context['roomId'] === 1 && !isset($context['name']);
			})
		);
		$this->service->{$method}(...$args);
	}

	public static function ownerMutationProvider(): array {
		return [
			'create' => ['createRoom', [1, 'Room']],
			'rename' => ['updateRoomName', [1, 'Renamed']],
			'delete' => ['deleteRoom', [1]],
		];
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
		// own the floor's house. CS-PLAN-071 review fix: the tombstone gate
		// re-reads the floor under the house lock (pre-read + locked read).
		$this->floorMapper
			->expects($this->exactly(2))
			->method('find')
			->with($floorId)
			->willReturn($floor);

		$this->authorization
			->expects($this->exactly(2))
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
	 * CreateRoom_TombstonedFloor_Yields404
	 *
	 * Q-70-01: no live planning state beneath deleted structure — a
	 * tombstoned floor cannot gain rooms.
	 */
	public function testCreateRoom_TombstonedFloor_Yields404(): void {
		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(1);
		$floor->setDeletedAt(new \DateTime());

		$this->floorMapper
			->expects($this->once())
			->method('find')
			->with(1)
			->willReturn($floor);
		$this->authorization->method('requireHouseOwner');
		$this->roomMapper->expects($this->never())->method('create');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Floor not found');

		$this->service->createRoom(1, 'Ghost Room');
	}

	/**
	 * UpdateRoomName_TombstonedRoom_Yields404
	 *
	 * Q-70-01: tombstoned structure is immutable evidence — renames
	 * answer 404 like a missing room.
	 */
	public function testUpdateRoomName_TombstonedRoom_Yields404(): void {
		$room = new Room();
		$room->setId(1);
		$room->setFloorId(7);
		$room->setDeletedAt(new \DateTime());

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(1);

		$this->roomMapper->method('find')->willReturn($room);
		$this->floorMapper->method('find')->willReturn($floor);
		$this->authorization->method('requireHouseOwner');
		$this->roomMapper->expects($this->never())->method('update');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Room not found');

		$this->service->updateRoomName(1, 'Renamed After Death');
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
			->expects($this->exactly(2))
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
			->expects($this->exactly(2))
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
	 * DeleteRoom_WithTasks_TombstonesInOneTransaction
	 *
	 * CS-PLAN-071 WP0 (Q-70-01): room deletion TOMBSTONES instead of
	 * hard-deleting — planning state (checklist progress → assignees) is
	 * deleted, room tasks and the room itself are tombstoned, and the
	 * completed_tasks evidence is RETAINED.
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

		// CS-PLAN-071 review fix: the tombstone gate re-reads the room
		// under the house lock (pre-read + locked read).
		$this->roomMapper
			->expects($this->exactly(2))
			->method('find')
			->with($roomId)
			->willReturn($room);

		$this->floorMapper
			->expects($this->once())
			->method('find')
			->with(7)
			->willReturn($floor);

		$this->authorization
			->expects($this->exactly(2))
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

		$this->roomTaskMapper
			->expects($this->once())
			->method('tombstoneByRoomIds')
			->with([$roomId], $this->isInstanceOf(\DateTime::class));

		$this->roomMapper
			->expects($this->once())
			->method('update')
			->with($this->callback(static fn (Room $r): bool => $r->getDeletedAt() !== null));

		// The completed_tasks evidence is never deleted (Q-70-01) and the
		// structural rows are never hard-deleted.
		$this->roomTaskMapper->expects($this->never())->method('delete');
		$this->roomMapper->expects($this->never())->method('delete');

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
		$this->roomMapper->expects($this->never())->method('update');
		$this->roomTaskMapper->expects($this->never())->method('tombstoneByRoomIds');

		// CS-PLAN-052: the rollback boundary must log the failure before
		// rethrowing (stable event + service context keys).
		$this->appLogger->expects($this->once())
			->method('logException')
			->with(
				'RoomService: room tombstone rolled back',
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
	 * CreateRoom_FloorTombstonedBehindPreRead_Yields404UnderLock
	 *
	 * CS-PLAN-071 review fix (TOCTOU): the authoritative tombstone gate is
	 * the FRESH read under the house lock — a floor deleted behind the
	 * pre-read still cannot gain rooms, with zero writes.
	 */
	public function testCreateRoom_FloorTombstonedBehindPreRead_Yields404UnderLock(): void {
		$liveFloor = new Floor();
		$liveFloor->setId(1);
		$liveFloor->setHouseId(1);

		$tombstonedFloor = new Floor();
		$tombstonedFloor->setId(1);
		$tombstonedFloor->setHouseId(1);
		$tombstonedFloor->setDeletedAt(new \DateTime());

		$this->floorMapper
			->expects($this->exactly(2))
			->method('find')
			->with(1)
			->willReturn($liveFloor, $tombstonedFloor);
		$this->authorization->method('requireHouseOwner');
		$this->roomMapper->expects($this->never())->method('create');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Floor not found');

		$this->service->createRoom(1, 'Race Room');
	}

	/**
	 * UpdateRoomName_RoomTombstonedBehindPreRead_Yields404UnderLock
	 *
	 * CS-PLAN-071 review fix (TOCTOU): a room deleted behind the pre-read
	 * is immutable evidence — the locked re-read rejects the rename with
	 * zero writes.
	 */
	public function testUpdateRoomName_RoomTombstonedBehindPreRead_Yields404UnderLock(): void {
		$liveRoom = new Room();
		$liveRoom->setId(1);
		$liveRoom->setFloorId(7);

		$tombstonedRoom = new Room();
		$tombstonedRoom->setId(1);
		$tombstonedRoom->setFloorId(7);
		$tombstonedRoom->setDeletedAt(new \DateTime());

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(1);

		$this->roomMapper
			->expects($this->exactly(2))
			->method('find')
			->with(1)
			->willReturn($liveRoom, $tombstonedRoom);
		$this->floorMapper->method('find')->willReturn($floor);
		$this->authorization->method('requireHouseOwner');
		$this->roomMapper->expects($this->never())->method('update');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Room not found');

		$this->service->updateRoomName(1, 'Race Rename');
	}

	/**
	 * DeleteRoom_RoomTombstonedBehindPreRead_Yields404ZeroWrites
	 *
	 * CS-PLAN-071 review fix (TOCTOU): a concurrent delete winner makes
	 * the second delete a clean 404 under the house lock — the original
	 * tombstone evidence (deleted_at) is never re-stamped.
	 */
	public function testDeleteRoom_RoomTombstonedBehindPreRead_Yields404ZeroWrites(): void {
		$liveRoom = new Room();
		$liveRoom->setId(1);
		$liveRoom->setFloorId(7);

		$tombstonedRoom = new Room();
		$tombstonedRoom->setId(1);
		$tombstonedRoom->setFloorId(7);
		$tombstonedRoom->setDeletedAt(new \DateTime('2026-09-18T10:00:00Z'));

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(1);

		$this->roomMapper
			->expects($this->exactly(2))
			->method('find')
			->with(1)
			->willReturn($liveRoom, $tombstonedRoom);
		$this->floorMapper->method('find')->willReturn($floor);
		$this->authorization->method('requireHouseOwner');
		$this->roomTaskMapper->expects($this->never())->method('findIdsByRoomId');
		$this->roomTaskMapper->expects($this->never())->method('tombstoneByRoomIds');
		$this->roomMapper->expects($this->never())->method('update');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Room not found');

		$this->service->deleteRoom(1);
	}

	/**
	 * DeleteRoom_TombstonedRoom_Yields404BeforeTransaction
	 *
	 * CS-PLAN-071 review fix: a repeat delete of a tombstoned room answers
	 * 404 like a missing room — the original deletion instant survives.
	 */
	public function testDeleteRoom_TombstonedRoom_Yields404BeforeTransaction(): void {
		$room = new Room();
		$room->setId(1);
		$room->setFloorId(7);
		$room->setDeletedAt(new \DateTime());

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(1);

		$this->roomMapper->method('find')->willReturn($room);
		$this->floorMapper->method('find')->willReturn($floor);
		$this->authorization->method('requireHouseOwner');
		$this->roomTaskMapper->expects($this->never())->method('tombstoneByRoomIds');
		$this->roomMapper->expects($this->never())->method('update');
		$this->db->expects($this->never())->method('beginTransaction');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Room not found');

		$this->service->deleteRoom(1);
	}

	/**
	 * UpdateRoomCleanliness_RecordsRequestedDeltaAndRebuildsCache
	 *
	 * CS-PLAN-070 WP3 (§3.5, CR-SVC-63 closure): the manual path journals
	 * the REQUESTED delta through the room journal (idempotent through the
	 * command UUID) and rebuilds the room cache by the chronological
	 * replay — house lock, journal write and cache update are ONE
	 * transaction; the house state version bumps.
	 */
	public function testUpdateRoomCleanliness_RecordsRequestedDeltaAndRebuildsCache(): void {
		$roomId = 1;
		$cleanlinessChange = 20;

		$house = new \OCA\ChoreStrike\Db\House();
		$house->setId(1);
		$house->setStateVersion(4);
		$this->houseMapper = $this->createMock(\OCA\ChoreStrike\Db\HouseMapper::class);
		$this->houseMapper->method('findForUpdate')->willReturn($house);
		$this->houseMapper->method('find')->willReturn($house);
		$this->rebuildService();

		$room = new Room();
		$room->setId($roomId);
		$room->setFloorId(7);
		$room->setCleanliness(50);

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(1);

		$replayed = new Room();
		$replayed->setId($roomId);
		$replayed->setFloorId(7);
		$replayed->setCleanliness(70);

		// RF-058-06: the service re-validates the room→floor→house chain
		// before the write.
		$this->roomMapper
			->expects($this->exactly(4))
			->method('find')
			->with($roomId)
			->willReturn($room, $room, $replayed, $replayed);

		$this->floorMapper
			->method('find')
			->with(7)
			->willReturn($floor);

		// CS-PLAN-071 WP0: the room row is locked inside the transaction.
		$this->roomMapper
			->expects($this->once())
			->method('lockForUpdate')
			->with($roomId)
			->willReturn($room);

		$this->roomReplayService
			->expects($this->once())
			->method('recordManualDelta')
			->with($roomId, 20, 'cmd-manual-1', $this->isInstanceOf(\DateTime::class));
		$this->roomReplayService
			->expects($this->once())
			->method('resyncRoomCache')
			->with($room)
			->willReturn($replayed);

		$this->houseMapper
			->expects($this->once())
			->method('update')
			->with($this->callback(static function ($h) {
				return $h->getStateVersion() === 5;
			}))
			->willReturnArgument(0);

		// The replay service owns the room write — RoomService never
		// writes the room row directly.
		$this->roomMapper->expects($this->never())->method('update');
		$this->commandService->expects($this->once())->method('runCommand')
			->with(1, 3, 'cmd-manual-1', 'room.manual_delta', [
				'roomId' => 1, 'cleanlinessChange' => 20, 'expectedVersion' => 4,
			], $this->isType('callable'));

		$result = $this->service->updateRoomCleanliness($roomId, 1, $cleanlinessChange, 'cmd-manual-1', 4);

		$this->assertSame($replayed, $result);
	}

	/**
	 * UpdateRoomCleanliness_StaleExpectedVersion_ThrowsConflict
	 *
	 * §3.6: `expectedVersion` guards the delta against a house state the
	 * caller has not seen (stale → 409, nothing journaled).
	 */
	public function testUpdateRoomCleanliness_StaleExpectedVersion_ThrowsConflict(): void {
		$house = new \OCA\ChoreStrike\Db\House();
		$house->setId(1);
		$house->setStateVersion(9);
		$this->houseMapper = $this->createMock(\OCA\ChoreStrike\Db\HouseMapper::class);
		$this->houseMapper->method('findForUpdate')->willReturn($house);
		$this->houseMapper->method('find')->willReturn($house);
		$this->rebuildService();

		$room = new Room();
		$room->setId(1);
		$room->setFloorId(7);

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(1);

		$this->roomMapper->method('find')->willReturn($room);
		$this->floorMapper->method('find')->willReturn($floor);

		$this->roomReplayService->expects($this->never())->method('recordManualDelta');
		$this->roomMapper->expects($this->never())->method('lockForUpdate');

		$this->expectException(\OCA\ChoreStrike\Exception\ConflictException::class);
		$this->service->updateRoomCleanliness(1, 1, 20, 'cmd-manual-stale', 8);
	}

	public function testUpdateRoomCleanliness_MemberRemovedBeforeHouseLock_DeniedWithoutJournal(): void {
		$room = new Room();
		$room->setId(1);
		$room->setFloorId(7);
		$floor = new Floor();
		$floor->setHouseId(1);
		$this->roomMapper->method('find')->willReturn($room);
		$this->floorMapper->method('find')->willReturn($floor);

		$checks = 0;
		$actor = new HouseUser();
		$actor->setId(3);
		$this->authorization->method('assertHouseMember')->willReturnCallback(
			static function () use ($actor, &$checks): HouseUser {
				if (++$checks > 1) {
					throw new \OCA\ChoreStrike\Exception\ForbiddenException('Not a member');
				}
				return $actor;
			}
		);
		$this->roomReplayService->expects($this->never())->method('recordManualDelta');
		$this->roomMapper->expects($this->never())->method('lockForUpdate');

		$this->expectException(\OCA\ChoreStrike\Exception\ForbiddenException::class);
		$this->service->updateRoomCleanliness(1, 1, 10, 'cmd-removed');
	}

	/**
	 * UpdateRoomCleanliness_OutOfBoundsDelta_RejectedBeforeTransaction
	 */
	public function testUpdateRoomCleanliness_OutOfBoundsDelta_RejectedBeforeTransaction(): void {
		$room = new Room();
		$room->setId(1);
		$room->setFloorId(7);

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(1);

		$this->roomMapper->method('find')->willReturn($room);
		$this->floorMapper->method('find')->willReturn($floor);

		$this->db->expects($this->never())->method('beginTransaction');
		$this->roomReplayService->expects($this->never())->method('recordManualDelta');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->updateRoomCleanliness(1, 1, 101, 'cmd-manual-101');
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
		$this->roomReplayService->expects($this->never())->method('recordManualDelta');

		$this->expectException(NotFoundException::class);
		$this->service->updateRoomCleanliness(1, 1, 20, 'cmd-manual-1');
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

	private function rebuildService(): void {
		$this->service = new RoomService(
			$this->roomMapper,
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->floorMapper,
			$this->houseMapper,
			$this->taskAssigneeMapper,
			$this->checklistProgressMapper,
			new \OCA\ChoreStrike\Db\TransactionExecutor($this->db),
			$this->roomReplayService,
			$this->commandService,
			$this->createIdentityL10n(),
			$this->appLogger,
			$this->authorization
		);
	}
}
