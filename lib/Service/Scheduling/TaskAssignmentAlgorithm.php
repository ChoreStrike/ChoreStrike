<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service\Scheduling;

use OCA\ChoreStrike\Db\AvailabilitySettings;

/**
 * Handles task assignment algorithms and scoring
 */
class TaskAssignmentAlgorithm {

	private AvailabilityCalculator $availabilityCalculator;

	public function __construct() {
		$this->availabilityCalculator = new AvailabilityCalculator();
	}

	/**
	 * Find the best day and user for a task
	 *
	 * @param array $scoredTask Task with priority information
	 * @param AvailabilitySettings[] $userSettings User availability settings
	 * @param array $userDistributions User preference distributions [userId => distribution]
	 * @param array $dailyWorkload Current workload tracking [userId][day] => workload
	 * @return array|null Best assignment or null if no suitable assignment found
	 */
	public function findBestAssignment(array $scoredTask, array $userSettings, array $userDistributions, array $dailyWorkload): ?array {
		$energyCost = $scoredTask['energy_cost'];
		$bestScore = -1;
		$bestAssignment = null;
		$weekDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

		foreach ($userSettings as $setting) {
			$userId = $setting->getUserId();
			$distribution = $userDistributions[$userId] ?? [];

			foreach ($weekDays as $dayName) {
				if (!$this->isAssignmentValid($setting, $dayName, $energyCost, $dailyWorkload)) {
					continue;
				}

				$score = $this->calculateAssignmentScore(
					$scoredTask,
					$dayName,
					$userId,
					$distribution,
					$dailyWorkload
				);

				if ($score > $bestScore) {
					$bestScore = $score;
					$bestAssignment = [
						'day' => $dayName,
						'user_id' => $userId,
						'score' => $score,
					];
				}
			}
		}

		return $bestAssignment;
	}

	/**
	 * Check if an assignment is valid based on constraints
	 */
	private function isAssignmentValid(AvailabilitySettings $setting, string $dayName, int $energyCost, array $dailyWorkload): bool {
		$userId = $setting->getUserId();

		// Check if user is available
		if (!$this->availabilityCalculator->isUserAvailable($setting, $dayName)) {
			return false;
		}

		// Check task limit
		$currentTasks = $dailyWorkload[$userId][$dayName]['task_count'] ?? 0;
		if ($this->availabilityCalculator->hasReachedTaskLimit($setting, $dayName, $currentTasks)) {
			return false;
		}

		// Check energy budget
		$currentEnergy = $dailyWorkload[$userId][$dayName]['energy_total'] ?? 0;
		if (!$this->availabilityCalculator->hasEnergyBudget($setting, $dayName, $energyCost, $currentEnergy)) {
			return false;
		}

		return true;
	}

	/**
	 * Calculate score for task assignment
	 */
	private function calculateAssignmentScore(array $scoredTask, string $dayName, string $userId, array $distribution, array $dailyWorkload): float {
		$score = 0.0;
		$isOverdue = $scoredTask['is_overdue'];
		$daysUntilDue = $scoredTask['days_until_due'];

		// Base score from task priority
		$score += $scoredTask['priority_score'];

		// Distribution preference bonus
		if (isset($distribution[$dayName])) {
			$score += $distribution[$dayName] * 50;
		}

		// Overdue tasks should be scheduled ASAP
		if ($isOverdue) {
			$dayIndex = array_search($dayName, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']);
			$score += (7 - $dayIndex) * 10;
		}

		// Tasks due soon should be scheduled before due date
		if (!$isOverdue && $daysUntilDue <= 3) {
			$score += (4 - $daysUntilDue) * 5;
		}

		// Workload balancing - prefer users/days with fewer tasks
		$currentTasks = $dailyWorkload[$userId][$dayName]['task_count'] ?? 0;
		$score -= $currentTasks * 3;

		// Energy balancing - prefer days with lower energy usage
		$currentEnergy = $dailyWorkload[$userId][$dayName]['energy_total'] ?? 0;
		$score -= ($currentEnergy / 10);

		// Slight randomness to break ties (deterministic based on task ID)
		$roomTask = $scoredTask['room_task'];
		$score += ($roomTask->getId() % 10) * 0.1;

		return $score;
	}

	/**
	 * Distribute tasks across the week using the assignment algorithm
	 *
	 * @param array $scoredTasks Array of scored tasks
	 * @param AvailabilitySettings[] $userSettings User availability settings
	 * @param \DateTime $weekStart Start of the week
	 * @return array Generated schedule
	 */
	public function distributeTasks(array $scoredTasks, array $userSettings, \DateTime $weekStart): array {
		$schedule = [];
		$dailyWorkload = $this->initializeDailyWorkload($userSettings);
		$userDistributions = $this->getUserDistributions($userSettings);
		$weekDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

		foreach ($scoredTasks as $scoredTask) {
			$task = $scoredTask['task'];
			$roomTask = $scoredTask['room_task'];
			$energyCost = $scoredTask['energy_cost'];

			$bestAssignment = $this->findBestAssignment(
				$scoredTask,
				$userSettings,
				$userDistributions,
				$dailyWorkload
			);

			if ($bestAssignment) {
				$dayName = $bestAssignment['day'];
				$userId = $bestAssignment['user_id'];

				// Calculate scheduled date
				$scheduledDate = clone $weekStart;
				$dayIndex = array_search($dayName, $weekDays);
				$scheduledDate->modify("+{$dayIndex} days");

				$schedule[] = [
					'room_task_id' => $roomTask->getId(),
					'task_name' => $task->getName(),
					'scheduled_date' => $scheduledDate->format('Y-m-d'),
					'scheduled_day' => $dayName,
					'assigned_to' => $userId,
					'priority_score' => $scoredTask['priority_score'],
					'energy_cost' => $energyCost,
					'is_overdue' => $scoredTask['is_overdue'],
				];

				// Update workload tracking
				$dailyWorkload[$userId][$dayName]['task_count']++;
				$dailyWorkload[$userId][$dayName]['energy_total'] += $energyCost;
			}
		}

		return $schedule;
	}

	/**
	 * Initialize daily workload tracking for all users
	 */
	private function initializeDailyWorkload(array $userSettings): array {
		$dailyWorkload = [];
		$weekDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

		foreach ($userSettings as $setting) {
			$userId = $setting->getUserId();
			foreach ($weekDays as $day) {
				$dailyWorkload[$userId][$day] = [
					'task_count' => 0,
					'energy_total' => 0,
				];
			}
		}

		return $dailyWorkload;
	}

	/**
	 * Get default distribution for each user
	 */
	private function getUserDistributions(array $userSettings): array {
		$userDistributions = [];
		foreach ($userSettings as $setting) {
			$userDistributions[$setting->getUserId()] = $setting->getDefaultDistribution();
		}
		return $userDistributions;
	}

	/**
	 * Get assignment statistics
	 *
	 * @param array $schedule Generated schedule
	 * @return array Assignment statistics
	 */
	public function getAssignmentStats(array $schedule): array {
		$stats = [
			'total_assignments' => count($schedule),
			'by_day' => [],
			'by_user' => [],
			'overdue_assigned' => 0,
			'high_priority_assigned' => 0,
			'total_energy' => 0,
		];

		foreach ($schedule as $assignment) {
			$day = $assignment['scheduled_day'];
			$user = $assignment['assigned_to'];

			$stats['by_day'][$day] = ($stats['by_day'][$day] ?? 0) + 1;
			$stats['by_user'][$user] = ($stats['by_user'][$user] ?? 0) + 1;
			$stats['total_energy'] += $assignment['energy_cost'];

			if ($assignment['is_overdue']) {
				$stats['overdue_assigned']++;
			}

			if ($assignment['priority_score'] >= 50) {
				$stats['high_priority_assigned']++;
			}
		}

		return $stats;
	}
}
