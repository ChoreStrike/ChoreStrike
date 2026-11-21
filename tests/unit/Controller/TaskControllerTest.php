<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\TaskController;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\TaskService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaskController
 */
class TaskControllerTest extends TestCase {
	private TaskController $controller;
	private AppLogger $appLogger;
	private TaskService $taskService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IRequest $request;

	protected function setUp(): void {
		$this->appLogger = $this->createMock(AppLogger::class);
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->taskService = $this->createMock(TaskService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);
		$this->controller = new TaskController(
			$this->request,
			$this->taskService,
			$this->houseService,
			$this->authService,
			$this->appLogger
		);
	}

	private function createHouseMock(int $houseId = 1): object {
		$house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
		$house->method('getId')->willReturn($houseId);

		return $house;
	}

	private function createHouseUserMock(string $userId = 'caller', bool $isOwner = false): HouseUser {
		// HouseUser accessors are magic (Entity __call), so use a real entity.
		// isOwner() is a real method reading $this->role.
		$houseUser = new HouseUser();
		$houseUser->setNextcloudUserId($userId);
		$houseUser->setRole($isOwner ? 'owner' : 'member');
		return $houseUser;
	}

	private function expectHouseMembershipContext(int $houseId = 1): HouseUser {
		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');

		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->willReturn($this->createHouseMock($houseId));

		$houseUser = $this->createHouseUserMock();
		$this->authService->expects($this->once())
			->method('assertHouseMember')
			->with($houseId)
			->willReturn($houseUser);
		return $houseUser;
	}

	/**
	 * Test GET /tasks returns list successfully
	 */
	public function testIndex_ReturnsTasksSuccessfully(): void {
		$task1 = new Task();
		$task1->setId(1);
		$task1->setName('Clean Kitchen');

		$task2 = new Task();
		$task2->setId(2);
		$task2->setName('Vacuum Living Room');

		$houseUser = $this->expectHouseMembershipContext();

		$this->taskService->expects($this->once())
			->method('getVisibleTasks')
			->with(1, $this->identicalTo($houseUser))
			->willReturn([$task1, $task2]);

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(2, $data['data']);
	}

	// CS-PLAN-063: the former POST /tasks create tests were pruned with the
	// endpoint, and CS-PLAN-091 WP-B6 pruned PUT /tasks/{id}: the one form
	// saves through POST /room-tasks and PUT /room-tasks/{id} (covered by
	// RoomTaskControllerTest, RoomTaskEditControllerTest and the services).
}
