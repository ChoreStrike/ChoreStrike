<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * API controller for room-related endpoints
 */
class RoomController extends OCSController {

	private RoomService $roomService;
	private HouseService $houseService;
	private AuthorizationService $authService;

	public function __construct(
		IRequest $request,
		RoomService $roomService,
		HouseService $houseService,
		AuthorizationService $authService
	) {
		parent::__construct('chorestrike', $request);
		$this->roomService = $roomService;
		$this->houseService = $houseService;
		$this->authService = $authService;
	}

	/**
	 * Get all rooms for a floor
	 *
	 * @NoAdminRequired
	 */
	public function index(int $floorId): JSONResponse {
		try {
			// Verify house membership via floor
			$floor = $this->roomService->getFloor($floorId);
			$this->authService->requireHouseMember($floor->getHouseId());
			
			$rooms = $this->roomService->getRooms($floorId);
			
			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn($room) => $room->jsonSerialize(), $rooms)
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ROOMS_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Get room with tasks
	 *
	 * @NoAdminRequired
	 */
	public function show(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());
			$this->roomService->validateRoomOwnership($id, $house->getId());
			
			$roomWithTasks = $this->roomService->getRoomWithTasks($id);
			$room = $roomWithTasks['room'];
			$this->authService->requireHouseMember($room->getHouseId());
			
			$tasksData = [];
			foreach ($roomWithTasks['tasks'] as $taskWithDetails) {
				$tasksData[] = [
					'roomTask' => $taskWithDetails['roomTask']->jsonSerialize(),
					'task' => $taskWithDetails['task']->jsonSerialize()
				];
			}
			
			return new JSONResponse([
				'success' => true,
				'data' => [
					'room' => $roomWithTasks['room']->jsonSerialize(),
					'tasks' => $tasksData,
					'stats' => $roomWithTasks['stats']
				]
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ROOM_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Create a new room
	 *
	 * @NoAdminRequired
	 */
	public function create(int $floorId, string $name): JSONResponse {
		try {
			if (empty(trim($name))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'Room name is required'
					]
				], 400);
			}

			// Verify house membership via floor
			$floor = $this->roomService->getFloor($floorId);
			$this->authService->requireHouseMember($floor->getHouseId());
			
			$room = $this->roomService->createRoom($floorId, $name);
			
			return new JSONResponse([
				'success' => true,
				'data' => $room->jsonSerialize()
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ROOM_CREATE_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Update room name
	 *
	 * @NoAdminRequired
	 */
	public function update(int $id, string $name): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());
			$this->roomService->validateRoomOwnership($id, $house->getId());
			
			if (empty(trim($name))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'Room name is required'
					]
				], 400);
			}
			
			// Get room and verify house membership before update
			$room = $this->roomService->getRoom($id);
			$this->authService->requireHouseMember($room->getHouseId());
			
			$room = $this->roomService->updateRoomName($id, $name);
			
			return new JSONResponse([
				'success' => true,
				'data' => $room->jsonSerialize()
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ROOM_UPDATE_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Delete room
	 *
	 * @NoAdminRequired
	 */
	public function delete(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());
			$this->roomService->validateRoomOwnership($id, $house->getId());
			
			$room = $this->roomService->getRoom($id);
			$this->authService->requireHouseMember($room->getHouseId());
			
			$this->roomService->deleteRoom($id);
			
			return new JSONResponse([
				'success' => true,
				'message' => 'Room deleted successfully'
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ROOM_DELETE_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Update room cleanliness
	 *
	 * @NoAdminRequired
	 */
	public function updateCleanliness(int $id, int $cleanlinessChange): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());
			$this->roomService->validateRoomOwnership($id, $house->getId());
			
			if ($cleanlinessChange < -100 || $cleanlinessChange > 100) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'Cleanliness change must be between -100 and 100'
					]
				], 400);
			}
			
			// Get room and verify house membership
			$room = $this->roomService->getRoom($id);
			$this->authService->requireHouseMember($room->getHouseId());

			$room = $this->roomService->updateRoomCleanliness($id, $cleanlinessChange);
			
			return new JSONResponse([
				'success' => true,
				'data' => $room->jsonSerialize()
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'CLEANLINESS_UPDATE_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Get rooms that need cleaning
	 *
	 * @NoAdminRequired
	 */
	public function needingCleaning(?int $floorId = null): JSONResponse {
		try {
			// If floorId provided, verify house membership
			if ($floorId !== null) {
				$floor = $this->roomService->getFloor($floorId);
				$this->authService->requireHouseMember($floor->getHouseId());
			} else {
				// Otherwise verify against house
				$instanceId = $this->houseService->getInstanceId();
				$house = $this->houseService->getOrCreateHouse($instanceId);
				$this->authService->requireHouseMember($house->getId());
			}
			
			$rooms = $this->roomService->getRoomsNeedingCleaning($floorId);
			
			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn($room) => $room->jsonSerialize(), $rooms)
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ROOMS_NEEDING_CLEANING_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Add task to room
	 *
	 * @NoAdminRequired
	 */
	public function addTask(int $roomId, int $taskId, ?string $nextDue = null, ?string $assignedToUserId = null): JSONResponse {
		try {
			// Verify house membership via room
			$room = $this->roomService->getRoom($roomId);
			$this->authService->requireHouseMember($room->getHouseId());
			
			$nextDueDate = null;
			if ($nextDue !== null) {
				$nextDueDate = new \DateTime($nextDue);
				if ($nextDueDate === false) {
					throw new \InvalidArgumentException('Invalid nextDue date format');
				}
			}

			$roomTask = $this->roomService->addTaskToRoom($roomId, $taskId, $nextDueDate, $assignedToUserId);
			
			return new JSONResponse([
				'success' => true,
				'data' => $roomTask->jsonSerialize()
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ADD_TASK_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Remove task from room
	 *
	 * @NoAdminRequired
	 */
	public function removeTask(int $roomId, int $taskId): JSONResponse {
		try {
			// Verify house membership via room
			$room = $this->roomService->getRoom($roomId);
			$this->authService->requireHouseMember($room->getHouseId());
			
			$this->roomService->removeTaskFromRoom($roomId, $taskId);
			
			return new JSONResponse([
				'success' => true,
				'message' => 'Task removed from room successfully'
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'REMOVE_TASK_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Complete room task
	 *
	 * @NoAdminRequired
	 */
	public function completeTask(int $roomTaskId, string $userId): JSONResponse {
		try {
			// Verify house membership - completion uses the current user's house membership
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());
			
			$result = $this->roomService->completeRoomTask($roomTaskId, $userId);
			
			return new JSONResponse([
				'success' => true,
				'data' => [
					'roomTask' => $result['roomTask']->jsonSerialize(),
					'room' => $result['room']->jsonSerialize(),
					'task' => $result['task']->jsonSerialize(),
					'rewards' => $result['rewards']
				]
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'COMPLETE_TASK_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}
}
