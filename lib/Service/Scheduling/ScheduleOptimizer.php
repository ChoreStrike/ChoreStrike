<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service\Scheduling;

use OCA\ChoreStrike\Db\AvailabilitySettings;

/**
 * Optimizes existing schedules to improve balance and efficiency
 */
class ScheduleOptimizer {

	private AvailabilityCalculator $availabilityCalculator;

	public function __construct() {
		$this->availabilityCalculator = new AvailabilityCalculator();
	}

	/**
	 * Optimize an existing schedule
	 *
	 * @param array $schedule Current schedule
	 * @param AvailabilitySettings[] $userSettings User availability settings
	 * @return array Optimized schedule
	 */
	public function optimizeSchedule(array $schedule, array $userSettings): array {
		if (empty($schedule)) {
			return $schedule;
		}

		// Calculate current metrics
		$currentMetrics = $this->calculateScheduleMetrics($schedule);
		
		// Apply optimization strategies
		$optimized = $this->minimizeDailyVariance($schedule, $userSettings);
		$optimized = $this->balanceUserWorkloads($optimized, $userSettings);
		$optimized = $this->groupRelatedTasks($optimized);

		// Calculate optimized metrics
		$optimizedMetrics = $this->calculateScheduleMetrics($optimized);

		// Return the better schedule
		$improvementScore = $this->calculateImprovementScore($currentMetrics, $optimizedMetrics);
		
		return [
			'schedule' => $improvementScore > 0 ? $optimized : $schedule,
			'metrics' => [
				'before' => $currentMetrics,
				'after' => $optimizedMetrics,
				'improvement_score' => $improvementScore,
			],
			'changes_made' => $improvementScore > 0 ? $this->countChanges($schedule, $optimized) : 0,
		];
	}

	/**
	 * Minimize daily variance in task distribution
	 */
	private function minimizeDailyVariance(array $schedule, array $userSettings): array {
		$weekDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
		
		// Calculate current daily task counts
		$dailyCounts = [];
		foreach ($weekDays as $day) {
			$dailyCounts[$day] = 0;
		}
		
		foreach ($schedule as $task) {
			$day = $task['scheduled_day'];
			$dailyCounts[$day]++;
		}

		// Calculate ideal distribution (total tasks / 7 days)
		$totalTasks = count($schedule);
		$idealPerDay = max(1, round($totalTasks / 7));
		
		$optimized = $schedule;
		
		// Try to balance overloaded days with underloaded days
		foreach ($weekDays as $overloadedDay) {
			if ($dailyCounts[$overloadedDay] > $idealPerDay + 1) {
				$excess = $dailyCounts[$overloadedDay] - $idealPerDay;
				
				// Find underloaded days
				foreach ($weekDays as $underloadedDay) {
					if ($dailyCounts[$underloadedDay] < $idealPerDay - 1 && $excess > 0) {
						// Try to move a task from overloaded to underloaded day
						$movedTask = $this->moveTaskBetweenDays($optimized, $overloadedDay, $underloadedDay, $userSettings);
						
						if ($movedTask) {
							$dailyCounts[$overloadedDay]--;
							$dailyCounts[$underloadedDay]++;
							$excess--;
						}
					}
				}
			}
		}

		return $optimized;
	}

	/**
	 * Balance user workloads across the week
	 */
	private function balanceUserWorkloads(array $schedule, array $userSettings): array {
		// Calculate current user workloads
		$userWorkloads = [];
		foreach ($userSettings as $setting) {
			$userWorkloads[$setting->getUserId()] = [
				'task_count' => 0,
				'energy_total' => 0,
			];
		}

		foreach ($schedule as $task) {
			$user = $task['assigned_to'];
			$userWorkloads[$user]['task_count']++;
			$userWorkloads[$user]['energy_total'] += $task['energy_cost'];
		}

		// Calculate average workload
		$totalTasks = count($schedule);
		$avgTasksPerUser = count($userSettings) > 0 ? $totalTasks / count($userSettings) : 0;

		$optimized = $schedule;

		// Try to balance overloaded users
		foreach ($userWorkloads as $userId => $workload) {
			if ($workload['task_count'] > $avgTasksPerUser + 1) {
				$excess = $workload['task_count'] - $avgTasksPerUser;
				
				// Find underloaded users
				foreach ($userWorkloads as $targetUserId => $targetWorkload) {
					if ($targetWorkload['task_count'] < $avgTasksPerUser - 1 && $excess > 0) {
						// Try to reassign a task
						$reassignedTask = $this->reassignTaskBetweenUsers($optimized, $userId, $targetUserId, $userSettings);
						
						if ($reassignedTask) {
							$userWorkloads[$userId]['task_count']--;
							$userWorkloads[$targetUserId]['task_count']++;
							$excess--;
						}
					}
				}
			}
		}

		return $optimized;
	}

	/**
	 * Group related tasks together
	 */
	private function groupRelatedTasks(array $schedule): array {
		// Group tasks by room or category if possible
		$grouped = [];
		$ungrouped = [];

		foreach ($schedule as $task) {
			// Try to find related tasks (same room or similar priority)
			$related = $this->findRelatedTasks($task, $ungrouped);
			
			if (!empty($related)) {
				// Group with related tasks
				$grouped[] = $task;
				foreach ($related as $relatedTask) {
					$grouped[] = $relatedTask;
				}
			} else {
				$ungrouped[] = $task;
			}
		}

		return array_merge($grouped, $ungrouped);
	}

	/**
	 * Calculate schedule metrics for comparison
	 */
	private function calculateScheduleMetrics(array $schedule): array {
		$weekDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
		
		$dailyCounts = [];
		$userWorkloads = [];
		
		foreach ($weekDays as $day) {
			$dailyCounts[$day] = 0;
		}

		foreach ($schedule as $task) {
			$day = $task['scheduled_day'];
			$user = $task['assigned_to'];
			
			$dailyCounts[$day]++;
			
			if (!isset($userWorkloads[$user])) {
				$userWorkloads[$user] = ['task_count' => 0, 'energy_total' => 0];
			}
			$userWorkloads[$user]['task_count']++;
			$userWorkloads[$user]['energy_total'] += $task['energy_cost'];
		}

		// Calculate variance
		$dailyValues = array_values($dailyCounts);
		$dailyVariance = $this->calculateVariance($dailyValues);
		
		$userTaskValues = array_column($userWorkloads, 'task_count');
		$userVariance = !empty($userTaskValues) ? $this->calculateVariance($userTaskValues) : 0;

		return [
			'daily_variance' => $dailyVariance,
			'user_variance' => $userVariance,
			'daily_balance_score' => $this->calculateBalanceScore($dailyValues),
			'user_balance_score' => $this->calculateBalanceScore($userTaskValues),
			'total_tasks' => count($schedule),
		];
	}

	/**
	 * Calculate improvement score between two schedules
	 */
	private function calculateImprovementScore(array $before, array $after): float {
		$dailyImprovement = ($before['daily_variance'] - $after['daily_variance']) * 10;
		$userImprovement = ($before['user_variance'] - $after['user_variance']) * 10;
		$balanceImprovement = ($after['user_balance_score'] - $before['user_balance_score']) * 5;
		
		return $dailyImprovement + $userImprovement + $balanceImprovement;
	}

	/**
	 * Calculate statistical variance
	 */
	private function calculateVariance(array $values): float {
		if (empty($values)) {
			return 0.0;
		}

		$mean = array_sum($values) / count($values);
		$squaredDiffs = array_map(fn($value) => pow($value - $mean, 2), $values);
		
		return array_sum($squaredDiffs) / count($values);
	}

	/**
	 * Calculate balance score (higher is better)
	 */
	private function calculateBalanceScore(array $values): float {
		if (empty($values)) {
			return 0.0;
		}

		$mean = array_sum($values) / count($values);
		$variance = $this->calculateVariance($values);
		
		// Lower variance = higher balance score
		return max(0, 100 - ($variance * 10));
	}

	/**
	 * Try to move a task between days
	 */
	private function moveTaskBetweenDays(array &$schedule, string $fromDay, string $toDay, array $userSettings): ?array {
		foreach ($schedule as $index => $task) {
			if ($task['scheduled_day'] === $fromDay) {
				// Check if user is available on target day
				$userSettingsForUser = array_filter($userSettings, fn($s) => $s->getUserId() === $task['assigned_to']);
				$userSetting = reset($userSettingsForUser);
				
				if ($userSetting && $this->availabilityCalculator->isUserAvailable($userSetting, $toDay)) {
					$schedule[$index]['scheduled_day'] = $toDay;
					return $task;
				}
			}
		}
		
		return null;
	}

	/**
	 * Try to reassign a task between users
	 */
	private function reassignTaskBetweenUsers(array &$schedule, string $fromUser, string $toUser, array $userSettings): ?array {
		foreach ($schedule as $index => $task) {
			if ($task['assigned_to'] === $fromUser) {
				// Check if target user is available on the task's day
				$userSettingsForTarget = array_filter($userSettings, fn($s) => $s->getUserId() === $toUser);
				$targetSetting = reset($userSettingsForTarget);
				
				if ($targetSetting && $this->availabilityCalculator->isUserAvailable($targetSetting, $task['scheduled_day'])) {
					$schedule[$index]['assigned_to'] = $toUser;
					return $task;
				}
			}
		}
		
		return null;
	}

	/**
	 * Find related tasks for grouping
	 */
	private function findRelatedTasks(array $task, array &$candidates): array {
		$related = [];
		$taskName = strtolower($task['task_name']);
		
		foreach ($candidates as $index => $candidate) {
			$candidateName = strtolower($candidate['task_name']);
			
			// Simple relatedness check: same room type or similar keywords
			if (
				strpos($taskName, 'clean') !== false && strpos($candidateName, 'clean') !== false ||
				strpos($taskName, 'vacuum') !== false && strpos($candidateName, 'clean') !== false ||
				strpos($taskName, 'kitchen') !== false && strpos($candidateName, 'kitchen') !== false ||
				strpos($taskName, 'bathroom') !== false && strpos($candidateName, 'bathroom') !== false
			) {
				$related[] = $candidate;
				unset($candidates[$index]);
			}
		}
		
		return $related;
	}

	/**
	 * Count changes between original and optimized schedule
	 */
	private function countChanges(array $original, array $optimized): int {
		$changes = 0;
		
		foreach ($optimized as $index => $task) {
			if (!isset($original[$index]) || 
				$original[$index]['scheduled_day'] !== $task['scheduled_day'] ||
				$original[$index]['assigned_to'] !== $task['assigned_to']) {
				$changes++;
			}
		}
		
		return $changes;
	}
}
