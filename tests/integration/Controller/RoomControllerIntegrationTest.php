<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\RoomController;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for RoomController API endpoints
 */
class RoomControllerIntegrationTest extends TestCase
{
    private RoomController $controller;
    private RoomService $roomService;
    private HouseService $houseService;
    private AuthorizationService $authService;
    private IRequest $request;

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
     * Index_ReturnsRoomsList_SuccessResponse
     */
    public function testIndex_ReturnsRoomsList_SuccessResponse(): void
    {
        $houseId = 1;
        $floorId = 1;
        $rooms = [
            $this->createMockRoom(1, 'Living Room', $floorId, 85),
            $this->createMockRoom(2, 'Kitchen', $floorId, 90),
        ];

        $floor = $this->createMockFloor($floorId, 'Ground Floor', 1, $houseId);

        $this->roomService->method('getFloor')
            ->with($floorId)
            ->willReturn($floor);
        $this->roomService->method('getRooms')
            ->with($floorId)
            ->willReturn($rooms);
        $this->authService->method('requireHouseMember')
            ->with($houseId)
            ->willReturn($this->createMock(\OCA\ChoreStrike\Db\HouseUser::class));

        $response = $this->controller->index($floorId);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']);
    }

    private function createMockFloor(int $id, string $name, int $sortOrder, int $houseId): \OCA\ChoreStrike\Db\Floor
    {
        $floor = $this->createMock(\OCA\ChoreStrike\Db\Floor::class);
        $floor->method('getId')->willReturn($id);
        $floor->method('getName')->willReturn($name);
        $floor->method('getSortOrder')->willReturn($sortOrder);
        $floor->method('getHouseId')->willReturn($houseId);
        return $floor;
    }

    private function createMockRoom(int $id, string $name, int $floorId, int $cleanliness): \OCA\ChoreStrike\Db\Room
    {
        $room = $this->createMock(\OCA\ChoreStrike\Db\Room::class);
        $room->method('getId')->willReturn($id);
        $room->method('getName')->willReturn($name);
        $room->method('getFloorId')->willReturn($floorId);
        $room->method('getCleanliness')->willReturn($cleanliness);
        $room->method('getHouseId')->willReturn(1);
        $room->method('jsonSerialize')->willReturn([
            'id' => $id,
            'name' => $name,
            'floorId' => $floorId,
            'cleanliness' => $cleanliness,
        ]);
        return $room;
    }

    /**
     * Show_ExistingRoom_ReturnsRoomDetails
     */
    public function testShow_ExistingRoom_ReturnsRoomDetails(): void
    {
        $roomId = 1;
        $houseId = 1;
        $room = $this->createMockRoom($roomId, 'Living Room', 1, 85);
        $tasks = [
            [
                'roomTask' => $this->createMockRoomTask(1, $roomId, 1),
                'task' => $this->createMockTask(1, 'Vacuum', 2),
            ],
        ];
        $roomWithTasks = [
            'room' => $room,
            'tasks' => $tasks,
            'stats' => ['totalTasks' => 1],
        ];

        $this->roomService->method('getRoomWithTasks')
            ->with($roomId)
            ->willReturn($roomWithTasks);
        $this->authService->method('requireHouseMember')
            ->with($houseId)
            ->willReturn($this->createMock(\OCA\ChoreStrike\Db\HouseUser::class));

        $response = $this->controller->show($roomId);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals($roomId, $data['data']['room']['id']);
    }

    private function createMockRoomTask(int $id, int $roomId, int $taskId): \OCA\ChoreStrike\Db\RoomTask
    {
        $roomTask = $this->createMock(\OCA\ChoreStrike\Db\RoomTask::class);
        $roomTask->method('getId')->willReturn($id);
        $roomTask->method('getRoomId')->willReturn($roomId);
        $roomTask->method('getTaskId')->willReturn($taskId);
        $roomTask->method('jsonSerialize')->willReturn([
            'id' => $id,
            'roomId' => $roomId,
            'taskId' => $taskId,
        ]);
        return $roomTask;
    }

    private function createMockTask(int $id, string $name, int $energyCost): \OCA\ChoreStrike\Db\Task
    {
        $task = $this->createMock(\OCA\ChoreStrike\Db\Task::class);
        $task->method('getId')->willReturn($id);
        $task->method('getName')->willReturn($name);
        $task->method('getEnergyCost')->willReturn($energyCost);
        $task->method('jsonSerialize')->willReturn([
            'id' => $id,
            'name' => $name,
            'energyCost' => $energyCost,
        ]);
        return $task;
    }

    /**
     * Create_ValidData_ReturnsCreatedRoom
     */
    public function testCreate_ValidData_ReturnsCreatedRoom(): void
    {
        $floorId = 1;
        $houseId = 1;
        $name = 'Bathroom';
        $room = $this->createMockRoom(4, $name, $floorId, 100);

        $floor = $this->createMockFloor($floorId, 'Ground Floor', 1, $houseId);

        $this->roomService->method('getFloor')
            ->with($floorId)
            ->willReturn($floor);
        $this->roomService->method('createRoom')
            ->with($floorId, $name)
            ->willReturn($room);
        $this->authService->method('requireHouseMember')
            ->with($houseId)
            ->willReturn($this->createMock(\OCA\ChoreStrike\Db\HouseUser::class));

        $response = $this->controller->create($floorId, $name);

        $this->assertEquals(201, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals($name, $data['data']['name']);
    }

    /**
     * Update_ValidData_ReturnsUpdatedRoom
     */
    public function testUpdate_ValidData_ReturnsUpdatedRoom(): void
    {
        $roomId = 1;
        $houseId = 1;
        $newName = 'Updated Room Name';
        $room = $this->createMockRoom($roomId, $newName, 1, 85);
        $existingRoom = $this->createMockRoom($roomId, 'Living Room', 1, 85);

        $this->roomService->method('getRoom')
            ->with($roomId)
            ->willReturn($existingRoom);
        $this->roomService->method('updateRoomName')
            ->with($roomId, $newName)
            ->willReturn($room);
        $this->authService->method('requireHouseMember')
            ->with($houseId)
            ->willReturn($this->createMock(\OCA\ChoreStrike\Db\HouseUser::class));

        $response = $this->controller->update($roomId, $newName);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals($newName, $data['data']['name']);
    }

    /**
     * Delete_ExistingRoom_ReturnsSuccess
     */
    public function testDelete_ExistingRoom_ReturnsSuccess(): void
    {
        $roomId = 1;
        $houseId = 1;
        $room = $this->createMockRoom($roomId, 'Living Room', 1, 85);

        $this->roomService->method('getRoom')
            ->with($roomId)
            ->willReturn($room);
        $this->roomService->method('deleteRoom')
            ->with($roomId);
        $this->authService->method('requireHouseMember')
            ->with($houseId)
            ->willReturn($this->createMock(\OCA\ChoreStrike\Db\HouseUser::class));

        $response = $this->controller->delete($roomId);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }

    /**
     * UpdateCleanliness_ValidValue_ReturnsUpdatedCleanliness
     */
    public function testUpdateCleanliness_ValidValue_ReturnsUpdatedCleanliness(): void
    {
        $roomId = 1;
        $houseId = 1;
        $change = 10;
        $room = $this->createMockRoom($roomId, 'Living Room', 1, 95);
        $existingRoom = $this->createMockRoom($roomId, 'Living Room', 1, 85);

        $this->roomService->method('getRoom')
            ->with($roomId)
            ->willReturn($existingRoom);
        $this->roomService->method('updateRoomCleanliness')
            ->with($roomId, $change)
            ->willReturn($room);
        $this->authService->method('requireHouseMember')
            ->with($houseId)
            ->willReturn($this->createMock(\OCA\ChoreStrike\Db\HouseUser::class));

        $response = $this->controller->updateCleanliness($roomId, $change);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(95, $data['data']['cleanliness']);
    }

    /**
     * Tasks_ReturnsRoomTasks_SuccessResponse
     */
    public function testTasks_ReturnsRoomTasks_SuccessResponse(): void
    {
        $roomId = 1;
        $tasks = [
            [
                'id' => 1,
                'name' => 'Vacuum',
                'energyCost' => 2,
                'priority' => 2,
                'isActive' => true,
            ],
            [
                'id' => 2,
                'name' => 'Dust surfaces',
                'energyCost' => 1,
                'priority' => 2,
                'isActive' => true,
            ],
        ];

        $this->roomService->method('getRoomWithTasks')
            ->with($roomId)
            ->willReturn(['id' => $roomId, 'name' => 'Living Room', 'tasks' => $tasks]);

        $response = $this->controller->tasks($roomId);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']['tasks']);
    }

    /**
     * NeedingCleaning_ReturnsDirtyRooms_SuccessResponse
     */
    public function testNeedingCleaning_ReturnsDirtyRooms_SuccessResponse(): void
    {
        $houseId = 1;
        $threshold = 60;
        $dirtyRooms = [
            $this->createMockRoom(3, 'Basement', 1, 45),
            $this->createMockRoom(5, 'Garage', 1, 30),
        ];

        $house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
        $house->method('getId')->willReturn($houseId);

        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->roomService->method('getRoomsNeedingCleaning')
            ->with(null)
            ->willReturn($dirtyRooms);
        $this->authService->method('requireHouseMember')
            ->with($houseId)
            ->willReturn($this->createMock(\OCA\ChoreStrike\Db\HouseUser::class));

        $response = $this->controller->needingCleaning();

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']);
    }
}
