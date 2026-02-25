<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Service for room task-related business logic
 */
class RoomTaskService {

	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private RoomService $roomService;

	public function __construct(
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		RoomMapper $roomMapper,
		RoomService $roomService
	) {
		$this->roomTaskMapper = $roomTaskMapper;
		$this->taskMapper = $taskMapper;
		$this->roomMapper = $roomMapper;
		$this->roomService = $roomService;
	}

	/**
	 * Get room task by ID
	 *
	 * @param int $roomTaskId The room task ID
	 * @throws DoesNotExistException if not found
	 */
	public function getRoomTask(int $roomTaskId): RoomTask {
		return $this->roomTaskMapper->find($roomTaskId);
	}

	/**
	 * Get all room tasks for a room
	 *
	 * @param int $roomId The room ID
	 * @param bool $activeOnly Whether to return only active tasks
	 * @return RoomTask[]
	 */
	public function getRoomTasks(int $roomId, bool $activeOnly = true): array {
		return $this->roomTaskMapper->findByRoomId($roomId, $activeOnly);
	}

	/**
	 * Get all room tasks for a user
	 *
	 * @param string $userId The user ID
	 * @param bool $activeOnly Whether to return only active tasks
	 * @return RoomTask[]
	 */
	public function getUserTasks(string $userId, bool $activeOnly = true): array {
		return $this->roomTaskMapper->findByUserId($userId, $activeOnly);
	}

	/**
	 * Get overdue tasks
	 *
	 * @param int|null $roomId Optional room ID to filter by
	 * @return RoomTask[]
	 */
	public function getOverdueTasks(?int $roomId = null): array {
		return $this->roomTaskMapper->findOverdue($roomId);
	}

	/**
	 * Get tasks due today
	 *
	 * @param int|null $roomId Optional room ID to filter by
	 * @return RoomTask[]
	 */
	public function getDueTodayTasks(?int $roomId = null): array {
		return $this->roomTaskMapper->findDueToday($roomId);
	}

	/**
	 * Get tasks due within the next N days
	 *
	 * @param int $days Number of days
	 * @param int|null $roomId Optional room ID to filter by
	 * @return RoomTask[]
	 */
	public function getDueWithinDaysTasks(int $days, ?int $roomId = null): array {
		return $this->roomTaskMapper->findDueWithinDays($days, $roomId);
	}

	/**
	 * Create a new room task
	 *
	 * @param int $roomId The room ID
	 * @param int $taskId The task ID
	 * @param \DateTime|null $nextDue Optional next due date (defaults to tomorrow)
	 * @param int|null $scopeId Optional scope ID
	 * @param string|null $assignedToUserId Optional assigned user ID
	 */
	public function createRoomTask(
		int $roomId,
		int $taskId,
		?\DateTime $nextDue = null,
		?int $scopeId = null,
		?string $assignedToUserId = null
	): RoomTask {
		// Verify room exists
		$this->roomMapper->find($roomId);
		
		// Verify task exists
		$this->taskMapper->find($taskId);

		// Check if room task already exists
		if ($this->roomTaskMapper->exists($roomId, $taskId)) {
			throw new \InvalidArgumentException("Task {$taskId} already exists in room {$roomId}");
		}

		// Default to tomorrow if no due date provided
		if ($nextDue === null) {
			$nextDue = new \DateTime();
			$nextDue->add(new \DateInterval('P1D'));
		}

		return $this->roomTaskMapper->create($roomId, $taskId, $nextDue, $assignedToUserId, $scopeId);
	}

	/**
	 * Get room tasks for a scope
	 *
	 * @param int $scopeId The scope ID
	 * @param bool $activeOnly Whether to return only active tasks
	 * @return RoomTask[]
	 */
	public function getScopeTasks(int $scopeId, bool $activeOnly = true): array {
		return $this->roomTaskMapper->findByScopeId($scopeId, $activeOnly);
	}

	/**
	 * Assign room task to user
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string $userId The user ID
	 */
	public function assignTask(int $roomTaskId, string $userId): RoomTask {
		$this->roomTaskMapper->find($roomTaskId);
		$this->roomTaskMapper->assignToUser($roomTaskId, $userId);
		
		// Return updated room task
		return $this->roomTaskMapper->find($roomTaskId);
	}

	/**
	 * Unassign room task from user
	 *
	 * @param int $roomTaskId The room task ID
	 */
	public function unassignTask(int $roomTaskId): RoomTask {
		$this->roomTaskMapper->find($roomTaskId);
		$this->roomTaskMapper->assignToUser($roomTaskId, '');
		
		// Return updated room task
		return $this->roomTaskMapper->find($roomTaskId);
	}

	/**
	 * Deactivate room task
	 *
	 * @param int $roomTaskId The room task ID
	 */
	public function deactivateTask(int $roomTaskId): RoomTask {
		$this->roomTaskMapper->find($roomTaskId);
		$this->roomTaskMapper->deactivate($roomTaskId);
		
		// Return updated room task
		return $this->roomTaskMapper->find($roomTaskId);
	}

	/**
	 * Reactivate room task
	 *
	 * @param int $roomTaskId The room task ID
	 */
	public function reactivateTask(int $roomTaskId): RoomTask {
		$this->roomTaskMapper->find($roomTaskId);
		$this->roomTaskMapper->reactivate($roomTaskId);
		
		// Return updated room task
		return $this->roomTaskMapper->find($roomTaskId);
	}

	/**
	 * Update room task due date
	 *
	 * @param int $roomTaskId The room task ID
	 * @param \DateTime $nextDue The new due date
	 */
	public function updateDueDate(int $roomTaskId, \DateTime $nextDue): RoomTask {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);
		$this->roomTaskMapper->updateNextDue($roomTaskId, $nextDue);
		
		// Return updated room task
		return $this->roomTaskMapper->find($roomTaskId);
	}

	/**
	 * Complete room task
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string $userId The user who completed the task
	 */
	public function completeTask(int $roomTaskId, string $userId): array {
		return $this->roomService->completeRoomTask($roomTaskId, $userId);
	}

	/**
	 * Delete room task
	 *
	 * @param int $roomTaskId The room task ID
	 */
	public function deleteRoomTask(int $roomTaskId): void {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);
		$this->roomTaskMapper->delete($roomTask);
	}

	/**
	 * Get room task with full details
	 *
	 * @param int $roomTaskId The room task ID
	 */
	public function getRoomTaskWithDetails(int $roomTaskId): array {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);
		$task = $this->taskMapper->find($roomTask->getTaskId());
		$room = $this->roomMapper->find($roomTask->getRoomId());

		return [
			'roomTask' => $roomTask,
			'task' => $task,
			'room' => $room,
			'status' => $roomTask->getStatus(),
			'isOverdue' => $roomTask->isOverdue(),
			'isDueToday' => $roomTask->isDueToday(),
			'daysUntilDue' => $roomTask->getDaysUntilDue()
		];
	}

	/**
	 * Get task statistics for a room
	 *
	 * @param int $roomId The room ID
	 */
	public function getRoomTaskStats(int $roomId): array {
		$allTasks = $this->roomTaskMapper->findByRoomId($roomId, false);
		$activeTasks = array_filter($allTasks, fn($task) => $task->getIsActive());
		$overdueTasks = array_filter($activeTasks, fn($task) => $task->isOverdue());
		$dueTodayTasks = array_filter($activeTasks, fn($task) => $task->isDueToday());
		$dueSoonTasks = array_filter($activeTasks, fn($task) => $task->isDueWithinDays(3));

		return [
			'totalTasks' => count($allTasks),
			'activeTasks' => count($activeTasks),
			'overdueTasks' => count($overdueTasks),
			'dueTodayTasks' => count($dueTodayTasks),
			'dueSoonTasks' => count($dueSoonTasks),
			'completionRate' => count($allTasks) > 0 ? round((count($allTasks) - count($activeTasks)) / count($allTasks) * 100, 2) : 100
		];
	}

	/**
	 * Get task statistics for a user
	 *
	 * @param string $userId The user ID
	 */
	public function getUserTaskStats(string $userId): array {
		$allTasks = $this->roomTaskMapper->findByUserId($userId, false);
		$activeTasks = array_filter($allTasks, fn($task) => $task->getIsActive());
		$overdueTasks = array_filter($activeTasks, fn($task) => $task->isOverdue());
		$dueTodayTasks = array_filter($activeTasks, fn($task) => $task->isDueToday());

		return [
			'totalTasks' => count($allTasks),
			'activeTasks' => count($activeTasks),
			'overdueTasks' => count($overdueTasks),
			'dueTodayTasks' => count($dueTodayTasks),
			'completionRate' => count($allTasks) > 0 ? round((count($allTasks) - count($activeTasks)) / count($allTasks) * 100, 2) : 100
		];
	}

	/**
	 * Bulk assign tasks to user
	 *
	 * @param array $roomTaskIds Array of room task IDs
	 * @param string $userId The user ID
	 */
	public function bulkAssignTasks(array $roomTaskIds, string $userId): array {
		$assignedTasks = [];
		
		foreach ($roomTaskIds as $roomTaskId) {
			try {
				$assignedTasks[] = $this->assignTask($roomTaskId, $userId);
			} catch (\Exception $e) {
				// Log error but continue with other tasks
				// In a real implementation, you'd want proper logging
				continue;
			}
		}

		return $assignedTasks;
	}

	/**
	 * Get upcoming tasks for user (next 7 days)
	 *
	 * @param string $userId The user ID
	 */
	public function getUpcomingTasksForUser(string $userId): array {
		$allTasks = $this->getUserTasks($userId);
		$upcomingTasks = [];

		foreach ($allTasks as $task) {
			if ($task->isDueWithinDays(7)) {
				$upcomingTasks[] = $task;
			}
		}

		// Sort by due date
		usort($upcomingTasks, function($a, $b) {
			return $a->getNextDue() <=> $b->getNextDue();
		});

		return $upcomingTasks;
	}
}
