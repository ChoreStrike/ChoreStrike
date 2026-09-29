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
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\TaskService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaskController
 */
class TaskControllerTest extends TestCase {
	private TaskController $controller;
	private TaskService $taskService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->taskService = $this->createMock(TaskService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);
		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->controller = new TaskController(
			$this->request,
			$this->taskService,
			$this->houseService,
			$this->authService,
			$l10n,
			$this->createMock(AppLogger::class)
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
	 * BUG-API-001 (D6): the update endpoint reads its closed payload from
	 * the request parameters (top-level JSON body keys merged over the
	 * route internals), so the unit tests mock exactly that dispatcher
	 * source instead of invoking a two-argument method the real dispatcher
	 * can never bind.
	 *
	 * @param array<string, mixed> $updates Top-level body keys
	 */
	private function mockUpdateRequest(array $updates, int $taskId = 1): void {
		$this->request->method('getParams')->willReturn(array_merge(
			['id' => (string)$taskId, '_route' => 'chorestrike.tasks.update'],
			$updates
		));
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
	// endpoint — creation runs through the atomic aggregate on
	// POST /room-tasks (covered by RoomTaskControllerTest and the
	// TaskCreationService tests).

	/**
	 * A non-owner may not update another member's private task. The update
	 * gate loads the task through the visibility policy (getVisibleTask),
	 * which denies invisible tasks with the app NotFoundException → 404
	 * (anti-enumeration) before any mutation. Regression for the missing
	 * visibility gate on update().
	 */
	public function testUpdate_Returns404WhenNonOwnerUpdatesPrivateTask(): void {
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createHouseMock());
		$this->authService->method('assertHouseMember')
			->willReturn($this->createHouseUserMock('member', false));

		$this->taskService->expects($this->once())
			->method('getVisibleTask')
			->with(1, $this->isInstanceOf(HouseUser::class))
			->willThrowException(new NotFoundException('Task not found'));
		$this->taskService->expects($this->never())->method('updateTask');

		$this->mockUpdateRequest(['name' => 'Hijacked']);
		$response = $this->controller->update(1);

		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	/**
	 * A non-owner may not update an admin-only task → 404 (the service's
	 * visibility policy denies the invisible task up front).
	 */
	public function testUpdate_Returns404WhenNonOwnerUpdatesAdminOnlyTask(): void {
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createHouseMock());
		$this->authService->method('assertHouseMember')
			->willReturn($this->createHouseUserMock('member', false));

		$this->taskService->expects($this->once())
			->method('getVisibleTask')
			->willThrowException(new NotFoundException('Task not found'));
		$this->taskService->expects($this->never())->method('updateTask');

		$this->mockUpdateRequest(['name' => 'Hijacked']);
		$response = $this->controller->update(1);

		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	/**
	 * A stale client still sending the removed `isPrivate` key has it
	 * dropped (CS-PLAN-060 WP1 D1); the canonical `visibilityType` stays
	 * the sole source of truth.
	 */
	public function testUpdate_DropsLegacyIsPrivateKey(): void {
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createHouseMock());
		$this->authService->method('assertHouseMember')
			->willReturn($this->createHouseUserMock('member', false));

		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PUBLIC);

		$this->taskService->expects($this->once())->method('getVisibleTask')->willReturn($task);

		$captured = null;
		$this->taskService->expects($this->once())
			->method('updateTask')
			->willReturnCallback(function (int $id, array $updates, HouseUser $actor) use ($task, &$captured) {
				$captured = $updates;
				return $task;
			});

		$this->mockUpdateRequest(['isPrivate' => true]);
		$response = $this->controller->update(1);

		$this->assertEquals(200, $response->getStatus());
		$this->assertArrayNotHasKey('isPrivate', $captured);
		$this->assertArrayNotHasKey('visibilityType', $captured);
	}

	/**
	 * CR-SVC-17 (CS-PLAN-061 WP2): the controller must pass the
	 * authenticated HouseUser actor into the service's visibility matrix.
	 */
	public function testUpdate_PassesAuthorizationContextToService(): void {
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createHouseMock());
		$houseUser = $this->createHouseUserMock('owner', true);
		$this->authService->method('assertHouseMember')
			->willReturn($houseUser);

		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PUBLIC);

		$this->taskService->expects($this->once())->method('getVisibleTask')->willReturn($task);

		$this->taskService->expects($this->once())
			->method('updateTask')
			->with(1, ['name' => 'Renamed'], $this->identicalTo($houseUser))
			->willReturn($task);

		$this->mockUpdateRequest(['name' => 'Renamed']);
		$response = $this->controller->update(1);

		$this->assertEquals(200, $response->getStatus());
	}

	/**
	 * CR-SVC-17 defense-in-depth: a visibility denial from the service's
	 * updateTask matrix (app NotFoundException, CS-PLAN-061 §3.2) must
	 * surface as 404 (anti-enumeration) — never a 403 that confirms the
	 * task exists.
	 */
	public function testUpdate_ServiceVisibilityDenial_Returns404(): void {
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createHouseMock());
		$this->authService->method('assertHouseMember')
			->willReturn($this->createHouseUserMock('member', false));

		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PUBLIC);

		$this->taskService->expects($this->once())->method('getVisibleTask')->willReturn($task);
		$this->taskService->method('updateTask')
			->willThrowException(new NotFoundException('Task not found'));

		$this->mockUpdateRequest(['ownerUserId' => 'attacker']);
		$response = $this->controller->update(1);

		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
		$this->assertEquals('Task not found', $response->getData()['error']['message']);
	}

	/**
	 * WP4 retirement pin (CS-PLAN-070 §3.4): the tier gate is gone — a
	 * stale client sending `taskTier` flows through to the service and
	 * dies on the generic whitelist rejection (400 via ValidationException).
	 */
	public function testUpdate_StaleTaskTierPayload_ServiceWhitelistRejection(): void {
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createHouseMock());
		$this->authService->method('assertHouseMember')
			->willReturn($this->createHouseUserMock('member', false));

		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PUBLIC);

		$this->taskService->expects($this->once())->method('getVisibleTask')->willReturn($task);
		$this->taskService->expects($this->once())
			->method('updateTask')
			->willThrowException(new \OCA\ChoreStrike\Exception\ValidationException('Unknown or immutable field: taskTier'));

		$this->mockUpdateRequest(['taskTier' => 'gold']);
		$response = $this->controller->update(1);

		$this->assertEquals(400, $response->getStatus());
		$this->assertEquals('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	/**
	 * BUG-API-001 (D6) dispatcher regression: the controller serves the
	 * real HTTP boundary, where the top-level body keys are merged over
	 * the route internals (`id`, `_route`) — the production client shape
	 * sends definition fields top-level, and the dispatcher can never bind
	 * an `updates` parameter from it. Route internals must never leak into
	 * the service payload, and the real top-level shape must reach
	 * updateTask verbatim.
	 */
	public function testUpdate_RealHttpBodyShape_ReachesServiceWithoutRouteInternals(): void {
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createHouseMock());
		$this->authService->method('assertHouseMember')
			->willReturn($this->createHouseUserMock('owner', true));

		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PUBLIC);

		$this->taskService->expects($this->once())->method('getVisibleTask')->willReturn($task);

		$captured = null;
		$this->taskService->expects($this->once())
			->method('updateTask')
			->willReturnCallback(function (int $id, array $updates, HouseUser $actor) use ($task, &$captured) {
				$captured = $updates;
				return $task;
			});

		// The production client shape (tasks.js::updateTask): edited
		// definition fields as the top-level JSON body — no `updates` key.
		$this->mockUpdateRequest([
			'name' => 'Renamed',
			'description' => 'New description',
			'energyCost' => 3,
		]);
		$response = $this->controller->update(1);

		$this->assertEquals(200, $response->getStatus());
		$this->assertNotNull($captured);
		$this->assertEquals([
			'name' => 'Renamed',
			'description' => 'New description',
			'energyCost' => 3,
		], $captured);
		$this->assertArrayNotHasKey('id', $captured);
		$this->assertArrayNotHasKey('_route', $captured);
		$this->assertArrayNotHasKey('updates', $captured);
	}
}
