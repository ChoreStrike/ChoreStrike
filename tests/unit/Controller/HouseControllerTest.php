<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\HouseController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\ActivityService;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HouseController
 */
class HouseControllerTest extends TestCase {
	private HouseController $controller;
	private $houseService;
	private $authService;
	private $activityService;
	private $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->controller = new HouseController(
			$this->request,
			$this->houseService,
			$this->authService,
			$this->activityService,
			$l10n,
			$this->createMock(AppLogger::class)
		);
	}

	/**
	 * Test GET /house returns house successfully
	 */
	public function testGet_ReturnsHouseSuccessfully(): void {
		$house = new House();
		$house->setId(1);
		$house->setName('Test House');

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember');

		$response = $this->controller->get();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('Test House', $data['data']['name']);
	}

	/**
	 * Test GET /house handles exceptions
	 */
	public function testGet_HandlesException(): void {
		$this->houseService->method('getInstanceId')
			->willThrowException(new \Exception('Database error'));

		$response = $this->controller->get();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(500, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('HOUSE_ERROR', $data['error']['code']);
	}

	/**
	 * Test UPDATE /house validates empty name
	 */
	public function testUpdate_ValidatesEmptyName(): void {
		$response = $this->controller->update('');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
	}

	/**
	 * Test UPDATE /house validates whitespace only name
	 */
	public function testUpdate_ValidatesWhitespaceName(): void {
		$response = $this->controller->update('   ');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
	}

	/**
	 * Test UPDATE /house updates successfully
	 */
	public function testUpdate_UpdatesSuccessfully(): void {
		$house = new House();
		$house->setId(1);
		$house->setName('Updated House');

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('requireHouseOwner');

		$this->houseService->expects($this->once())
			->method('updateHouseName')
			->with(1, 'New House Name')
			->willReturn($house);

		$response = $this->controller->update('New House Name');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * Test UPDATE /house handles exceptions
	 */
	public function testUpdate_HandlesException(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('requireHouseOwner')
			->willThrowException(new \Exception('Not authorized'));

		$response = $this->controller->update('New Name');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(500, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('HOUSE_UPDATE_ERROR', $data['error']['code']);
	}

	/**
	 * GET /house/activity returns the activity payload (CS-PLAN-040).
	 */
	public function testActivity_ReturnsActivitySuccessfully(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember');

		$payload = ['completions' => [['id' => 5, 'taskTitle' => 'Vacuum']], 'total' => 1];
		$this->activityService->expects($this->once())
			->method('getActivity')
			->with(1, ActivityService::DEFAULT_DAYS, ActivityService::DEFAULT_LIMIT, null)
			->willReturn($payload);

		$response = $this->controller->activity();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame($payload, $data['data']);
	}

	/**
	 * GET /house/activity forwards the query params to the service.
	 */
	public function testActivity_ForwardsParams(): void {
		$house = new House();
		$house->setId(2);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember');

		$this->activityService->expects($this->once())
			->method('getActivity')
			->with(2, 7, 25, 'alice')
			->willReturn(['completions' => [], 'total' => 0]);

		$response = $this->controller->activity(7, 25, 'alice');

		$this->assertEquals(200, $response->getStatus());
	}

	/**
	 * GET /house/activity denies non-members with 403.
	 */
	public function testActivity_Returns403ForNonMember(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember')
			->willThrowException(new ForbiddenException('Not a member'));

		$response = $this->controller->activity();

		$this->assertEquals(403, $response->getStatus());
		$this->assertEquals('FORBIDDEN', $response->getData()['error']['code']);
	}

	/**
	 * GET /house/activity maps a non-member target userId (NotFoundException
	 * from the service) to a clean 404 — not a 500.
	 */
	public function testActivity_Returns404ForNonMemberTargetUser(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember');
		$this->activityService->method('getActivity')
			->willThrowException(new NotFoundException('User is not a member of this house'));

		$response = $this->controller->activity(30, 50, 'outsider');

		$this->assertEquals(404, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}
}
