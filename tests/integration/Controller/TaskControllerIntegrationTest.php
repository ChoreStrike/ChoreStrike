<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\TaskController;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\TaskService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for TaskController API endpoints
 */
class TaskControllerIntegrationTest extends TestCase
{
    private TaskController $controller;
    private TaskService $taskService;
    private RoomTaskService $roomTaskService;
    private HouseService $houseService;
    private AuthorizationService $authService;
    private IRequest $request;

    protected function setUp(): void
    {
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

    /**
     * Index_ReturnsTasksList_SuccessResponse
     */
    public function testIndex_ReturnsTasksList_SuccessResponse(): void
    {
        $task1 = $this->createMockTask(1, 'Vacuum floor');
        $task1->setEnergyCost(2);
        $task1->setPriority(2);
        $task2 = $this->createMockTask(2, 'Clean windows');
        $task2->setEnergyCost(3);
        $task2->setPriority(1);
        $tasks = [$task1, $task2];

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
        $this->taskService->method('getAllTasks')
            ->willReturn($tasks);

        $response = $this->controller->index();

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }

    /**
     * Show_ExistingTask_ReturnsTaskDetails
     */
    public function testShow_ExistingTask_ReturnsTaskDetails(): void
    {
        $taskId = 1;
        $task = $this->createMockTask($taskId, 'Vacuum floor');
        $task->setDescription('Vacuum all floors in the room');
        $task->setEnergyCost(2);
        $task->setPriority(2);
        $task->setEstimatedMinutes(20);

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
        $this->taskService->method('getTask')
            ->with($taskId)
            ->willReturn($task);

        $response = $this->controller->show($taskId);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('Vacuum floor', $data['data']['name']);
    }

    /**
     * Create_ValidData_ReturnsCreatedTask
     */
    public function testCreate_ValidData_ReturnsCreatedTask(): void
    {
        $createdTask = $this->createMockTask(3, 'Mop floor');
        $createdTask->setDescription('Mop hard floors');
        $createdTask->setEnergyCost(2);
        $createdTask->setPriority(2);
        $createdTask->setEstimatedMinutes(25);

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
        $this->taskService->method('createTask')
            ->willReturn($createdTask);

        $response = $this->controller->create(
            'Mop floor',
            'Mop hard floors',
            2,
            2,
            'weekly',
            1,
            25,
            'cleaning'
        );

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }

    /**
     * Create_InvalidData_ReturnsValidationError
     */
    public function testCreate_InvalidData_ReturnsValidationError(): void
    {
        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
        $this->taskService->method('validateTaskData')
            ->willReturn(['Task name is required']);

        $response = $this->controller->create(
            '', // Empty name
            null,
            6, // Invalid energy cost
            2,
            'weekly',
            1,
            25,
            'cleaning'
        );

        $this->assertEquals(400, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
    }

    /**
     * Update_ValidData_ReturnsUpdatedTask
     */
    public function testUpdate_ValidData_ReturnsUpdatedTask(): void
    {
        $taskId = 1;
        $updates = [
            'name' => 'Deep vacuum',
            'energyCost' => 3,
        ];
        $updatedTask = $this->createMockTask($taskId, 'Deep vacuum');
        $updatedTask->setDescription('Vacuum all floors in the room');
        $updatedTask->setEnergyCost(3);
        $updatedTask->setPriority(2);

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
        $this->taskService->method('validateTaskData')->willReturn([]);
        $this->taskService->method('updateTask')
            ->with($taskId, $updates)
            ->willReturn($updatedTask);

        $response = $this->controller->update($taskId, $updates);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('Deep vacuum', $data['data']['name']);
    }

    /**
     * Delete_ExistingTask_ReturnsSuccess
     */
    public function testDelete_ExistingTask_ReturnsSuccess(): void
    {
        $taskId = 1;

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
        $this->taskService->method('deleteTask')
            ->with($taskId);

        $response = $this->controller->delete($taskId);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }

    /**
     * Templates_ReturnsTemplateTasks_SuccessResponse
     */
    public function testTemplates_ReturnsTemplateTasks_SuccessResponse(): void
    {
        $task1 = $this->createMockTask(100, 'Vacuum floor');
        $task1->setIsTemplate(true);
        $task2 = $this->createMockTask(101, 'Clean windows');
        $task2->setIsTemplate(true);
        $templates = [$task1, $task2];

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
        $this->taskService->method('getTaskTemplates')
            ->willReturn($templates);

        $response = $this->controller->templates();

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }

    /**
     * Categories_ReturnsCategoryList_SuccessResponse
     */
    public function testCategories_ReturnsCategoryList_SuccessResponse(): void
    {
        $categories = [
            ['name' => 'cleaning', 'count' => 5],
            ['name' => 'kitchen', 'count' => 3],
            ['name' => 'bathroom', 'count' => 4],
        ];

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
        $this->taskService->method('getTaskCategories')
            ->willReturn($categories);

        $response = $this->controller->categories();

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals($categories, $data['data']);
    }

    private function createMockHouse(): \OCA\ChoreStrike\Db\House
    {
        $house = new \OCA\ChoreStrike\Db\House();
        $house->setId(1);
        $house->setNextcloudInstanceId('test-instance');
        $house->setName('Test House');
        return $house;
    }

    private function createMockTask(int $id, string $name): \OCA\ChoreStrike\Db\Task
    {
        $task = new \OCA\ChoreStrike\Db\Task();
        $task->setId($id);
        $task->setName($name);
        $task->setCategory('general');
        $task->setFrequencyType('weekly');
        $task->setFrequencyValue(1);
        $task->setSchedulingMode('flexible');
        $task->setScopeType('house');
        $task->setIsTemplate(false);
        $task->setIsVoluntary(false);
        $task->setAllowTeamwork(false);
        $task->setTeamSparkMultiplier(1.0);
        $task->setCreatedAt(new \DateTime());
        return $task;
    }
}
