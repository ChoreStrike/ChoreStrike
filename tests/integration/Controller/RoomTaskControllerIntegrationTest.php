<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\RoomTaskController;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for RoomTaskController API endpoints
 */
class RoomTaskControllerIntegrationTest extends TestCase {

	private RoomTaskController $controller;
	private RoomTaskService $roomTaskService;
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->roomTaskService = $this->createMock(RoomTaskService::class);

		$this->controller = new RoomTaskController(
			$this->request,
			$this->roomTaskService
		);
	}

	/**
	 * Index_ReturnsRoomTasksList_SuccessResponse
	 */
	public function testIndex_ReturnsRoomTasksList_SuccessResponse(): void {
		$roomId = 1;
		$roomTasks = [
			[
				'id' => 1,
				'taskId' => 1,
				'roomId' => $roomId,
				'taskName' => 'Vacuum floor',
				'isActive' => true,
				'nextDue' => '2026-02-25T10:00:00Z',
			],
			[
				'id' => 2,
				'taskId' => 2,
				'roomId' => $roomId,
				'taskName' => 'Dust surfaces',
				'isActive' => true,
				'nextDue' => '2026-02-26T14:00:00Z',
			],
		];

		$this->roomTaskService->method('getRoomTasks')
			->with($roomId)
			->willReturn($roomTasks);

		$response = $this->controller->index($roomId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($roomTasks, $data['data']);
	}

	/**
	 * Create_ValidData_ReturnsCreatedRoomTask
	 */
	public function testCreate_ValidData_ReturnsCreatedRoomTask(): void {
		$roomId = 1;
		$taskId = 5;
		$data = [
			'notes' => 'Weekly vacuuming',
			'nextDue' => '2026-02-28T10:00:00Z',
		];

		$createdRoomTask = [
			'id' => 3,
			'roomId' => $roomId,
			'taskId' => $taskId,
			'notes' => 'Weekly vacuuming',
			'nextDue' => '2026-02-28T10:00:00Z',
			'isActive' => true,
		];

		$this->roomTaskService->method('createRoomTask')
			->with($roomId, $taskId, $data)
			->willReturn($createdRoomTask);

		$response = $this->controller->create($roomId, $taskId, $data);

		$this->assertEquals(201, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($createdRoomTask, $data['data']);
	}

	/**
	 * Complete_ExistingRoomTask_ReturnsSuccessWithCompletion
	 */
	public function testComplete_ExistingRoomTask_ReturnsSuccessWithCompletion(): void {
		$roomTaskId = 1;
		$userId = 'user123';
		$completion = [
			'id' => 1,
			'roomTaskId' => $roomTaskId,
			'userId' => $userId,
			'completedAt' => '2026-02-24T10:30:00Z',
			'contributionScore' => 25,
			'teamSparks' => 15,
		];

		$this->roomTaskService->method('completeTask')
			->with($roomTaskId, $userId)
			->willReturn($completion);

		$response = $this->controller->complete($roomTaskId, $userId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($completion, $data['data']);
	}

	/**
	 * Assign_ValidUser_ReturnsAssignedRoomTask
	 */
	public function testAssign_ValidUser_ReturnsAssignedRoomTask(): void {
		$roomTaskId = 1;
		$userId = 'user456';
		$assignedRoomTask = [
			'id' => $roomTaskId,
			'assignedTo' => $userId,
			'taskName' => 'Vacuum floor',
			'isActive' => true,
		];

		$this->roomTaskService->method('assignTask')
			->with($roomTaskId, $userId)
			->willReturn($assignedRoomTask);

		$response = $this->controller->assign($roomTaskId, $userId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($userId, $data['data']['assignedTo']);
	}

	/**
	 * Unassign_ExistingAssignment_ReturnsUnassignedRoomTask
	 */
	public function testUnassign_ExistingAssignment_ReturnsUnassignedRoomTask(): void {
		$roomTaskId = 1;
		$unassignedRoomTask = [
			'id' => $roomTaskId,
			'assignedTo' => null,
			'taskName' => 'Vacuum floor',
			'isActive' => true,
		];

		$this->roomTaskService->method('unassignTask')
			->with($roomTaskId)
			->willReturn($unassignedRoomTask);

		$response = $this->controller->unassign($roomTaskId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertNull($data['data']['assignedTo']);
	}

	/**
	 * Stats_ReturnsRoomTaskStatistics_SuccessResponse
	 */
	public function testStats_ReturnsRoomTaskStatistics_SuccessResponse(): void {
		$roomId = 1;
		$stats = [
			'totalTasks' => 5,
			'activeTasks' => 4,
			'completedThisWeek' => 3,
			'overdueTasks' => 1,
			'dueToday' => 2,
			'averageCompletionTime' => 18.5,
		];

		$this->roomTaskService->method('getRoomTaskStats')
			->with($roomId)
			->willReturn($stats);

		$response = $this->controller->stats($roomId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($stats, $data['data']);
	}

	/**
	 * Overdue_ReturnsOverdueTasks_SuccessResponse
	 */
	public function testOverdue_ReturnsOverdueTasks_SuccessResponse(): void {
		$overdueTasks = [
			[
				'id' => 1,
				'taskName' => 'Clean windows',
				'roomName' => 'Living Room',
				'daysOverdue' => 3,
			],
			[
				'id' => 2,
				'taskName' => 'Mop floor',
				'roomName' => 'Kitchen',
				'daysOverdue' => 1,
			],
		];

		$this->roomTaskService->method('getOverdueTasks')
			->willReturn($overdueTasks);

		$response = $this->controller->overdue();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($overdueTasks, $data['data']);
	}
}
