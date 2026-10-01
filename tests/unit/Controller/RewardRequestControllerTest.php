<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\RewardRequestController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RewardRequestService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the request lifecycle HTTP surface (CS-PLAN-070 WP7,
 * CS-PLAN-078 §3.3/C4.10): explicit confirmation flags (validated by the
 * service), 404-vs-403 mapping and localized safe errors.
 */
class RewardRequestControllerTest extends TestCase {
	private RewardRequestController $controller;
	private $requestService;
	private $authService;
	private $houseService;

	protected function setUp(): void {
		$this->requestService = $this->createMock(RewardRequestService::class);
		$this->authService = $this->createMock(AuthorizationService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->controller = new RewardRequestController(
			$this->createRequest([]),
			$this->requestService,
			$this->authService,
			$this->houseService,
			$l10n,
			$this->createMock(AppLogger::class),
		);

		$house = new House();
		$house->setId(1);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')->willReturn(new HouseUser());
		$this->authService->method('requireCurrentUserId')->willReturn('member');
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function createRequest(array $params): IRequest {
		$mock = $this->getMockBuilder(IRequest::class)->getMock();
		$mock->method('getParams')->willReturn($params);
		$mock->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => array_key_exists($key, $params) ? $params[$key] : $default
		);
		return $mock;
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function useRequest(array $params): void {
		$ref = new \ReflectionClass(\OCP\AppFramework\Controller::class);
		$prop = $ref->getProperty('request');
		$prop->setAccessible(true);
		$prop->setValue($this->controller, $this->createRequest($params));
	}

	public function testIndex_RejectsUnknownScope(): void {
		$this->useRequest(['scope' => 'everyone']);
		$response = $this->controller->index();

		$this->assertEquals(400, $response->getStatus());
		$this->assertEquals('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	public function testIndex_SurfacesTheServiceStateFilterValidationAs400(): void {
		$this->useRequest(['state' => 'expired']);
		$this->requestService->method('listForViewer')
			->willThrowException(new \InvalidArgumentException('Unknown state filter'));

		$response = $this->controller->index();

		$this->assertEquals(400, $response->getStatus());
	}

	public function testShow_MapsInvisibleRequestTo404(): void {
		$this->requestService->method('showForViewer')
			->willThrowException(new NotFoundException('Reward request not found'));

		$response = $this->controller->show(5);

		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	public function testCreate_DelegatesAndReturnsDto(): void {
		$this->useRequest(['itemId' => 7, 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => '11111111-1111-4111-8111-111111111111']);
		$this->requestService->expects($this->once())
			->method('create')
			->with(1, 'member', $this->callback(static fn (array $p): bool => $p['itemId'] === 7 && $p['priceMinor'] === 1250))
			->willReturn(['id' => 100, 'state' => 'pending', 'version' => 0]);

		$response = $this->controller->create();

		$this->assertEquals(200, $response->getStatus());
		$this->assertSame('pending', $response->getData()['data']['state']);
	}

	public function testFulfill_MapsTheServiceConfirmationCheckTo400(): void {
		$this->useRequest(['commandId' => '11111111-1111-4111-8111-111111111111', 'expectedVersion' => 1]);
		// The service enforces the explicit delivered confirmation; the
		// controller surfaces it as 400.
		$this->requestService->method('fulfill')
			->willThrowException(new \InvalidArgumentException('Fulfillment requires the explicit delivered confirmation'));

		$response = $this->controller->fulfill(5);

		$this->assertEquals(400, $response->getStatus());
	}

	public function testFulfill_PassesThroughWithDeliveredConfirmation(): void {
		$this->useRequest(['commandId' => '11111111-1111-4111-8111-111111111111', 'expectedVersion' => 1, 'delivered' => true]);
		$this->requestService->expects($this->once())
			->method('fulfill')
			->with(1, 'member', 5, $this->callback(static fn (array $p): bool => $p['delivered'] === true))
			->willReturn(['id' => 5, 'state' => 'fulfilled']);

		$response = $this->controller->fulfill(5);

		$this->assertEquals(200, $response->getStatus());
	}

	public function testCorrect_MapsTheServiceConfirmationCheckTo400(): void {
		$this->useRequest(['commandId' => '11111111-1111-4111-8111-111111111111', 'expectedVersion' => 2, 'reasonCode' => 'not_delivered', 'settlementEntryId' => 9]);
		$this->requestService->method('correct')
			->willThrowException(new \InvalidArgumentException('Correction requires the explicit notDelivered confirmation'));

		$response = $this->controller->correct(5);

		$this->assertEquals(400, $response->getStatus());
	}

	public function testLifecycleActions_MapBusinessConflictsTo409(): void {
		$this->useRequest(['commandId' => '11111111-1111-4111-8111-111111111111', 'expectedVersion' => 0]);
		$this->requestService->method('cancel')
			->willThrowException(new ConflictException('This request is already settled and can no longer be cancelled'));

		$response = $this->controller->cancel(5);

		$this->assertEquals(409, $response->getStatus());
		$this->assertEquals('CONFLICT', $response->getData()['error']['code']);
	}

	public function testLifecycleActions_MapRoleDenialsTo403(): void {
		$this->useRequest(['commandId' => '11111111-1111-4111-8111-111111111111', 'expectedVersion' => 0]);
		$this->requestService->method('approve')
			->willThrowException(new ForbiddenException('You are not allowed to perform this action on this request'));

		$response = $this->controller->approve(5);

		$this->assertEquals(403, $response->getStatus());
		$this->assertEquals('FORBIDDEN', $response->getData()['error']['code']);
	}

	public function testLifecycleActions_MapForeignRequestsTo404(): void {
		$this->useRequest(['commandId' => '11111111-1111-4111-8111-111111111111', 'expectedVersion' => 0]);
		$this->requestService->method('cancel')
			->willThrowException(new NotFoundException('Reward request not found'));

		$response = $this->controller->cancel(5);

		$this->assertEquals(404, $response->getStatus());
	}
}
