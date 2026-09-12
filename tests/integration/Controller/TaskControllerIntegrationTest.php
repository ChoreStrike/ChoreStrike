<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\TaskController;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\TaskService;
use OCP\IL10N;
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
	// POST /room-tasks (TaskCreationAggregateTest covers the real-DB path).

	/**
	 * Update_ValidData_ReturnsUpdatedTask
	 */
	public function testUpdate_ValidData_ReturnsUpdatedTask(): void {
		$taskId = 1;
		$updates = [
			'name' => 'Deep vacuum',
			'energyCost' => 3,
		];
		$updatedTask = $this->createMockTask($taskId, 'Deep vacuum');
		$updatedTask->setDescription('Vacuum all floors in the room');
		$updatedTask->setEnergyCost(3);
		$updatedTask->setPriority(2);

		$houseUser = new \OCA\ChoreStrike\Db\HouseUser();
		$houseUser->setNextcloudUserId('owner-user');
		$houseUser->setRole('owner');

		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->authService->method('assertHouseMember')->willReturn($houseUser);
		$this->taskService->method('getVisibleTask')->willReturn($this->createMockTask($taskId, 'Vacuum'));
		$this->taskService->method('validateTaskData')->willReturn([]);
		// CS-PLAN-061: updateTask receives the HouseUser actor.
		$this->taskService->method('updateTask')
			->with($taskId, $updates, $this->identicalTo($houseUser))
			->willReturn($updatedTask);

		// BUG-API-001 (D6): the endpoint reads its closed payload from the
		// request parameters (top-level body keys over the route internals).
		$this->request->method('getParams')->willReturn(array_merge(
			['id' => (string)$taskId, '_route' => 'chorestrike.tasks.update'],
			$updates
		));
		$response = $this->controller->update($taskId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('Deep vacuum', $data['data']['name']);
	}

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
		$task->setTeamSparkMultiplier(1.0);
		$task->setCreatedAt(new \DateTime());
		return $task;
	}
}
