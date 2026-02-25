<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCP\ITimeFactory;

/**
 * Service for synchronizing with Nextcloud Tasks (CalDAV)
 */
class TaskSyncService {

	private RoomTaskMapper $roomTaskMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private HouseUserMapper $houseUserMapper;
	private ITimeFactory $timeFactory;

	public function __construct(
		RoomTaskMapper $roomTaskMapper,
		CompletedTaskMapper $completedTaskMapper,
		TaskMapper $taskMapper,
		RoomMapper $roomMapper,
		HouseUserMapper $houseUserMapper,
		ITimeFactory $timeFactory
	) {
		$this->roomTaskMapper = $roomTaskMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->taskMapper = $taskMapper;
		$this->roomMapper = $roomMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->timeFactory = $timeFactory;
	}

	/**
	 * Mark a room task as completed and sync to Nextcloud Tasks
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string $nextcloudUserId The Nextcloud user ID completing the task
	 * @param int|null $nextcloudTaskId Optional linked Nextcloud task ID
	 * @return array Completion result with sync status
	 */
	public function completeTask(int $roomTaskId, string $nextcloudUserId, ?int $nextcloudTaskId = null): array {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);
		$task = $this->taskMapper->find($roomTask->getTaskId());
		$room = $this->roomMapper->find($roomTask->getRoomId());
		
		// Get house user ID from Nextcloud user ID
		$houseUser = $this->houseUserMapper->findByNextcloudUserId($room->getHouseId(), $nextcloudUserId);
		$houseUserId = $houseUser->getId();

		$completedAt = new \DateTime();

		// Mark room task as completed
		$roomTask->setIsActive(false);
		$roomTask->setLastCompleted($completedAt);
		$this->roomTaskMapper->update($roomTask);

		// Create completion record
		$completedTask = new CompletedTask();
		$completedTask->setRoomTaskId($roomTaskId);
		$completedTask->setUserId($houseUserId);
		$completedTask->setCompletedAt($completedAt);
		$completedTask->setSparksEarned(0);
		$completedTask->setWasCombo(false);
		$completedTask->setSyncedToNextcloud(false);
		if ($nextcloudTaskId) {
			$completedTask->setNextcloudTaskId($nextcloudTaskId);
		}
		$this->completedTaskMapper->insert($completedTask);

		// Calculate next due date
		$nextDue = $this->calculateNextDueDateFromTask($task);
		$roomTask->setNextDue($nextDue);
		$roomTask->setIsActive(true);
		$this->roomTaskMapper->update($roomTask);

		// Log event for conflict resolution
		$this->logSyncEvent($roomTaskId, 'completed', $houseUserId, $nextcloudTaskId);

		return [
			'roomTaskId' => $roomTaskId,
			'taskName' => $task->getName(),
			'completedAt' => $completedAt->format('c'),
			'nextcloudTaskId' => $nextcloudTaskId,
			'nextDue' => $nextDue ? $nextDue->format('Y-m-d') : null,
			'synced' => false,
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
				$this->logSyncEvent($roomTaskId, 'conflict_local_wins', null, $nextcloudTaskId);
				return null;
			}
		}

		// Apply completion from Nextcloud
		$roomTask->setIsActive(false);
		$roomTask->setLastCompleted($completedDate);
		$this->roomTaskMapper->update($roomTask);

		// Create or update completion record
		if (!$localCompletion) {
			$completedTask = new CompletedTask();
			$completedTask->setRoomTaskId($roomTaskId);
			$completedTask->setUserId(null); // Unknown from Nextcloud
			$completedTask->setCompletedAt($completedDate);
			$completedTask->setSparksEarned(0);
			$completedTask->setWasCombo(false);
			$completedTask->setNextcloudTaskId($nextcloudTaskId);
			$completedTask->setSyncedToNextcloud(true);
			$this->completedTaskMapper->insert($completedTask);
		}

		// Calculate and set next due
		$nextDue = $this->calculateNextDueDate($roomTask);
		$roomTask->setNextDue($nextDue);
		$roomTask->setIsActive(true);
		$this->roomTaskMapper->update($roomTask);

		$this->logSyncEvent($roomTaskId, 'synced_from_nextcloud', null, $nextcloudTaskId);

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
	 * Calculate next due date based on frequency
	 *
	 * @param RoomTask $roomTask The room task
	 * @return \DateTime|null Next due date or null
	 */
	private function calculateNextDueDate(RoomTask $roomTask): ?\DateTime {
		$task = $this->taskMapper->find($roomTask->getTaskId());

		return $this->calculateNextDueDateFromTask($task);
	}

	/**
	 * Calculate next due date from task frequency settings.
	 *
	 * @param Task $task The task
	 * @return \DateTime|null Next due date or null
	 */
	private function calculateNextDueDateFromTask(Task $task): ?\DateTime {
		$frequencyType = $task->getFrequencyType();
		$frequencyValue = $task->getFrequencyValue() ?? 1;

		if (!$frequencyType || $frequencyType === 'once') {
			return null;
		}

		$nextDue = new \DateTime();

		return match ($frequencyType) {
			'daily' => $nextDue->modify("+{$frequencyValue} days"),
			'weekly' => $nextDue->modify("+{$frequencyValue} weeks"),
			'monthly' => $nextDue->modify("+{$frequencyValue} months"),
			default => $nextDue->modify('+1 week'),
		};
	}

	/**
	 * Log sync event for conflict resolution
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string $eventType Type of sync event
	 * @param int|null $houseUserId The house user ID
	 * @param int|null $nextcloudTaskId The Nextcloud task ID
	 */
	private function logSyncEvent(int $roomTaskId, string $eventType, ?int $houseUserId = null, ?int $nextcloudTaskId = null): void {
		// Event logging would be implemented here
		// For now, this is a placeholder for future audit logging
	}

	/**
	 * Check whether a room task belongs to the given house.
	 *
	 * @param int $roomTaskId The room task ID
	 * @param int $houseId The house ID
	 * @return bool True if the room task belongs to the house
	 */
	private function roomTaskBelongsToHouse(int $roomTaskId, int $houseId): bool {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);
		$room = $this->roomMapper->find($roomTask->getRoomId());

		return $room->getHouseId() === $houseId;
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
