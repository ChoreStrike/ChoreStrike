<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\TaskController;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TaskService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaskController
 */
class TaskControllerTest extends TestCase {
	private TaskController $controller;
	private TaskService $taskService;
	private RoomTaskService $roomTaskService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->taskService = $this->createMock(TaskService::class);
		$this->roomTaskService = $this->createMock(RoomTaskService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);

		$this->controller = new TaskController(
			$this->request,
			$this->taskService,
			$this->roomTaskService,
			$this->houseService,
			$this->authService
		);
	}

	private function createHouseMock(int $houseId = 1): object {
		$house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
		$house->method('getId')->willReturn($houseId);

		return $house;
	}

	private function createHouseUserMock(string $userId = 'caller', bool $isOwner = false): object {
		// HouseUser accessors are magic (Entity __call), so use a real entity.
		// isOwner() is a real method reading $this->role.
		$houseUser = new \OCA\ChoreStrike\Db\HouseUser();
		$houseUser->setNextcloudUserId($userId);
		$houseUser->setRole($isOwner ? 'owner' : 'member');
		return $houseUser;
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
			->willReturn($this->createHouseUserMock());
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

		$this->expectHouseMembershipContext();

		$this->taskService->expects($this->once())
			->method('getVisibleTasks')
			->willReturn([$task1, $task2]);

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(2, $data['data']);
	}

	/**
	 * Test POST /tasks validates empty name
	 */
	public function testCreate_ValidatesEmptyName(): void {
		$this->expectHouseMembershipContext();

		$this->taskService->expects($this->once())
			->method('validateTaskData')
			->willReturn(['Task name is required']);

		$this->taskService->expects($this->never())
			->method('createTask');

		$response = $this->controller->create('');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
		$this->assertEquals('Validation failed', $data['error']['message']);
		$this->assertEquals(['Task name is required'], $data['error']['details']);
	}

	/**
	 * Test POST /tasks creates successfully
	 */
	public function testCreate_CreatesSuccessfully(): void {
		$task = new Task();
		$task->setId(1);
		$task->setName('Clean Kitchen');

		$this->expectHouseMembershipContext();

		$this->taskService->expects($this->once())
			->method('validateTaskData')
			->willReturn([]);

		$this->taskService->expects($this->once())
			->method('createTask')
			->willReturn($task);

		$response = $this->controller->create('Clean Kitchen');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('Clean Kitchen', $data['data']['name']);
	}

	/**
	 * Test GET /tasks/{id} returns task successfully
	 */
	public function testShow_ReturnsTaskSuccessfully(): void {
		$task = new Task();
		$task->setId(1);
		$task->setName('Clean Kitchen');

		$this->expectHouseMembershipContext();

		$this->taskService->expects($this->once())
			->method('getTask')
			->with(1)
			->willReturn($task);

		$response = $this->controller->show(1);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('Clean Kitchen', $data['data']['name']);
	}

	/**
	 * Test DELETE /tasks/{id} deletes successfully
	 */
	public function testDelete_DeletesSuccessfully(): void {
		$this->expectHouseMembershipContext();

		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PUBLIC);

		$this->taskService->expects($this->once())
			->method('getTask')
			->with(1)
			->willReturn($task);

		$this->taskService->expects($this->once())
			->method('deleteTask')
			->with(1, 'caller', false);

		$response = $this->controller->delete(1);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * Test DELETE /tasks/{id} returns 404 for hidden or missing resource
	 */
	public function testDelete_Returns404WhenTaskIsHiddenOrMissing(): void {
		$this->expectHouseMembershipContext();

		$this->taskService->expects($this->once())
			->method('getTask')
			->with(1)
			->willThrowException(new NotFoundException('Task not found'));

		$this->taskService->expects($this->never())
			->method('deleteTask');

		$response = $this->controller->delete(1);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(404, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('RESOURCE_NOT_FOUND', $data['error']['code']);
	}

	/**
	 * Test DELETE /tasks/{id} returns 403 for forbidden actions
	 */
	public function testDelete_Returns403WhenActionIsForbidden(): void {
		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');

		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->willReturn($this->createHouseMock());

		$this->authService->expects($this->once())
			->method('ensureHouseMember')
			->with(1)
			->willThrowException(new ForbiddenException('Owner role required'));

		$this->taskService->expects($this->never())
			->method('deleteTask');

		$response = $this->controller->delete(1);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(403, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('FORBIDDEN', $data['error']['code']);
	}

	/**
	 * A private task may only be deleted by its owner; a non-owner member
	 * receives 404 (anti-enumeration, same as show()).
	 */
	public function testDelete_Returns404WhenNonOwnerDeletesPrivateTask(): void {
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createHouseMock());
		$this->authService->method('ensureHouseMember')
			->willReturn($this->createHouseUserMock('member', false));

		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PRIVATE);
		$task->setOwnerUserId('someone-else');

		$this->taskService->expects($this->once())->method('getTask')->willReturn($task);
		$this->taskService->expects($this->never())->method('deleteTask');

		$response = $this->controller->delete(1);

		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	/**
	 * An admin-only task may only be deleted by a house owner; a non-owner
	 * member receives 404.
	 */
	public function testDelete_Returns404WhenNonOwnerDeletesAdminOnlyTask(): void {
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createHouseMock());
		$this->authService->method('ensureHouseMember')
			->willReturn($this->createHouseUserMock('member', false));

		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_ADMIN_ONLY);

		$this->taskService->expects($this->once())->method('getTask')->willReturn($task);
		$this->taskService->expects($this->never())->method('deleteTask');

		$response = $this->controller->delete(1);

		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	/**
	 * The owner of a private task may delete it.
	 */
	public function testDelete_AllowsOwnerToDeleteOwnPrivateTask(): void {
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createHouseMock());
		$this->authService->method('ensureHouseMember')
			->willReturn($this->createHouseUserMock('owner', true));

		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PRIVATE);
		$task->setOwnerUserId('owner');

		$this->taskService->expects($this->once())->method('getTask')->willReturn($task);
		$this->taskService->expects($this->once())->method('deleteTask')->with(1, 'owner', true);

		$response = $this->controller->delete(1);

		$this->assertEquals(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	/**
	 * A non-owner may not update another member's private task. The update
	 * gate mirrors show()/delete() and returns 404 (anti-enumeration) before
	 * any mutation. Regression for the missing visibility gate on update().
	 */
	public function testUpdate_Returns404WhenNonOwnerUpdatesPrivateTask(): void {
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createHouseMock());
		$this->authService->method('ensureHouseMember')
			->willReturn($this->createHouseUserMock('member', false));

		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PRIVATE);
		$task->setOwnerUserId('someone-else');

		$this->taskService->expects($this->once())->method('getTask')->willReturn($task);
		$this->taskService->expects($this->never())->method('updateTask');

		$response = $this->controller->update(1, ['name' => 'Hijacked']);

		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	/**
	 * A non-owner may not update an admin-only task → 404.
	 */
	public function testUpdate_Returns404WhenNonOwnerUpdatesAdminOnlyTask(): void {
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createHouseMock());
		$this->authService->method('ensureHouseMember')
			->willReturn($this->createHouseUserMock('member', false));

		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_ADMIN_ONLY);

		$this->taskService->expects($this->once())->method('getTask')->willReturn($task);
		$this->taskService->expects($this->never())->method('updateTask');

		$response = $this->controller->update(1, ['name' => 'Hijacked']);

		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	/**
	 * The legacy `isPrivate` shim must be normalized into the canonical
	 * `visibilityType` (and stripped) so it can't clobber an admin-only
	 * value or bypass the transition gate. Going private must also claim
	 * ownership so the task isn't orphaned (private + null owner).
	 */
	public function testUpdate_NormalizesIsPrivateAndClaimsOwnershipWhenGoingPrivate(): void {
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createHouseMock());
		$this->authService->method('ensureHouseMember')
			->willReturn($this->createHouseUserMock('member', false));

		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PUBLIC);

		$this->taskService->expects($this->once())->method('getTask')->willReturn($task);
		$this->taskService->method('validateTaskData')->willReturn([]);

		$captured = null;
		$this->taskService->expects($this->once())
			->method('updateTask')
			->willReturnCallback(function (int $id, array $updates) use ($task, &$captured) {
				$captured = $updates;
				return $task;
			});

		$response = $this->controller->update(1, ['isPrivate' => true]);

		$this->assertEquals(200, $response->getStatus());
		$this->assertSame(Task::VISIBILITY_PRIVATE, $captured['visibilityType']);
		$this->assertArrayNotHasKey('isPrivate', $captured);
		$this->assertSame('member', $captured['ownerUserId']);
	}
}
