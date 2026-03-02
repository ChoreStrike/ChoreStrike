<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service\Scheduling;

use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;

/**
 * Calculates priority scores for tasks based on various factors
 */
class TaskPriorityScorer {

	public function __construct(
		private TaskMapper $taskMapper
	) {
	}

	/**
	 * Calculate priority scores for an array of room tasks
	 *
	 * @param RoomTask[] $roomTasks
	 * @return array Array of scored tasks with priority information
	 */
	public function calculateScores(array $roomTasks): array {
		$scoredTasks = [];
		$now = new \DateTime();

		foreach ($roomTasks as $roomTask) {
			$task = $this->taskMapper->find($roomTask->getTaskId());
			$nextDue = $roomTask->getNextDue();

			if (!$nextDue) {
				continue;
			}

			// Calculate days until due
			$daysUntilDue = (int)$now->diff($nextDue)->days;
			$isOverdue = $nextDue < $now;
			$overdueDays = $isOverdue ? (int)$nextDue->diff($now)->days : 0;

			// Priority score calculation
			$priorityScore = $this->calculatePriorityScore(
				$task->getPriority(),
				$daysUntilDue,
				$overdueDays,
				$task->getEnergyCost()
			);

			$scoredTasks[] = [
				'room_task' => $roomTask,
				'task' => $task,
				'priority_score' => $priorityScore,
				'days_until_due' => $daysUntilDue,
				'is_overdue' => $isOverdue,
				'energy_cost' => $task->getEnergyCost(),
			];
		}

		return $scoredTasks;
	}

	/**
	 * Calculate priority score for a single task
	 */
	public function calculatePriorityScore(int $priority, int $daysUntilDue, int $overdueDays, int $energyCost): int {
		return (
			($priority * 10) +
			(max(0, 7 - $daysUntilDue) * 5) +
			($overdueDays * 20) +
			($energyCost * 2)
		);
	}

	/**
	 * Sort scored tasks by priority (highest first)
	 *
	 * @param array $scoredTasks
	 * @return array Sorted tasks
	 */
	public function sortByPriority(array $scoredTasks): array {
		usort($scoredTasks, fn($a, $b) => $b['priority_score'] <=> $a['priority_score']);
		return $scoredTasks;
	}

	/**
	 * Get overdue tasks from scored tasks
	 *
	 * @param array $scoredTasks
	 * @return array Overdue tasks
	 */
	public function getOverdueTasks(array $scoredTasks): array {
		return array_filter($scoredTasks, fn($task) => $task['is_overdue']);
	}

	/**
	 * Get tasks due soon (within 3 days)
	 *
	 * @param array $scoredTasks
	 * @return array Tasks due soon
	 */
	public function getTasksDueSoon(array $scoredTasks): array {
		return array_filter($scoredTasks, fn($task) => !$task['is_overdue'] && $task['days_until_due'] <= 3);
	}

	/**
	 * Get high priority tasks (priority score above threshold)
	 *
	 * @param array $scoredTasks
	 * @param int $threshold
	 * @return array High priority tasks
	 */
	public function getHighPriorityTasks(array $scoredTasks, int $threshold = 50): array {
		return array_filter($scoredTasks, fn($task) => $task['priority_score'] >= $threshold);
	}
}
