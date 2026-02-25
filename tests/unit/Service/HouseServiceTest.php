<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HouseService
 */
class HouseServiceTest extends TestCase {

	private HouseService $service;
	private HouseMapper $houseMapper;
	private FloorMapper $floorMapper;
	private RoomMapper $roomMapper;
	private IConfig $config;

	protected function setUp(): void {
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->floorMapper = $this->createMock(FloorMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->config = $this->createMock(IConfig::class);

		$this->service = new HouseService(
			$this->houseMapper,
			$this->floorMapper,
			$this->roomMapper,
			$this->config
		);
	}

	/**
	 * GetOrCreateHouse_ExistingHouse_ReturnsHouse
	 */
	public function testGetOrCreateHouse_ExistingHouse_ReturnsHouse(): void {
		$instanceId = 'instance123';
		$houseName = 'My House';

		$house = new House();
		$house->setId(1);
		$house->setName('Existing House');

		$this->houseMapper
			->expects($this->once())
			->method('findByInstanceId')
			->with($instanceId)
			->willReturn($house);

		$result = $this->service->getOrCreateHouse($instanceId, $houseName);

		$this->assertSame($house, $result);
	}

	/**
	 * GetOrCreateHouse_NewHouse_CreatesAndReturnsHouse
	 */
	public function testGetOrCreateHouse_NewHouse_CreatesAndReturnsHouse(): void {
		$instanceId = 'instance123';
		$houseName = 'My House';

		$house = new House();
		$house->setId(1);
		$house->setName($houseName);

		$this->houseMapper
			->expects($this->once())
			->method('findByInstanceId')
			->with($instanceId)
			->willThrowException(new DoesNotExistException('House not found'));

		$this->houseMapper
			->expects($this->once())
			->method('createForInstance')
			->with($instanceId, $houseName)
			->willReturn($house);

		$result = $this->service->getOrCreateHouse($instanceId, $houseName);

		$this->assertSame($house, $result);
		$this->assertEquals($houseName, $result->getName());
	}

	/**
	 * UpdateHouseName_ValidName_ReturnsUpdatedHouse
	 */
	public function testUpdateHouseName_ValidName_ReturnsUpdatedHouse(): void {
		$houseId = 1;
		$newName = 'Updated House';

		$house = new House();
		$house->setId($houseId);
		$house->setName('Old Name');

		$updatedHouse = new House();
		$updatedHouse->setId($houseId);
		$updatedHouse->setName($newName);

		$this->houseMapper
			->expects($this->once())
			->method('find')
			->with($houseId)
			->willReturn($house);

		$this->houseMapper
			->expects($this->once())
			->method('update')
			->with($this->callback(function ($h) use ($newName) {
				return $h->getName() === $newName;
			}))
			->willReturn($updatedHouse);

		$result = $this->service->updateHouseName($houseId, $newName);

		$this->assertEquals($newName, $result->getName());
	}

	/**
	 * CalculateHouseHealth_NoRooms_Returns100
	 */
	public function testCalculateHouseHealth_NoRooms_Returns100(): void {
		$houseId = 1;

		$this->floorMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([]);

		$result = $this->service->calculateHouseHealth($houseId);

		$this->assertEquals(100, $result);
	}

	/**
	 * CalculateHouseHealth_WithRooms_ReturnsAverageCleanliness
	 */
	public function testCalculateHouseHealth_WithRooms_ReturnsAverageCleanliness(): void {
		$houseId = 1;

		$floor = new Floor();
		$floor->setId(1);

		$room1 = new Room();
		$room1->setId(1);
		$room1->setCleanliness(80);

		$room2 = new Room();
		$room2->setId(2);
		$room2->setCleanliness(60);

		$this->floorMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$floor]);

		$this->roomMapper
			->expects($this->once())
			->method('findByFloorId')
			->with(1)
			->willReturn([$room1, $room2]);

		$this->houseMapper
			->expects($this->once())
			->method('updateHealth')
			->with($houseId, 70);

		$result = $this->service->calculateHouseHealth($houseId);

		$this->assertEquals(70, $result);
	}

	/**
	 * GetHouseStats_WithRooms_ReturnsCompleteStats
	 */
	public function testGetHouseStats_WithRooms_ReturnsCompleteStats(): void {
		$houseId = 1;

		$house = new House();
		$house->setId($houseId);
		$house->setHouseHealth(75);

		$floor = new Floor();
		$floor->setId(1);

		$cleanRoom = new Room();
		$cleanRoom->setId(1);
		$cleanRoom->setCleanliness(70);

		$dirtyRoom = new Room();
		$dirtyRoom->setId(2);
		$dirtyRoom->setCleanliness(40);

		$this->houseMapper
			->expects($this->once())
			->method('find')
			->with($houseId)
			->willReturn($house);

		$this->floorMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$floor]);

		$this->roomMapper
			->expects($this->once())
			->method('findByFloorId')
			->with(1)
			->willReturn([$cleanRoom, $dirtyRoom]);

		$result = $this->service->getHouseStats($houseId);

		$this->assertSame($house, $result['house']);
		$this->assertEquals(2, $result['stats']['totalRooms']);
		$this->assertEquals(1, $result['stats']['cleanRooms']);
		$this->assertEquals(1, $result['stats']['dirtyRooms']);
		$this->assertEquals(75, $result['stats']['houseHealth']);
	}

	/**
	 * ResetHouse_UpdatesHealthAndRooms
	 */
	public function testResetHouse_UpdatesHealthAndRooms(): void {
		$houseId = 1;

		$house = new House();
		$house->setId($houseId);
		$house->setHouseHealth(50);

		$floor = new Floor();
		$floor->setId(1);

		$room = new Room();
		$room->setId(1);
		$room->setCleanliness(20);
		$room->setMonsterCount(3);

		$this->houseMapper
			->expects($this->once())
			->method('find')
			->with($houseId)
			->willReturn($house);

		$this->houseMapper
			->expects($this->once())
			->method('update')
			->with($this->callback(function ($h) {
				return $h->getHouseHealth() === 100;
			}));

		$this->floorMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$floor]);

		$this->roomMapper
			->expects($this->once())
			->method('findByFloorId')
			->with(1)
			->willReturn([$room]);

		$this->roomMapper
			->expects($this->once())
			->method('update')
			->with($this->callback(function ($r) {
				return $r->getCleanliness() === 100 && $r->getMonsterCount() === 0;
			}));

		$this->service->resetHouse($houseId);

		// Assertions verified by mock expectations
		$this->assertTrue(true);
	}

	/**
	 * GetInstanceId_ReturnsConfigValue
	 */
	public function testGetInstanceId_ReturnsConfigValue(): void {
		$this->config
			->expects($this->once())
			->method('getSystemValue')
			->with('instanceid', 'default')
			->willReturn('abc123');

		$result = $this->service->getInstanceId();

		$this->assertEquals('abc123', $result);
	}
}
