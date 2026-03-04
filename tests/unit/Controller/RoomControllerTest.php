<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\RoomController;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\Floor;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomController
 */
class RoomControllerTest extends TestCase
{
    private RoomController $controller;
    private $roomService;
    private $houseService;
    private $authService;
    private $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->roomService = $this->createMock(RoomService::class);
        $this->houseService = $this->createMock(HouseService::class);
        $this->authService = $this->createMock(AuthorizationService::class);

        $this->controller = new RoomController(
            $this->request,
            $this->roomService,
            $this->houseService,
            $this->authService
        );
    }

    /**
     * Test GET /rooms returns list successfully
     */
    public function testIndex_ReturnsRoomsSuccessfully(): void
    {
        $floor = new Floor();
        $floor->setId(1);
        $floor->setHouseId(1);

        $room1 = new Room();
        $room1->setId(1);
        $room1->setName('Kitchen');
        $room1->setFloorId(1);

        $room2 = new Room();
        $room2->setId(2);
        $room2->setName('Living Room');
        $room2->setFloorId(1);

        $this->roomService->expects($this->once())
            ->method('getFloor')
            ->with(1)
            ->willReturn($floor);

        $this->authService->expects($this->once())
            ->method('requireHouseMember')
            ->with(1);

        $this->roomService->expects($this->once())
            ->method('getRooms')
            ->with(1)
            ->willReturn([$room1, $room2]);

        $response = $this->controller->index(1);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']);
    }

    /**
     * Test POST /rooms validates empty name
     */
    public function testCreate_ValidatesEmptyName(): void
    {
        $floor = new Floor();
        $floor->setId(1);
        $floor->setHouseId(1);

        $this->roomService->expects($this->once())
            ->method('getFloor')
            ->with(1)
            ->willReturn($floor);

        $this->authService->expects($this->once())
            ->method('requireHouseMember')
            ->with(1);

        $response = $this->controller->create(1, '');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());

        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
    }

    /**
     * Test GET /rooms/{id} returns room successfully
     */
    public function testShow_ReturnsRoomSuccessfully(): void
    {
        $room = new Room();
        $room->setId(1);
        $room->setName('Kitchen');
        $room->setHouseId(1);

        $this->roomService->expects($this->once())
            ->method('getRoomWithTasks')
            ->with(1)
            ->willReturn([
                'room' => $room,
                'tasks' => [],
                'stats' => ['total' => 0]
            ]);

        $this->roomService->expects($this->once())
            ->method('validateRoomOwnership')
            ->with(1, 1);

        $this->houseService->method('getInstanceId')->willReturn('instance-123');
        $house = new \OCA\ChoreStrike\Db\House();
        $house->setId(1);
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->method('requireHouseMember');

        $response = $this->controller->show(1);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('Kitchen', $data['data']['room']['name']);
    }

    /**
     * Test GET /rooms/{id} handles not found
     */
    public function testShow_HandlesNotFound(): void
    {
        $this->houseService->method('getInstanceId')->willReturn('instance-123');
        $house = new \OCA\ChoreStrike\Db\House();
        $house->setId(1);
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->method('requireHouseMember');

        $this->roomService->expects($this->once())
            ->method('validateRoomOwnership')
            ->with(999, 1)
            ->willThrowException(new \Exception('Room not found'));

        $response = $this->controller->show(999);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());

        $data = $response->getData();
        $this->assertFalse($data['success']);
    }

    /**
     * Helper method to get Floor mock
     */
    private function getFloor(): Floor
    {
        $floor = new Floor();
        $floor->setId(1);
        $floor->setHouseId(1);
        return $floor;
    }
}
