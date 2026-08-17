<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskMapper;

/**
 * Service for synchronizing with Nextcloud Tasks (CalDAV)
 */
class TaskSyncService {
	private RoomTaskMapper $roomTaskMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private FloorMapper $floorMapper;
	private HouseUserMapper $houseUserMapper;
	private RoomService $roomService;

	public function __construct(
		RoomTaskMapper $roomTaskMapper,
		CompletedTaskMapper $completedTaskMapper,
		TaskMapper $taskMapper,
		RoomMapper $roomMapper,
		FloorMapper $floorMapper,
		HouseUserMapper $houseUserMapper,
		RoomService $roomService,
	) {
		$this->roomTaskMapper = $roomTaskMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->taskMapper = $taskMapper;
		$this->roomMapper = $roomMapper;
		$this->floorMapper = $floorMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->roomService = $roomService;
	}

	/**
	 * Mark a room task as completed and sync to Nextcloud Tasks.
	 *
	 * CR-SVC-07: routed through the canonical {@see RoomService::completeRoomTask}
	 * so rewards, the house_user row lock, the daily duplicate guard, the
	 * cleanliness update, and recurrence (RoomTask::markCompleted) all apply
	 * uniformly — replacing the reward-less, lock-less, divergent-recurrence
	 * parallel path. Only the sync-specific concern (linking the Nextcloud
	 * task id) is layered on top.
	 *
	 * CR-SVC-46: the Nextcloud task id is threaded into the canonical path so
	 * the completion row itself carries it — {@see CompletedTaskMapper::findPendingSyncs()}
	 * filters on completed_tasks.nextcloud_task_id, so without the stamp the
	 * ChoreStrike → Nextcloud sync-out never saw these completions.
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string $nextcloudUserId The Nextcloud user ID completing the task
	 * @param int|null $nextcloudTaskId Optional linked Nextcloud task ID
	 * @return array Completion result with sync status
	 */
	public function completeTask(int $roomTaskId, string $nextcloudUserId, ?int $nextcloudTaskId = null): array {
		$completion = $this->roomService->completeRoomTask($roomTaskId, $nextcloudUserId, $nextcloudTaskId);

		// Link the Nextcloud task id (if provided) for later sync.
		if ($nextcloudTaskId !== null) {
			$this->linkTask($roomTaskId, $nextcloudTaskId);
		}

		$roomTask = $completion['roomTask'];
		$task = $completion['task'];
		$nextDue = $roomTask->getNextDue();

		return [
			'roomTaskId' => $roomTaskId,
			'taskName' => $task->getName(),
			'completedAt' => $roomTask->getLastCompleted()?->format('c'),
			'nextcloudTaskId' => $nextcloudTaskId,
			'nextDue' => $nextDue ? $nextDue->format('Y-m-d') : null,
			'synced' => false,
			'rewards' => $completion['rewards'],
		];
	}

	/**
	 * Complete a room task only if it belongs to the given house.
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string $nextcloudUserId The Nextcloud user ID completing the task
	 * @param int $houseId The authorized house ID
	 * @param int|null $nextcloudTaskId Optional linked Nextcloud task ID
	 * @return array Completion result with sync status
	 */
	public function completeTaskForHouse(int $roomTaskId, string $nextcloudUserId, int $houseId, ?int $nextcloudTaskId = null): array {
		$this->requireRoomTaskInHouse($roomTaskId, $houseId);

		return $this->completeTask($roomTaskId, $nextcloudUserId, $nextcloudTaskId);
	}

	/**
	 * Sync completion from Nextcloud Task back to ChoreStrike
	 *
	 * Recurrence advances via the canonical RoomTask::markCompleted and the
	 * room-cleanliness bump (energyCost * 10) mirrors the canonical path;
	 * only rewards are skipped by design (the external completer is
	 * unknown). The `$completedAt` timestamp itself is validated at the
	 * controller boundary (CR-SVC-48).
	 *
	 * @param int $nextcloudTaskId The Nextcloud task ID
	 * @param string $completedAt ISO date string of completion
	 * @param int|null $roomTaskId Optional linked room task ID
	 * @return array|null Sync result or null if not found
	 */
	public function syncCompletionFromNextcloud(int $nextcloudTaskId, string $completedAt, ?int $roomTaskId = null): ?array {
		// Find linked room task if not provided
		if ($roomTaskId === null) {
			$roomTask = $this->roomTaskMapper->findByNextcloudTaskId($nextcloudTaskId);
			if (!$roomTask) {
				return null;
			}
			$roomTaskId = $roomTask->getId();
		} else {
			$roomTask = $this->roomTaskMapper->find($roomTaskId);
		}

		$completedDate = new \DateTime($completedAt);

		// Check for conflicts (local completion vs sync)
		$localCompletion = $this->completedTaskMapper->findLatestByRoomTask($roomTaskId);
		if ($localCompletion) {
			$localTime = $localCompletion->getCompletedAt();
			if ($localTime > $completedDate) {
				// Local completion is newer, use local
				return null;
			}
		}

		// Apply completion from Nextcloud. Recurrence uses the canonical
		// RoomTask::markCompleted (CR-SVC-07) instead of the deleted divergent
		// helper, so a Nextcloud-originated completion advances identically to
		// a normal one. Room cleanliness is bumped with the same formula as
		// the canonical path (CR-SVC-48: energyCost * 10 — a Nextcloud
		// completion is a real completion). No reward is awarded here by
		// design: the external completer is unknown (rewards flow through the
		// canonical path when a known user completes via completeTask).
		$task = $this->taskMapper->find($roomTask->getTaskId());
		$roomTask->markCompleted(
			$completedDate,
			$task->getFrequencyType(),
			$task->getFrequencyValue(),
			$task->getRecurrenceMode(),
			$task->getSchedulingMode(),
			$task->getActiveUntil(),
			$task->getFixedScheduleTime(),
			$task->getFixedScheduleDays(),
			$task->getFlexDays(),
			[],
			[]
		);
		$this->roomTaskMapper->update($roomTask);
		$this->roomService->updateRoomCleanliness(
			$roomTask->getRoomId(),
			$task->getEnergyCost() * 10
		);

		// Create or update completion record
		if (!$localCompletion) {
			$completedTask = new CompletedTask();
			$completedTask->setRoomTaskId($roomTaskId);
			$completedTask->setUserId(null); // Unknown from Nextcloud
			$completedTask->setCompletedAt($completedDate);
			$completedTask->setCoinsEarned(0);
			$completedTask->setWasCombo(false);
			$completedTask->setNextcloudTaskId($nextcloudTaskId);
			$completedTask->setSyncedToNextcloud(true);
			$this->completedTaskMapper->insert($completedTask);
		}

		$nextDue = $roomTask->getNextDue();

		return [
			'roomTaskId' => $roomTaskId,
			'completedAt' => $completedDate->format('c'),
			'nextDue' => $nextDue ? $nextDue->format('Y-m-d') : null,
		];
	}

	/**
	 * Sync completion from Nextcloud and enforce house ownership for the linked task.
	 *
	 * @param int $nextcloudTaskId The Nextcloud task ID
	 * @param string $completedAt ISO date string of completion
	 * @param int $houseId The authorized house ID
	 * @param int|null $roomTaskId Optional linked room task ID
	 * @return array|null Sync result or null if not found
	 */
	public function syncCompletionFromNextcloudForHouse(int $nextcloudTaskId, string $completedAt, int $houseId, ?int $roomTaskId = null): ?array {
		if ($roomTaskId !== null) {
			$this->requireRoomTaskInHouse($roomTaskId, $houseId);
			return $this->syncCompletionFromNextcloud($nextcloudTaskId, $completedAt, $roomTaskId);
		}

		$roomTask = $this->roomTaskMapper->findByNextcloudTaskId($nextcloudTaskId);
		if ($roomTask === null) {
			return null;
		}

		$this->requireRoomTaskInHouse($roomTask->getId(), $houseId);

		return $this->syncCompletionFromNextcloud($nextcloudTaskId, $completedAt, $roomTask->getId());
	}

	/**
	 * Map reminder settings to Nextcloud-compatible format
	 *
	 * @param RoomTask $roomTask The room task
	 * @return array Reminder configuration
	 */
	public function mapRemindersToNextcloud(RoomTask $roomTask): array {
		$task = $this->taskMapper->find($roomTask->getTaskId());
		$nextDue = $roomTask->getNextDue();

		if (!$nextDue) {
			return [];
		}

		$reminderType = $task->getReminderType() ?? 'none';
		$nextDueDate = clone $nextDue;

		return match ($reminderType) {
			'due' => [
				'type' => 'absolute',
				'value' => $nextDueDate->format('c'),
			],
			'before' => [
				'type' => 'relative',
				'value' => $task->getReminderMinutesBefore() ?? 60,
			],
			'optional' => [
				'type' => 'absolute',
				'value' => $nextDueDate->modify('+1 day')->format('c'),
			],
			'none' => [],
			default => [],
		};
	}

	/**
	 * Get pending completions that need to be synced to Nextcloud
	 *
	 * @param int $limit Maximum number of records to return
	 * @return array Pending completions
	 */
	public function getPendingSyncs(int $limit = 50): array {
		return $this->completedTaskMapper->findPendingSyncs($limit);
	}

	/**
	 * Get pending completions restricted to one house.
	 *
	 * @param int $houseId The authorized house ID
	 * @param int $limit Maximum number of records to return
	 * @return array Pending completions for the house
	 */
	public function getPendingSyncsForHouse(int $houseId, int $limit = 50): array {
		$pending = $this->completedTaskMapper->findPendingSyncs($limit * 5);
		$filtered = [];

		foreach ($pending as $completedTask) {
			if ($this->roomTaskBelongsToHouse($completedTask->getRoomTaskId(), $houseId)) {
				$filtered[] = $completedTask;
			}

			if (count($filtered) >= $limit) {
				break;
			}
		}

		return $filtered;
	}

	/**
	 * Mark completion as synced to Nextcloud
	 *
	 * @param int $completedTaskId The completed task ID
	 */
	public function markAsSynced(int $completedTaskId): void {
		$completedTask = $this->completedTaskMapper->find($completedTaskId);
		$completedTask->setSyncedToNextcloud(true);
		$this->completedTaskMapper->update($completedTask);
	}

	/**
	 * Mark completion as synced, but only if the completion belongs to the given house.
	 *
	 * @param int $completedTaskId The completed task ID
	 * @param int $houseId The authorized house ID
	 */
	public function markAsSyncedForHouse(int $completedTaskId, int $houseId): void {
		$completedTask = $this->completedTaskMapper->find($completedTaskId);
		$this->requireRoomTaskInHouse($completedTask->getRoomTaskId(), $houseId);

		$this->markAsSynced($completedTaskId);
	}

	/**
	 * Link a room task to a Nextcloud task
	 *
	 * @param int $roomTaskId The room task ID
	 * @param int $nextcloudTaskId The Nextcloud task ID
	 */
	public function linkTask(int $roomTaskId, int $nextcloudTaskId): void {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);
		$roomTask->setNextcloudTaskId($nextcloudTaskId);
		$this->roomTaskMapper->update($roomTask);
	}

	/**
	 * Link a room task to a Nextcloud task for a specific house.
	 *
	 * @param int $roomTaskId The room task ID
	 * @param int $nextcloudTaskId The Nextcloud task ID
	 * @param int $houseId The authorized house ID
	 */
	public function linkTaskForHouse(int $roomTaskId, int $nextcloudTaskId, int $houseId): void {
		$this->requireRoomTaskInHouse($roomTaskId, $houseId);

		$this->linkTask($roomTaskId, $nextcloudTaskId);
	}

	/**
	 * Get room task by ID.
	 *
	 * @param int $roomTaskId The room task ID
	 * @return RoomTask The room task
	 */
	public function getRoomTask(int $roomTaskId): RoomTask {
		return $this->roomTaskMapper->find($roomTaskId);
	}

	/**
	 * Get room task by ID and ensure it belongs to the specified house.
	 *
	 * @param int $roomTaskId The room task ID
	 * @param int $houseId The authorized house ID
	 * @return RoomTask The room task
	 */
	public function getRoomTaskForHouse(int $roomTaskId, int $houseId): RoomTask {
		$this->requireRoomTaskInHouse($roomTaskId, $houseId);

		return $this->roomTaskMapper->find($roomTaskId);
	}

	/**
	 * Check whether a room task belongs to the given house.
	 *
	 * Resolves the house via the floor chain (room → floor → house) — Room has
	 * no house_id column, so the previous `$room->getHouseId()` was a latent
	 * bug that threw on the real DB (CR-SVC-21 house-scoping inconsistency,
	 * exposed by the CR-SVC-07 integration test).
	 *
	 * @param int $roomTaskId The room task ID
	 * @param int $houseId The house ID
	 * @return bool True if the room task belongs to the house
	 */
	private function roomTaskBelongsToHouse(int $roomTaskId, int $houseId): bool {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);
		$room = $this->roomMapper->find($roomTask->getRoomId());
		$floor = $this->floorMapper->find($room->getFloorId());

		return $floor->getHouseId() === $houseId;
	}

	/**
	 * Require that a room task belongs to the given house.
	 *
	 * @param int $roomTaskId The room task ID
	 * @param int $houseId The house ID
	 */
	private function requireRoomTaskInHouse(int $roomTaskId, int $houseId): void {
		if (!$this->roomTaskBelongsToHouse($roomTaskId, $houseId)) {
			throw new \InvalidArgumentException('Room task does not belong to this house');
		}
	}
}
