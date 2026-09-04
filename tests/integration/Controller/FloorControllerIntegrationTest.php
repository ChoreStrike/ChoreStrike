<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\FloorController;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\FloorService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Integration tests for FloorController API endpoints
 *
 * Mock-based endpoint coverage plus real-DB role-gate tests for the
 * owner-only floor deletion (CR-053-03, RoleGuardApiTest pattern).
 */
class FloorControllerIntegrationTest extends IntegrationTestCase {
	private FloorController $controller;
	private FloorService $floorService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IRequest $request;
	private int $instanceHouseId = 0;

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
			$l10n,
			\OC::$server->get(AppLogger::class)
		);
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
		$this->authService->method('assertHouseMember')->willReturn($this->createMockHouseUser());
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
		$this->authService->method('assertHouseMember')->willReturn($this->createMockHouseUser());
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
		$this->authService->method('assertHouseMember')->willReturn($this->createMockHouseUser());
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
		$this->authService->method('assertHouseMember')->willReturn($this->createMockHouseUser());
		// CS-PLAN-061 §3.5: foreign/missing ids surface as NotFoundException
		// → 404 with RESOURCE_NOT_FOUND (never 500, never 403).
		$this->floorService->method('validateFloorOwnership')
			->willThrowException(new NotFoundException('Floor not found'));

		$response = $this->controller->delete($floorId);

		$this->assertEquals(404, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('RESOURCE_NOT_FOUND', $data['error']['code']);
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
		$this->authService->method('assertHouseMember')->willReturn($this->createMockHouseUser());
		$this->floorService->method('reorderFloors')
			->willReturn($floors);

		$response = $this->controller->reorder($order);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	// ========== Real-DB owner gate (CR-053-03) ==========

	/**
	 * Delete_MemberRole_Returns403AndKeepsFloor
	 */
	public function testDelete_MemberRole_Returns403AndKeepsFloor(): void {
		$floorId = $this->createFloor($this->resolveInstanceHouseId(), 'Member Delete Target');
		$this->addHouseMember($this->instanceHouseId, 'floor_del_member', 'Member', 'member');

		$controller = $this->getController(FloorController::class, 'floor_del_member');
		$response = $controller->delete($floorId);

		self::assertSame(403, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('FORBIDDEN', $data['error']['code']);
		self::assertTrue($this->floorExists($floorId), 'the floor must remain intact');
	}

	/**
	 * Delete_ChildRole_Returns403AndKeepsFloor
	 */
	public function testDelete_ChildRole_Returns403AndKeepsFloor(): void {
		$floorId = $this->createFloor($this->resolveInstanceHouseId(), 'Child Delete Target');
		$this->addHouseMember($this->instanceHouseId, 'floor_del_child', 'Child', 'child');

		$controller = $this->getController(FloorController::class, 'floor_del_child');
		$response = $controller->delete($floorId);

		self::assertSame(403, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('FORBIDDEN', $data['error']['code']);
		self::assertTrue($this->floorExists($floorId), 'the floor must remain intact');
	}

	/**
	 * Delete_OwnerRole_DeletesFloor
	 */
	public function testDelete_OwnerRole_DeletesFloor(): void {
		$floorId = $this->createFloor($this->resolveInstanceHouseId(), 'Owner Delete Target');
		$this->addHouseMember($this->instanceHouseId, 'floor_del_owner', 'Owner', 'owner');

		$controller = $this->getController(FloorController::class, 'floor_del_owner');
		$response = $controller->delete($floorId);

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['success']);
		self::assertFalse($this->floorExists($floorId), 'the floor must be gone');
	}

	/**
	 * Resolve (and cache) the shared instance house the controllers use
	 * via getInstanceId() + getOrCreateHouse().
	 */
	private function resolveInstanceHouseId(): int {
		if ($this->instanceHouseId === 0) {
			$houseService = \OC::$server->get(HouseService::class);
			$this->instanceHouseId = $houseService
				->getOrCreateHouse($houseService->getInstanceId())
				->getId();
		}

		return $this->instanceHouseId;
	}

	private function floorExists(int $floorId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('chorestrike_floors')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($floorId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}
}
