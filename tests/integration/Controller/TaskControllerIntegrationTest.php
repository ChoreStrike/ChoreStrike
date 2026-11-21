<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\TaskController;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\TaskService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for TaskController API endpoints
 */
class TaskControllerIntegrationTest extends TestCase {
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

		$this->controller = new TaskController(
			$this->request,
			$this->taskService,
			$this->houseService,
			$this->authService,
			$this->createMock(AppLogger::class)
		);
	}

	/**
	 * Index_ReturnsTasksList_SuccessResponse
	 */
	public function testIndex_ReturnsTasksList_SuccessResponse(): void {
		$task1 = $this->createMockTask(1, 'Vacuum floor');
		$task1->setEnergyCost(2);
		$task1->setPriority(2);
		$task2 = $this->createMockTask(2, 'Clean windows');
		$task2->setEnergyCost(3);
		$task2->setPriority(1);
		$tasks = [$task1, $task2];

		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->taskService->method('getVisibleTasks')
			->willReturn($tasks);

		$response = $this->controller->index();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	// CS-PLAN-063: the former POST /tasks create tests were pruned with the
	// endpoint — creation runs through the atomic aggregate on
	// POST /room-tasks (TaskCreationAggregateTest covers the real-DB path);
	// CS-PLAN-091 WP-B6 pruned PUT /tasks/{id} for the one form's save.

	private function createMockHouse(): \OCA\ChoreStrike\Db\House {
		$house = new \OCA\ChoreStrike\Db\House();
		$house->setId(1);
		$house->setNextcloudInstanceId('test-instance');
		$house->setName('Test House');
		return $house;
	}

	private function createMockTask(int $id, string $name): \OCA\ChoreStrike\Db\Task {
		$task = new \OCA\ChoreStrike\Db\Task();
		$task->setId($id);
		$task->setName($name);
		$task->setCategory('general');
		$task->setFrequencyType('weekly');
		$task->setFrequencyValue(1);
		$task->setSchedulingMode('flexible');
		$task->setScopeType('house');
		$task->setIsVoluntary(false);
		$task->setAllowTeamwork(false);
		$task->setCreatedAt(new \DateTime());
		return $task;
	}
}
