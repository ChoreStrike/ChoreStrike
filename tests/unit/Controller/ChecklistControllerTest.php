<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\ChecklistController;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgress;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper;
use OCA\ChoreStrike\Db\TaskChecklistItem;
use OCA\ChoreStrike\Db\TaskChecklistItemMapper;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ChecklistController.
 */
class ChecklistControllerTest extends TestCase {
	private ChecklistController $controller;
	private TaskChecklistItemMapper $checklistItemMapper;
	private RoomTaskChecklistProgressMapper $progressMapper;
	private RoomTaskService $roomTaskService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->checklistItemMapper = $this->createMock(TaskChecklistItemMapper::class);
		$this->progressMapper = $this->createMock(RoomTaskChecklistProgressMapper::class);
		$this->roomTaskService = $this->createMock(RoomTaskService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);

		$this->controller = new ChecklistController(
			$this->request,
			$this->checklistItemMapper,
			$this->progressMapper,
			$this->roomTaskService,
			$this->houseService,
			$this->authService
		);
	}

	/**
	 * @return object
	 */
	private function createHouseMock(int $houseId = 1): object {
		$house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
		$house->method('getId')->willReturn($houseId);

		return $house;
	}

	/**
	 * @param int $houseUserId Internal house member identifier.
	 */
	private function createHouseUserMock(int $houseUserId = 5): HouseUser {
		$houseUser = $this->createMock(HouseUser::class);
		$houseUser->method('getId')->willReturn($houseUserId);

		return $houseUser;
	}

	/**
	 * @param int $houseId House identifier used by the request context.
	 */
	private function expectHouseMembershipContext(int $houseId = 1): void {
		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');

		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->willReturn($this->createHouseMock($houseId));

		$this->authService->expects($this->once())
			->method('ensureHouseMember')
			->with($houseId);
	}

	/**
	 * ToggleItem_ReturnsSuccessForAuthorizedRoomTask.
	 */
	public function testToggleItem_ReturnsSuccessForAuthorizedRoomTask(): void {
		$progress = new RoomTaskChecklistProgress();
		$progress->setId(50);
		$progress->setRoomTaskId(10);
		$progress->setChecklistItemId(3);
		$progress->setIsChecked(true);

		$this->authService->expects($this->once())
			->method('ensureHouseMember')
			->with(1)
			->willReturn($this->createHouseUserMock(5));
		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');
		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->willReturn($this->createHouseMock());
		$this->roomTaskService->expects($this->once())
			->method('validateRoomTaskOwnership')
			->with(10, 1);
		$this->progressMapper->expects($this->once())
			->method('toggle')
			->with(10, 3, 5)
			->willReturn($progress);
		$this->progressMapper->expects($this->once())
			->method('getProgressStats')
			->with(10)
			->willReturn(['completed' => 1, 'total' => 2]);

		$response = $this->controller->toggleItem(10, 3);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertTrue($data['data']['progress']['isChecked']);
		$this->assertSame(['completed' => 1, 'total' => 2], $data['data']['stats']);
	}

	/**
	 * ToggleItem_Returns404WhenRoomTaskIsHidden.
	 */
	public function testToggleItem_Returns404WhenRoomTaskIsHidden(): void {
		$this->authService->expects($this->once())
			->method('ensureHouseMember')
			->with(1)
			->willReturn($this->createHouseUserMock(5));
		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');
		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->willReturn($this->createHouseMock());
		$this->roomTaskService->expects($this->once())
			->method('validateRoomTaskOwnership')
			->with(10, 1)
			->willThrowException(new NotFoundException('Room task not found'));
		$this->progressMapper->expects($this->never())
			->method('toggle');

		$response = $this->controller->toggleItem(10, 3);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(404, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('RESOURCE_NOT_FOUND', $data['error']['code']);
	}

	/**
	 * InitializeProgress_UsesServerSideRoomTaskTaskId.
	 */
	public function testInitializeProgress_UsesServerSideRoomTaskTaskId(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(10);
		$roomTask->setTaskId(7);

		$itemA = new TaskChecklistItem();
		$itemA->setId(101);
		$itemA->setTaskId(7);
		$itemA->setLabel('Check sink');
		$itemA->setSortOrder(0);

		$itemB = new TaskChecklistItem();
		$itemB->setId(102);
		$itemB->setTaskId(7);
		$itemB->setLabel('Wipe counter');
		$itemB->setSortOrder(1);

		$this->expectHouseMembershipContext();
		$this->roomTaskService->expects($this->once())
			->method('validateRoomTaskOwnership')
			->with(10, 1);
		$this->roomTaskService->expects($this->once())
			->method('getRoomTask')
			->with(10)
			->willReturn($roomTask);
		$this->checklistItemMapper->expects($this->once())
			->method('findByTaskId')
			->with(7)
			->willReturn([$itemA, $itemB]);
		$this->progressMapper->expects($this->once())
			->method('initializeProgress')
			->with(10, [101, 102]);

		$response = $this->controller->initializeProgress(10, 999);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('Progress initialized', $data['message']);
	}
}
