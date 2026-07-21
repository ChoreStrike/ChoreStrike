<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\TaskController;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TaskService;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaskController
 */
class TaskControllerTest extends TestCase
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

    private function createHouseMock(int $houseId = 1): object
    {
        $house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
        $house->method('getId')->willReturn($houseId);

        return $house;
    }

    private function expectHouseMembershipContext(int $houseId = 1): void
    {
        $this->houseService->expects($this->once())
            ->method('getInstanceId')
            ->willReturn('instance-123');

        $this->houseService->expects($this->once())
            ->method('getOrCreateHouse')
            ->willReturn($this->createHouseMock($houseId));

        $this->authService->expects($this->once())
            ->method('requireHouseMember')
            ->with($houseId);
    }

    /**
     * Test GET /tasks returns list successfully
     */
    public function testIndex_ReturnsTasksSuccessfully(): void
    {
        $task1 = new Task();
        $task1->setId(1);
        $task1->setName('Clean Kitchen');

        $task2 = new Task();
        $task2->setId(2);
        $task2->setName('Vacuum Living Room');

        $this->expectHouseMembershipContext();

        $this->taskService->expects($this->once())
            ->method('getAllTasks')
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
    public function testCreate_ValidatesEmptyName(): void
    {
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
    public function testCreate_CreatesSuccessfully(): void
    {
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
    public function testShow_ReturnsTaskSuccessfully(): void
    {
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
    public function testDelete_DeletesSuccessfully(): void
    {
        $this->expectHouseMembershipContext();

        $this->taskService->expects($this->once())
            ->method('deleteTask')
            ->with(1);

        $response = $this->controller->delete(1);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertTrue($data['success']);
    }

    /**
     * Test DELETE /tasks/{id} returns 404 for hidden or missing resource
     */
    public function testDelete_Returns404WhenTaskIsHiddenOrMissing(): void
    {
        $this->expectHouseMembershipContext();

        $this->taskService->expects($this->once())
            ->method('deleteTask')
            ->with(1)
            ->willThrowException(new NotFoundException('Task not found'));

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
    public function testDelete_Returns403WhenActionIsForbidden(): void
    {
        $this->houseService->expects($this->once())
            ->method('getInstanceId')
            ->willReturn('instance-123');

        $this->houseService->expects($this->once())
            ->method('getOrCreateHouse')
            ->willReturn($this->createHouseMock());

        $this->authService->expects($this->once())
            ->method('requireHouseMember')
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
}
