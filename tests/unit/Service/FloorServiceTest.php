<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Service\FloorService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FloorService
 */
class FloorServiceTest extends TestCase {
	private FloorService $service;
	private FloorMapper $floorMapper;
	private RoomMapper $roomMapper;

	protected function setUp(): void {
		$this->floorMapper = $this->createMock(FloorMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);

		$this->service = new FloorService(
			$this->floorMapper,
			$this->roomMapper
		);
	}

	/**
	 * GetFloors_ExistingHouse_ReturnsFloors
	 */
	public function testGetFloors_ExistingHouse_ReturnsFloors(): void {
		$houseId = 1;

		$floor1 = new Floor();
		$floor1->setId(1);
		$floor1->setName('Ground Floor');

		$floor2 = new Floor();
		$floor2->setId(2);
		$floor2->setName('First Floor');

		$this->floorMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$floor1, $floor2]);

		$result = $this->service->getFloors($houseId);

		$this->assertCount(2, $result);
		$this->assertEquals('Ground Floor', $result[0]->getName());
		$this->assertEquals('First Floor', $result[1]->getName());
	}

	/**
	 * GetFloor_ExistingId_ReturnsFloor
	 */
	public function testGetFloor_ExistingId_ReturnsFloor(): void {
		$floorId = 1;

		$floor = new Floor();
		$floor->setId($floorId);
		$floor->setName('Test Floor');

		$this->floorMapper
			->expects($this->once())
			->method('find')
			->with($floorId)
			->willReturn($floor);

		$result = $this->service->getFloor($floorId);

		$this->assertSame($floor, $result);
	}

	/**
	 * GetFloor_NonExistingId_ThrowsException
	 */
	public function testGetFloor_NonExistingId_ThrowsException(): void {
		$floorId = 999;

		$this->floorMapper
			->expects($this->once())
			->method('find')
			->with($floorId)
			->willThrowException(new DoesNotExistException('Floor not found'));

		$this->expectException(DoesNotExistException::class);

		$this->service->getFloor($floorId);
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
	 * UpdateFloorName_ValidName_ReturnsUpdatedFloor
	 */
	public function testUpdateFloorName_ValidName_ReturnsUpdatedFloor(): void {
		$floorId = 1;
		$newName = 'Updated Floor';

		$floor = new Floor();
		$floor->setId($floorId);
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
	 * ReorderFloors_ValidOrder_UpdatesSortOrder
	 */
	public function testReorderFloors_ValidOrder_UpdatesSortOrder(): void {
		$houseId = 1;
		$floorOrder = [2, 1, 3];

		$floor1 = new Floor();
		$floor1->setId(1);

		$floor2 = new Floor();
		$floor2->setId(2);

		$floor3 = new Floor();
		$floor3->setId(3);

		$this->floorMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$floor1, $floor2, $floor3]);

		$this->floorMapper
			->expects($this->exactly(3))
			->method('updateSortOrder')
			->withConsecutive([2, 0], [1, 1], [3, 2]);

		$this->floorMapper
			->method('find')
			->willReturnOnConsecutiveCalls($floor2, $floor1, $floor3);

		$result = $this->service->reorderFloors($houseId, $floorOrder);

		$this->assertCount(3, $result);
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
	 * DeleteFloor_WithRooms_DeletesFloorAndRooms
	 */
	public function testDeleteFloor_WithRooms_DeletesFloorAndRooms(): void {
		$floorId = 1;

		$floor = new Floor();
		$floor->setId($floorId);

		$this->floorMapper
			->expects($this->once())
			->method('find')
			->with($floorId)
			->willReturn($floor);

		$this->floorMapper
			->expects($this->once())
			->method('deleteWithRooms')
			->with($floorId);

		$this->service->deleteFloor($floorId);

		// Assertions verified by mock expectations
		$this->assertTrue(true);
	}

	/**
	 * GetFloorWithRooms_ReturnsFloorWithRoomsAndStats
	 */
	public function testGetFloorWithRooms_ReturnsFloorWithRoomsAndStats(): void {
		$floorId = 1;

		$floor = new Floor();
		$floor->setId($floorId);
		$floor->setName('Test Floor');

		$room1 = new Room();
		$room1->setId(1);
		$room1->setCleanliness(80);
		$room1->setMonsterCount(0);

		$room2 = new Room();
		$room2->setId(2);
		$room2->setCleanliness(40);
		$room2->setMonsterCount(2);

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

		$result = $this->service->getFloorWithRooms($floorId);

		$this->assertSame($floor, $result['floor']);
		$this->assertCount(2, $result['rooms']);
		$this->assertEquals(60, $result['stats']['averageCleanliness']);
		$this->assertEquals(2, $result['stats']['totalMonsters']);
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
