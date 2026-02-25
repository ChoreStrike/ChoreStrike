<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\FloorController;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\FloorService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for FloorController API endpoints
 */
class FloorControllerIntegrationTest extends TestCase {

	private FloorController $controller;
	private FloorService $floorService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->floorService = $this->createMock(FloorService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);

		$this->controller = new FloorController(
			$this->request,
			$this->floorService,
			$this->houseService,
			$this->authService
		);
	}

	/**
	 * Index_ReturnsFloorsList_SuccessResponse
	 */
	public function testIndex_ReturnsFloorsList_SuccessResponse(): void {
		$houseId = 1;
		$floors = [
			$this->createMockFloor(1, 'Ground Floor', 1, $houseId),
			$this->createMockFloor(2, 'First Floor', 2, $houseId),
		];

		$house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
		$house->method('getId')->willReturn($houseId);

		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->floorService->method('getFloors')
			->with($houseId)
			->willReturn($floors);
		$this->authService->method('requireHouseMember')
			->with($houseId)
			->willReturn($this->createMock(\OCA\ChoreStrike\Db\HouseUser::class));

		$response = $this->controller->index();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(2, $data['data']);
	}

	private function createMockFloor(int $id, string $name, int $sortOrder, int $houseId): \OCA\ChoreStrike\Db\Floor {
		$floor = $this->createMock(\OCA\ChoreStrike\Db\Floor::class);
		$floor->method('getId')->willReturn($id);
		$floor->method('getName')->willReturn($name);
		$floor->method('getSortOrder')->willReturn($sortOrder);
		$floor->method('getHouseId')->willReturn($houseId);
		$floor->method('jsonSerialize')->willReturn([
			'id' => $id,
			'name' => $name,
			'sortOrder' => $sortOrder,
			'houseId' => $houseId,
		]);
		return $floor;
	}

	/**
	 * Create_ValidName_ReturnsCreatedFloor
	 */
	public function testCreate_ValidName_ReturnsCreatedFloor(): void {
		$houseId = 1;
		$name = 'Basement';
		$floor = $this->createMockFloor(3, $name, 3, $houseId);

		$house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
		$house->method('getId')->willReturn($houseId);

		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->floorService->method('createFloor')
			->with($houseId, $name, null)
			->willReturn($floor);
		$this->authService->method('requireHouseMember')
			->with($houseId)
			->willReturn($this->createMock(\OCA\ChoreStrike\Db\HouseUser::class));

		$response = $this->controller->create($name);

		$this->assertEquals(201, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($name, $data['data']['name']);
	}

	/**
	 * Create_EmptyName_ReturnsValidationError
	 */
	public function testCreate_EmptyName_ReturnsValidationError(): void {
		// Controller validates name before calling service
		$response = $this->controller->create('');

		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Update_ValidData_ReturnsUpdatedFloor
	 */
	public function testUpdate_ValidData_ReturnsUpdatedFloor(): void {
		$floorId = 1;
		$houseId = 1;
		$newName = 'Updated Floor Name';
		$floor = $this->createMockFloor($floorId, $newName, 1, $houseId);

		$existingFloor = $this->createMockFloor($floorId, 'Ground Floor', 1, $houseId);

		$this->floorService->method('getFloor')
			->with($floorId)
			->willReturn($existingFloor);
		$this->floorService->method('updateFloorName')
			->with($floorId, $newName)
			->willReturn($floor);
		$this->authService->method('requireHouseMember')
			->with($houseId)
			->willReturn($this->createMock(\OCA\ChoreStrike\Db\HouseUser::class));

		$response = $this->controller->update($floorId, $newName);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($newName, $data['data']['name']);
	}

	/**
	 * Delete_ExistingFloor_ReturnsSuccess
	 */
	public function testDelete_ExistingFloor_ReturnsSuccess(): void {
		$floorId = 1;
		$houseId = 1;
		$floor = $this->createMockFloor($floorId, 'Ground Floor', 1, $houseId);

		$this->floorService->method('getFloor')
			->with($floorId)
			->willReturn($floor);
		$this->floorService->method('deleteFloor')
			->with($floorId);
		$this->authService->method('requireHouseMember')
			->with($houseId)
			->willReturn($this->createMock(\OCA\ChoreStrike\Db\HouseUser::class));

		$response = $this->controller->delete($floorId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('Floor deleted successfully', $data['data']['message']);
	}

	/**
	 * Delete_NonExistentFloor_ReturnsNotFound
	 */
	public function testDelete_NonExistentFloor_ReturnsNotFound(): void {
		$floorId = 999;

		$this->floorService->method('getFloor')
			->with($floorId)
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Floor not found'));

		$response = $this->controller->delete($floorId);

		$this->assertEquals(404, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Reorder_ValidOrder_ReturnsSuccess
	 */
	public function testReorder_ValidOrder_ReturnsSuccess(): void {
		$houseId = 1;
		$order = [2, 1, 3];
		$floors = [
			$this->createMockFloor(2, 'First Floor', 1, $houseId),
			$this->createMockFloor(1, 'Ground Floor', 2, $houseId),
			$this->createMockFloor(3, 'Basement', 3, $houseId),
		];

		$house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
		$house->method('getId')->willReturn($houseId);

		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->floorService->method('reorderFloors')
			->with($houseId, $order)
			->willReturn($floors);
		$this->authService->method('requireHouseMember')
			->with($houseId)
			->willReturn($this->createMock(\OCA\ChoreStrike\Db\HouseUser::class));

		$response = $this->controller->reorder($order);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * WithRooms_ReturnsFloorsWithRooms_SuccessResponse
	 */
	public function testWithRooms_ReturnsFloorsWithRooms_SuccessResponse(): void {
		$houseId = 1;
		$floorWithRooms = [
			[
				'floor' => $this->createMockFloor(1, 'Ground Floor', 1, $houseId),
				'rooms' => [
					$this->createMockRoom(1, 'Living Room', 85),
					$this->createMockRoom(2, 'Kitchen', 90),
				],
				'stats' => ['totalRooms' => 2],
			],
			[
				'floor' => $this->createMockFloor(2, 'First Floor', 2, $houseId),
				'rooms' => [
					$this->createMockRoom(3, 'Bedroom', 75),
				],
				'stats' => ['totalRooms' => 1],
			],
		];

		$house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
		$house->method('getId')->willReturn($houseId);

		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->floorService->method('getFloorsWithRooms')
			->with($houseId)
			->willReturn($floorWithRooms);
		$this->authService->method('requireHouseMember')
			->with($houseId)
			->willReturn($this->createMock(\OCA\ChoreStrike\Db\HouseUser::class));

		$response = $this->controller->withRooms();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(2, $data['data']);
	}

	private function createMockRoom(int $id, string $name, int $cleanliness): \OCA\ChoreStrike\Db\Room {
		$room = $this->createMock(\OCA\ChoreStrike\Db\Room::class);
		$room->method('getId')->willReturn($id);
		$room->method('getName')->willReturn($name);
		$room->method('getCleanliness')->willReturn($cleanliness);
		$room->method('jsonSerialize')->willReturn([
			'id' => $id,
			'name' => $name,
			'cleanliness' => $cleanliness,
		]);
		return $room;
	}

	/**
	 * Show_ExistingFloor_ReturnsFloor
	 */
	public function testShow_ExistingFloor_ReturnsFloor(): void {
		$floorId = 1;
		$houseId = 1;
		$floor = $this->createMockFloor($floorId, 'Ground Floor', 1, $houseId);
		$rooms = [
			$this->createMockRoom(1, 'Living Room', 85),
			$this->createMockRoom(2, 'Kitchen', 90),
		];
		$floorWithRooms = [
			'floor' => $floor,
			'rooms' => $rooms,
			'stats' => ['totalRooms' => 2],
		];

		$this->floorService->method('getFloorWithRooms')
			->with($floorId)
			->willReturn($floorWithRooms);
		$this->authService->method('requireHouseMember')
			->with($houseId)
			->willReturn($this->createMock(\OCA\ChoreStrike\Db\HouseUser::class));

		$response = $this->controller->show($floorId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($floorId, $data['data']['floor']['id']);
	}
}
