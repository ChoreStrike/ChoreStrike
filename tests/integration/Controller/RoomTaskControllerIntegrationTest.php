<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\RoomTaskController;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for RoomTaskController API endpoints
 */
class RoomTaskControllerIntegrationTest extends TestCase {
	private RoomTaskController $controller;
	private RoomTaskService $roomTaskService;
	private \OCA\ChoreStrike\Service\RoomTaskReadService|PHPUnit\Framework\MockObject\MockObject $roomTaskReadService;
	private CompletionService|PHPUnit\Framework\MockObject\MockObject $completionService;
	private \OCA\ChoreStrike\Service\TaskCreationService|PHPUnit\Framework\MockObject\MockObject $taskCreationService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->roomTaskService = $this->createMock(RoomTaskService::class);
		$this->roomTaskReadService = $this->createMock(\OCA\ChoreStrike\Service\RoomTaskReadService::class);
		$this->completionService = $this->createMock(CompletionService::class);
		$this->taskCreationService = $this->createMock(\OCA\ChoreStrike\Service\TaskCreationService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);
		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->controller = new RoomTaskController(
			$this->request,
			$this->roomTaskService,
			$this->roomTaskReadService,
			$this->completionService,
			$this->taskCreationService,
			$this->houseService,
			$this->authService,
			$l10n,
			new CalendarService(),
			\OC::$server->get(AppLogger::class)
		);
	}

	/**
	 * Index_ReturnsRoomTasksList_SuccessResponse
	 */
	public function testIndex_ReturnsRoomTasksList_SuccessResponse(): void {
		$roomId = 1;
		$roomTasks = [
			$this->createMockRoomTask(1, 1, $roomId),
			$this->createMockRoomTask(2, 2, $roomId),
		];

		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->authService->method('assertHouseMember')->willReturn($this->createMockHouseUser());
		$this->roomTaskReadService->method('getRoomTasks')
			->with($roomId, $this->isInstanceOf(HouseUser::class), true)
			->willReturn($roomTasks);
		$this->roomTaskReadService->method('assigneesByRoomTasks')->willReturn([]);

		$response = $this->controller->index($roomId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	private function createMockRoomTask(int $id, int $taskId, int $roomId): \OCA\ChoreStrike\Db\RoomTask {
		$roomTask = new \OCA\ChoreStrike\Db\RoomTask();
		$roomTask->setId($id);
		$roomTask->setTaskId($taskId);
		$roomTask->setRoomId($roomId);
		$roomTask->setIsActive(true);
		$roomTask->setNextDue(new \DateTime());
		$roomTask->setCreatedAt(new \DateTime());
		return $roomTask;
	}

	private function createMockHouse(): \OCA\ChoreStrike\Db\House {
		$house = new \OCA\ChoreStrike\Db\House();
		$house->setId(1);
		$house->setNextcloudInstanceId('test-instance');
		$house->setName('Test House');
		return $house;
	}

	private function createMockHouseUser(): \OCA\ChoreStrike\Db\HouseUser {
		$user = new \OCA\ChoreStrike\Db\HouseUser();
		$user->setId(1);
		$user->setHouseId(1);
		$user->setNextcloudUserId('test-user');
		$user->setDisplayName('Test User');
		$user->setRole('member');
		return $user;
	}

	/**
	 * Create_ValidData_ReturnsAggregateShape
	 *
	 * CS-PLAN-063 §3.3: the creation endpoint routes through the atomic
	 * TaskCreationService and returns the unified aggregate shape.
	 */
	public function testCreate_ValidData_ReturnsAggregateShape(): void {
		$roomId = 1;
		$taskId = 5;

		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key) => ['roomId' => $roomId, 'taskId' => $taskId][$key] ?? null
		);
		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->authService->method('ensureHouseMember')->willReturn($this->createMockHouseUser());
		$this->taskCreationService->method('create')
			->willReturn([
				'task' => $this->createMockTask($taskId, 'Test Task'),
				'roomTask' => $this->createMockRoomTask(3, $taskId, $roomId),
				'assignees' => [],
			]);

		$response = $this->controller->create();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('task', $data['data']);
		$this->assertArrayHasKey('roomTask', $data['data']);
		$this->assertArrayHasKey('assignees', $data['data']);
	}

	/**
	 * Complete_ExistingRoomTask_ReturnsSuccessWithCompletion
	 */
	public function testComplete_ExistingRoomTask_ReturnsSuccessWithCompletion(): void {
		$roomTaskId = 1;
		// CompletionService::complete returns array with roomTask, room,
		// task, rewards plus the occurrence receipt surface (WP1).
		$completedData = [
			'roomTask' => $this->createMockRoomTask($roomTaskId, 1, 1),
			'room' => $this->createMockRoom(1, 'Living Room', 1, 85),
			'task' => $this->createMockTask(1, 'Test Task'),
			'rewards' => ['coinsEarned' => 10],
			'completionId' => 7,
			'occurrence' => null,
			'replay' => false,
		];

		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->authService->method('ensureHouseMember')->willReturn($this->createMockHouseUser());
		$this->authService->method('requireCurrentUserId')->willReturn('test-user');
		$this->roomTaskService->method('validateRoomTaskOwnership');
		$this->completionService->method('complete')
			->willReturn($completedData);
		// The receipt contract (CS-PLAN-072 WP1) rides the request params.
		$this->request->method('getParams')->willReturn([
			'commandId' => '11111111-2222-4333-8444-555555555555',
			'occurrenceId' => '1:1',
			'expectedVersion' => 0,
			'selectionToken' => 'token',
		]);

		$response = $this->controller->complete($roomTaskId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * Stats endpoint removed — no coverage existed to migrate.
	 */

	/**
	 * Overdue_ReturnsOverdueTasks_SuccessResponse
	 */
	public function testOverdue_ReturnsOverdueTasks_SuccessResponse(): void {
		$overdueTasks = [
			$this->createMockRoomTask(1, 1, 1),
			$this->createMockRoomTask(2, 2, 2),
		];

		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->authService->method('assertHouseMember')->willReturn($this->createMockHouseUser());
		$this->roomTaskReadService->method('getOverdueTaskRows')
			->with(1, $this->isInstanceOf(HouseUser::class), null)
			->willReturn([
				['roomTask' => $overdueTasks[0], 'task' => $this->createMockTask(1, 'A'), 'room' => $this->createMockHouse()],
				['roomTask' => $overdueTasks[1], 'task' => $this->createMockTask(2, 'B'), 'room' => $this->createMockHouse()],
			]);
		$this->roomTaskReadService->method('assigneesByRoomTasks')->willReturn([]);

		$response = $this->controller->overdue();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * BulkAssign_ValidPayload_DelegatesToServiceWithHouseScope
	 *
	 * CR-SVC-61: the endpoint is a thin delegation to the batched service
	 * write path; ids and target user pass through unmodified.
	 */
	public function testBulkAssign_ValidPayload_DelegatesToServiceWithHouseScope(): void {
		$assigned = $this->createMockRoomTask(7, 1, 1);

		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->authService->method('assertHouseMember')->willReturn($this->createMockHouseUser());
		$this->roomTaskService->expects($this->once())
			->method('bulkAssignTasks')
			->with([7, 9], 'user456', 1, $this->isInstanceOf(HouseUser::class))
			->willReturn([$assigned]);
		$this->roomTaskReadService->method('assigneesByRoomTasks')->willReturn([7 => []]);

		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ['userId' => 'user456', 'roomTaskIds' => [7, 9]][$key] ?? $default
		);

		$response = $this->controller->bulkAssign();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame([7], array_map(fn ($rt) => $rt['id'], $data['data']));
	}

	/**
	 * BulkAssign_NonMemberCaller_ReturnsForbiddenEnvelope
	 */
	public function testBulkAssign_NonMemberCaller_ReturnsForbiddenEnvelope(): void {
		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->authService->method('assertHouseMember')
			->willThrowException(new ForbiddenException('Not a member of this house'));

		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ['userId' => 'user456', 'roomTaskIds' => [7]][$key] ?? $default
		);

		$response = $this->controller->bulkAssign();

		$this->assertEquals(403, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('FORBIDDEN', $data['error']['code']);
	}

	/**
	 * BulkAssign_ServiceValidationError_Returns400
	 *
	 * A non-member target user fails the whole request up front in the
	 * service (CR-SVC-09/CS-PLAN-037) — surfaced as a 400 validation error.
	 */
	public function testBulkAssign_ServiceValidationError_Returns400(): void {
		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->authService->method('assertHouseMember')->willReturn($this->createMockHouseUser());
		$this->roomTaskService->method('bulkAssignTasks')
			->willThrowException(new \InvalidArgumentException('User is not a member of this house: stranger'));

		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ['userId' => 'stranger', 'roomTaskIds' => [7]][$key] ?? $default
		);

		$response = $this->controller->bulkAssign();

		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('VALIDATION_ERROR', $data['error']['code']);
	}

	private function createMockRoom(int $id, string $name, int $floorId, int $cleanliness): \OCA\ChoreStrike\Db\Room {
		$room = new \OCA\ChoreStrike\Db\Room();
		$room->setId($id);
		$room->setName($name);
		$room->setFloorId($floorId);
		$room->setCleanliness($cleanliness);
		$room->setIcon('Home');
		$room->setCreatedAt(new \DateTime());
		return $room;
	}

	private function createMockTask(int $id, string $name): \OCA\ChoreStrike\Db\Task {
		$task = new \OCA\ChoreStrike\Db\Task();
		$task->setId($id);
		$task->setName($name);
		$task->setDescription('Test task description');
		$task->setEnergyCost(10);
		$task->setPriority(1);
		$task->setFrequencyType('daily');
		$task->setFrequencyValue(1);
		$task->setEstimatedMinutes(15);
		$task->setCategory('cleaning');
		$task->setScopeType('room');
		$task->setRecurrenceMode('repeat');
		$task->setIsVoluntary(false);
		$task->setSchedulingMode('flexible');
		$task->setCreatedAt(new \DateTime());
		return $task;
	}
}
