<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\ChoreStrike\Exception\NotFoundException;

/**
 * Service for floor-related business logic
 */
class FloorService
{
    private FloorMapper $floorMapper;
    private RoomMapper $roomMapper;

    public function __construct(
        FloorMapper $floorMapper,
        RoomMapper $roomMapper
    ) {
        $this->floorMapper = $floorMapper;
        $this->roomMapper = $roomMapper;
    }

    /**
     * Validate that a floor belongs to the specified house
     *
     * @param int $floorId The floor ID
     * @param int $houseId The house ID to validate against
     * @throws NotFoundException if floor doesn't belong to house
     */
    public function validateFloorOwnership(int $floorId, int $houseId): void
    {
        $floor = $this->floorMapper->find($floorId);

        if ($floor->getHouseId() !== $houseId) {
            throw new NotFoundException('Floor not found');
        }
    }

    /**
     * Get all floors for a house
     *
     * @param int $houseId The house ID
     * @return Floor[]
     */
    public function getFloors(int $houseId): array
    {
        return $this->floorMapper->findByHouseId($houseId);
    }

    /**
     * Get floor by ID
     *
     * @param int $floorId The floor ID
     * @throws DoesNotExistException if not found
     */
    public function getFloor(int $floorId): Floor
    {
        return $this->floorMapper->find($floorId);
    }

    /**
     * Create a new floor
     *
     * @param int $houseId The house ID
     * @param string $name The floor name
     * @param int|null $sortOrder Optional sort order (auto-calculated if null)
     */
    public function createFloor(int $houseId, string $name, ?int $sortOrder = null): Floor
    {
        // Auto-calculate sort order if not provided
        if ($sortOrder === null) {
            $existingFloors = $this->floorMapper->findByHouseId($houseId);
            $sortOrder = count($existingFloors);
        }

        return $this->floorMapper->create($houseId, $name, $sortOrder);
    }

    /**
     * Update floor name
     *
     * @param int $floorId The floor ID
     * @param string $name The new name
     */
    public function updateFloorName(int $floorId, string $name): Floor
    {
        $floor = $this->floorMapper->find($floorId);
        $floor->setName($name);
        return $this->floorMapper->update($floor);
    }

    /**
     * Reorder floors
     *
     * @param int $houseId The house ID
     * @param array $floorOrder Array of floor IDs in desired order
     */
    public function reorderFloors(int $houseId, array $floorOrder): array
    {
        $floors = $this->floorMapper->findByHouseId($houseId);
        $updatedFloors = [];

        // Validate all floor IDs belong to this house
        $houseFloorIds = array_map(fn ($floor) => $floor->getId(), $floors);
        foreach ($floorOrder as $floorId) {
            if (!in_array($floorId, $houseFloorIds)) {
                throw new \InvalidArgumentException("Floor ID {$floorId} does not belong to house {$houseId}");
            }
        }

        // Update sort order
        foreach ($floorOrder as $index => $floorId) {
            $this->floorMapper->updateSortOrder($floorId, $index);
            $updatedFloors[] = $this->floorMapper->find($floorId);
        }

        return $updatedFloors;
    }

    /**
     * Delete floor and all associated rooms
     *
     * @param int $floorId The floor ID
     */
    public function deleteFloor(int $floorId): void
    {
        $floor = $this->floorMapper->find($floorId);

        // Check if floor has rooms and if deletion is allowed
        $rooms = $this->roomMapper->findByFloorId($floorId);
        if (!empty($rooms)) {
            // For now, allow deletion with rooms (cascade will handle it)
            // In the future, we might want to require explicit confirmation
        }

        $this->floorMapper->deleteWithRooms($floorId);
    }

    /**
     * Get floor with rooms
     *
     * @param int $floorId The floor ID
     */
    public function getFloorWithRooms(int $floorId): array
    {
        $floor = $this->floorMapper->find($floorId);
        $rooms = $this->roomMapper->findByFloorId($floorId);

        return [
            'floor' => $floor,
            'rooms' => $rooms,
            'stats' => $this->calculateFloorStats($rooms)
        ];
    }

    /**
     * Calculate floor statistics
     *
     * @param Room[] $rooms Array of rooms
     */
    private function calculateFloorStats(array $rooms): array
    {
        if (empty($rooms)) {
            return [
                'totalRooms' => 0,
                'averageCleanliness' => 100,
                'roomsNeedingCleaning' => 0,
                'totalMonsters' => 0
            ];
        }

        $totalCleanliness = 0;
        $roomsNeedingCleaning = 0;
        $totalMonsters = 0;

        foreach ($rooms as $room) {
            $totalCleanliness += $room->getCleanliness();
            if ($room->needsCleaning()) {
                $roomsNeedingCleaning++;
            }
            $totalMonsters += $room->getMonsterCount();
        }

        return [
            'totalRooms' => count($rooms),
            'averageCleanliness' => (int)round($totalCleanliness / count($rooms)),
            'roomsNeedingCleaning' => $roomsNeedingCleaning,
            'totalMonsters' => $totalMonsters
        ];
    }

    /**
     * Get all floors with their rooms for a house
     *
     * @param int $houseId The house ID
     */
    public function getFloorsWithRooms(int $houseId): array
    {
        $floors = $this->floorMapper->findByHouseId($houseId);
        $result = [];

        foreach ($floors as $floor) {
            $result[] = $this->getFloorWithRooms($floor->getId());
        }

        return $result;
    }
}
