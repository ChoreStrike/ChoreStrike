<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\RoomTaskController;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\House;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomTaskController
 */
class RoomTaskControllerTest extends TestCase
{
    private RoomTaskController $controller;
    private $roomTaskService;
    private $houseService;
    private $authService;
    private $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->roomTaskService = $this->createMock(RoomTaskService::class);
        $this->houseService = $this->createMock(HouseService::class);
        $this->authService = $this->createMock(AuthorizationService::class);

        $this->controller = new RoomTaskController(
            $this->request,
            $this->roomTaskService,
            $this->houseService,
            $this->authService
        );
    }

    /**
     * Test GET /room-tasks returns list successfully
     */
    public function testIndex_ReturnsRoomTasksSuccessfully(): void
    {
        $house = new House();
        $house->setId(1);

        $roomTask1 = new RoomTask();
        $roomTask1->setId(1);
        $roomTask1->setRoomId(1);
        $roomTask1->setTaskId(1);

        $roomTask2 = new RoomTask();
        $roomTask2->setId(2);
        $roomTask2->setRoomId(2);
        $roomTask2->setTaskId(2);

        $this->houseService->method('getInstanceId')->willReturn('instance-123');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->method('ensureHouseMember');

        $this->roomTaskService->expects($this->once())
            ->method('getRoomTasks')
            ->willReturn([$roomTask1, $roomTask2]);

        $this->roomTaskService->method('getAssigneeUserIdsByRoomTasks')->willReturn([]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']);
    }

    /**
     * Test POST /room-tasks validates missing roomId
     */
    public function testCreate_ValidatesMissingRoomId(): void
    {
        $response = $this->controller->create(null, 1);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());

        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
    }

    /**
     * Test POST /room-tasks validates missing taskId
     */
    public function testCreate_ValidatesMissingTaskId(): void
    {
        $response = $this->controller->create(1, null);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());

        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
    }

    /**
     * Test POST /room-tasks/{id}/complete completes successfully
     */
    public function testComplete_CompletesSuccessfully(): void
    {
        $house = new House();
        $house->setId(1);

        $roomTask = new RoomTask();
        $roomTask->setId(1);
        $roomTask->setRoomId(1);
        $roomTask->setIsActive(true);

        $this->houseService->method('getInstanceId')->willReturn('instance-123');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->method('ensureHouseMember');

        $this->roomTaskService->expects($this->once())
            ->method('completeRoomTask')
            ->with(1, 'user1')
            ->willReturn(['roomTask' => $roomTask, 'medals' => []]);

        $response = $this->controller->complete(1, 'user1');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertTrue($data['success']);
    }

    /**
     * userTasks must reject a target userId that is not a member of the
     * caller's house (cross-house access → 403).
     */
    public function testUserTasks_Returns403ForCrossHouseUser(): void
    {
        $house = new House();
        $house->setId(1);

        $this->houseService->method('getInstanceId')->willReturn('instance-123');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->method('ensureHouseMember');
        $this->authService->expects($this->once())
            ->method('assertUserInHouse')
            ->with(1, 'outsider')
            ->willThrowException(new \OCA\ChoreStrike\Exception\ForbiddenException('User is not a member of this house'));

        $this->roomTaskService->expects($this->never())->method('getUserTasks');

        $response = $this->controller->userTasks('outsider');

        $this->assertEquals(403, $response->getStatus());
        $this->assertEquals('FORBIDDEN', $response->getData()['error']['code']);
    }

    /**
     * userStats must reject a cross-house target userId (403).
     */
    public function testUserStats_Returns403ForCrossHouseUser(): void
    {
        $house = new House();
        $house->setId(1);

        $this->houseService->method('getInstanceId')->willReturn('instance-123');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->method('ensureHouseMember');
        $this->authService->expects($this->once())
            ->method('assertUserInHouse')
            ->with(1, 'outsider')
            ->willThrowException(new \OCA\ChoreStrike\Exception\ForbiddenException('User is not a member of this house'));

        $this->roomTaskService->expects($this->never())->method('getUserTaskStats');

        $response = $this->controller->userStats('outsider');

        $this->assertEquals(403, $response->getStatus());
        $this->assertEquals('FORBIDDEN', $response->getData()['error']['code']);
    }

    /**
     * userTasks for a same-house member returns 200.
     */
    public function testUserTasks_ReturnsTasksForSameHouseUser(): void
    {
        $house = new House();
        $house->setId(1);

        $roomTask = new RoomTask();
        $roomTask->setId(7);

        $this->houseService->method('getInstanceId')->willReturn('instance-123');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->method('ensureHouseMember');
        $this->authService->method('assertUserInHouse');
        $this->roomTaskService->expects($this->once())
            ->method('getUserTasks')
            ->with('member', true)
            ->willReturn([$roomTask]);

        $response = $this->controller->userTasks('member');

        $this->assertEquals(200, $response->getStatus());
        $this->assertTrue($response->getData()['success']);
    }

    /**
     * assign must reject a target userId that is not a member of the caller's
     * house (cross-house BOLA → 403). Regression for the missing membership
     * check on the legacy assign() endpoint.
     */
    public function testAssign_Returns403ForCrossHouseUser(): void
    {
        $house = new House();
        $house->setId(1);

        $this->houseService->method('getInstanceId')->willReturn('instance-123');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->method('ensureHouseMember');
        $this->roomTaskService->method('validateRoomTaskOwnership');
        $this->authService->expects($this->once())
            ->method('assertUserInHouse')
            ->with(1, 'outsider')
            ->willThrowException(new \OCA\ChoreStrike\Exception\ForbiddenException('User is not a member of this house'));

        $this->roomTaskService->expects($this->never())->method('assignTask');

        $response = $this->controller->assign(7, 'outsider');

        $this->assertEquals(403, $response->getStatus());
        $this->assertEquals('FORBIDDEN', $response->getData()['error']['code']);
    }
}
