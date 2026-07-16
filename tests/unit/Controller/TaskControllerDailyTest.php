<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\TaskController;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TaskService;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\RoomTask;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TaskControllerDailyTest extends TestCase
{
    private TaskController $controller;
    private RoomTaskService|MockObject $roomTaskService;
    private HouseService|MockObject $houseService;
    private AuthorizationService|MockObject $authService;
    private TaskService|MockObject $taskService;
    private IRequest|MockObject $request;

    protected function setUp(): void
    {
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

    public function testDailyReturnsSuccess(): void
    {
        $house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
        $house->method('getId')->willReturn(1);

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->expects($this->once())->method('requireHouseMember')->with(1);

        $today = new \DateTime();

        $roomTask = new RoomTask();
        $roomTask->setId(1);
        $roomTask->setTaskId(5);
        $roomTask->setRoomId(2);
        $roomTask->setIsActive(true);
        $roomTask->setLastCompleted(clone $today);
        $roomTask->setNextDue(new \DateTime('2026-03-03'));
        $roomTask->setAssignedToUserId('user-1');

        $task = new Task();
        $task->setId(5);
        $task->setName('Test Task');
        $task->setPriority(3);
        $task->setEnergyCost(4);
        $task->setAllowTeamwork(true);

        $room = new Room();
        $room->setId(2);
        $room->setName('Kitchen');

        $this->roomTaskService->expects($this->once())
            ->method('getDailyTasksWithDetails')
            ->with(1, $this->isInstanceOf(\DateTime::class))
            ->willReturn([
                [
                    'roomTask' => $roomTask,
                    'task' => $task,
                    'room' => $room,
                ],
            ]);

        $response = $this->controller->daily();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['data']);
        $this->assertEquals(1, $data['data'][0]['id']);
        $this->assertEquals('Test Task', $data['data'][0]['name']);
        $this->assertEquals('Kitchen', $data['data'][0]['roomName']);
        $this->assertEquals(4, $data['data'][0]['energyCost']);
        $this->assertEquals(3, $data['data'][0]['priority']);
        $this->assertTrue($data['data'][0]['completed']);
        $this->assertTrue($data['data'][0]['allowTeamwork']);
    }

    public function testDailyHandlesException(): void
    {
        $house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
        $house->method('getId')->willReturn(1);

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->expects($this->once())->method('requireHouseMember')->with(1);

        $this->roomTaskService->expects($this->once())
            ->method('getDailyTasksWithDetails')
            ->willThrowException(new \Exception('Database error'));

        $response = $this->controller->daily();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('DAILY_TASKS_ERROR', $data['error']['code']);
        // A generic (non-domain) exception must not leak its internal message;
        // the detail stays server-side in the logs, the client gets a safe message.
        $this->assertEquals('An unexpected error occurred', $data['error']['message']);
    }

    public function testCompleteReturnsSuccess(): void
    {
        $house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
        $house->method('getId')->willReturn(1);

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->expects($this->once())->method('requireHouseMember')->with(1);
        $this->roomTaskService->expects($this->once())->method('validateRoomTaskOwnership')->with(1, 1);

        $roomTask = $this->createMock(RoomTask::class);
        $roomTask->method('jsonSerialize')->willReturn(['id' => 1, 'completed' => true]);

        $this->roomTaskService->expects($this->once())
            ->method('completeTaskSimple')
            ->with(1)
            ->willReturn($roomTask);

        $response = $this->controller->complete(1);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(['id' => 1, 'completed' => true], $data['data']);
    }

    public function testUncompleteReturnsSuccess(): void
    {
        $house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
        $house->method('getId')->willReturn(1);

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->expects($this->once())->method('requireHouseMember')->with(1);
        $this->roomTaskService->expects($this->once())->method('validateRoomTaskOwnership')->with(1, 1);

        $roomTask = $this->createMock(RoomTask::class);
        $roomTask->method('jsonSerialize')->willReturn(['id' => 1, 'completed' => false]);

        $this->roomTaskService->expects($this->once())
            ->method('uncompleteTask')
            ->with(1)
            ->willReturn($roomTask);

        $response = $this->controller->uncomplete(1);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(['id' => 1, 'completed' => false], $data['data']);
    }

    public function testSkipReturnsSuccess(): void
    {
        $house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
        $house->method('getId')->willReturn(1);

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->expects($this->once())->method('requireHouseMember')->with(1);
        $this->roomTaskService->expects($this->once())->method('validateRoomTaskOwnership')->with(1, 1);

        $roomTask = $this->createMock(RoomTask::class);
        $roomTask->method('jsonSerialize')->willReturn(['id' => 1, 'skipped' => true]);

        $this->roomTaskService->expects($this->once())
            ->method('skipTask')
            ->with(1)
            ->willReturn($roomTask);

        $response = $this->controller->skip(1);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(['id' => 1, 'skipped' => true], $data['data']);
    }

    public function testRescheduleReturnsSuccess(): void
    {
        $house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
        $house->method('getId')->willReturn(1);

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->expects($this->once())->method('requireHouseMember')->with(1);
        $this->roomTaskService->expects($this->once())->method('validateRoomTaskOwnership')->with(1, 1);

        $roomTask = $this->createMock(RoomTask::class);
        $roomTask->method('jsonSerialize')->willReturn(['id' => 1, 'new_date' => '2026-03-03']);

        $this->roomTaskService->expects($this->once())
            ->method('rescheduleTask')
            ->with(1, $this->isInstanceOf(\DateTime::class))
            ->willReturn($roomTask);

        $response = $this->controller->reschedule(1, '2026-03-03');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(['id' => 1, 'new_date' => '2026-03-03'], $data['data']);
    }

    public function testRescheduleHandlesInvalidDate(): void
    {
        $house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
        $house->method('getId')->willReturn(1);

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->expects($this->once())->method('requireHouseMember')->with(1);
        $this->roomTaskService->expects($this->once())->method('validateRoomTaskOwnership')->with(1, 1);

        $this->roomTaskService->expects($this->never())->method('rescheduleTask');

        $response = $this->controller->reschedule(1, 'invalid-date');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
    }

    public function testCompleteReturns404WhenRoomTaskIsHidden(): void
    {
        $house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
        $house->method('getId')->willReturn(1);

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->expects($this->once())->method('requireHouseMember')->with(1);
        $this->roomTaskService->expects($this->once())
            ->method('validateRoomTaskOwnership')
            ->with(1, 1)
            ->willThrowException(new \OCP\AppFramework\Http\NotFoundException('Room task not found'));

        $response = $this->controller->complete(1);

        $this->assertEquals(404, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('RESOURCE_NOT_FOUND', $data['error']['code']);
    }

    public function testCompleteReturns403ForForbiddenAction(): void
    {
        $house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
        $house->method('getId')->willReturn(1);

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->expects($this->once())
            ->method('requireHouseMember')
            ->with(1)
            ->willThrowException(new \OCP\AppFramework\Http\ForbiddenException('Owner role required'));

        $response = $this->controller->complete(1);

        $this->assertEquals(403, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('FORBIDDEN', $data['error']['code']);
    }
}
