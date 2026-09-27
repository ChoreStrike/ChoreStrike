<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\RoomController;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\TemplateService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Integration tests for RoomController API endpoints
 *
 * Mock-based endpoint coverage plus real-DB role-gate tests for the
 * owner-only room deletion (CR-053-03, RoleGuardApiTest pattern).
 */
class RoomControllerIntegrationTest extends IntegrationTestCase {
	private RoomController $controller;
	private RoomService $roomService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private TemplateService $templateService;
	private IRequest $request;
	private int $instanceHouseId = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->roomService = $this->createMock(RoomService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);
		$this->templateService = $this->createMock(TemplateService::class);
		$this->templateService->method('isValidRoomType')->willReturn(true);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->controller = new RoomController(
			$this->request,
			$this->roomService,
			$this->houseService,
			$this->authService,
			$this->templateService,
			$l10n,
			\OC::$server->get(AppLogger::class)
		);
	}

	private function createMockFloor(int $id, string $name, int $sortOrder, int $houseId): \OCA\ChoreStrike\Db\Floor {
		$floor = new \OCA\ChoreStrike\Db\Floor();
		$floor->setId($id);
		$floor->setName($name);
		$floor->setSortOrder($sortOrder);
		$floor->setHouseId($houseId);
		$floor->setCreatedAt(new \DateTime());
		return $floor;
	}

	private function createMockRoom(int $id, string $name, int $floorId, int $cleanliness): \OCA\ChoreStrike\Db\Room {
		$room = new \OCA\ChoreStrike\Db\Room();
		$room->setId($id);
		$room->setName($name);
		$room->setFloorId($floorId);
		$room->setCleanliness($cleanliness);
		$room->setIcon('Home');
		$room->setCreatedAt(new \DateTime());
		return $room;
	}

	private function createMockHouse(): \OCA\ChoreStrike\Db\House {
		$house = new \OCA\ChoreStrike\Db\House();
		$house->setId(1);
		$house->setNextcloudInstanceId('test-instance');
		$house->setName('Test House');
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
	 * Create_ValidData_ReturnsCreatedRoom
	 */
	public function testCreate_ValidData_ReturnsCreatedRoom(): void {
		$floorId = 1;
		$name = 'Bathroom';
		$room = $this->createMockRoom(4, $name, $floorId, 100);
		$floor = $this->createMockFloor($floorId, 'Ground Floor', 1, 1);

		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->authService->method('assertHouseMember')->willReturn($this->createMockHouseUser());
		$this->roomService->method('getFloor')
			->with($floorId)
			->willReturn($floor);
		$this->roomService->method('createRoom')
			->willReturn($room);

		$response = $this->controller->create($floorId, $name);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * Update_ValidData_ReturnsUpdatedRoom
	 */
	public function testUpdate_ValidData_ReturnsUpdatedRoom(): void {
		$roomId = 1;
		$newName = 'Updated Room Name';
		$room = $this->createMockRoom($roomId, $newName, 1, 85);

		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->authService->method('assertHouseMember')->willReturn($this->createMockHouseUser());
		$this->roomService->method('updateRoomName')
			->willReturn($room);

		$response = $this->controller->update($roomId, $newName);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * Delete_ExistingRoom_ReturnsSuccess
	 */
	public function testDelete_ExistingRoom_ReturnsSuccess(): void {
		$roomId = 1;

		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->authService->method('assertHouseMember')->willReturn($this->createMockHouseUser());
		$this->roomService->method('deleteRoom')
			->with($roomId);

		$response = $this->controller->delete($roomId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * UpdateCleanliness_ValidValue_ReturnsUpdatedCleanliness
	 */
	public function testUpdateCleanliness_ValidValue_ReturnsUpdatedCleanliness(): void {
		$roomId = 1;
		$change = 10;
		$room = $this->createMockRoom($roomId, 'Living Room', 1, 95);

		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->authService->method('assertHouseMember')->willReturn($this->createMockHouseUser());
		// WP3 §3.5/§3.6: the manual delta is a journalled idempotent
		// command — the receipt carries commandId + expectedVersion.
		$receipt = [
			'commandId' => 'A1B2C3D4-E5F6-4A7B-8C9D-0123456789AB',
			'expectedVersion' => 0,
		];
		$this->request->method('getParams')->willReturn($receipt);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $receipt[$key] ?? $default
		);
		$this->roomService->method('updateRoomCleanliness')
			->with($this->identicalTo($roomId), $this->identicalTo(1), $this->identicalTo($change), $this->anything(), $this->anything())
			->willReturn($room);

		$response = $this->controller->updateCleanliness($roomId, $change);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	// ========== Real-DB owner gate (CR-053-03) ==========

	/**
	 * Delete_MemberRole_Returns403AndKeepsRoom
	 */
	public function testDelete_MemberRole_Returns403AndKeepsRoom(): void {
		$roomId = $this->createRoom(0, $this->createFloor($this->resolveInstanceHouseId(), 'Member Delete Floor'), 'Member Delete Target');
		$this->addHouseMember($this->instanceHouseId, 'room_del_member', 'Member', 'member');

		$controller = $this->getController(RoomController::class, 'room_del_member');
		$response = $controller->delete($roomId);

		self::assertSame(403, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('FORBIDDEN', $data['error']['code']);
		self::assertTrue($this->roomExists($roomId), 'the room must remain intact');
	}

	/**
	 * Delete_ChildRole_Returns403AndKeepsRoom
	 */
	public function testDelete_ChildRole_Returns403AndKeepsRoom(): void {
		$roomId = $this->createRoom(0, $this->createFloor($this->resolveInstanceHouseId(), 'Child Delete Floor'), 'Child Delete Target');
		$this->addHouseMember($this->instanceHouseId, 'room_del_child', 'Child', 'child');

		$controller = $this->getController(RoomController::class, 'room_del_child');
		$response = $controller->delete($roomId);

		self::assertSame(403, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('FORBIDDEN', $data['error']['code']);
		self::assertTrue($this->roomExists($roomId), 'the room must remain intact');
	}

	/**
	 * Delete_OwnerRole_DeletesRoom
	 */
	public function testDelete_OwnerRole_DeletesRoom(): void {
		$roomId = $this->createRoom(0, $this->createFloor($this->resolveInstanceHouseId(), 'Owner Delete Floor'), 'Owner Delete Target');
		$this->addHouseMember($this->instanceHouseId, 'room_del_owner', 'Owner', 'owner');

		$controller = $this->getController(RoomController::class, 'room_del_owner');
		$response = $controller->delete($roomId);

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['success']);
		// CS-PLAN-071 (Q-70-01): deletion tombstones — the row survives with
		// deleted_at set and vanishes from the active-planning reads.
		self::assertTrue($this->roomTombstoned($roomId), 'the room must be tombstoned (deleted_at set)');
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


	private function roomExists(int $roomId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('chorestrike_rooms')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}	private function roomTombstoned(int $roomId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('deleted_at')
			->from('chorestrike_rooms')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$deletedAt = $result->fetchOne();
		$result->closeCursor();

		return $deletedAt !== false && $deletedAt !== null;
	}
}
