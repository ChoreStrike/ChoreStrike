<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\FloorController;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\FloorService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\IL10N;
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
		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->controller = new FloorController(
			$this->request,
			$this->floorService,
			$this->houseService,
			$this->authService,
			$l10n
		);
	}

	/**
	 * Index_ReturnsFloorsList_SuccessResponse
	 */
	public function testIndex_ReturnsFloorsList_SuccessResponse(): void {
		$floors = [
			$this->createMockFloor(1, 'Ground Floor', 1),
			$this->createMockFloor(2, 'First Floor', 2),
		];

		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->authService->method('ensureHouseMember')->willReturn($this->createMockHouseUser());
		$this->floorService->method('getFloors')
			->willReturn($floors);

		$response = $this->controller->index();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	private function createMockFloor(int $id, string $name, int $sortOrder): \OCA\ChoreStrike\Db\Floor {
		$floor = new \OCA\ChoreStrike\Db\Floor();
		$floor->setId($id);
		$floor->setName($name);
		$floor->setSortOrder($sortOrder);
		$floor->setHouseId(1);
		$floor->setCreatedAt(new \DateTime());
		return $floor;
	}

	private function createMockHouse(): \OCA\ChoreStrike\Db\House {
		$house = new \OCA\ChoreStrike\Db\House();
		$house->setId(1);
		$house->setNextcloudInstanceId('test-instance');
		$house->setName('Test House');
		$house->setHouseHealth(100);
		return $house;
	}

	private function createMockHouseUser(): \OCA\ChoreStrike\Db\HouseUser {
		$user = new \OCA\ChoreStrike\Db\HouseUser();
		$user->setId(1);
		$user->setHouseId(1);
		$user->setNextcloudUserId('test-user');
		$user->setDisplayName('Test User');
		$user->setRole('member');
		return $user;
	}

	/**
	 * Create_ValidName_ReturnsCreatedFloor
	 */
	public function testCreate_ValidName_ReturnsCreatedFloor(): void {
		$name = 'Basement';
		$floor = $this->createMockFloor(3, $name, 3);

		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->authService->method('ensureHouseMember')->willReturn($this->createMockHouseUser());
		$this->floorService->method('createFloor')
			->willReturn($floor);

		$response = $this->controller->create($name);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
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
		$newName = 'Updated Floor Name';
		$floor = $this->createMockFloor($floorId, $newName, 1);

		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->authService->method('ensureHouseMember')->willReturn($this->createMockHouseUser());
		$this->floorService->method('updateFloorName')
			->willReturn($floor);

		$response = $this->controller->update($floorId, $newName);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * Delete_ExistingFloor_ReturnsSuccess
	 */
	public function testDelete_ExistingFloor_ReturnsSuccess(): void {
		$floorId = 1;

		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->authService->method('ensureHouseMember')->willReturn($this->createMockHouseUser());
		$this->floorService->method('deleteFloor')
			->with($floorId);

		$response = $this->controller->delete($floorId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * Delete_NonExistentFloor_ReturnsNotFound
	 */
	public function testDelete_NonExistentFloor_ReturnsNotFound(): void {
		$floorId = 999;

		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->authService->method('ensureHouseMember')->willReturn($this->createMockHouseUser());
		$this->floorService->method('validateFloorOwnership')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Floor not found'));

		$response = $this->controller->delete($floorId);

		// Controller catches exception and returns 500 with error
		$this->assertEquals(500, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Reorder_ValidOrder_ReturnsSuccess
	 */
	public function testReorder_ValidOrder_ReturnsSuccess(): void {
		$order = [2, 1, 3];
		$floors = [
			$this->createMockFloor(2, 'First Floor', 1),
			$this->createMockFloor(1, 'Ground Floor', 2),
			$this->createMockFloor(3, 'Basement', 3),
		];

		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->authService->method('ensureHouseMember')->willReturn($this->createMockHouseUser());
		$this->floorService->method('reorderFloors')
			->willReturn($floors);

		$response = $this->controller->reorder($order);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}
}
