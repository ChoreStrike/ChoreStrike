<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\ChecklistController;
use OCA\ChoreStrike\Db\CycleProgress;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\ChecklistService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ChecklistController (CR-053-01 thin-controller shape).
 *
 * The controller delegates to ChecklistService with the resolved house
 * context; these tests pin the delegation contract (house id + viewer
 * passed through) and the envelope mapping. The guard semantics
 * themselves are pinned by the real-DB ChecklistApiTest.
 */
class ChecklistControllerTest extends TestCase {
	private ChecklistController $controller;
	private ChecklistService $checklistService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IRequest $request;
	private HouseUser $viewer;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->checklistService = $this->createMock(ChecklistService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);

		$viewer = new HouseUser();
		$viewer->setId(5);
		$viewer->setHouseId(1);
		$viewer->setNextcloudUserId('viewer');
		$viewer->setRole('member');
		$this->viewer = $viewer;

		$this->controller = new ChecklistController(
			$this->request,
			$this->checklistService,
			$this->houseService,
			$this->authService,
			$this->createIdentityL10n(),
			$this->createMock(AppLogger::class)
		);
	}

	private function createIdentityL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		return $l10n;
	}

	/**
	 * @return object
	 */
	private function createHouseMock(int $houseId = 1): object {
		$house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
		$house->method('getId')->willReturn($houseId);

		return $house;
	}

	private function expectHouseMembershipContext(int $houseId = 1): void {
		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');

		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->willReturn($this->createHouseMock($houseId));

		$this->authService->expects($this->once())
			->method('assertHouseMember')
			->with($houseId)
			->willReturn($this->viewer);
	}

	/**
	 * GetTaskItems_DelegatesWithHouseContext
	 */
	public function testGetTaskItems_DelegatesWithHouseContext(): void {
		$this->expectHouseMembershipContext();

		$this->checklistService->expects($this->once())
			->method('getTaskItems')
			->with(7, 1, $this->identicalTo($this->viewer))
			->willReturn([]);

		$response = $this->controller->getTaskItems(7);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	/**
	 * GetTaskItems_ServiceDenial_MapsTo404
	 */
	public function testGetTaskItems_ServiceDenial_MapsTo404(): void {
		$this->expectHouseMembershipContext();

		$this->checklistService->expects($this->once())
			->method('getTaskItems')
			->willThrowException(new NotFoundException('Task not found'));

		$response = $this->controller->getTaskItems(7);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	/**
	 * GetRoomTaskProgress_DelegatesWithHouseContext
	 */
	public function testGetRoomTaskProgress_DelegatesWithHouseContext(): void {
		$this->expectHouseMembershipContext();

		$this->checklistService->expects($this->once())
			->method('getRoomTaskProgress')
			->with(10, null, 1, $this->identicalTo($this->viewer))
			->willReturn(['items' => [], 'stats' => ['total' => 0], 'cycle' => ['cycleKey' => 'c21']]);

		$response = $this->controller->getRoomTaskProgress(10);

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame(['total' => 0], $data['data']['stats']);
		$this->assertSame('c21', $data['data']['cycle']['cycleKey']);
	}

	/**
	 * ToggleItem_ReturnsSuccessForAuthorizedRoomTask
	 */
	public function testToggleItem_ReturnsSuccessForAuthorizedRoomTask(): void {
		$progress = new CycleProgress();
		$progress->setId(50);
		$progress->setRoomTaskId(10);
		$progress->setCycleKey('c21');
		$progress->setChecklistItemId(3);
		$progress->setIsChecked(true);

		$this->expectHouseMembershipContext();

		$this->checklistService->expects($this->once())
			->method('toggleItem')
			->with(10, null, 3, null, 1, $this->identicalTo($this->viewer))
			->willReturn(['progress' => $progress, 'stats' => ['completed' => 1, 'total' => 2], 'cycle' => ['cycleKey' => 'c21']]);

		$response = $this->controller->toggleItem(10, 3);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertTrue($data['data']['progress']['isChecked']);
		$this->assertSame(['completed' => 1, 'total' => 2], $data['data']['stats']);
	}

	/**
	 * ToggleItem_Returns404WhenRoomTaskIsHidden
	 */
	public function testToggleItem_Returns404WhenRoomTaskIsHidden(): void {
		$this->expectHouseMembershipContext();

		$this->checklistService->expects($this->once())
			->method('toggleItem')
			->willThrowException(new NotFoundException('Room task not found'));

		$response = $this->controller->toggleItem(10, 3);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(404, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('RESOURCE_NOT_FOUND', $data['error']['code']);
	}

	/**
	 * RF-072-WP1-13: a malformed cycleKey on the progress endpoint is
	 * a clean 400 at the controller boundary — never an
	 * InvalidArgumentException escaping into the generic 500 handler
	 * (the completion route applies the same parse contract).
	 */
	public function testGetRoomTaskProgress_MalformedCycleKey_Returns400(): void {
		$this->expectHouseMembershipContext();
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key) => $key === 'cycleKey' ? 'not-a-key' : null
		);

		$this->checklistService->expects($this->never())->method('getRoomTaskProgress');

		$response = $this->controller->getRoomTaskProgress(10);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	/**
	 * RF-072-WP1-13: a malformed cycleKey on the toggle endpoint is a
	 * 400, not a 500 — and performs no write.
	 */
	public function testToggleItem_MalformedCycleKey_Returns400(): void {
		$this->expectHouseMembershipContext();
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key) => $key === 'cycleKey' ? 'c12x' : null
		);

		$this->checklistService->expects($this->never())->method('toggleItem');

		$response = $this->controller->toggleItem(10, 3);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	/**
	 * RF-072-WP1-13: a WELL-FORMED cycleKey passes the boundary
	 * validation and reaches the service unchanged.
	 */
	public function testToggleItem_WellFormedCycleKey_Delegates(): void {
		$this->expectHouseMembershipContext();
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key) => $key === 'cycleKey' ? 'd2026-11-21' : null
		);

		$progress = new CycleProgress();
		$progress->setId(51);
		$progress->setIsChecked(true);
		$this->checklistService->expects($this->once())
			->method('toggleItem')
			->with(10, 'd2026-11-21', 3, null, 1, $this->identicalTo($this->viewer))
			->willReturn(['progress' => $progress, 'stats' => ['completed' => 1, 'total' => 2], 'cycle' => ['cycleKey' => 'd2026-11-21']]);

		$response = $this->controller->toggleItem(10, 3);

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}
}
