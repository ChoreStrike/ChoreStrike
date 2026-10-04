<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Service for floor-related business logic
 */
class FloorService {
	private FloorMapper $floorMapper;
	private RoomMapper $roomMapper;
	private RoomTaskMapper $roomTaskMapper;
	private HouseMapper $houseMapper;
	private RoomService $roomService;
	private TransactionExecutor $transactions;
	private IL10N $l10n;
	private AppLogger $appLogger;
	private AuthorizationService $authorization;

	public function __construct(
		FloorMapper $floorMapper,
		RoomMapper $roomMapper,
		RoomTaskMapper $roomTaskMapper,
		HouseMapper $houseMapper,
		RoomService $roomService,
		TransactionExecutor $transactions,
		IL10N $l10n,
		AppLogger $appLogger,
		AuthorizationService $authorization,
	) {
		$this->floorMapper = $floorMapper;
		$this->roomMapper = $roomMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->houseMapper = $houseMapper;
		$this->roomService = $roomService;
		$this->transactions = $transactions;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
		$this->authorization = $authorization;
	}

	/**
	 * Validate that a floor belongs to the specified house
	 *
	 * Tombstoned floors are not found for planning purposes (CS-PLAN-071
	 * Q-70-01); history reads resolve them through their own paths.
	 *
	 * @param int $floorId The floor ID
	 * @param int $houseId The house ID to validate against
	 * @throws NotFoundException if floor doesn't exist or belong to house
	 */
	public function validateFloorOwnership(int $floorId, int $houseId): void {
		try {
			$floor = $this->floorMapper->find($floorId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Floor not found'));
		}

		if ($floor->getHouseId() !== $houseId || $floor->getDeletedAt() !== null) {
			throw new NotFoundException($this->l10n->t('Floor not found'));
		}
	}

	/**
	 * Create a new floor (owner-only, CS-PLAN-061 §3.4 / ADR-006).
	 *
	 * @param int $houseId The house ID
	 * @param string $name The floor name
	 * @param int|null $sortOrder Optional sort order (auto-calculated if null)
	 * @throws \OCA\ChoreStrike\Exception\ForbiddenException if the caller is not the house owner
	 */
	public function createFloor(int $houseId, string $name, ?int $sortOrder = null): Floor {
		// Structure mutation → owner-only in the service (controller checks
		// remain as defense in depth).
		$actorId = (int)$this->authorization->requireHouseOwner($houseId)->getId();

		$floor = $this->transactions->run(function () use ($houseId, $name, $sortOrder, &$actorId): Floor {
			// FIRST lock of the documented order: the house row.
			$this->houseMapper->findForUpdate($houseId);
			$actorId = (int)$this->authorization->requireHouseOwner($houseId)->getId();

			// Auto-calculate sort order if not provided
			$sortOrder ??= count($this->floorMapper->findByHouseId($houseId));

			return $this->floorMapper->create($houseId, $name, $sortOrder);
		});

		// Q-081-03 operational audit: post-commit, minimal numeric context.
		$this->appLogger->audit('Floor created', [
			'event' => 'house.floor.created',
			'service' => 'FloorService',
			'houseId' => $houseId,
			'actorId' => $actorId,
			'floorId' => (int)$floor->getId(),
			'outcome' => 'created',
		]);
		return $floor;
	}

	/**
	 * Update floor name (owner-only, CS-PLAN-061 §3.4 / ADR-006).
	 *
	 * CS-PLAN-071 WP0: runs under the house row lock (single house-first
	 * order).
	 *
	 * @param int $floorId The floor ID
	 * @param string $name The new name
	 * @throws NotFoundException if the floor does not exist
	 * @throws \OCA\ChoreStrike\Exception\ForbiddenException if the caller is not the house owner
	 */
	public function updateFloorName(int $floorId, string $name): Floor {
		$floor = $this->floorMapper->find($floorId);
		$actorId = (int)$this->authorization->requireHouseOwner($floor->getHouseId())->getId();

		// Q-70-01: tombstoned structure is immutable evidence — renames
		// answer 404 like a missing floor.
		if ($floor->getDeletedAt() !== null) {
			throw new NotFoundException($this->l10n->t('Floor not found'));
		}

		$changed = false;
		$updated = $this->transactions->run(function () use ($floorId, $name, $floor, &$actorId, &$changed): Floor {
			$this->houseMapper->findForUpdate($floor->getHouseId());
			$actorId = (int)$this->authorization->requireHouseOwner($floor->getHouseId())->getId();
			$fresh = $this->floorMapper->find($floorId);

			// Authoritative tombstone gate (Q-70-01): a floor deleted
			// behind the pre-read is immutable evidence — 404, zero writes.
			if ($fresh->getDeletedAt() !== null) {
				throw new NotFoundException($this->l10n->t('Floor not found'));
			}

			$changed = $fresh->getName() !== $name;
			$fresh->setName($name);
			return $this->floorMapper->update($fresh);
		});

		// Q-081-03 operational audit: post-commit, minimal numeric context.
		$this->appLogger->audit('Floor rename completed', [
			'event' => 'house.floor.renamed',
			'service' => 'FloorService',
			'houseId' => (int)$floor->getHouseId(),
			'actorId' => $actorId,
			'floorId' => $floorId,
			'outcome' => $changed ? 'changed' : 'no-change',
		]);
		return $updated;
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
		// Structure mutation → owner-only in the service (CS-PLAN-061 §3.4).
		$actorId = (int)$this->authorization->requireHouseOwner($houseId)->getId();

		$changed = false;
		$updatedFloors = $this->transactions->run(function () use ($houseId, $floorOrder, &$actorId, &$changed): array {
			// FIRST lock of the documented order: the house row.
			$this->houseMapper->findForUpdate($houseId);
			$actorId = (int)$this->authorization->requireHouseOwner($houseId)->getId();

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
				$changed = $changed || (int)$floorsById[$floorId]->getSortOrder() !== $index;
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
		});

		// Q-081-03 operational audit: post-commit, minimal numeric context.
		$this->appLogger->audit('Floor reorder completed', [
			'event' => 'house.floor.reordered',
			'service' => 'FloorService',
			'houseId' => $houseId,
			'actorId' => $actorId,
			'floorCount' => count($updatedFloors),
			'outcome' => $changed ? 'changed' : 'no-change',
		]);
		return $updatedFloors;
	}

	/**
	 * Tombstone a floor and everything structurally beneath it
	 * (CS-PLAN-071 WP0, Q-070-01 / RF-070-16 WP0 portion).
	 *
	 * The former evidence-deleting cascade (CS-PLAN-037 D1/D2) is
	 * superseded: inside one house-first transaction the floor is
	 * tombstoned, its live rooms are tombstoned, their room tasks are
	 * tombstoned (`deleted_at` + `deleted_token = id` + `is_active = 0`),
	 * and each tombstoned room task's assignees and checklist progress are
	 * deleted (active-planning state). **`completed_tasks` rows are
	 * retained** — minimum history, existing visibility, valid earned
	 * effects (decision D4). The explicit cascade lives here, not in
	 * FloorMapper (mappers never cross-instantiate other mappers).
	 *
	 * @param int $floorId The floor ID
	 */
	public function deleteFloor(int $floorId): void {
		$floor = $this->floorMapper->find($floorId);
		// Structure mutation → owner-only in the service (CS-PLAN-061 §3.4).
		$actorId = (int)$this->authorization->requireHouseOwner($floor->getHouseId())->getId();

		// Q-70-01: tombstoned structure is immutable evidence — a repeat
		// delete answers 404 like a missing floor and never re-stamps the
		// original deletion instant.
		if ($floor->getDeletedAt() !== null) {
			throw new NotFoundException($this->l10n->t('Floor not found'));
		}

		try {
			$this->transactions->run(function () use ($floorId, $floor, &$actorId): void {
				// FIRST lock of the documented order: the house row.
				$this->houseMapper->findForUpdate($floor->getHouseId());
				$actorId = (int)$this->authorization->requireHouseOwner($floor->getHouseId())->getId();

				// Authoritative tombstone gate on the FRESH locked row:
				// a concurrent delete winner makes this invocation a clean
				// 404 with zero writes (the original deleted_at survives).
				$fresh = $this->floorMapper->find($floorId);
				if ($fresh->getDeletedAt() !== null) {
					throw new NotFoundException($this->l10n->t('Floor not found'));
				}

				$roomIds = [];
				foreach ($this->roomMapper->findByFloorId($floorId) as $room) {
					$roomIds[] = $room->getId();
				}

				$roomTaskIds = $this->roomTaskMapper->findIdsByRoomIds($roomIds);
				$this->roomService->deleteRoomTaskPlanningState($roomTaskIds);

				$deletedAt = new \DateTime();
				$this->roomTaskMapper->tombstoneByRoomIds($roomIds, $deletedAt);
				$this->roomMapper->tombstoneByFloorId($floorId, $deletedAt);
				$fresh->setDeletedAt($deletedAt);
				$this->floorMapper->update($fresh);
			});

			// Q-081-03 operational audit: post-commit, minimal numeric context.
			$this->appLogger->audit('Floor deleted', [
				'event' => 'house.floor.deleted',
				'service' => 'FloorService',
				'houseId' => (int)$floor->getHouseId(),
				'actorId' => $actorId,
				'floorId' => $floorId,
				'outcome' => 'deleted',
			]);
		} catch (\Throwable $e) {
			$this->appLogger->logException('FloorService: floor tombstone rolled back', $e, [
				'event' => 'floor_delete_rollback',
				'service' => 'FloorService',
				'floorId' => $floorId,
			]);
			throw $e;
		}
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
