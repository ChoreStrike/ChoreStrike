<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\ChecklistController;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgress;
use OCA\ChoreStrike\Exception\NotFoundException;
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
			$this->createIdentityL10n()
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
			->method('ensureHouseMember')
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
	 * AddTaskItem_EmptyLabel_Returns400
	 */
	public function testAddTaskItem_EmptyLabel_Returns400(): void {
		$this->checklistService->expects($this->never())->method('addTaskItem');

		$response = $this->controller->addTaskItem(7, '   ');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	/**
	 * AddTaskItem_DelegatesWithHouseContext
	 */
	public function testAddTaskItem_DelegatesWithHouseContext(): void {
		$this->expectHouseMembershipContext();

		$item = new \OCA\ChoreStrike\Db\TaskChecklistItem();
		$item->setId(3);
		$item->setTaskId(7);
		$item->setLabel('Check sink');
		$item->setSortOrder(0);

		$this->checklistService->expects($this->once())
			->method('addTaskItem')
			->with(7, 'Check sink', null, 1, $this->identicalTo($this->viewer))
			->willReturn($item);

		$response = $this->controller->addTaskItem(7, 'Check sink');

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('Check sink', $data['data']['label']);
	}

	/**
	 * UpdateTaskItem_DelegatesWithHouseContext
	 */
	public function testUpdateTaskItem_DelegatesWithHouseContext(): void {
		$this->expectHouseMembershipContext();

		$item = new \OCA\ChoreStrike\Db\TaskChecklistItem();
		$item->setId(3);
		$item->setTaskId(7);
		$item->setLabel('New label');
		$item->setSortOrder(0);

		$this->checklistService->expects($this->once())
			->method('updateTaskItem')
			->with(3, 'New label', 1, $this->identicalTo($this->viewer))
			->willReturn($item);

		$response = $this->controller->updateTaskItem(3, 'New label');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	/**
	 * DeleteTaskItem_ForeignItem_MapsTo404
	 */
	public function testDeleteTaskItem_ForeignItem_MapsTo404(): void {
		$this->expectHouseMembershipContext();

		$this->checklistService->expects($this->once())
			->method('deleteTaskItem')
			->with(3, 1, $this->identicalTo($this->viewer))
			->willThrowException(new NotFoundException('Checklist item not found'));

		$response = $this->controller->deleteTaskItem(3);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	/**
	 * ReorderTaskItems_EmptyOrders_Returns400
	 */
	public function testReorderTaskItems_EmptyOrders_Returns400(): void {
		$this->request->method('getParam')->willReturn([]);

		$this->checklistService->expects($this->never())->method('reorderTaskItems');

		$response = $this->controller->reorderTaskItems();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	/**
	 * ReorderTaskItems_MalformedPayload_MapsServiceValidationTo400
	 */
	public function testReorderTaskItems_MalformedPayload_MapsServiceValidationTo400(): void {
		$this->request->method('getParam')->willReturn(['not-an-id' => 1]);
		$this->expectHouseMembershipContext();

		$this->checklistService->expects($this->once())
			->method('reorderTaskItems')
			->with(['not-an-id' => 1], 1, $this->identicalTo($this->viewer))
			->willThrowException(new \InvalidArgumentException('Item orders must map integer item ids to integer orders'));

		$response = $this->controller->reorderTaskItems();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	/**
	 * ReorderTaskItems_DelegatesWithHouseContext
	 */
	public function testReorderTaskItems_DelegatesWithHouseContext(): void {
		$this->request->method('getParam')->willReturn(['3' => 0, '4' => 1]);
		$this->expectHouseMembershipContext();

		$this->checklistService->expects($this->once())
			->method('reorderTaskItems')
			->with(['3' => 0, '4' => 1], 1, $this->identicalTo($this->viewer));

		$response = $this->controller->reorderTaskItems();

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	/**
	 * GetRoomTaskProgress_DelegatesWithHouseContext
	 */
	public function testGetRoomTaskProgress_DelegatesWithHouseContext(): void {
		$this->expectHouseMembershipContext();

		$this->checklistService->expects($this->once())
			->method('getRoomTaskProgress')
			->with(10, 1, $this->identicalTo($this->viewer))
			->willReturn(['items' => [], 'stats' => ['total' => 0]]);

		$response = $this->controller->getRoomTaskProgress(10);

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame(['total' => 0], $data['data']['stats']);
	}

	/**
	 * ToggleItem_ReturnsSuccessForAuthorizedRoomTask
	 */
	public function testToggleItem_ReturnsSuccessForAuthorizedRoomTask(): void {
		$progress = new RoomTaskChecklistProgress();
		$progress->setId(50);
		$progress->setRoomTaskId(10);
		$progress->setChecklistItemId(3);
		$progress->setIsChecked(true);

		$this->expectHouseMembershipContext();

		$this->checklistService->expects($this->once())
			->method('toggleItem')
			->with(10, 3, 1, $this->identicalTo($this->viewer))
			->willReturn(['progress' => $progress, 'stats' => ['completed' => 1, 'total' => 2]]);

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

}
