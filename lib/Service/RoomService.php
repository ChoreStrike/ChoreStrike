<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CycleProgressMapper;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Service for room-related business logic
 *
 * The completion/undo critical path moved to {@see CompletionService}
 * (CS-PLAN-064 WP5, decision D2); RoomService keeps the room structure
 * surface and the shared dependents cascade.
 */
class RoomService {
	private RoomMapper $roomMapper;
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private FloorMapper $floorMapper;
	private HouseMapper $houseMapper;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private RoomTaskChecklistProgressMapper $checklistProgressMapper;
	private CycleProgressMapper $cycleProgressMapper;
	private TransactionExecutor $transactions;
	private RoomReplayService $roomReplayService;
	private IL10N $l10n;
	private AppLogger $appLogger;
	private AuthorizationService $authorization;

	public function __construct(
		RoomMapper $roomMapper,
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		FloorMapper $floorMapper,
		HouseMapper $houseMapper,
		TaskAssigneeMapper $taskAssigneeMapper,
		RoomTaskChecklistProgressMapper $checklistProgressMapper,
		CycleProgressMapper $cycleProgressMapper,
		TransactionExecutor $transactions,
		RoomReplayService $roomReplayService,
		IL10N $l10n,
		AppLogger $appLogger,
		AuthorizationService $authorization,
	) {
		$this->roomMapper = $roomMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->taskMapper = $taskMapper;
		$this->floorMapper = $floorMapper;
		$this->houseMapper = $houseMapper;
		$this->taskAssigneeMapper = $taskAssigneeMapper;
		$this->checklistProgressMapper = $checklistProgressMapper;
		$this->cycleProgressMapper = $cycleProgressMapper;
		$this->transactions = $transactions;
		$this->roomReplayService = $roomReplayService;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
		$this->authorization = $authorization;
	}

	/**
	 * Validate that a room belongs to the specified house
	 *
	 * Tombstoned rooms are not found for planning purposes (CS-PLAN-071
	 * Q-70-01); history reads resolve them through their own paths.
	 *
	 * @param int $roomId The room ID
	 * @param int $houseId The house ID to validate against
	 * @throws NotFoundException if room doesn't exist or belong to house
	 */
	public function validateRoomOwnership(int $roomId, int $houseId): void {
		try {
			$room = $this->roomMapper->find($roomId);
			$floor = $this->floorMapper->find($room->getFloorId());
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Room not found'));
		}

		if ($floor->getHouseId() !== $houseId || $room->getDeletedAt() !== null) {
			throw new NotFoundException($this->l10n->t('Room not found'));
		}
	}

	/**
	 * Get floor by ID.
	 *
	 * @param int $floorId The floor ID
	 * @throws NotFoundException if not found
	 */
	public function getFloor(int $floorId): Floor {
		try {
			return $this->floorMapper->find($floorId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Floor not found'));
		}
	}

	/**
	 * Create a new room (owner-only, CS-PLAN-061 §3.4 / ADR-006).
	 *
	 * The floor is resolved here (a nonexistent floor id maps to 404) and
	 * the caller must own the floor's house — controller checks stay as
	 * defense in depth only.
	 *
	 * @param int $floorId The floor ID
	 * @param string $name The room name
	 * @param ?string $icon The configured room icon
	 * @param string $roomType The room type (defaults to 'generic')
	 * @throws NotFoundException if the floor does not exist
	 * @throws \OCA\ChoreStrike\Exception\ForbiddenException if the caller is not the house owner
	 */
	public function createRoom(int $floorId, string $name, ?string $icon = null, string $roomType = 'generic'): Room {
		$floor = $this->getFloor($floorId);
		$this->authorization->requireHouseOwner($floor->getHouseId());

		// Q-70-01 (CS-PLAN-071 WP0): no live planning state beneath
		// tombstoned structure — a deleted floor cannot gain rooms.
		if ($floor->getDeletedAt() !== null) {
			throw new NotFoundException($this->l10n->t('Floor not found'));
		}

		// CS-PLAN-071 WP0: structure mutations enter through the house row
		// lock (single house-first order).
		$actorId = 0;
		$room = $this->transactions->run(function () use ($floor, $floorId, $name, $icon, $roomType, &$actorId): Room {
			$this->houseMapper->findForUpdate($floor->getHouseId());
			$actorId = (int)$this->authorization->requireHouseOwner($floor->getHouseId())->getId();

			// Authoritative tombstone gate (Q-70-01): a floor deleted
			// behind the pre-read still cannot gain rooms.
			if ($this->floorMapper->find($floorId)->getDeletedAt() !== null) {
				throw new NotFoundException($this->l10n->t('Floor not found'));
			}

			// FEAT-UX-044: a duplicate name gets the next free number,
			// resolved under the house lock.
			$name = RoomNamePolicy::unique($name, $this->roomMapper->findLiveNamesByHouseId((int)$floor->getHouseId()));
			$room = $this->roomMapper->create($floorId, $name, $icon, $roomType);
			// WP3 §3.5: the creation baseline is journaled BEFORE any room
			// mutation (cleanliness 50, monsters 0, no last_cleaned).
			$this->roomReplayService->recordBaseline($room);
			return $room;
		});
		$this->appLogger->audit('Room created', [
			'event' => 'house.room.created', 'service' => 'RoomService',
			'houseId' => (int)$floor->getHouseId(), 'actorId' => $actorId,
			'roomId' => (int)$room->getId(), 'outcome' => 'created',
		]);
		return $room;
	}

	/**
	 * Update room name (owner-only, CS-PLAN-061 §3.4 / ADR-006).
	 *
	 * CS-PLAN-071 WP0: the write runs under the house row lock (single
	 * house-first order).
	 *
	 * @param int $roomId The room ID
	 * @param string $name The new name
	 * @throws NotFoundException if the room does not exist
	 * @throws \OCA\ChoreStrike\Exception\ForbiddenException if the caller is not the house owner
	 */
	public function updateRoomName(int $roomId, string $name): Room {
		$room = $this->roomMapper->find($roomId);
		$floor = $this->floorMapper->find($room->getFloorId());
		$this->authorization->requireHouseOwner($floor->getHouseId());

		// Q-70-01: tombstoned structure is immutable evidence — renames
		// answer 404 like a missing room.
		if ($room->getDeletedAt() !== null) {
			throw new NotFoundException($this->l10n->t('Room not found'));
		}

		$houseId = $floor->getHouseId();
		$actorId = 0;
		$changed = false;
		$updated = $this->transactions->run(function () use ($roomId, $name, $houseId, &$actorId, &$changed): Room {
			$this->houseMapper->findForUpdate($houseId);
			$actorId = (int)$this->authorization->requireHouseOwner($houseId)->getId();
			$fresh = $this->roomMapper->find($roomId);

			// Authoritative tombstone gate (Q-70-01): a room deleted
			// behind the pre-read is immutable evidence — 404, zero writes.
			if ($fresh->getDeletedAt() !== null) {
				throw new NotFoundException($this->l10n->t('Room not found'));
			}

			// FEAT-UX-044: unique among the house's other live rooms; saving
			// the unchanged name keeps a legacy duplicate as it is.
			$name = trim($name) === $fresh->getName()
				? $fresh->getName()
				: RoomNamePolicy::unique($name, $this->roomMapper->findLiveNamesByHouseId((int)$houseId, $roomId));
			$changed = $fresh->getName() !== $name;
			$fresh->setName($name);
			return $this->roomMapper->update($fresh);
		});
		$this->appLogger->audit('Room rename completed', [
			'event' => 'house.room.renamed', 'service' => 'RoomService',
			'houseId' => (int)$houseId, 'actorId' => $actorId,
			'roomId' => $roomId, 'outcome' => $changed ? 'changed' : 'no-change',
		]);
		return $updated;
	}

	/**
	 * Tombstone a room and its room tasks (CS-PLAN-071 WP0, Q-070-01 /
	 * RF-070-16 WP0 portion).
	 *
	 * Structural deletion keeps the evidence: inside one house-first
	 * transaction the room is tombstoned (`deleted_at` set), its room
	 * tasks are tombstoned (`deleted_at` + `deleted_token = id` +
	 * `is_active = 0`), and each tombstoned room task's assignees and
	 * checklist progress are deleted (active-planning state).
	 * **`completed_tasks` rows are retained** — minimum history, existing
	 * visibility, valid earned effects (decision D4). History/activity
	 * reads keep resolving names through the tombstoned rows; no
	 * production path hard-deletes structural rows outside the test-mode
	 * reset.
	 *
	 * @param int $roomId The room ID
	 */
	public function deleteRoom(int $roomId): void {
		$room = $this->roomMapper->find($roomId);
		$floor = $this->floorMapper->find($room->getFloorId());
		// Structure mutation → owner-only in the service (CS-PLAN-061 §3.4).
		$this->authorization->requireHouseOwner($floor->getHouseId());

		// Q-70-01: tombstoned structure is immutable evidence — a repeat
		// delete answers 404 like a missing room and never re-stamps the
		// original deletion instant.
		if ($room->getDeletedAt() !== null) {
			throw new NotFoundException($this->l10n->t('Room not found'));
		}

		$actorId = 0;
		try {
			$this->transactions->run(function () use ($roomId, $floor, &$actorId): void {
				// FIRST lock of the documented order: the house row.
				$this->houseMapper->findForUpdate($floor->getHouseId());
				$actorId = (int)$this->authorization->requireHouseOwner($floor->getHouseId())->getId();

				// Authoritative tombstone gate on the FRESH locked row:
				// a concurrent delete winner makes this invocation a clean
				// 404 with zero writes (the original deleted_at survives).
				$room = $this->roomMapper->find($roomId);
				if ($room->getDeletedAt() !== null) {
					throw new NotFoundException($this->l10n->t('Room not found'));
				}

				$roomTaskIds = $this->roomTaskMapper->findIdsByRoomId($roomId);
				$this->deleteRoomTaskPlanningState($roomTaskIds);
				$deletedAt = new \DateTime();
				$this->roomTaskMapper->tombstoneByRoomIds([$roomId], $deletedAt);
				$room->setDeletedAt($deletedAt);
				$this->roomMapper->update($room);
			});
			$this->appLogger->audit('Room deleted', [
				'event' => 'house.room.deleted', 'service' => 'RoomService',
				'houseId' => (int)$floor->getHouseId(), 'actorId' => $actorId,
				'roomId' => $roomId, 'outcome' => 'deleted',
			]);
		} catch (\Throwable $e) {
			$this->appLogger->logException('RoomService: room tombstone rolled back', $e, [
				'event' => 'room_delete_rollback',
				'service' => 'RoomService',
				'roomId' => $roomId,
			]);
			throw $e;
		}
	}

	/**
	 * Delete the active-planning state of the given room tasks — the
	 * assignees and checklist progress shared by the tombstone cascades of
	 * `deleteRoom`, `RoomTaskService::deleteRoomTask`, and
	 * `FloorService::deleteFloor` (CS-PLAN-071 WP0, Q-070-01).
	 *
	 * **`completed_tasks` rows are never touched here** — the former
	 * evidence-deleting cascade (CS-PLAN-037 decision D2) is superseded:
	 * completion history is retained for tombstoned room tasks (minimum
	 * history, Q-070-01). Lives here (not RoomTaskService) because
	 * RoomTaskService already depends on RoomService — the dependency
	 * arrow can only point this way. Callers wrap it in their own
	 * house-first transaction.
	 *
	 * @param int[] $roomTaskIds The room task IDs (empty input is a no-op)
	 */
	public function deleteRoomTaskPlanningState(array $roomTaskIds): void {
		$this->checklistProgressMapper->deleteByRoomTaskIds($roomTaskIds);
		$this->cycleProgressMapper->deleteByRoomTaskIds($roomTaskIds);
		$this->taskAssigneeMapper->deleteByRoomTasks($roomTaskIds);
	}

	/**
	 * Get room task by ID
	 *
	 * @param int $roomTaskId The room task ID
	 * @throws NotFoundException if not found
	 */
	public function getRoomTask(int $roomTaskId): array {
		try {
			$roomTask = $this->roomTaskMapper->find($roomTaskId);
			$task = $this->taskMapper->find($roomTask->getTaskId());
			$room = $this->roomMapper->find($roomTask->getRoomId());
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}

		return [
			'roomTask' => $roomTask,
			'task' => $task,
			'room' => $room
		];
	}

}
