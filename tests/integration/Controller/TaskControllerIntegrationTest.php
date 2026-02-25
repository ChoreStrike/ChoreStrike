<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\TaskController;
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
			$this->authService
		);
	}

	/**
	 * Index_ReturnsTasksList_SuccessResponse
	 */
	public function testIndex_ReturnsTasksList_SuccessResponse(): void {
		$tasks = [
			[
				'id' => 1,
				'name' => 'Vacuum floor',
				'energyCost' => 2,
				'priority' => 2,
				'category' => 'cleaning',
				'isTemplate' => false,
			],
			[
				'id' => 2,
				'name' => 'Clean windows',
				'energyCost' => 3,
				'priority' => 1,
				'category' => 'cleaning',
				'isTemplate' => false,
			],
		];

		$this->taskService->method('getAllTasks')
			->willReturn($tasks);

		$response = $this->controller->index();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($tasks, $data['data']);
	}

	/**
	 * Show_ExistingTask_ReturnsTaskDetails
	 */
	public function testShow_ExistingTask_ReturnsTaskDetails(): void {
		$taskId = 1;
		$task = [
			'id' => $taskId,
			'name' => 'Vacuum floor',
			'description' => 'Vacuum all floors in the room',
			'energyCost' => 2,
			'priority' => 2,
			'frequencyType' => 'weekly',
			'frequencyValue' => 1,
			'estimatedMinutes' => 20,
			'category' => 'cleaning',
		];

		$this->taskService->method('getTask')
			->with($taskId)
			->willReturn($task);

		$response = $this->controller->show($taskId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($task, $data['data']);
	}

	/**
	 * Create_ValidData_ReturnsCreatedTask
	 */
	public function testCreate_ValidData_ReturnsCreatedTask(): void {
		$taskData = [
			'name' => 'Mop floor',
			'description' => 'Mop hard floors',
			'energyCost' => 2,
			'priority' => 2,
			'frequencyType' => 'weekly',
			'frequencyValue' => 1,
			'estimatedMinutes' => 25,
			'category' => 'cleaning',
		];

		$createdTask = array_merge(['id' => 3], $taskData);

		$this->taskService->method('createTask')
			->willReturn($createdTask);

		$response = $this->controller->create($taskData);

		$this->assertEquals(201, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('Mop floor', $data['data']['name']);
	}

	/**
	 * Create_InvalidData_ReturnsValidationError
	 */
	public function testCreate_InvalidData_ReturnsValidationError(): void {
		$invalidTaskData = [
			'name' => '', // Empty name
			'energyCost' => 6, // Invalid: should be 1-5
			'priority' => 2,
			'category' => 'cleaning',
		];

		$this->taskService->method('validateTaskData')
			->willReturn(['Task name is required', 'Energy cost must be between 1 and 5']);

		$response = $this->controller->create($invalidTaskData);

		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertNotEmpty($data['error']['validationErrors']);
	}

	/**
	 * Update_ValidData_ReturnsUpdatedTask
	 */
	public function testUpdate_ValidData_ReturnsUpdatedTask(): void {
		$taskId = 1;
		$updateData = [
			'name' => 'Deep vacuum',
			'energyCost' => 3,
		];

		$updatedTask = [
			'id' => $taskId,
			'name' => 'Deep vacuum',
			'description' => 'Vacuum all floors in the room',
			'energyCost' => 3,
			'priority' => 2,
			'category' => 'cleaning',
		];

		$this->taskService->method('updateTask')
			->with($taskId, $updateData)
			->willReturn($updatedTask);

		$response = $this->controller->update($taskId, $updateData);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('Deep vacuum', $data['data']['name']);
		$this->assertEquals(3, $data['data']['energyCost']);
	}

	/**
	 * Delete_ExistingTask_ReturnsSuccess
	 */
	public function testDelete_ExistingTask_ReturnsSuccess(): void {
		$taskId = 1;

		$this->taskService->method('deleteTask')
			->with($taskId)
			->willReturn(true);

		$response = $this->controller->delete($taskId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * Templates_ReturnsTemplateTasks_SuccessResponse
	 */
	public function testTemplates_ReturnsTemplateTasks_SuccessResponse(): void {
		$templates = [
			[
				'id' => 100,
				'name' => 'Vacuum floor',
				'isTemplate' => true,
				'category' => 'cleaning',
			],
			[
				'id' => 101,
				'name' => 'Clean windows',
				'isTemplate' => true,
				'category' => 'cleaning',
			],
		];

		$this->taskService->method('getTaskTemplates')
			->willReturn($templates);

		$response = $this->controller->templates();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($templates, $data['data']);
	}

	/**
	 * Categories_ReturnsCategoryList_SuccessResponse
	 */
	public function testCategories_ReturnsCategoryList_SuccessResponse(): void {
		$categories = [
			['name' => 'cleaning', 'count' => 5],
			['name' => 'kitchen', 'count' => 3],
			['name' => 'bathroom', 'count' => 4],
		];

		$this->taskService->method('getTaskCategories')
			->willReturn($categories);

		$response = $this->controller->categories();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($categories, $data['data']);
	}
}
