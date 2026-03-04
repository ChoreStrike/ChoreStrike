<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\RoomController;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Entity\Room;
use OCA\ChoreStrike\Entity\House;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomController
 */
class RoomControllerTest extends TestCase
{
    private RoomController $controller;
    private $roomMapper;
    private $houseService;
    private $authService;
    private $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->roomMapper = $this->createMock(RoomMapper::class);
        $this->houseService = $this->createMock(HouseService::class);
        $this->authService = $this->createMock(AuthorizationService::class);

        $this->controller = new RoomController(
            $this->request,
            $this->roomMapper,
            $this->houseService,
            $this->authService
        );
    }

    /**
     * Test GET /rooms returns list successfully
     */
    public function testIndex_ReturnsRoomsSuccessfully(): void
    {
        $house = new House();
        $house->setId(1);

        $room1 = new Room();
        $room1->setId(1);
        $room1->setName('Kitchen');
        $room1->setHouseId(1);

        $room2 = new Room();
        $room2->setId(2);
        $room2->setName('Living Room');
        $room2->setHouseId(1);

        $this->houseService->expects($this->once())
            ->method('getInstanceId')
            ->willReturn('instance-123');

        $this->houseService->expects($this->once())
            ->method('getOrCreateHouse')
            ->with('instance-123')
            ->willReturn($house);

        $this->authService->expects($this->once())
            ->method('requireHouseMember')
            ->with(1);

        $this->roomMapper->expects($this->once())
            ->method('findByHouseId')
            ->with(1)
            ->willReturn([$room1, $room2]);

        $response = $this->controller->index();

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
        $house = new House();
        $house->setId(1);

        $room = new Room();
        $room->setId(1);
        $room->setName('Kitchen');
        $room->setHouseId(1);

        $this->houseService->expects($this->once())
            ->method('getInstanceId')
            ->willReturn('instance-123');

        $this->houseService->expects($this->once())
            ->method('getOrCreateHouse')
            ->willReturn($house);

        $this->authService->expects($this->once())
            ->method('requireHouseMember')
            ->with(1);

        $this->roomMapper->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($room);

        $response = $this->controller->show(1);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('Kitchen', $data['data']['name']);
    }

    /**
     * Test GET /rooms/{id} handles not found
     */
    public function testShow_HandlesNotFound(): void
    {
        $house = new House();
        $house->setId(1);

        $this->houseService->expects($this->once())
            ->method('getInstanceId')
            ->willReturn('instance-123');

        $this->houseService->expects($this->once())
            ->method('getOrCreateHouse')
            ->willReturn($house);

        $this->authService->expects($this->once())
            ->method('requireHouseMember')
            ->with(1);

        $this->roomMapper->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $response = $this->controller->show(999);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());

        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('ROOM_NOT_FOUND', $data['error']['code']);
    }
}
