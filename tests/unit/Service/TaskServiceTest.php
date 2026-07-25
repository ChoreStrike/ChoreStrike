<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Service\TaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaskService
 */
class TaskServiceTest extends TestCase
{
    private TaskService $service;
    private TaskMapper $taskMapper;

    protected function setUp(): void
    {
        $this->taskMapper = $this->createMock(TaskMapper::class);
        $this->service = new TaskService($this->taskMapper);
    }

    /**
     * GetAllTasks_ReturnsAllTasks
     */
    public function testGetAllTasks_ReturnsAllTasks(): void
    {
        $task1 = new Task();
        $task1->setId(1);
        $task1->setName('Task 1');

        $task2 = new Task();
        $task2->setId(2);
        $task2->setName('Task 2');

        $this->taskMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$task1, $task2]);

        $result = $this->service->getAllTasks();

        $this->assertCount(2, $result);
        $this->assertEquals('Task 1', $result[0]->getName());
    }

    /**
     * GetTask_ExistingId_ReturnsTask
     */
    public function testGetTask_ExistingId_ReturnsTask(): void
    {
        $taskId = 1;

        $task = new Task();
        $task->setId($taskId);
        $task->setName('Test Task');

        $this->taskMapper
            ->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn($task);

        $result = $this->service->getTask($taskId);

        $this->assertSame($task, $result);
    }

    /**
     * GetTask_NonExistingId_ThrowsException
     */
    public function testGetTask_NonExistingId_ThrowsException(): void
    {
        $taskId = 999;

        $this->taskMapper
            ->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willThrowException(new DoesNotExistException('Task not found'));

        $this->expectException(DoesNotExistException::class);

        $this->service->getTask($taskId);
    }

    /**
     * CreateTask_ValidData_ReturnsCreatedTask
     */
    public function testCreateTask_ValidData_ReturnsCreatedTask(): void
    {
        $task = new Task();
        $task->setId(1);
        $task->setName('New Task');

        $this->taskMapper
            ->expects($this->once())
            ->method('create')
            ->willReturn($task);

        $result = $this->service->createTask(
            'New Task',
            'Description',
            2,
            1,
            'weekly',
            1,
            30,
            'cleaning'
        );

        $this->assertSame($task, $result);
    }

    /**
     * UpdateTask_ValidUpdates_ReturnsUpdatedTask
     */
    public function testUpdateTask_ValidUpdates_ReturnsUpdatedTask(): void
    {
        $taskId = 1;
        $updates = ['name' => 'Updated Name', 'energyCost' => 4];

        $task = new Task();
        $task->setId($taskId);
        $task->setName('Old Name');
        $task->setEnergyCost(2);

        $updatedTask = new Task();
        $updatedTask->setId($taskId);
        $updatedTask->setName('Updated Name');
        $updatedTask->setEnergyCost(4);

        $this->taskMapper
            ->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn($task);

        $this->taskMapper
            ->expects($this->once())
            ->method('update')
            ->willReturn($updatedTask);

        $result = $this->service->updateTask($taskId, $updates);

        $this->assertEquals('Updated Name', $result->getName());
        $this->assertEquals(4, $result->getEnergyCost());
    }

    /**
     * UpdateTask_DateFields_ParsesDates
     */
    public function testUpdateTask_DateFields_ParsesDates(): void
    {
        $taskId = 1;
        $updates = ['activeFrom' => '2026-03-01', 'activeUntil' => '2026-12-31'];

        $task = new Task();
        $task->setId($taskId);

        $this->taskMapper
            ->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn($task);

        $this->taskMapper
            ->expects($this->once())
            ->method('update')
            ->willReturn($task);

        $result = $this->service->updateTask($taskId, $updates);

        $this->assertSame($task, $result);
    }

    /**
     * DeleteTask_ExistingTask_DeletesTask
     */
    public function testDeleteTask_ExistingTask_DeletesTask(): void
    {
        $taskId = 1;

        $task = new Task();
        $task->setId($taskId);

        $this->taskMapper
            ->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn($task);

        $this->taskMapper
            ->expects($this->once())
            ->method('delete')
            ->with($task);

        $this->service->deleteTask($taskId, 'owner-user', false);

        $this->assertTrue(true);
    }

    /**
     * GetTaskTemplates_ReturnsTemplates
     */
    public function testGetTaskTemplates_ReturnsTemplates(): void
    {
        $template = new Task();
        $template->setId(1);
        $template->setName('Template Task');
        $template->setIsTemplate(true);

        $this->taskMapper
            ->expects($this->once())
            ->method('findTemplates')
            ->willReturn([$template]);

        $result = $this->service->getTaskTemplates();

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]->getIsTemplate());
    }

    /**
     * CreateFromTemplate_CreatesTaskFromTemplate
     */
    public function testCreateFromTemplate_CreatesTaskFromTemplate(): void
    {
        $templateId = 1;

        $task = new Task();
        $task->setId(2);
        $task->setName('Copied Task');

        $this->taskMapper
            ->expects($this->once())
            ->method('createFromTemplate')
            ->with($templateId)
            ->willReturn($task);

        $result = $this->service->createFromTemplate($templateId);

        $this->assertSame($task, $result);
    }

    /**
     * GetTasksByCategory_ReturnsFilteredTasks
     */
    public function testGetTasksByCategory_ReturnsFilteredTasks(): void
    {
        $category = 'cleaning';

        $task = new Task();
        $task->setId(1);
        $task->setCategory($category);

        $this->taskMapper
            ->expects($this->once())
            ->method('findByCategory')
            ->with($category)
            ->willReturn([$task]);

        $result = $this->service->getTasksByCategory($category);

        $this->assertCount(1, $result);
    }

    /**
     * SearchTasks_WithQuery_ReturnsMatchingTasks
     */
    public function testSearchTasks_WithQuery_ReturnsMatchingTasks(): void
    {
        $query = 'vacuum';

        $task = new Task();
        $task->setId(1);
        $task->setName('Vacuum floor');

        $this->taskMapper
            ->expects($this->once())
            ->method('searchByName')
            ->with($query)
            ->willReturn([$task]);

        $result = $this->service->searchTasks($query);

        $this->assertCount(1, $result);
    }

    /**
     * GetTaskCategories_ReturnsCategories
     */
    public function testGetTaskCategories_ReturnsCategories(): void
    {
        $categories = ['cleaning', 'kitchen', 'bathroom'];

        $this->taskMapper
            ->expects($this->once())
            ->method('getCategories')
            ->willReturn($categories);

        $result = $this->service->getTaskCategories();

        $this->assertEquals($categories, $result);
    }

    /**
     * CreateTaskTemplate_CreatesTemplate
     */
    public function testCreateTaskTemplate_CreatesTemplate(): void
    {
        $template = new Task();
        $template->setId(1);
        $template->setName('Template');
        $template->setIsTemplate(true);

        $this->taskMapper
            ->expects($this->once())
            ->method('create')
            ->willReturn($template);

        $result = $this->service->createTaskTemplate(
            'Template',
            'Description',
            2,
            1,
            'weekly',
            1,
            30,
            'cleaning'
        );

        $this->assertTrue($result->getIsTemplate());
    }

    /**
     * ValidateTaskData_EmptyName_ReturnsError
     */
    public function testValidateTaskData_EmptyName_ReturnsError(): void
    {
        $taskData = ['name' => ''];

        $result = $this->service->validateTaskData($taskData);

        $this->assertContains('Task name is required', $result);
    }

    /**
     * ValidateTaskData_InvalidEnergyCost_ReturnsError
     */
    public function testValidateTaskData_InvalidEnergyCost_ReturnsError(): void
    {
        $taskData = ['name' => 'Test', 'energyCost' => 10];

        $result = $this->service->validateTaskData($taskData);

        $this->assertContains('Energy cost must be between 1 and 5', $result);
    }

    /**
     * ValidateTaskData_InvalidPriority_ReturnsError
     */
    public function testValidateTaskData_InvalidPriority_ReturnsError(): void
    {
        $taskData = ['name' => 'Test', 'priority' => 5];

        $result = $this->service->validateTaskData($taskData);

        $this->assertContains('Priority must be between 1 and 3', $result);
    }

    /**
     * ValidateTaskData_InvalidFrequencyType_ReturnsError
     */
    public function testValidateTaskData_InvalidFrequencyType_ReturnsError(): void
    {
        $taskData = ['name' => 'Test', 'frequencyType' => 'yearly'];

        $result = $this->service->validateTaskData($taskData);

        $this->assertContains('Invalid frequency type', $result);
    }

    /**
     * ValidateTaskData_InvalidScopeType_ReturnsError
     */
    public function testValidateTaskData_InvalidScopeType_ReturnsError(): void
    {
        $taskData = ['name' => 'Test', 'scopeType' => 'invalid'];

        $result = $this->service->validateTaskData($taskData);

        $this->assertContains('Invalid scope type', $result);
    }

    /**
     * ValidateTaskData_InvalidRecurrenceMode_ReturnsError
     */
    public function testValidateTaskData_InvalidRecurrenceMode_ReturnsError(): void
    {
        $taskData = ['name' => 'Test', 'recurrenceMode' => 'monthly'];

        $result = $this->service->validateTaskData($taskData);

        $this->assertContains('Invalid recurrence mode', $result);
    }

    /**
     * ValidateTaskData_InvalidDateRange_ReturnsError
     */
    public function testValidateTaskData_InvalidDateRange_ReturnsError(): void
    {
        $taskData = [
            'name' => 'Test',
            'activeFrom' => '2026-12-31',
            'activeUntil' => '2026-01-01'
        ];

        $result = $this->service->validateTaskData($taskData);

        $this->assertContains('Active until must be equal or after active from', $result);
    }

    /**
     * ValidateTaskData_ValidData_ReturnsEmptyArray
     */
    public function testValidateTaskData_ValidData_ReturnsEmptyArray(): void
    {
        $taskData = [
            'name' => 'Test Task',
            'energyCost' => 3,
            'priority' => 2,
            'frequencyType' => 'weekly',
            'scopeType' => 'room',
            'recurrenceMode' => 'recurring',
            'frequencyValue' => 1
        ];

        $result = $this->service->validateTaskData($taskData);

        $this->assertEmpty($result);
    }

    /**
     * CreateDefaultTemplates_CreatesMultipleTemplates
     */
    public function testCreateDefaultTemplates_CreatesMultipleTemplates(): void
    {
        $this->taskMapper
            ->expects($this->exactly(15))
            ->method('create')
            ->willReturnCallback(function () {
                static $id = 1;
                $task = new Task();
                $task->setId($id++);
                $task->setIsTemplate(true);
                return $task;
            });

        $result = $this->service->createDefaultTemplates();

        $this->assertCount(15, $result);
        $this->assertTrue($result[0]->getIsTemplate());
    }
}
