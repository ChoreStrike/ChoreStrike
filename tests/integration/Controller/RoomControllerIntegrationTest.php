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
        $floorId = 1;
        $rooms = [
            $this->createMockRoom(1, 'Living Room', $floorId, 85),
            $this->createMockRoom(2, 'Kitchen', $floorId, 90),
        ];

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
        $this->authService->method('requireHouseMember')->willReturn($this->createMockHouseUser());
        $this->roomService->method('getRooms')
            ->with($floorId)
            ->willReturn($rooms);

        $response = $this->controller->index($floorId);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }

    private function createMockFloor(int $id, string $name, int $sortOrder, int $houseId): \OCA\ChoreStrike\Db\Floor
    {
        $floor = new \OCA\ChoreStrike\Db\Floor();
        $floor->setId($id);
        $floor->setName($name);
        $floor->setSortOrder($sortOrder);
        $floor->setHouseId($houseId);
        $floor->setCreatedAt(new \DateTime());
        return $floor;
    }

    private function createMockRoom(int $id, string $name, int $floorId, int $cleanliness): \OCA\ChoreStrike\Db\Room
    {
        $room = new \OCA\ChoreStrike\Db\Room();
        $room->setId($id);
        $room->setName($name);
        $room->setFloorId($floorId);
        $room->setCleanliness($cleanliness);
        $room->setIcon('Home');
        $room->setCreatedAt(new \DateTime());
        return $room;
    }

    private function createMockHouse(): \OCA\ChoreStrike\Db\House
    {
        $house = new \OCA\ChoreStrike\Db\House();
        $house->setId(1);
        $house->setNextcloudInstanceId('test-instance');
        $house->setName('Test House');
        return $house;
    }

    private function createMockHouseUser(): \OCA\ChoreStrike\Db\HouseUser
    {
        $user = new \OCA\ChoreStrike\Db\HouseUser();
        $user->setId(1);
        $user->setHouseId(1);
        $user->setNextcloudUserId('test-user');
        $user->setDisplayName('Test User');
        $user->setRole('member');
        return $user;
    }

    /**
     * Show_ExistingRoom_ReturnsRoomDetails
     */
    public function testShow_ExistingRoom_ReturnsRoomDetails(): void
    {
        $roomId = 1;
        $room = $this->createMockRoom($roomId, 'Living Room', 1, 85);

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
        $this->authService->method('requireHouseMember')->willReturn($this->createMockHouseUser());
        $this->roomService->method('getRoomWithTasks')
            ->with($roomId)
            ->willReturn(['room' => $room, 'tasks' => [], 'stats' => ['totalTasks' => 0]]);

        $response = $this->controller->show($roomId);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }

    private function createMockRoomTask(int $id, int $roomId, int $taskId): \OCA\ChoreStrike\Db\RoomTask
    {
        $roomTask = new \OCA\ChoreStrike\Db\RoomTask();
        $roomTask->setId($id);
        $roomTask->setRoomId($roomId);
        $roomTask->setTaskId($taskId);
        $roomTask->setIsActive(true);
        $roomTask->setNextDue(new \DateTime());
        $roomTask->setCreatedAt(new \DateTime());
        return $roomTask;
    }

    private function createMockTask(int $id, string $name, int $energyCost): \OCA\ChoreStrike\Db\Task
    {
        $task = new \OCA\ChoreStrike\Db\Task();
        $task->setId($id);
        $task->setName($name);
        $task->setEnergyCost($energyCost);
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
