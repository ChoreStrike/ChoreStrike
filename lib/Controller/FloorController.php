<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\FloorService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * API controller for floor-related endpoints
 */
class FloorController extends OCSController
{
    private FloorService $floorService;
    private HouseService $houseService;
    private AuthorizationService $authService;

    public function __construct(
        IRequest $request,
        FloorService $floorService,
        HouseService $houseService,
        AuthorizationService $authService
    ) {
        parent::__construct('chorestrike', $request);
        $this->floorService = $floorService;
        $this->houseService = $houseService;
        $this->authService = $authService;
    }

    /**
     * Get all floors for the current house
     *
     * @NoAdminRequired
     */
    public function index(): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);

            // Require house membership
            $this->authService->requireHouseMember($house->getId());

            $floors = $this->floorService->getFloors($house->getId());

            return new JSONResponse([
                'success' => true,
                'data' => array_map(fn ($floor) => $floor->jsonSerialize(), $floors)
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
     * @NoAdminRequired
     */
    public function show(int $id): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());
            $this->floorService->validateFloorOwnership($id, $house->getId());

            $floorWithRooms = $this->floorService->getFloorWithRooms($id);
            $houseId = $floorWithRooms['floor']->getHouseId();
            $this->authService->requireHouseMember($houseId);

            return new JSONResponse([
                'success' => true,
                'data' => [
                    'floor' => $floorWithRooms['floor']->jsonSerialize(),
                    'rooms' => array_map(fn ($room) => $room->jsonSerialize(), $floorWithRooms['rooms']),
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
     * @NoAdminRequired
     */
    public function create(string $name, ?int $sortOrder = null): JSONResponse
    {
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

            // Require house membership for creation
            $this->authService->requireHouseMember($house->getId());

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
     * @NoAdminRequired
     */
    public function update(int $id, string $name): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());
            $this->floorService->validateFloorOwnership($id, $house->getId());

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
     * @NoAdminRequired
     */
    public function reorder(array $floorOrder): JSONResponse
    {
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

            // Require house membership
            $this->authService->requireHouseMember($house->getId());

            $updatedFloors = $this->floorService->reorderFloors($house->getId(), $floorOrder);

            return new JSONResponse([
                'success' => true,
                'data' => array_map(fn ($floor) => $floor->jsonSerialize(), $updatedFloors)
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
     * @NoAdminRequired
     */
    public function delete(int $id): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());
            $this->floorService->validateFloorOwnership($id, $house->getId());

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
     * @NoAdminRequired
     */
    public function withRooms(): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);

            // Require house membership
            $this->authService->requireHouseMember($house->getId());

            $floorsWithRooms = $this->floorService->getFloorsWithRooms($house->getId());

            $result = [];
            foreach ($floorsWithRooms as $floorWithRooms) {
                $result[] = [
                    'floor' => $floorWithRooms['floor']->jsonSerialize(),
                    'rooms' => array_map(fn ($room) => $room->jsonSerialize(), $floorWithRooms['rooms']),
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
