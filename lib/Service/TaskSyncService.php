<?php

declare(strict_types=1);

namespace OCA\ChoreWars\Service;

use OCA\ChoreWars\Db\CompletedTask;
use OCA\ChoreWars\Db\CompletedTaskMapper;
use OCA\ChoreWars\Db\RoomTask;
use OCA\ChoreWars\Db\RoomTaskMapper;
use OCA\ChoreWars\Db\Task;
use OCA\ChoreWars\Db\TaskMapper;
use OCA\ChoreWars\Db\Room;
use OCA\ChoreWars\Db\RoomMapper;
use OCA\ChoreWars\Db\HouseUserMapper;
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
	 * @param int $userId The house user ID completing the task
	 * @param int|null $nextcloudTaskId Optional linked Nextcloud task ID
	 * @return array Completion result with sync status
	 */
	public function completeTask(int $roomTaskId, int $userId, ?int $nextcloudTaskId = null): array {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);
		$task = $this->taskMapper->find($roomTask->getTaskId());
		$user = $this->houseUserMapper->find($userId);

		$completedAt = new \DateTime();

		// Mark room task as completed
		$roomTask->setIsActive(false);
		$roomTask->setLastCompleted($completedAt);
		$this->roomTaskMapper->update($roomTask);

		// Create completion record
		$completedTask = new CompletedTask();
		$completedTask->setRoomTaskId($roomTaskId);
		$completedTask->setUserId($userId);
		$completedTask->setCompletedAt($completedAt);
		$completedTask->setSparksEarned(0);
		$completedTask->setWasCombo(false);
		$completedTask->setSyncedToNextcloud(false);
		if ($nextcloudTaskId) {
			$completedTask->setNextcloudTaskId($nextcloudTaskId);
		}
		$this->completedTaskMapper->insert($completedTask);

		// Calculate next due date
		$nextDue = $this->calculateNextDueDate($roomTask);
		$roomTask->setNextDue($nextDue);
		$roomTask->setIsActive(true);
		$this->roomTaskMapper->update($roomTask);

		// Log event for conflict resolution
		$this->logSyncEvent($roomTaskId, 'completed', $userId, $nextcloudTaskId);

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
			$localTime = new \DateTime($localCompletion->getCompletedAt());
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
		$nextDueDate = new \DateTime($nextDue);

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
	 * Calculate next due date based on frequency
	 *
	 * @param RoomTask $roomTask The room task
	 * @return \DateTime|null Next due date or null
	 */
	private function calculateNextDueDate(RoomTask $roomTask): ?\DateTime {
		$task = $this->taskMapper->find($roomTask->getTaskId());
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
	 * @param int|null $userId The user ID
	 * @param int|null $nextcloudTaskId The Nextcloud task ID
	 */
	private function logSyncEvent(int $roomTaskId, string $eventType, ?int $userId = null, ?int $nextcloudTaskId = null): void {
		// Event logging would be implemented here
		// For now, this is a placeholder for future audit logging
	}
}
