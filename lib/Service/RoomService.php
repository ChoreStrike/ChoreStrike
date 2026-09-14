<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
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
	private TaskAssigneeMapper $taskAssigneeMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private RoomTaskChecklistProgressMapper $checklistProgressMapper;
	private TransactionExecutor $transactions;
	private IL10N $l10n;
	private AppLogger $appLogger;
	private AuthorizationService $authorization;

	public function __construct(
		RoomMapper $roomMapper,
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		FloorMapper $floorMapper,
		TaskAssigneeMapper $taskAssigneeMapper,
		CompletedTaskMapper $completedTaskMapper,
		RoomTaskChecklistProgressMapper $checklistProgressMapper,
		TransactionExecutor $transactions,
		IL10N $l10n,
		AppLogger $appLogger,
		AuthorizationService $authorization,
	) {
		$this->roomMapper = $roomMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->taskMapper = $taskMapper;
		$this->floorMapper = $floorMapper;
		$this->taskAssigneeMapper = $taskAssigneeMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->checklistProgressMapper = $checklistProgressMapper;
		$this->transactions = $transactions;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
		$this->authorization = $authorization;
	}

	/**
	 * Validate that a room belongs to the specified house
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

		if ($floor->getHouseId() !== $houseId) {
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

		return $this->roomMapper->create($floorId, $name, $icon, $roomType);
	}

	/**
	 * Update room name (owner-only, CS-PLAN-061 §3.4 / ADR-006).
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

		$room->setName($name);
		return $this->roomMapper->update($room);
	}

	/**
	 * Delete room and everything that depends on it (CS-PLAN-037, D1/D2).
	 *
	 * The schema has no FK constraints, so the cascade is explicit and
	 * transactional (all-or-nothing): checklist progress → assignees →
	 * completions → room tasks → room, each as one bulk statement over
	 * the room's room-task ids (3 + N_roomTasks → constant ~7 queries).
	 * Before this, dependent rows in completed_tasks, task_assignees,
	 * and room_task_checklist_progress were left orphaned.
	 *
	 * @param int $roomId The room ID
	 */
	public function deleteRoom(int $roomId): void {
		$room = $this->roomMapper->find($roomId);
		$floor = $this->floorMapper->find($room->getFloorId());
		// Structure mutation → owner-only in the service (CS-PLAN-061 §3.4).
		$this->authorization->requireHouseOwner($floor->getHouseId());

		try {
			$this->transactions->run(function () use ($roomId, $room) {
				$this->deleteRoomTaskDependents($this->roomTaskMapper->findIdsByRoomId($roomId));
				$this->roomTaskMapper->deleteByRoomId($roomId);
				$this->roomMapper->delete($room);
			});
		} catch (\Throwable $e) {
			$this->appLogger->logException('RoomService: room delete rolled back', $e, [
				'event' => 'room_delete_rollback',
				'service' => 'RoomService',
				'roomId' => $roomId,
			]);
			throw $e;
		}
	}

	/**
	 * Delete all rows that depend on the given room tasks — the explicit
	 * FK-cascade replacement shared by deleteRoom,
	 * RoomTaskService::deleteRoomTask, and FloorService::deleteFloor
	 * (CS-PLAN-037).
	 *
	 * Dependency order: room_task_checklist_progress → task_assignees →
	 * completed_tasks (D2: the completion history goes with its task;
	 * medals/streaks/coins are denormalized snapshots and stay valid).
	 * Lives here (not RoomTaskService) because RoomTaskService already
	 * depends on RoomService — the dependency arrow can only point this
	 * way. Callers wrap it in their own transaction.
	 *
	 * @param int[] $roomTaskIds The room task IDs (empty input is a no-op)
	 */
	public function deleteRoomTaskDependents(array $roomTaskIds): void {
		$this->checklistProgressMapper->deleteByRoomTaskIds($roomTaskIds);
		$this->taskAssigneeMapper->deleteByRoomTasks($roomTaskIds);
		$this->completedTaskMapper->deleteByRoomTaskIds($roomTaskIds);
	}

	/**
	 * Update room cleanliness (manual endpoint, RoomController#updateCleanliness).
	 *
	 * Deliberately NOT used by the completion path anymore: completion
	 * writes cleanliness on the locked room row inside the CompletionService
	 * aggregate (CS-PLAN-064 WP5). This unlocked read-modify-write remains
	 * only for the manual endpoint (CR-SVC-63 tracks its race surface).
	 *
	 * House-scoping is validated service-side (RF-058-06): the caller
	 * supplies the acting house id and the room→floor→house chain is
	 * re-checked here, so a direct service caller cannot mutate a foreign
	 * house's room.
	 *
	 * @param int $roomId The room ID
	 * @param int $houseId The acting user's house ID
	 * @param int $cleanlinessChange The change in cleanliness (-100 to 100)
	 */
	public function updateRoomCleanliness(int $roomId, int $houseId, int $cleanlinessChange): Room {
		$this->validateRoomOwnership($roomId, $houseId);
		$room = $this->roomMapper->find($roomId);
		$room->updateCleanliness($cleanlinessChange);
		return $this->roomMapper->update($room);
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
