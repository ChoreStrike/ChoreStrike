<?php

declare(strict_types=1);

namespace OCA\ChoreWars\Tests\Integration\Controller;

use OCA\ChoreWars\Controller\FloorController;
use OCA\ChoreWars\Service\FloorService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for FloorController API endpoints
 */
class FloorControllerIntegrationTest extends TestCase {

	private FloorController $controller;
	private FloorService $floorService;
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->floorService = $this->createMock(FloorService::class);

		$this->controller = new FloorController(
			$this->request,
			$this->floorService
		);
	}

	/**
	 * Index_ReturnsFloorsList_SuccessResponse
	 */
	public function testIndex_ReturnsFloorsList_SuccessResponse(): void {
		$floors = [
			['id' => 1, 'name' => 'Ground Floor', 'sortOrder' => 1],
			['id' => 2, 'name' => 'First Floor', 'sortOrder' => 2],
		];

		$this->floorService->method('getFloors')
			->willReturn($floors);

		$response = $this->controller->index();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($floors, $data['data']);
	}

	/**
	 * Create_ValidName_ReturnsCreatedFloor
	 */
	public function testCreate_ValidName_ReturnsCreatedFloor(): void {
		$name = 'Basement';
		$createdFloor = [
			'id' => 3,
			'name' => $name,
			'sortOrder' => 3,
		];

		$this->floorService->method('createFloor')
			->with($name)
			->willReturn($createdFloor);

		$response = $this->controller->create($name);

		$this->assertEquals(201, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($createdFloor, $data['data']);
	}

	/**
	 * Create_EmptyName_ReturnsValidationError
	 */
	public function testCreate_EmptyName_ReturnsValidationError(): void {
		$this->floorService->method('createFloor')
			->willThrowException(new \InvalidArgumentException('Floor name is required'));

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
		$newName = 'Updated Floor Name';
		$updatedFloor = [
			'id' => $floorId,
			'name' => $newName,
			'sortOrder' => 1,
		];

		$this->floorService->method('updateFloorName')
			->with($floorId, $newName)
			->willReturn($updatedFloor);

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

		$this->floorService->method('deleteFloor')
			->with($floorId)
			->willReturn(true);

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

		$this->floorService->method('deleteFloor')
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
		$order = [2, 1, 3];

		$this->floorService->method('reorderFloors')
			->with($order)
			->willReturn(true);

		$response = $this->controller->reorder($order);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * WithRooms_ReturnsFloorsWithRooms_SuccessResponse
	 */
	public function testWithRooms_ReturnsFloorsWithRooms_SuccessResponse(): void {
		$floorsWithRooms = [
			[
				'id' => 1,
				'name' => 'Ground Floor',
				'sortOrder' => 1,
				'rooms' => [
					['id' => 1, 'name' => 'Living Room', 'cleanliness' => 85],
					['id' => 2, 'name' => 'Kitchen', 'cleanliness' => 90],
				],
			],
			[
				'id' => 2,
				'name' => 'First Floor',
				'sortOrder' => 2,
				'rooms' => [
					['id' => 3, 'name' => 'Bedroom', 'cleanliness' => 75],
				],
			],
		];

		$this->floorService->method('getFloorsWithRooms')
			->willReturn($floorsWithRooms);

		$response = $this->controller->withRooms();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($floorsWithRooms, $data['data']);
	}

	/**
	 * Show_ExistingFloor_ReturnsFloor
	 */
	public function testShow_ExistingFloor_ReturnsFloor(): void {
		$floorId = 1;
		$floor = [
			'id' => $floorId,
			'name' => 'Ground Floor',
			'sortOrder' => 1,
		];

		$this->floorService->method('getFloor')
			->with($floorId)
			->willReturn($floor);

		$response = $this->controller->show($floorId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($floor, $data['data']);
	}
}
