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
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\AvailabilitySettings;
use OCA\ChoreStrike\Db\AvailabilitySettingsMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\FloorMapper;

/**
 * Suggests task reassignments based on availability and workload
 */
class TaskReassignmentSuggester {

	private TaskPriorityScorer $priorityScorer;
	private AvailabilityCalculator $availabilityCalculator;
	private TaskAssignmentAlgorithm $assignmentAlgorithm;

	public function __construct(
		private TaskMapper $taskMapper,
		private RoomTaskMapper $roomTaskMapper,
		private AvailabilitySettingsMapper $availabilityMapper,
		private RoomMapper $roomMapper,
		private FloorMapper $floorMapper,
	) {
		$this->priorityScorer = new TaskPriorityScorer($taskMapper);
		$this->availabilityCalculator = new AvailabilityCalculator();
		$this->assignmentAlgorithm = new TaskAssignmentAlgorithm();
	}

	/**
	 * Get suggested reassignments for a specific room task
	 *
	 * @param int $roomTaskId The room task to reassign
	 * @return array Suggested reassignments with scores and reasons
	 */
	public function suggestReassignment(int $roomTaskId): array {
		try {
			// Get the room task
			$roomTask = $this->roomTaskMapper->find($roomTaskId);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return [
				'success' => false,
				'error' => 'Room task not found',
				'suggestions' => [],
			];
		} catch (\Exception $e) {
			return [
				'success' => false,
				'error' => 'Room task not found',
				'suggestions' => [],
			];
		}

		// Get task details
		try {
			$task = $this->taskMapper->find($roomTask->getTaskId());
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return [
				'success' => false,
				'error' => 'Task not found',
				'suggestions' => [],
			];
		} catch (\Exception $e) {
			return [
				'success' => false,
				'error' => 'Task not found',
				'suggestions' => [],
			];
		}

		// Get house ID from room
		$room = $this->roomMapper->find($roomTask->getRoomId());
		$floor = $this->floorMapper->find($room->getFloorId());
		$houseId = $floor->getHouseId();

		// Get all users in the house
		$userSettings = $this->availabilityMapper->findByHouse($houseId);

		// Calculate priority score for this task
		$scoredTask = $this->priorityScorer->calculateScores([$roomTask]);
		if (empty($scoredTask)) {
			return [
				'success' => false,
				'error' => 'Cannot calculate task priority',
				'suggestions' => [],
			];
		}

		$taskData = $scoredTask[0];

		// Generate suggestions for different scenarios
		$suggestions = [
			'best_available' => $this->findBestAvailableAssignment($taskData, $userSettings),
			'workload_balanced' => $this->findWorkloadBalancedAssignment($taskData, $userSettings),
			'energy_optimized' => $this->findEnergyOptimizedAssignment($taskData, $userSettings),
			'urgent_reassignment' => $this->findUrgentAssignment($taskData, $userSettings),
		];

		// Filter and rank suggestions
		$rankedSuggestions = $this->rankSuggestions($suggestions);

		return [
			'success' => true,
			'task' => [
				'id' => $roomTaskId,
				'name' => $task->getName(),
				'room' => $room->getName(),
				'priority_score' => $taskData['priority_score'],
				'energy_cost' => $taskData['energy_cost'],
				'is_overdue' => $taskData['is_overdue'],
				'current_assignment' => $roomTask->getAssignedToUserId(),
			],
			'suggestions' => $rankedSuggestions,
			'metadata' => [
				'total_users' => count($userSettings),
				'available_users' => count($this->getAvailableUsers($userSettings)),
				'analysis_timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
			],
		];
	}

	/**
	 * Find best available assignment based on current constraints
	 */
	private function findBestAvailableAssignment(array $taskData, array $userSettings): array {
		$weekDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
		$suggestions = [];

		foreach ($userSettings as $setting) {
			$userId = $setting->getUserId();
			
			foreach ($weekDays as $day) {
				if ($this->availabilityCalculator->isUserAvailable($setting, $day)) {
					$score = $this->calculateReassignmentScore($taskData, $setting, $day);
					
					if ($score > 0) {
						$suggestions[] = [
							'user_id' => $userId,
							'day' => $day,
							'score' => $score,
							'reason' => 'Available with good fit',
							'benefits' => $this->getAssignmentBenefits($taskData, $setting, $day),
							'constraints' => $this->getAssignmentConstraints($taskData, $setting, $day),
						];
					}
				}
			}
		}

		// Sort by score and return top 3
		usort($suggestions, fn($a, $b) => $b['score'] <=> $a['score']);
		return array_slice($suggestions, 0, 3);
	}

	/**
	 * Find assignment that balances workloads
	 */
	private function findWorkloadBalancedAssignment(array $taskData, array $userSettings): array {
		// Calculate current workloads (simplified)
		$currentWorkloads = [];
		foreach ($userSettings as $setting) {
			$currentWorkloads[$setting->getUserId()] = [
				'task_count' => rand(0, 5), // In real implementation, calculate from actual schedule
				'energy_total' => rand(0, 20),
			];
		}

		$suggestions = [];
		$weekDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

		foreach ($userSettings as $setting) {
			$userId = $setting->getUserId();
			$currentWorkload = $currentWorkloads[$userId];
			
			foreach ($weekDays as $day) {
				if ($this->availabilityCalculator->isUserAvailable($setting, $day)) {
					// Prefer users with lower current workloads
					$workloadBonus = max(0, (5 - $currentWorkload['task_count']) * 10);
					$energyBonus = max(0, (20 - $currentWorkload['energy_total']) * 2);
					
					$score = $this->calculateReassignmentScore($taskData, $setting, $day) + $workloadBonus + $energyBonus;
					
					if ($score > 0) {
						$suggestions[] = [
							'user_id' => $userId,
							'day' => $day,
							'score' => $score,
							'reason' => 'Balances workload distribution',
							'benefits' => array_merge(
								$this->getAssignmentBenefits($taskData, $setting, $day),
								['Reduces workload imbalance', 'Improves team efficiency']
							),
							'constraints' => $this->getAssignmentConstraints($taskData, $setting, $day),
							'workload_impact' => [
								'before' => $currentWorkload,
								'after' => [
									'task_count' => $currentWorkload['task_count'] + 1,
									'energy_total' => $currentWorkload['energy_total'] + $taskData['energy_cost'],
								],
							],
						];
					}
				}
			}
		}

		usort($suggestions, fn($a, $b) => $b['score'] <=> $a['score']);
		return array_slice($suggestions, 0, 3);
	}

	/**
	 * Find assignment optimized for energy usage
	 */
	private function findEnergyOptimizedAssignment(array $taskData, array $userSettings): array {
		$suggestions = [];
		$weekDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

		foreach ($userSettings as $setting) {
			$userId = $setting->getUserId();
			
			foreach ($weekDays as $day) {
				if ($this->availabilityCalculator->isUserAvailable($setting, $day)) {
					$energyBudget = $this->availabilityCalculator->getEnergyBudget($setting, $day);
					$taskEnergy = $taskData['energy_cost'];
					
					// Prefer days with higher energy budget for this task
					$energyFitScore = $energyBudget > 0 ? min(100, ($taskEnergy / $energyBudget) * 100) : 0;
					
					$score = $this->calculateReassignmentScore($taskData, $setting, $day) + $energyFitScore;
					
					if ($score > 0) {
						$suggestions[] = [
							'user_id' => $userId,
							'day' => $day,
							'score' => $score,
							'reason' => 'Optimized for energy efficiency',
							'benefits' => array_merge(
								$this->getAssignmentBenefits($taskData, $setting, $day),
								["Fits within energy budget ({$taskEnergy}/{$energyBudget})"]
							),
							'constraints' => $this->getAssignmentConstraints($taskData, $setting, $day),
							'energy_analysis' => [
								'task_energy' => $taskEnergy,
								'day_budget' => $energyBudget,
								'efficiency_score' => $energyFitScore,
							],
						];
					}
				}
			}
		}

		usort($suggestions, fn($a, $b) => $b['score'] <=> $a['score']);
		return array_slice($suggestions, 0, 3);
	}

	/**
	 * Find urgent assignment for overdue tasks
	 */
	private function findUrgentAssignment(array $taskData, array $userSettings): array {
		if (!$taskData['is_overdue']) {
			return [];
		}

		$suggestions = [];
		$weekDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
		
		// For urgent tasks, prioritize earliest available days
		foreach ($weekDays as $dayIndex => $day) {
			foreach ($userSettings as $setting) {
				if ($this->availabilityCalculator->isUserAvailable($setting, $day)) {
					// Urgency bonus: earlier in week gets higher score
					$urgencyBonus = (7 - $dayIndex) * 20;
					
					$score = $this->calculateReassignmentScore($taskData, $setting, $day) + $urgencyBonus;
					
					$suggestions[] = [
						'user_id' => $setting->getUserId(),
						'day' => $day,
						'score' => $score,
						'reason' => 'Urgent reassignment needed (overdue task)',
						'benefits' => array_merge(
							$this->getAssignmentBenefits($taskData, $setting, $day),
							['Addresses overdue task immediately', 'Minimizes delay impact']
						),
						'constraints' => $this->getAssignmentConstraints($taskData, $setting, $day),
						'urgency_level' => 'high',
						'days_overdue' => abs($taskData['days_until_due']),
					];
				}
			}
		}

		usort($suggestions, fn($a, $b) => $b['score'] <=> $a['score']);
		return array_slice($suggestions, 0, 2); // Return fewer options for urgent cases
	}

	/**
	 * Calculate reassignment score
	 */
	private function calculateReassignmentScore(array $taskData, AvailabilitySettings $setting, string $day): float {
		$score = 0.0;

		// Base availability score
		$score += $this->availabilityCalculator->calculateAvailabilityScore($setting, $day, 0, 0);

		// Energy fit
		$energyBudget = $this->availabilityCalculator->getEnergyBudget($setting, $day);
		$taskEnergy = $taskData['energy_cost'];
		if ($energyBudget >= $taskEnergy) {
			$score += 20;
		}

		// Priority consideration
		$score += $taskData['priority_score'] * 0.1;

		return $score;
	}

	/**
	 * Get assignment benefits
	 */
	private function getAssignmentBenefits(array $taskData, AvailabilitySettings $setting, string $day): array {
		$benefits = [];

		if ($this->availabilityCalculator->isUserAvailable($setting, $day)) {
			$benefits[] = 'User is available on this day';
		}

		$energyBudget = $this->availabilityCalculator->getEnergyBudget($setting, $day);
		if ($energyBudget >= $taskData['energy_cost']) {
			$benefits[] = 'Fits within energy budget';
		}

		if ($taskData['is_overdue']) {
			$benefits[] = 'Addresses overdue task';
		}

		return $benefits;
	}

	/**
	 * Get assignment constraints
	 */
	private function getAssignmentConstraints(array $taskData, AvailabilitySettings $setting, string $day): array {
		$constraints = [];

		$energyBudget = $this->availabilityCalculator->getEnergyBudget($setting, $day);
		if ($energyBudget < $taskData['energy_cost']) {
			$constraints[] = 'Exceeds energy budget';
		}

		$maxTasks = $setting->getMaxTasksPerDay();
		if ($maxTasks <= 1) {
			$constraints[] = 'Limited task capacity';
		}

		return $constraints;
	}

	/**
	 * Get available users
	 */
	private function getAvailableUsers(array $userSettings): array {
		return array_filter($userSettings, fn($setting) => 
			$this->availabilityCalculator->getAvailableDays($setting) !== []
		);
	}

	/**
	 * Rank and filter suggestions
	 */
	private function rankSuggestions(array $suggestions): array {
		$ranked = [];

		foreach ($suggestions as $type => $typeSuggestions) {
			foreach ($typeSuggestions as $suggestion) {
				$suggestion['suggestion_type'] = $type;
				$ranked[] = $suggestion;
			}
		}

		// Sort by score
		usort($ranked, fn($a, $b) => $b['score'] <=> $a['score']);

		// Remove duplicates and return top 5
		$unique = [];
		$seen = [];

		foreach ($ranked as $suggestion) {
			$key = $suggestion['user_id'] . ':' . $suggestion['day'];
			if (!isset($seen[$key])) {
				$seen[$key] = true;
				$unique[] = $suggestion;
			}
		}

		return array_slice($unique, 0, 5);
	}
}
