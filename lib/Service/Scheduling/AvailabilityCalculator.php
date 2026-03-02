<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service\Scheduling;

use OCA\ChoreStrike\Db\AvailabilitySettings;

/**
 * Calculates and manages user availability for scheduling
 */
class AvailabilityCalculator {

	/**
	 * Check if user is available on a specific day
	 */
	public function isUserAvailable(AvailabilitySettings $settings, string $dayName): bool {
		return $settings->isAvailableOn($dayName);
	}

	/**
	 * Get available days for a user
	 *
	 * @return string[] Array of available day names
	 */
	public function getAvailableDays(AvailabilitySettings $settings): array {
		return $settings->getAvailableDays();
	}

	/**
	 * Get energy budget for a specific day
	 */
	public function getEnergyBudget(AvailabilitySettings $settings, string $dayName): int {
		return $settings->getEnergyForDay($dayName);
	}

	/**
	 * Check if user has enough energy budget for a task
	 */
	public function hasEnergyBudget(AvailabilitySettings $settings, string $dayName, int $requiredEnergy, int $currentEnergyUsage): bool {
		$dayBudget = $this->getEnergyBudget($settings, $dayName);
		return ($currentEnergyUsage + $requiredEnergy) <= $dayBudget;
	}

	/**
	 * Check if user has reached max tasks per day limit
	 */
	public function hasReachedTaskLimit(AvailabilitySettings $settings, string $dayName, int $currentTaskCount): bool {
		return $currentTaskCount >= $settings->getMaxTasksPerDay();
	}

	/**
	 * Get total weekly energy budget for a user
	 */
	public function getTotalWeeklyEnergy(AvailabilitySettings $settings): int {
		return $settings->getTotalWeeklyEnergy();
	}

	/**
	 * Get available hours per day for a user
	 */
	public function getAvailableHours(AvailabilitySettings $settings): float {
		return $settings->getAvailableHours();
	}

	/**
	 * Calculate daily workload capacity for a user
	 *
	 * @return array [dayName => ['max_tasks' => int, 'max_energy' => int]]
	 */
	public function calculateDailyCapacity(AvailabilitySettings $settings): array {
		$weekDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
		$capacity = [];

		foreach ($weekDays as $day) {
			$capacity[$day] = [
				'max_tasks' => $settings->getMaxTasksPerDay(),
				'max_energy' => $this->getEnergyBudget($settings, $day),
				'available' => $this->isUserAvailable($settings, $day),
			];
		}

		return $capacity;
	}

	/**
	 * Filter users available on a specific day
	 *
	 * @param AvailabilitySettings[] $userSettings
	 * @param string $dayName
	 * @return AvailabilitySettings[] Available users
	 */
	public function filterAvailableUsers(array $userSettings, string $dayName): array {
		return array_filter($userSettings, fn($settings) => $this->isUserAvailable($settings, $dayName));
	}

	/**
	 * Get users with sufficient energy budget for a task
	 *
	 * @param AvailabilitySettings[] $userSettings
	 * @param string $dayName
	 * @param int $requiredEnergy
	 * @param array $currentEnergyUsage [userId => currentEnergy]
	 * @return AvailabilitySettings[] Users with sufficient energy
	 */
	public function filterUsersByEnergy(array $userSettings, string $dayName, int $requiredEnergy, array $currentEnergyUsage): array {
		return array_filter($userSettings, function($settings) use ($dayName, $requiredEnergy, $currentEnergyUsage) {
			$userId = $settings->getUserId();
			$currentUsage = $currentEnergyUsage[$userId][$dayName] ?? 0;
			return $this->hasEnergyBudget($settings, $dayName, $requiredEnergy, $currentUsage);
		});
	}

	/**
	 * Get users who haven't reached their daily task limit
	 *
	 * @param AvailabilitySettings[] $userSettings
	 * @param string $dayName
	 * @param array $currentTaskCounts [userId => taskCount]
	 * @return AvailabilitySettings[] Users under their limit
	 */
	public function filterUsersByTaskLimit(array $userSettings, string $dayName, array $currentTaskCounts): array {
		return array_filter($userSettings, function($settings) use ($dayName, $currentTaskCounts) {
			$userId = $settings->getUserId();
			$currentCount = $currentTaskCounts[$userId][$dayName] ?? 0;
			return !$this->hasReachedTaskLimit($settings, $dayName, $currentCount);
		});
	}

	/**
	 * Calculate availability score for a user on a specific day
	 * Higher score means more available/better fit
	 */
	public function calculateAvailabilityScore(AvailabilitySettings $settings, string $dayName, int $currentEnergyUsage, int $currentTaskCount): float {
		if (!$this->isUserAvailable($settings, $dayName)) {
			return 0.0;
		}

		$score = 10.0; // Base score for being available

		// Energy availability bonus
		$energyBudget = $this->getEnergyBudget($settings, $dayName);
		$energyRatio = $energyBudget > 0 ? ($energyBudget - $currentEnergyUsage) / $energyBudget : 0;
		$score += $energyRatio * 5.0;

		// Task capacity bonus
		$maxTasks = $settings->getMaxTasksPerDay();
		$taskRatio = $maxTasks > 0 ? ($maxTasks - $currentTaskCount) / $maxTasks : 0;
		$score += $taskRatio * 3.0;

		return $score;
	}
}
