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
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Service for floor-related business logic
 */
class FloorService {
	private FloorMapper $floorMapper;
	private RoomMapper $roomMapper;
	private RoomTaskMapper $roomTaskMapper;
	private RoomService $roomService;
	private IDBConnection $db;
	private IL10N $l10n;

	public function __construct(
		FloorMapper $floorMapper,
		RoomMapper $roomMapper,
		RoomTaskMapper $roomTaskMapper,
		RoomService $roomService,
		IDBConnection $db,
		IL10N $l10n,
	) {
		$this->floorMapper = $floorMapper;
		$this->roomMapper = $roomMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->roomService = $roomService;
		$this->db = $db;
		$this->l10n = $l10n;
	}

	/**
	 * Validate that a floor belongs to the specified house
	 *
	 * @param int $floorId The floor ID
	 * @param int $houseId The house ID to validate against
	 * @throws NotFoundException if floor doesn't belong to house
	 */
	public function validateFloorOwnership(int $floorId, int $houseId): void {
		$floor = $this->floorMapper->find($floorId);

		if ($floor->getHouseId() !== $houseId) {
			throw new NotFoundException($this->l10n->t('Floor not found'));
		}
	}

	/**
	 * Get all floors for a house
	 *
	 * @param int $houseId The house ID
	 * @return Floor[]
	 */
	public function getFloors(int $houseId): array {
		return $this->floorMapper->findByHouseId($houseId);
	}

	/**
	 * Get floor by ID
	 *
	 * @param int $floorId The floor ID
	 * @throws DoesNotExistException if not found
	 */
	public function getFloor(int $floorId): Floor {
		return $this->floorMapper->find($floorId);
	}

	/**
	 * Create a new floor
	 *
	 * @param int $houseId The house ID
	 * @param string $name The floor name
	 * @param int|null $sortOrder Optional sort order (auto-calculated if null)
	 */
	public function createFloor(int $houseId, string $name, ?int $sortOrder = null): Floor {
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
	public function updateFloorName(int $floorId, string $name): Floor {
		$floor = $this->floorMapper->find($floorId);
		$floor->setName($name);
		return $this->floorMapper->update($floor);
	}

	/**
	 * Reorder floors
	 *
	 * One CASE-WHEN bulk UPDATE (CS-PLAN-037, CR-SVC-60) instead of a
	 * per-floor updateSortOrder + find re-fetch (1 + 2N → 2 queries);
	 * the return payload is built from the already-fetched floor
	 * entities with their new sort_order set in PHP.
	 *
	 * @param int $houseId The house ID
	 * @param array $floorOrder Array of floor IDs in desired order
	 */
	public function reorderFloors(int $houseId, array $floorOrder): array {
		$floors = $this->floorMapper->findByHouseId($houseId);

		$floorsById = [];
		foreach ($floors as $floor) {
			$floorsById[$floor->getId()] = $floor;
		}

		// Validate all floor IDs belong to this house
		foreach ($floorOrder as $floorId) {
			if (!isset($floorsById[$floorId])) {
				throw new \InvalidArgumentException("Floor ID {$floorId} does not belong to house {$houseId}");
			}
		}

		$floorIdToOrder = [];
		foreach ($floorOrder as $index => $floorId) {
			$floorIdToOrder[$floorId] = $index;
		}
		$this->floorMapper->updateSortOrders($floorIdToOrder);

		// Build the payload from the already-fetched entities — the only
		// floors in the payload are the reordered ones, in the new order.
		$updatedFloors = [];
		foreach ($floorOrder as $index => $floorId) {
			$floor = $floorsById[$floorId];
			$floor->setSortOrder($index);
			$updatedFloors[] = $floor;
		}

		return $updatedFloors;
	}

	/**
	 * Delete floor and everything that depends on it (CS-PLAN-037, D1/D2).
	 *
	 * The explicit cascade lives here, not in FloorMapper (architecture
	 * fix: mappers never cross-instantiate other mappers; the old
	 * deleteWithRooms also left orphaned dependents behind and carried a
	 * misleading "cascade will handle this" comment — the schema has no
	 * FK constraints). Transactional, constant query count: dependents
	 * via RoomService::deleteRoomTaskDependents, then room tasks by room
	 * ids, rooms by floor id, and the floor itself.
	 *
	 * @param int $floorId The floor ID
	 */
	public function deleteFloor(int $floorId): void {
		$floor = $this->floorMapper->find($floorId);

		$roomIds = [];
		foreach ($this->roomMapper->findByFloorId($floorId) as $room) {
			$roomIds[] = $room->getId();
		}

		$this->db->beginTransaction();
		try {
			$this->roomService->deleteRoomTaskDependents(
				$this->roomTaskMapper->findIdsByRoomIds($roomIds)
			);
			$this->roomTaskMapper->deleteByRoomIds($roomIds);
			$this->roomMapper->deleteByFloorId($floorId);
			$this->floorMapper->delete($floor);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Get floor with rooms
	 *
	 * @param int $floorId The floor ID
	 */
	public function getFloorWithRooms(int $floorId): array {
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
	private function calculateFloorStats(array $rooms): array {
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
	 * Rooms for every floor come from ONE house-scoped fetch
	 * (RoomMapper::findByHouseId, CS-PLAN-036) grouped by floor_id in PHP,
	 * instead of delegating to getFloorWithRooms' 2 queries per floor.
	 * The single-floor endpoint keeps its own behavior.
	 *
	 * @param int $houseId The house ID
	 */
	public function getFloorsWithRooms(int $houseId): array {
		$floors = $this->floorMapper->findByHouseId($houseId);

		$roomsByFloorId = [];
		foreach ($this->roomMapper->findByHouseId($houseId) as $room) {
			$roomsByFloorId[$room->getFloorId()][] = $room;
		}

		$result = [];
		foreach ($floors as $floor) {
			$rooms = $roomsByFloorId[$floor->getId()] ?? [];
			$result[] = [
				'floor' => $floor,
				'rooms' => $rooms,
				'stats' => $this->calculateFloorStats($rooms)
			];
		}

		return $result;
	}
}
