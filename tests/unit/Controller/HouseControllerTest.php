<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\HouseController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\ActivityService;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\ArcadeAccessService;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CalendarService;
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
	private $arcadeAccess;
	private $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->arcadeAccess = $this->createMock(ArcadeAccessService::class);
		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->controller = new HouseController(
			$this->request,
			$this->houseService,
			$this->authService,
			$this->activityService,
			$l10n,
			$this->createMock(AppLogger::class),
			new CalendarService(),
			$this->arcadeAccess
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
			->method('updateHouse')
			->with(1, 'New House Name', null)
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
	 * HouseUser viewer for the activity visibility redaction wiring.
	 */
	private function createHouseUser(): HouseUser {
		$houseUser = new HouseUser();
		$houseUser->setNextcloudUserId('caller');
		$houseUser->setRole('member');
		return $houseUser;
	}

	/**
	 * GET /house/activity returns the activity payload (CS-PLAN-040).
	 */
	public function testActivity_ReturnsActivitySuccessfully(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$houseUser = $this->createHouseUser();
		$this->authService->method('assertHouseMember')->willReturn($houseUser);

		$payload = ['completions' => [['id' => 5, 'taskTitle' => 'Vacuum']], 'total' => 1];
		$this->activityService->expects($this->once())
			->method('getActivity')
			->with(1, ActivityService::DEFAULT_DAYS, ActivityService::DEFAULT_LIMIT, null, $this->identicalTo($houseUser))
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
		$houseUser = $this->createHouseUser();
		$this->authService->method('assertHouseMember')->willReturn($houseUser);

		$this->activityService->expects($this->once())
			->method('getActivity')
			->with(2, 7, 25, 'alice', $this->identicalTo($houseUser))
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
		$this->authService->method('assertHouseMember')
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
		$this->authService->method('assertHouseMember');
		$this->activityService->method('getActivity')
			->willThrowException(new NotFoundException('User is not a member of this house'));

		$response = $this->controller->activity(30, 50, 'outsider');

		$this->assertEquals(404, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	/**
	 * PUT /house/catalog validates the strict payload (CS-PLAN-070 WP7).
	 */
	public function testUpdateCatalog_ValidatesStrictPayload(): void {
		$house = new House();
		$house->setId(1);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember');
		$this->authService->method('requireHouseOwner');

		// Non-boolean gate.
		$this->setParams(['catalogEnabled' => 1, 'expectedVersion' => 0, 'commandId' => '11111111-1111-4111-8111-111111111111']);
		$response = $this->controller->updateCatalog();
		$this->assertEquals(400, $response->getStatus());
		$this->assertEquals('VALIDATION_ERROR', $response->getData()['error']['code']);

		// Non-integer version.
		$this->setParams(['catalogEnabled' => true, 'expectedVersion' => '0', 'commandId' => '11111111-1111-4111-8111-111111111111']);
		$response = $this->controller->updateCatalog();
		$this->assertEquals(400, $response->getStatus());

		// Malformed command UUID.
		$this->setParams(['catalogEnabled' => true, 'expectedVersion' => 0, 'commandId' => 'nope']);
		$response = $this->controller->updateCatalog();
		$this->assertEquals(400, $response->getStatus());
	}

	/**
	 * PUT /house/catalog delegates to the versioned owner command and
	 * returns the refreshed house payload.
	 */
	public function testUpdateCatalog_DelegatesToHouseService(): void {
		$house = new House();
		$house->setId(1);
		$house->setCatalogEnabled(true);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember');
		$this->authService->method('requireHouseOwner')->willReturn(new HouseUser());
		$this->authService->method('requireCurrentUserId')->willReturn('owner');

		$this->houseService->expects($this->once())
			->method('setCatalogEnabled')
			->with(1, true, 5, '11111111-1111-4111-8111-111111111111')
			->willReturn(['catalogEnabled' => true, 'stateVersion' => 6]);

		$this->setParams(['catalogEnabled' => true, 'expectedVersion' => 5, 'commandId' => '11111111-1111-4111-8111-111111111111']);
		$response = $this->controller->updateCatalog();

		$this->assertEquals(200, $response->getStatus());
		$this->assertTrue($response->getData()['data']['catalogEnabled']);
	}

	/**
	 * PUT /house/catalog maps a stale version to 409.
	 */
	public function testUpdateCatalog_MapsStaleVersionTo409(): void {
		$house = new House();
		$house->setId(1);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember');
		$this->authService->method('requireHouseOwner')->willReturn(new HouseUser());
		$this->authService->method('requireCurrentUserId')->willReturn('owner');
		$this->houseService->method('setCatalogEnabled')
			->willThrowException(new ConflictException('The house state changed since it was displayed'));

		$this->setParams(['catalogEnabled' => true, 'expectedVersion' => 5, 'commandId' => '11111111-1111-4111-8111-111111111111']);
		$response = $this->controller->updateCatalog();

		$this->assertEquals(409, $response->getStatus());
		$this->assertEquals('CONFLICT', $response->getData()['error']['code']);
	}

	/**
	 * Get_CarriesTheArcadeGate (CS-PLAN-086 D-086-02): the flag lives in
	 * app config, yet every house payload the client stores carries it.
	 */
	public function testGet_CarriesTheArcadeGate(): void {
		$house = new House();
		$house->setId(1);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->arcadeAccess->method('isGateEnabled')->willReturn(false);

		$data = $this->controller->get()->getData()['data'];

		$this->assertFalse($data['arcadeGateEnabled']);
		$this->assertSame(array_keys($house->jsonSerialize()), array_slice(array_keys($data), 0, -1), 'the entity fields stay unchanged');
	}

	/**
	 * UpdateArcadeGate_RequiresABoolean: no write for a non-boolean value.
	 *
	 * @dataProvider nonBooleanGateValues
	 */
	public function testUpdateArcadeGate_RequiresABoolean(array $params): void {
		$house = new House();
		$house->setId(1);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->arcadeAccess->expects($this->never())->method('setGateEnabled');

		$this->setParams($params);
		$response = $this->controller->updateArcadeGate();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
		$this->assertSame('arcadeGateEnabled must be a boolean', $response->getData()['error']['message']);
	}

	public static function nonBooleanGateValues(): array {
		return [
			'missing' => [[]],
			'integer' => [['arcadeGateEnabled' => 0]],
			'string' => [['arcadeGateEnabled' => 'false']],
			'null' => [['arcadeGateEnabled' => null]],
		];
	}

	/**
	 * UpdateArcadeGate_NonOwner_Returns403BeforeValidation: the owner
	 * check runs first, so a member learns nothing about the payload rules.
	 */
	public function testUpdateArcadeGate_NonOwner_Returns403BeforeValidation(): void {
		$house = new House();
		$house->setId(1);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('requireHouseOwner')
			->willThrowException(new ForbiddenException('Owner role required'));
		$this->arcadeAccess->expects($this->never())->method('setGateEnabled');

		$this->setParams(['arcadeGateEnabled' => 'not-a-boolean']);
		$response = $this->controller->updateArcadeGate();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('FORBIDDEN', $response->getData()['error']['code']);
	}

	/**
	 * UpdateArcadeGate_DelegatesAndReturnsTheHousePayload
	 */
	public function testUpdateArcadeGate_DelegatesAndReturnsTheHousePayload(): void {
		$house = new House();
		$house->setId(1);
		$house->setName('Home');
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->arcadeAccess->expects($this->once())->method('setGateEnabled')->with(1, false);
		$this->arcadeAccess->method('isGateEnabled')->willReturn(false);

		$this->setParams(['arcadeGateEnabled' => false]);
		$response = $this->controller->updateArcadeGate();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData()['data'];
		$this->assertSame('Home', $data['name']);
		$this->assertFalse($data['arcadeGateEnabled']);
	}

	/**
	 * Swap the controller's request params (reflection on the protected
	 * AppFramework property).
	 *
	 * @param array<string, mixed> $params
	 */
	private function setParams(array $params): void {
		$mock = $this->getMockBuilder(IRequest::class)->getMock();
		$mock->method('getParams')->willReturn($params);
		$mock->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $params[$key] ?? $default
		);
		$ref = new \ReflectionClass(\OCP\AppFramework\Controller::class);
		$prop = $ref->getProperty('request');
		$prop->setAccessible(true);
		$prop->setValue($this->controller, $mock);
	}
}
