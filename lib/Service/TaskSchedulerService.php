<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\AvailabilitySettings;
use OCA\ChoreStrike\Db\AvailabilitySettingsMapper;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\UserStatsMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\RoomMapper;

/**
 * Service for intelligent task scheduling
 */
class TaskSchedulerService {

	public function __construct(
		private AvailabilitySettingsMapper $availabilityMapper,
		private FloorMapper $floorMapper,
		private RoomTaskMapper $roomTaskMapper,
		private UserStatsMapper $userStatsMapper,
		private TaskMapper $taskMapper,
		private RoomMapper $roomMapper,
	) {
	}

	/**
	 * Generate weekly schedule for house
	 */
	public function generateWeeklySchedule(int $houseId, \DateTime $weekStart): array {
		// Collect data
		$roomTasks = $this->getActiveRoomTasks($houseId);
		$userSettings = $this->availabilityMapper->findByHouse($houseId);
		
		// Calculate priority scores
		$scoredTasks = $this->calculatePriorityScores($roomTasks);
		
		// Sort by priority (highest first)
		usort($scoredTasks, fn($a, $b) => $b['priority_score'] <=> $a['priority_score']);
		
		// Generate schedule
		$schedule = $this->distributeTasks($scoredTasks, $userSettings, $weekStart);
		
		return [
			'week_start' => $weekStart->format('Y-m-d'),
			'tasks' => $schedule,
			'total_tasks' => count($schedule),
			'users_count' => count($userSettings),
		];
	}

	/**
	 * Get active room tasks for house
	 *
	 * @return RoomTask[]
	 */
	private function getActiveRoomTasks(int $houseId): array {
		$floors = $this->floorMapper->findByHouseId($houseId);
		$allTasks = [];
		
		foreach ($floors as $floor) {
			$rooms = $this->roomMapper->findByFloorId($floor->getId());
			foreach ($rooms as $room) {
				$tasks = $this->roomTaskMapper->findByRoomId($room->getId(), true);
				foreach ($tasks as $task) {
					if ($this->shouldScheduleTask($task)) {
						$allTasks[] = $task;
					}
				}
			}
		}
		
		return $allTasks;
	}

	/**
	 * Check if task should be scheduled
	 */
	private function shouldScheduleTask(RoomTask $task): bool {
		// Skip completed or inactive tasks
		if (!$task->getIsActive()) {
			return false;
		}
		
		$nextDue = $task->getNextDue();
		if (!$nextDue) {
			return false;
		}
		
		$now = new \DateTime();
		$daysUntilDue = (int)$now->diff($nextDue)->days;
		
		// Schedule if overdue or due within 7 days
		return $daysUntilDue <= 7 || $nextDue < $now;
	}

	/**
	 * Calculate priority scores for tasks
	 */
	private function calculatePriorityScores(array $roomTasks): array {
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
			$priorityScore = (
				($task->getPriority() * 10) +
				(max(0, 7 - $daysUntilDue) * 5) +
				($overdueDays * 20) +
				($task->getEnergyCost() * 2)
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
	 * Get user stats map
	 */
	private function getUserStatsMap(int $houseId): array {
		$stats = $this->userStatsMapper->findByHouse($houseId);
		$map = [];
		
		foreach ($stats as $stat) {
			$map[$stat->getUserId()] = $stat;
		}
		
		return $map;
	}

	/**
	 * Distribute tasks across week
	 */
	private function distributeTasks(array $scoredTasks, array $userSettings, \DateTime $weekStart): array {
		$schedule = [];
		$dailyWorkload = []; // Track tasks per day per user
		$weekDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
		
		// Initialize daily workload tracking
		foreach ($userSettings as $setting) {
			$userId = $setting->getUserId();
			foreach ($weekDays as $day) {
				$dailyWorkload[$userId][$day] = [
					'task_count' => 0,
					'energy_total' => 0,
				];
			}
		}
		
		// Get default distribution for each user
		$userDistributions = [];
		foreach ($userSettings as $setting) {
			$userDistributions[$setting->getUserId()] = $setting->getDefaultDistribution();
		}
		
		// Assign tasks
		foreach ($scoredTasks as $scoredTask) {
			$task = $scoredTask['task'];
			$roomTask = $scoredTask['room_task'];
			$energyCost = $scoredTask['energy_cost'];
			
			// Find best day and user for this task
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
	 * Find best day and user for a task
	 */
	private function findBestAssignment(array $scoredTask, array $userSettings, array $userDistributions, array $dailyWorkload): ?array {
		$energyCost = $scoredTask['energy_cost'];
		
		$bestScore = -1;
		$bestAssignment = null;
		$weekDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
		
		foreach ($userSettings as $setting) {
			$userId = $setting->getUserId();
			$distribution = $userDistributions[$userId] ?? [];
			
			foreach ($weekDays as $dayName) {
				// Check if user is available on this day
				if (!$setting->isAvailableOn($dayName)) {
					continue;
				}
				
				// Check max tasks per day limit
				$currentTasks = $dailyWorkload[$userId][$dayName]['task_count'] ?? 0;
				if ($currentTasks >= $setting->getMaxTasksPerDay()) {
					continue;
				}
				
				// Check energy budget
				$currentEnergy = $dailyWorkload[$userId][$dayName]['energy_total'] ?? 0;
				$dayEnergyBudget = $setting->getEnergyForDay($dayName);
				if ($currentEnergy + $energyCost > $dayEnergyBudget) {
					continue;
				}
				
				// Calculate assignment score
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
			// Prefer earlier in week for overdue
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
	 * Optimize existing schedule
	 */
	public function optimizeSchedule(array $schedule): array {
		// TODO: Implement optimization algorithm
		// - Minimize daily variance
		// - Balance user workloads
		// - Group related tasks
		return $schedule;
	}

	/**
	 * Get suggested task reassignment
	 */
	public function suggestReassignment(int $roomTaskId): array {
		// TODO: Implement reassignment suggestions
		return [];
	}

	/**
	 * Get scheduling statistics
	 */
	public function getScheduleStats(int $houseId, \DateTime $weekStart): array {
		$schedule = $this->generateWeeklySchedule($houseId, $weekStart);
		
		$stats = [
			'total_tasks' => count($schedule['tasks']),
			'by_day' => [],
			'by_user' => [],
			'total_energy' => 0,
		];
		
		foreach ($schedule['tasks'] as $task) {
			$day = $task['scheduled_day'];
			$user = $task['assigned_to'];
			
			$stats['by_day'][$day] = ($stats['by_day'][$day] ?? 0) + 1;
			$stats['by_user'][$user] = ($stats['by_user'][$user] ?? 0) + 1;
			$stats['total_energy'] += $task['energy_cost'];
		}
		
		return $stats;
	}
}
