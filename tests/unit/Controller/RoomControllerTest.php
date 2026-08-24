<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\RoomController;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\TemplateService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomController
 */
class RoomControllerTest extends TestCase {
	private RoomController $controller;
	private $roomService;
	private $houseService;
	private $authService;
	private $templateService;
	private $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->roomService = $this->createMock(RoomService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);
		$this->templateService = $this->createMock(TemplateService::class);
		// CR-027-01: default stub accepts catalog types; individual tests
		// override for rejection cases.
		$this->templateService->method('isValidRoomType')
			->willReturnCallback(static fn (string $roomType): bool => in_array($roomType, ['generic', 'kitchen', 'bathroom'], true));

		$this->controller = new RoomController(
			$this->request,
			$this->roomService,
			$this->houseService,
			$this->authService,
			$this->templateService
		);
	}

	/**
	 * Test GET /rooms returns list successfully
	 */
	public function testIndex_ReturnsRoomsSuccessfully(): void {
		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(1);

		$room1 = new Room();
		$room1->setId(1);
		$room1->setName('Kitchen');
		$room1->setFloorId(1);

		$room2 = new Room();
		$room2->setId(2);
		$room2->setName('Living Room');
		$room2->setFloorId(1);

		$this->roomService->expects($this->once())
			->method('getFloor')
			->with(1)
			->willReturn($floor);

		$this->authService->expects($this->once())
			->method('ensureHouseMember')
			->with(1);

		$this->roomService->expects($this->once())
			->method('getRooms')
			->with(1)
			->willReturn([$room1, $room2]);

		$response = $this->controller->index(1);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(2, $data['data']);
	}

	/**
	 * Test POST /rooms validates empty name
	 */
	public function testCreate_ValidatesEmptyName(): void {
		$response = $this->controller->create(1, '');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
	}

	/**
	 * Test GET /rooms POST-to-GET fallback forwards icon parameter
	 */
	public function testIndex_PostToGetFallback_ForwardsRoomIcon(): void {
		$_GET['name'] = 'Bathroom';
		$_GET['floorId'] = '1';
		$_GET['icon'] = 'Bath';

		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(1);

		$room = new Room();
		$room->setId(11);
		$room->setFloorId(1);
		$room->setName('Bathroom');
		$room->setIcon('Bath');

		$this->roomService->expects($this->once())
			->method('getFloor')
			->with(1)
			->willReturn($floor);

		$this->authService->expects($this->once())
			->method('ensureHouseMember')
			->with(1);

		$this->roomService->expects($this->once())
			->method('createRoom')
			->with(1, 'Bathroom', 'Bath')
			->willReturn($room);

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('Bath', $data['data']['icon']);

		$_GET = [];
	}

	/**
	 * Test POST /rooms passes icon to room creation
	 */
	public function testCreate_PassesOptionalRoomIcon(): void {
		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(1);

		$room = new Room();
		$room->setId(5);
		$room->setFloorId(1);
		$room->setName('Bathroom');
		$room->setIcon('Bath');

		$this->roomService->expects($this->once())
			->method('getFloor')
			->with(1)
			->willReturn($floor);

		$this->authService->expects($this->once())
			->method('ensureHouseMember')
			->with(1);

		$this->roomService->expects($this->once())
			->method('createRoom')
			->with(1, 'Bathroom', 'Bath')
			->willReturn($room);

		$response = $this->controller->create(1, 'Bathroom', 'Bath');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('Bath', $data['data']['icon']);
	}

	/**
	 * Test GET /rooms/{id} returns room successfully
	 */
	public function testShow_ReturnsRoomSuccessfully(): void {
		$room = new Room();
		$room->setId(1);
		$room->setName('Kitchen');
		$room->setHouseId(1);

		$this->roomService->expects($this->once())
			->method('getRoomWithTasks')
			->with(1)
			->willReturn([
				'room' => $room,
				'tasks' => [],
				'stats' => ['total' => 0]
			]);

		$this->roomService->expects($this->once())
			->method('validateRoomOwnership')
			->with(1, 1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$house = new \OCA\ChoreStrike\Db\House();
		$house->setId(1);
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember');

		$response = $this->controller->show(1);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('Kitchen', $data['data']['room']['name']);
	}

	/**
	 * Test GET /rooms/{id} handles not found
	 */
	public function testShow_HandlesNotFound(): void {
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$house = new \OCA\ChoreStrike\Db\House();
		$house->setId(1);
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember');

		$this->roomService->expects($this->once())
			->method('validateRoomOwnership')
			->with(999, 1)
			->willThrowException(new \Exception('Room not found'));

		$response = $this->controller->show(999);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(500, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Test POST /rooms rejects a roomType that is not in the catalog
	 * (CR-027-01, decision D2: 400, never a silent fallback).
	 */
	public function testCreate_UnknownRoomType_ReturnsValidationError(): void {
		$response = $this->controller->create(1, 'Kitchen', null, 'spaceship');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
		$this->assertEquals('Invalid room type', $data['error']['message']);
	}

	/**
	 * Test POST /rooms rejects an oversized roomType before persistence
	 * (CR-027-01: >50 chars would 500 on insert).
	 */
	public function testCreate_OversizedRoomType_ReturnsValidationError(): void {
		$oversizedRoomType = str_repeat('x', 51);

		$response = $this->controller->create(1, 'Kitchen', null, $oversizedRoomType);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
		$this->assertEquals('Room type must be at most 50 characters', $data['error']['message']);
	}

	/**
	 * Test POST /rooms without a roomType defaults to 'generic' and passes
	 * the catalog check (CR-027-01).
	 */
	public function testCreate_OmittedRoomType_DefaultsToGeneric(): void {
		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(1);

		$room = new Room();
		$room->setId(6);
		$room->setFloorId(1);
		$room->setName('Storage');

		$this->roomService->expects($this->once())
			->method('getFloor')
			->with(1)
			->willReturn($floor);

		$this->authService->expects($this->once())
			->method('ensureHouseMember')
			->with(1);

		$this->roomService->expects($this->once())
			->method('createRoom')
			->with(1, 'Storage', null, 'generic')
			->willReturn($room);

		$response = $this->controller->create(1, 'Storage');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * Test POST /rooms accepts a catalog roomType (CR-027-01).
	 */
	public function testCreate_CatalogRoomType_Succeeds(): void {
		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(1);

		$room = new Room();
		$room->setId(7);
		$room->setFloorId(1);
		$room->setName('Kitchen');

		$this->roomService->expects($this->once())
			->method('getFloor')
			->with(1)
			->willReturn($floor);

		$this->authService->expects($this->once())
			->method('ensureHouseMember')
			->with(1);

		$this->roomService->expects($this->once())
			->method('createRoom')
			->with(1, 'Kitchen', null, 'kitchen')
			->willReturn($room);

		$response = $this->controller->create(1, 'Kitchen', null, 'kitchen');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * Helper method to get Floor mock
	 */
	private function getFloor(): Floor {
		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(1);
		return $floor;
	}
}
