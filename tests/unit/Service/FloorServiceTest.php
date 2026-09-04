<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\FloorService;
use OCA\ChoreStrike\Service\RoomService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FloorService
 */
class FloorServiceTest extends TestCase {
	private FloorService $service;
	private FloorMapper $floorMapper;
	private RoomMapper $roomMapper;
	private RoomTaskMapper $roomTaskMapper;
	private RoomService $roomService;
	private IDBConnection $db;
	private AppLogger $appLogger;
	private AuthorizationService $authorization;

	protected function setUp(): void {
		$this->floorMapper = $this->createMock(FloorMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->roomService = $this->createMock(RoomService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->appLogger = $this->createMock(AppLogger::class);
		$this->authorization = $this->createMock(AuthorizationService::class);
		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->service = new FloorService(
			$this->floorMapper,
			$this->roomMapper,
			$this->roomTaskMapper,
			$this->roomService,
			new \OCA\ChoreStrike\Db\TransactionExecutor($this->db),
			$l10n,
			$this->appLogger,
			$this->authorization
		);
	}

	/**
	 * CreateFloor_WithSortOrder_CreatesFloor
	 */
	public function testCreateFloor_WithSortOrder_CreatesFloor(): void {
		$houseId = 1;
		$name = 'New Floor';
		$sortOrder = 2;

		$floor = new Floor();
		$floor->setId(1);
		$floor->setName($name);

		$this->floorMapper
			->expects($this->once())
			->method('create')
			->with($houseId, $name, $sortOrder)
			->willReturn($floor);

		$result = $this->service->createFloor($houseId, $name, $sortOrder);

		$this->assertSame($floor, $result);
	}

	/**
	 * CreateFloor_WithoutSortOrder_AutoCalculatesOrder
	 */
	public function testCreateFloor_WithoutSortOrder_AutoCalculatesOrder(): void {
		$houseId = 1;
		$name = 'New Floor';

		$existingFloor = new Floor();
		$existingFloor->setId(1);

		$newFloor = new Floor();
		$newFloor->setId(2);
		$newFloor->setName($name);

		$this->floorMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$existingFloor]);

		$this->floorMapper
			->expects($this->once())
			->method('create')
			->with($houseId, $name, 1) // Auto-calculated: count = 1
			->willReturn($newFloor);

		$result = $this->service->createFloor($houseId, $name);

		$this->assertSame($newFloor, $result);
	}

	/**
	 * CreateFloor_NonOwner_ThrowsForbiddenWithoutCreating
	 *
	 * CS-PLAN-061 §3.4: structure mutation is owner-only in the service;
	 * the gate runs before any floor lookup or write.
	 */
	public function testCreateFloor_NonOwner_ThrowsForbiddenWithoutCreating(): void {
		$this->authorization->expects($this->once())
			->method('requireHouseOwner')
			->with(1)
			->willThrowException(new ForbiddenException('Owner role required'));

		$this->floorMapper->expects($this->never())->method('findByHouseId');
		$this->floorMapper->expects($this->never())->method('create');

		$this->expectException(ForbiddenException::class);
		$this->service->createFloor(1, 'New Floor');
	}

	/**
	 * UpdateFloorName_ValidName_ReturnsUpdatedFloor
	 */
	public function testUpdateFloorName_ValidName_ReturnsUpdatedFloor(): void {
		$floorId = 1;
		$newName = 'Updated Floor';

		$floor = new Floor();
		$floor->setId($floorId);
		$floor->setHouseId(1);
		$floor->setName('Old Name');

		$updatedFloor = new Floor();
		$updatedFloor->setId($floorId);
		$updatedFloor->setName($newName);

		$this->floorMapper
			->expects($this->once())
			->method('find')
			->with($floorId)
			->willReturn($floor);

		$this->floorMapper
			->expects($this->once())
			->method('update')
			->with($this->callback(function ($f) use ($newName) {
				return $f->getName() === $newName;
			}))
			->willReturn($updatedFloor);

		$result = $this->service->updateFloorName($floorId, $newName);

		$this->assertEquals($newName, $result->getName());
	}

	/**
	 * ValidateFloorOwnership_UnknownFloor_ThrowsNotFound
	 *
	 * CS-PLAN-061: the mapper's DoesNotExistException is wrapped into the
	 * domain NotFoundException('Floor not found') instead of leaking.
	 */
	public function testValidateFloorOwnership_UnknownFloor_ThrowsNotFound(): void {
		$this->floorMapper->method('find')->willThrowException(new DoesNotExistException('not found'));

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Floor not found');
		$this->service->validateFloorOwnership(99, 1);
	}

	/**
	 * ValidateFloorOwnership_FloorOfOtherHouse_ThrowsNotFound
	 */
	public function testValidateFloorOwnership_FloorOfOtherHouse_ThrowsNotFound(): void {
		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(2);
		$this->floorMapper->method('find')->willReturn($floor);

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Floor not found');
		$this->service->validateFloorOwnership(1, 1);
	}

	/**
	 * ReorderFloors_ValidOrder_BulkUpdatesAndBuildsPayloadWithoutReFetch
	 *
	 * CS-PLAN-037: one CASE-WHEN bulk UPDATE instead of per-floor
	 * updateSortOrder + find re-fetch; the payload is built from the
	 * already-fetched entities with the new sort_order set in PHP.
	 */
	public function testReorderFloors_ValidOrder_BulkUpdatesAndBuildsPayloadWithoutReFetch(): void {
		$houseId = 1;
		$floorOrder = [2, 1, 3];

		$floor1 = new Floor();
		$floor1->setId(1);
		$floor1->setSortOrder(0);

		$floor2 = new Floor();
		$floor2->setId(2);
		$floor2->setSortOrder(1);

		$floor3 = new Floor();
		$floor3->setId(3);
		$floor3->setSortOrder(2);

		$this->floorMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$floor1, $floor2, $floor3]);

		// ONE bulk call carrying the full id → order map.
		$this->floorMapper
			->expects($this->once())
			->method('updateSortOrders')
			->with([2 => 0, 1 => 1, 3 => 2]);

		// The payload path never re-fetches floors.
		$this->floorMapper->expects($this->never())->method('find');

		$result = $this->service->reorderFloors($houseId, $floorOrder);

		$this->assertCount(3, $result);
		// Payload is in the NEW order, entities carry the new sort_order.
		$this->assertSame($floor2, $result[0]);
		$this->assertSame(0, $result[0]->getSortOrder());
		$this->assertSame($floor1, $result[1]);
		$this->assertSame(1, $result[1]->getSortOrder());
		$this->assertSame($floor3, $result[2]);
		$this->assertSame(2, $result[2]->getSortOrder());
	}

	/**
	 * ReorderFloors_InvalidFloorId_ThrowsException
	 */
	public function testReorderFloors_InvalidFloorId_ThrowsException(): void {
		$houseId = 1;
		$floorOrder = [999]; // Invalid floor ID

		$floor = new Floor();
		$floor->setId(1);

		$this->floorMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$floor]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Floor ID 999 does not belong to house 1');

		$this->service->reorderFloors($houseId, $floorOrder);
	}

	/**
	 * DeleteFloor_WithRooms_CascadesDependentsInOneTransaction
	 *
	 * CS-PLAN-037 (D1/D2): the floor cascade lives in the service (the
	 * mapper no longer cross-instantiates RoomMapper) and is orphan-free:
	 * room-task dependents first, then room tasks, rooms, and the floor.
	 */
	public function testDeleteFloor_WithRooms_CascadesDependentsInOneTransaction(): void {
		$floorId = 1;

		$floor = new Floor();
		$floor->setId($floorId);
		$floor->setHouseId(1);

		$room1 = new Room();
		$room1->setId(5);
		$room1->setFloorId($floorId);

		$room2 = new Room();
		$room2->setId(6);
		$room2->setFloorId($floorId);

		$roomTaskIds = [10, 11];

		$this->floorMapper
			->expects($this->once())
			->method('find')
			->with($floorId)
			->willReturn($floor);

		$this->roomMapper
			->expects($this->once())
			->method('findByFloorId')
			->with($floorId)
			->willReturn([$room1, $room2]);

		$this->roomTaskMapper
			->expects($this->once())
			->method('findIdsByRoomIds')
			->with([5, 6])
			->willReturn($roomTaskIds);

		$this->roomService
			->expects($this->once())
			->method('deleteRoomTaskDependents')
			->with($roomTaskIds);

		$this->roomTaskMapper
			->expects($this->once())
			->method('deleteByRoomIds')
			->with([5, 6]);

		$this->roomMapper
			->expects($this->once())
			->method('deleteByFloorId')
			->with($floorId);

		$this->floorMapper
			->expects($this->once())
			->method('delete')
			->with($floor);

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$this->service->deleteFloor($floorId);
	}

	/**
	 * DeleteFloor_CascadeFailure_RollsBackAndReThrows
	 */
	public function testDeleteFloor_CascadeFailure_RollsBackAndReThrows(): void {
		$floorId = 1;

		$floor = new Floor();
		$floor->setId($floorId);
		$floor->setHouseId(1);

		$room = new Room();
		$room->setId(5);
		$room->setFloorId($floorId);

		$this->floorMapper->method('find')->willReturn($floor);
		$this->roomMapper->method('findByFloorId')->willReturn([$room]);
		$this->roomTaskMapper->method('findIdsByRoomIds')->willReturn([10]);
		$this->roomTaskMapper->method('deleteByRoomIds')
			->willThrowException(new \RuntimeException('db down'));

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->roomMapper->expects($this->never())->method('deleteByFloorId');
		$this->floorMapper->expects($this->never())->method('delete');

		// CS-PLAN-052: the rollback boundary must log the failure before
		// rethrowing (stable event + service context keys).
		$this->appLogger->expects($this->once())
			->method('logException')
			->with(
				'FloorService: floor cascade rolled back',
				$this->callback(fn (\Throwable $e) => $e->getMessage() === 'db down'),
				$this->callback(function (array $context): bool {
					return $context['event'] === 'floor_delete_rollback'
						&& $context['service'] === 'FloorService'
						&& $context['floorId'] === 1;
				})
			);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('db down');

		$this->service->deleteFloor($floorId);
	}

	/**
	 * GetFloorsWithRooms_ReturnsAllFloorsWithRooms
	 *
	 * CS-PLAN-036: rooms for all floors come from ONE house-scoped fetch
	 * grouped by floor_id, instead of 2 queries per floor via
	 * getFloorWithRooms.
	 */
	public function testGetFloorsWithRooms_ReturnsAllFloorsWithRooms(): void {
		$houseId = 1;

		$floor1 = new Floor();
		$floor1->setId(1);
		$floor1->setName('Ground Floor');

		$floor2 = new Floor();
		$floor2->setId(2);
		$floor2->setName('First Floor');

		$room = new Room();
		$room->setId(1);
		$room->setFloorId(1);
		$room->setCleanliness(100);

		$this->floorMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$floor1, $floor2]);

		// One batch fetch; rooms are grouped onto their floor in PHP.
		$this->roomMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$room]);

		// The plural path never re-fetches floors or per-floor rooms.
		$this->floorMapper->expects($this->never())->method('find');
		$this->roomMapper->expects($this->never())->method('findByFloorId');

		$result = $this->service->getFloorsWithRooms($houseId);

		$this->assertCount(2, $result);
		$this->assertEquals('Ground Floor', $result[0]['floor']->getName());
		$this->assertEquals(100, $result[0]['stats']['averageCleanliness']);
		$this->assertEquals(100, $result[1]['stats']['averageCleanliness']); // Empty floor = 100
	}
}
