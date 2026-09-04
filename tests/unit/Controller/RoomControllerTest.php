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
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\TemplateService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
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
		$l10n = $this->createMock(IL10N::class);
		// Identity mock: message assertions match the wrapped source text.
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->controller = new RoomController(
			$this->request,
			$this->roomService,
			$this->houseService,
			$this->authService,
			$this->templateService,
			$l10n,
			$this->createMock(AppLogger::class)
		);
	}

	/**
	 * Stub the caller-scoped house preamble (CS-PLAN-061 §3.1): create()
	 * resolves the caller's instance house and asserts membership BEFORE the
	 * floor is loaded.
	 */
	private function expectInstanceHouse(int $houseId = 1): void {
		$house = new \OCA\ChoreStrike\Db\House();
		$house->setId($houseId);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')
			->with($houseId)
			->willReturn(new \OCA\ChoreStrike\Db\HouseUser());
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

		$this->expectInstanceHouse(1);

		$this->roomService->expects($this->once())
			->method('createRoom')
			->with(1, 'Bathroom', 'Bath', 'generic')
			->willReturn($room);

		$response = $this->controller->create(1, 'Bathroom', 'Bath');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('Bath', $data['data']['icon']);
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

		$this->expectInstanceHouse(1);

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

		$this->expectInstanceHouse(1);

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
	 * CS-PLAN-061 §3.1: a floor from a foreign house maps to the same 404
	 * as a nonexistent one (anti-enumeration) — create() must never
	 * auto-join the floor's house.
	 */
	public function testCreate_ForeignHouseFloor_Returns404(): void {
		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(99);

		$this->expectInstanceHouse(1);

		$this->roomService->expects($this->once())
			->method('getFloor')
			->with(1)
			->willReturn($floor);

		$this->roomService->expects($this->never())->method('createRoom');

		$response = $this->controller->create(1, 'Kitchen', null, 'kitchen');

		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
		$this->assertEquals('Floor not found', $response->getData()['error']['message']);
	}

	/**
	 * CS-PLAN-061 §3.1: a nonexistent floor (app NotFoundException from
	 * getFloor) maps to a clean 404.
	 */
	public function testCreate_UnknownFloor_Returns404(): void {
		$this->expectInstanceHouse(1);

		$this->roomService->expects($this->once())
			->method('getFloor')
			->with(1)
			->willThrowException(new \OCA\ChoreStrike\Exception\NotFoundException('Floor not found'));

		$this->roomService->expects($this->never())->method('createRoom');

		$response = $this->controller->create(1, 'Kitchen', null, 'kitchen');

		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
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
