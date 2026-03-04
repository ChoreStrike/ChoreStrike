<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\RoomTaskController;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Entity\RoomTask;
use OCA\ChoreStrike\Entity\House;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomTaskController
 */
class RoomTaskControllerTest extends TestCase
{
    private RoomTaskController $controller;
    private $roomTaskMapper;
    private $houseService;
    private $authService;
    private $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
        $this->houseService = $this->createMock(HouseService::class);
        $this->authService = $this->createMock(AuthorizationService::class);

        $this->controller = new RoomTaskController(
            $this->request,
            $this->roomTaskMapper,
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

        $this->houseService->expects($this->once())
            ->method('getInstanceId')
            ->willReturn('instance-123');

        $this->houseService->expects($this->once())
            ->method('getOrCreateHouse')
            ->willReturn($house);

        $this->authService->expects($this->once())
            ->method('requireHouseMember')
            ->with(1);

        $this->roomTaskMapper->expects($this->once())
            ->method('findByHouseId')
            ->with(1)
            ->willReturn([$roomTask1, $roomTask2]);

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

        $this->houseService->expects($this->once())
            ->method('getInstanceId')
            ->willReturn('instance-123');

        $this->houseService->expects($this->once())
            ->method('getOrCreateHouse')
            ->willReturn($house);

        $this->authService->expects($this->once())
            ->method('requireHouseMember')
            ->with(1);

        $this->roomTaskMapper->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($roomTask);

        $this->roomTaskMapper->expects($this->once())
            ->method('complete')
            ->with(1, 'user1')
            ->willReturn(['roomTask' => $roomTask, 'medals' => []]);

        $response = $this->controller->complete(1, 'user1');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertTrue($data['success']);
    }
}
