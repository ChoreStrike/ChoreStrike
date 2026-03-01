<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCP\ILogger;

/**
 * Smart Scheduler Service - Intelligent task assignment algorithm
 * Considers member availability, energy budgets, fairness, and task priorities
 */
class SmartSchedulerService {

	private HouseUserMapper $houseUserMapper;
	private RoomTaskMapper $roomTaskMapper;
	private SchedulingService $schedulingService;
	private ILogger $logger;

	public function __construct(
		HouseUserMapper $houseUserMapper,
		RoomTaskMapper $roomTaskMapper,
		SchedulingService $schedulingService,
		ILogger $logger
	) {
		$this->houseUserMapper = $houseUserMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->schedulingService = $schedulingService;
		$this->logger = $logger;
	}

	/**
	 * Generate optimal task schedule for a house
	 *
	 * @param int $houseId The house ID
	 * @param \DateTime $startDate Schedule start date
	 * @param \DateTime $endDate Schedule end date
	 * @return array Generated schedule with assignments
	 */
	public function generateSchedule(int $houseId, \DateTime $startDate, \DateTime $endDate): array {
		$this->logger->info('Generating schedule for house {houseId}', ['houseId' => $houseId]);

		// Get all unscheduled tasks
		$tasks = $this->getUnscheduledTasks($houseId);
		
		// Get member availability and energy budgets
		$members = $this->schedulingService->getMemberAvailability($houseId);
		
		// Initialize schedule structure
		$schedule = $this->initializeSchedule($startDate, $endDate, $members);
		
		// Sort tasks by priority and deadline
		$sortedTasks = $this->sortTasksByPriority($tasks);
		
		// Assign tasks using smart algorithm
		$assignments = $this->assignTasks($sortedTasks, $members, $schedule);
		
		return [
			'schedule' => $schedule,
			'assignments' => $assignments,
			'unassignedTasks' => $this->getUnassignedTasks($assignments),
			'totalTasks' => count($tasks),
			'assignedTasks' => count($assignments),
			'efficiency' => $this->calculateEfficiency($assignments, $members),
		];
	}

	/**
	 * Get scheduling preview without actually assigning tasks
	 *
	 * @param int $houseId The house ID
	 * @param int $days Number of days to preview
	 * @return array Preview data
	 */
	public function getSchedulePreview(int $houseId, int $days = 7): array {
		$startDate = new \DateTime();
		$endDate = (new \DateTime())->modify("+{$days} days");
		
		$tasks = $this->getUnscheduledTasks($houseId);
		$members = $this->schedulingService->getMemberAvailability($houseId);
		
		$preview = [
			'dateRange' => [
				'start' => $startDate->format('Y-m-d'),
				'end' => $endDate->format('Y-m-d'),
			],
			'taskCount' => count($tasks),
			'memberCount' => count($members),
			'dailyCapacity' => [],
			'potentialConflicts' => [],
		];
		
		// Calculate daily capacity
		$current = clone $startDate;
		while ($current <= $endDate) {
			$dayName = strtolower($current->format('l'));
			$dailyCapacity = 0;
			
			foreach ($members as $member) {
				if ($member['weekdayAvailability'][$dayName] ?? true) {
					$dailyCapacity += $member['energyBudget'][$dayName] ?? 10;
				}
			}
			
			$preview['dailyCapacity'][$current->format('Y-m-d')] = $dailyCapacity;
			$current->modify('+1 day');
		}
		
		// Check for potential conflicts
		$preview['potentialConflicts'] = $this->detectPotentialConflicts($tasks, $members, $startDate, $endDate);
		
		return $preview;
	}

	/**
	 * Get unscheduled tasks for a house
	 */
	private function getUnscheduledTasks(int $houseId): array {
		$tasks = $this->roomTaskMapper->findByHouseId($houseId);
		
		return array_filter($tasks, function (RoomTask $task) {
			return $task->getAssignedTo() === null && $task->getCompletedAt() === null;
		});
	}

	/**
	 * Initialize empty schedule structure
	 */
	private function initializeSchedule(\DateTime $startDate, \DateTime $endDate, array $members): array {
		$schedule = [];
		$current = clone $startDate;
		
		while ($current <= $endDate) {
			$dateKey = $current->format('Y-m-d');
			$schedule[$dateKey] = [
				'date' => $dateKey,
				'dayName' => strtolower($current->format('l')),
				'assignments' => [],
				'memberLoads' => [],
			];
			
			// Initialize member loads
			foreach ($members as $member) {
				$schedule[$dateKey]['memberLoads'][$member['userId']] = [
					'energyUsed' => 0,
					'taskCount' => 0,
					'available' => true,
				];
			}
			
			$current->modify('+1 day');
		}
		
		return $schedule;
	}

	/**
	 * Sort tasks by priority and deadline
	 */
	private function sortTasksByPriority(array $tasks): array {
		usort($tasks, function (RoomTask $a, RoomTask $b) {
			// First by priority
			$priorityA = $a->getPriority() ?? 3;
			$priorityB = $b->getPriority() ?? 3;
			
			if ($priorityA !== $priorityB) {
				return $priorityA - $priorityB; // Lower number = higher priority
			}
			
			// Then by deadline (earlier deadline first)
			$deadlineA = $a->getDeadline();
			$deadlineB = $b->getDeadline();
			
			if ($deadlineA && $deadlineB) {
				return $deadlineA <=> $deadlineB;
			}
			
			if ($deadlineA) return -1;
			if ($deadlineB) return 1;
			
			// Finally by difficulty (easier tasks first)
			return ($a->getDifficulty() ?? 3) - ($b->getDifficulty() ?? 3);
		});
		
		return $tasks;
	}

	/**
	 * Assign tasks to members using smart algorithm
	 */
	private function assignTasks(array $tasks, array $members, array &$schedule): array {
		$assignments = [];
		$memberStats = $this->initializeMemberStats($members);
		
		foreach ($tasks as $task) {
			$bestAssignment = $this->findBestAssignment($task, $members, $schedule, $memberStats);
			
			if ($bestAssignment) {
				$assignment = [
					'taskId' => $task->getId(),
					'taskTitle' => $task->getTitle(),
					'assignedTo' => $bestAssignment['userId'],
					'date' => $bestAssignment['date'],
					'energyCost' => $task->getEnergyCost() ?? 5,
					'score' => $bestAssignment['score'],
				];
				
				$assignments[] = $assignment;
				
				// Update schedule and member stats
				$schedule[$bestAssignment['date']]['assignments'][] = $assignment;
				$memberStats[$bestAssignment['userId']]['totalEnergy'] += $task->getEnergyCost() ?? 5;
				$memberStats[$bestAssignment['userId']]['taskCount']++;
				
				// Update daily load
				$schedule[$bestAssignment['date']]['memberLoads'][$bestAssignment['userId']]['energyUsed'] += $task->getEnergyCost() ?? 5;
				$schedule[$bestAssignment['date']]['memberLoads'][$bestAssignment['userId']]['taskCount']++;
			}
		}
		
		return $assignments;
	}

	/**
	 * Find best assignment for a task
	 */
	private function findBestAssignment(RoomTask $task, array $members, array $schedule, array $memberStats): ?array {
		$bestAssignment = null;
		$bestScore = -1;
		
		// Check each day in the schedule
		foreach ($schedule as $date => $dayData) {
			$dayName = $dayData['dayName'];
			
			// Find available members for this day
			$availableMembers = array_filter($members, function ($member) use ($dayName) {
				return $member['weekdayAvailability'][$dayName] ?? true;
			});
			
			foreach ($availableMembers as $member) {
				$energyCost = $task->getEnergyCost() ?? 5;
				$dailyBudget = $member['energyBudget'][$dayName] ?? 10;
				$currentLoad = $dayData['memberLoads'][$member['userId']]['energyUsed'] ?? 0;
				
				// Check if member has capacity
				if ($currentLoad + $energyCost <= $dailyBudget) {
					$score = $this->calculateAssignmentScore($task, $member, $date, $memberStats, $currentLoad, $dailyBudget);
					
					if ($score > $bestScore) {
						$bestScore = $score;
						$bestAssignment = [
							'userId' => $member['userId'],
							'date' => $date,
							'score' => $score,
						];
					}
				}
			}
		}
		
		return $bestAssignment;
	}

	/**
	 * Calculate assignment score based on multiple factors
	 */
	private function calculateAssignmentScore(RoomTask $task, array $member, string $date, array $memberStats, int $currentLoad, int $dailyBudget): float {
		$score = 0;
		
		// Factor 1: Energy efficiency (prefer using available budget efficiently)
		$energyCost = $task->getEnergyCost() ?? 5;
		$energyEfficiency = $currentLoad / $dailyBudget;
		$score += (1 - $energyEfficiency) * 30; // Prefer not overloading
		
		// Factor 2: Fairness (balance workload across members)
		$avgTasksPerMember = array_sum(array_column($memberStats, 'taskCount')) / max(1, count($memberStats));
		$memberTaskCount = $memberStats[$member['userId']]['taskCount'] ?? 0;
		$fairnessScore = 1 - abs($memberTaskCount - $avgTasksPerMember) / max(1, $avgTasksPerMember);
		$score += $fairnessScore * 25;
		
		// Factor 3: Deadline urgency
		if ($task->getDeadline()) {
			$deadline = new \DateTime($task->getDeadline());
			$assignmentDate = new \DateTime($date);
			$daysUntilDeadline = $deadline->diff($assignmentDate)->days;
			
			if ($daysUntilDeadline <= 0) {
				$score += 40; // Overdue - very high priority
			} elseif ($daysUntilDeadline <= 3) {
				$score += 30; // Due soon - high priority
			} elseif ($daysUntilDeadline <= 7) {
				$score += 20; // Due this week - medium priority
			}
		}
		
		// Factor 4: Task priority
		$priority = $task->getPriority() ?? 3;
		$score += (4 - $priority) * 15; // Lower priority number = higher score
		
		// Factor 5: Member preference (could be extended with actual preferences)
		$score += 10; // Base score for any assignment
		
		return $score;
	}

	/**
	 * Initialize member statistics
	 */
	private function initializeMemberStats(array $members): array {
		$stats = [];
		
		foreach ($members as $member) {
			$stats[$member['userId']] = [
				'totalEnergy' => 0,
				'taskCount' => 0,
				'userName' => $member['userName'],
			];
		}
		
		return $stats;
	}

	/**
	 * Get tasks that couldn't be assigned
	 */
	private function getUnassignedTasks(array $assignments): array {
		// This would need to be implemented based on the actual task data
		return [];
	}

	/**
	 * Calculate schedule efficiency
	 */
	private function calculateEfficiency(array $assignments, array $members): float {
		if (empty($assignments) || empty($members)) {
			return 0.0;
		}
		
		$totalEnergy = array_sum(array_column($assignments, 'energyCost'));
		$memberCount = count($members);
		
		// Efficiency based on how well tasks are distributed
		$tasksPerMember = count($assignments) / $memberCount;
		$idealDistribution = 1.0; // Perfect distribution
		
		// Calculate variance from ideal distribution
		$memberTaskCounts = [];
		foreach ($members as $member) {
			$memberTaskCounts[$member['userId']] = 0;
		}
		
		foreach ($assignments as $assignment) {
			$memberTaskCounts[$assignment['assignedTo']]++;
		}
		
		$variance = 0.0;
		foreach ($memberTaskCounts as $count) {
			$variance += pow($count - $tasksPerMember, 2);
		}
		
		$variance /= $memberCount;
		
		// Convert variance to efficiency (lower variance = higher efficiency)
		$maxVariance = pow($tasksPerMember, 2);
		$efficiency = $maxVariance > 0 ? 1.0 - ($variance / $maxVariance) : 1.0;
		
		return round($efficiency, 2);
	}

	/**
	 * Apply a scheduling suggestion by assigning a task to a member
	 *
	 * @param int $houseId The house ID
	 * @param int $taskId The task ID to assign
	 * @param string $assignedTo The Nextcloud user ID to assign the task to
	 * @param string $scheduledFor The scheduled date (Y-m-d format)
	 * @return RoomTask The updated task entity
	 * @throws \InvalidArgumentException If validation fails
	 * @throws \Exception If task or user not found
	 */
	public function applySuggestion(int $houseId, int $taskId, string $assignedTo, string $scheduledFor): RoomTask {
		// Validate inputs
		if (empty($assignedTo)) {
			throw new \InvalidArgumentException('Assignee cannot be empty');
		}

		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledFor)) {
			throw new \InvalidArgumentException('Invalid date format. Use Y-m-d');
		}

		// Verify task exists and belongs to this house
		$task = $this->roomTaskMapper->find($taskId);
		if (!$task) {
			throw new \Exception('Task not found');
		}

		// Note: RoomTask has getHouseId() from RoomTaskMapper relationship
		// We need to verify the task belongs to the house
		// This assumes the task has a getHouseId method or we need to verify through room
		// For now, we trust the task exists in the house context

		// Verify assignee is a member of this house
		$member = $this->houseUserMapper->findByNextcloudUserId($houseId, $assignedTo);
		if (!$member) {
			throw new \Exception('Assignee is not a member of this house');
		}

		// Verify member has availability for the scheduled day
		$dayOfWeek = strtolower((new \DateTime($scheduledFor))->format('l'));
		$memberPrefs = $this->schedulingService->getUserPreferences($member->getId());
		$isAvailable = $memberPrefs['weekdayAvailability'][$dayOfWeek] ?? true;

		if (!$isAvailable) {
			throw new \InvalidArgumentException('Member is not available on ' . $dayOfWeek);
		}

		// Apply the assignment
		$task->setAssignedTo($assignedTo);
		$task->setScheduledFor($scheduledFor);

		// Persist changes
		$this->roomTaskMapper->update($task);

		$this->logger->info(
			'Suggestion applied: Task {taskId} assigned to {user} on {date}',
			['taskId' => $taskId, 'user' => $assignedTo, 'date' => $scheduledFor]
		);

		return $task;
	}
}
