<?php

declare(strict_types=1);

namespace OCA\ChoreWars\Controller;

use OCA\ChoreWars\Service\FloorService;
use OCA\ChoreWars\Service\HouseService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * API controller for floor-related endpoints
 */
class FloorController extends OCSController {

	private FloorService $floorService;
	private HouseService $houseService;

	public function __construct(
		IRequest $request,
		FloorService $floorService,
		HouseService $houseService
	) {
		parent::__construct($request);
		$this->floorService = $floorService;
		$this->houseService = $houseService;
	}

	/**
	 * Get all floors for the current house
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function index(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$floors = $this->floorService->getFloors($house->getId());
			
			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn($floor) => $floor->jsonSerialize(), $floors)
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'FLOORS_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Get floor with rooms
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function show(int $id): JSONResponse {
		try {
			$floorWithRooms = $this->floorService->getFloorWithRooms($id);
			
			return new JSONResponse([
				'success' => true,
				'data' => [
					'floor' => $floorWithRooms['floor']->jsonSerialize(),
					'rooms' => array_map(fn($room) => $room->jsonSerialize(), $floorWithRooms['rooms']),
					'stats' => $floorWithRooms['stats']
				]
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'FLOOR_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Create a new floor
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function create(string $name, ?int $sortOrder = null): JSONResponse {
		try {
			if (empty(trim($name))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'Floor name is required'
					]
				], 400);
			}

			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$floor = $this->floorService->createFloor($house->getId(), $name, $sortOrder);
			
			return new JSONResponse([
				'success' => true,
				'data' => $floor->jsonSerialize()
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'FLOOR_CREATE_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Update floor name
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function update(int $id, string $name): JSONResponse {
		try {
			if (empty(trim($name))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'Floor name is required'
					]
				], 400);
			}

			$floor = $this->floorService->updateFloorName($id, $name);
			
			return new JSONResponse([
				'success' => true,
				'data' => $floor->jsonSerialize()
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'FLOOR_UPDATE_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Reorder floors
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function reorder(array $floorOrder): JSONResponse {
		try {
			if (empty($floorOrder)) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'Floor order is required'
					]
				], 400);
			}

			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$updatedFloors = $this->floorService->reorderFloors($house->getId(), $floorOrder);
			
			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn($floor) => $floor->jsonSerialize(), $updatedFloors)
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'FLOOR_REORDER_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Delete floor
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function delete(int $id): JSONResponse {
		try {
			$this->floorService->deleteFloor($id);
			
			return new JSONResponse([
				'success' => true,
				'message' => 'Floor deleted successfully'
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'FLOOR_DELETE_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Get all floors with their rooms for the current house
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function withRooms(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$floorsWithRooms = $this->floorService->getFloorsWithRooms($house->getId());
			
			$result = [];
			foreach ($floorsWithRooms as $floorWithRooms) {
				$result[] = [
					'floor' => $floorWithRooms['floor']->jsonSerialize(),
					'rooms' => array_map(fn($room) => $room->jsonSerialize(), $floorWithRooms['rooms']),
					'stats' => $floorWithRooms['stats']
				];
			}
			
			return new JSONResponse([
				'success' => true,
				'data' => $result
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'FLOORS_WITH_ROOMS_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}
}
