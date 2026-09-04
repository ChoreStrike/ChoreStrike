<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\FloorController;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\FloorService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FloorController
 */
class FloorControllerTest extends TestCase {
	private FloorController $controller;
	private $floorService;
	private $houseService;
	private $authService;
	private $request;

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
			$this->createMock(AppLogger::class)
		);
	}

	/**
	 * Test POST /floors validates empty name
	 */
	public function testCreate_ValidatesEmptyName(): void {
		$response = $this->controller->create('');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
	}

	/**
	 * Test POST /floors resolves request params when no explicit arguments are passed
	 */
	public function testCreate_UsesRequestParamsWhenArgumentsAreMissing(): void {
		$house = new House();
		$house->setId(1);

		$floor = new Floor();
		$floor->setId(2);
		$floor->setName('Basement');
		$floor->setHouseId(1);

		$this->request->method('getParams')->willReturn([
			'name' => 'Basement',
			'sortOrder' => null,
		]);
		$this->request->method('getParam')->willReturnMap([
			['name', 'Basement'],
			['sortOrder', null],
		]);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember');
		$this->floorService->expects($this->once())
			->method('createFloor')
			->with(1, 'Basement', null)
			->willReturn($floor);

		$response = $this->controller->create();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('Basement', $data['data']['name']);
	}

	/**
	 * Test DELETE /floors/{id} deletes successfully
	 */
	public function testDelete_DeletesSuccessfully(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember');
		$this->authService->method('requireHouseOwner');

		$this->floorService->expects($this->once())
			->method('deleteFloor')
			->with(1);

		$response = $this->controller->delete(1);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * A nonexistent floor (app NotFoundException from the ownership check)
	 * maps to a clean 404 (CS-PLAN-061 WP2) — not a 500.
	 */
	public function testUpdate_UnknownFloor_Returns404(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember');
		$this->floorService->method('validateFloorOwnership')
			->willThrowException(new \OCA\ChoreStrike\Exception\NotFoundException('Floor not found'));

		$this->floorService->expects($this->never())->method('updateFloorName');

		$response = $this->controller->update(999, 'Renamed');

		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}
}
