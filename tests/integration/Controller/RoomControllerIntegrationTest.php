<?php

declare(strict_types=1);

namespace OCA\ChoreWars\Tests\Integration\Controller;

use OCA\ChoreWars\Controller\RoomController;
use OCA\ChoreWars\Service\RoomService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for RoomController API endpoints
 */
class RoomControllerIntegrationTest extends TestCase {

	private RoomController $controller;
	private RoomService $roomService;
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->roomService = $this->createMock(RoomService::class);

		$this->controller = new RoomController(
			$this->request,
			$this->roomService
		);
	}

	/**
	 * Index_ReturnsRoomsList_SuccessResponse
	 */
	public function testIndex_ReturnsRoomsList_SuccessResponse(): void {
		$rooms = [
			['id' => 1, 'name' => 'Living Room', 'floorId' => 1, 'cleanliness' => 85],
			['id' => 2, 'name' => 'Kitchen', 'floorId' => 1, 'cleanliness' => 90],
			['id' => 3, 'name' => 'Bedroom', 'floorId' => 2, 'cleanliness' => 75],
		];

		$this->roomService->method('getRooms')
			->willReturn($rooms);

		$response = $this->controller->index();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($rooms, $data['data']);
	}

	/**
	 * Show_ExistingRoom_ReturnsRoomDetails
	 */
	public function testShow_ExistingRoom_ReturnsRoomDetails(): void {
		$roomId = 1;
		$room = [
			'id' => $roomId,
			'name' => 'Living Room',
			'floorId' => 1,
			'cleanliness' => 85,
			'lastCleaned' => '2026-02-20T10:00:00Z',
		];

		$this->roomService->method('getRoom')
			->with($roomId)
			->willReturn($room);

		$response = $this->controller->show($roomId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($room, $data['data']);
	}

	/**
	 * Create_ValidData_ReturnsCreatedRoom
	 */
	public function testCreate_ValidData_ReturnsCreatedRoom(): void {
		$name = 'Bathroom';
		$floorId = 1;
		$createdRoom = [
			'id' => 4,
			'name' => $name,
			'floorId' => $floorId,
			'cleanliness' => 100,
		];

		$this->roomService->method('createRoom')
			->with($name, $floorId)
			->willReturn($createdRoom);

		$response = $this->controller->create($name, $floorId);

		$this->assertEquals(201, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($createdRoom, $data['data']);
	}

	/**
	 * Update_ValidData_ReturnsUpdatedRoom
	 */
	public function testUpdate_ValidData_ReturnsUpdatedRoom(): void {
		$roomId = 1;
		$newName = 'Updated Room Name';
		$newFloorId = 2;
		$updatedRoom = [
			'id' => $roomId,
			'name' => $newName,
			'floorId' => $newFloorId,
			'cleanliness' => 85,
		];

		$this->roomService->method('updateRoomName')
			->with($roomId, $newName, $newFloorId)
			->willReturn($updatedRoom);

		$response = $this->controller->update($roomId, $newName, $newFloorId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($newName, $data['data']['name']);
	}

	/**
	 * Delete_ExistingRoom_ReturnsSuccess
	 */
	public function testDelete_ExistingRoom_ReturnsSuccess(): void {
		$roomId = 1;

		$this->roomService->method('deleteRoom')
			->with($roomId)
			->willReturn(true);

		$response = $this->controller->delete($roomId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * UpdateCleanliness_ValidValue_ReturnsUpdatedCleanliness
	 */
	public function testUpdateCleanliness_ValidValue_ReturnsUpdatedCleanliness(): void {
		$roomId = 1;
		$newCleanliness = 95;
		$updatedRoom = [
			'id' => $roomId,
			'name' => 'Living Room',
			'cleanliness' => $newCleanliness,
		];

		$this->roomService->method('updateRoomCleanliness')
			->with($roomId, $newCleanliness)
			->willReturn($updatedRoom);

		$response = $this->controller->updateCleanliness($roomId, $newCleanliness);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($newCleanliness, $data['data']['cleanliness']);
	}

	/**
	 * Tasks_ReturnsRoomTasks_SuccessResponse
	 */
	public function testTasks_ReturnsRoomTasks_SuccessResponse(): void {
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
	public function testNeedingCleaning_ReturnsDirtyRooms_SuccessResponse(): void {
		$threshold = 60;
		$dirtyRooms = [
			['id' => 3, 'name' => 'Basement', 'cleanliness' => 45],
			['id' => 5, 'name' => 'Garage', 'cleanliness' => 30],
		];

		$this->roomService->method('getRoomsNeedingCleaning')
			->with($threshold)
			->willReturn($dirtyRooms);

		$response = $this->controller->needingCleaning($threshold);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($dirtyRooms, $data['data']);
	}
}
