<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;

/**
 * Service for managing member scheduling preferences (availability and energy budget)
 */
class SchedulingService {
	private HouseUserMapper $houseUserMapper;
	private CompletedTaskMapper $completedTaskMapper;

	public function __construct(HouseUserMapper $houseUserMapper, CompletedTaskMapper $completedTaskMapper) {
		$this->houseUserMapper = $houseUserMapper;
		$this->completedTaskMapper = $completedTaskMapper;
	}

	/**
	 * Get scheduling preferences for a user
	 *
	 * CR-SVC-28: reports the real consumed energy for today instead of a
	 * hardcoded 0, so both this and the ByNextcloudUserId variant agree.
	 *
	 * @param int $userId The house user ID
	 * @return array Scheduling preferences
	 */
	public function getUserPreferences(int $userId): array {
		$user = $this->houseUserMapper->find($userId);

		return $this->buildPreferencesPayload($user, $user->getSchedulingPreferences());
	}

	/**
	 * Get scheduling preferences for a Nextcloud user within a specific house.
	 *
	 * @param int $houseId The house ID
	 * @param string $nextcloudUserId The Nextcloud user ID
	 * @return array Scheduling preferences
	 */
	public function getUserPreferencesByNextcloudUserId(int $houseId, string $nextcloudUserId): array {
		$user = $this->houseUserMapper->findByNextcloudUserId($houseId, $nextcloudUserId);

		return $this->getUserPreferences($user->getId());
	}

	/**
	 * Update the current user's scheduling preferences in bulk
	 * (section-granular, never day-granular).
	 *
	 * Each provided section replaces the stored section wholesale; absent
	 * sections and sibling keys (e.g. `maxDailyTasks`) are preserved. The
	 * lookup is scoped by houseId, so a user who is not a member of the
	 * house is not found (membership enforced at the persistence layer).
	 *
	 * @param int $houseId The current house ID
	 * @param string $nextcloudUserId The Nextcloud user ID
	 * @param array|null $weekdayAvailability Full week of availability flags (null = leave unchanged)
	 * @param array|null $energyBudget Full week of energy budgets 1-20 (null = leave unchanged)
	 * @return array The persisted scheduling preferences
	 * @throws \InvalidArgumentException When an energy value is not an integer in 1-20
	 */
	public function updatePreferencesByNextcloudUserId(int $houseId, string $nextcloudUserId, ?array $weekdayAvailability, ?array $energyBudget): array {
		$user = $this->houseUserMapper->findByNextcloudUserId($houseId, $nextcloudUserId);
		$preferences = $user->getSchedulingPreferences();

		if ($energyBudget !== null) {
			foreach ($energyBudget as $energy) {
				if (!is_int($energy) || $energy < 1 || $energy > 20) {
					throw new \InvalidArgumentException('Energy budget must be between 1 and 20');
				}
			}
			$preferences['energyBudget'] = $energyBudget;
		}

		if ($weekdayAvailability !== null) {
			$preferences['weekdayAvailability'] = $weekdayAvailability;
		}

		$user->setSchedulingPreferences($preferences);
		$this->houseUserMapper->update($user);

		return $this->buildPreferencesPayload($user, $user->getSchedulingPreferences());
	}

	/**
	 * Build the public preferences payload for a house user.
	 *
	 * CR-SVC-28: reports the real consumed energy for today instead of a
	 * hardcoded 0, so both this and the ByNextcloudUserId variant agree.
	 *
	 * @param HouseUser $user The house user (already loaded)
	 * @param array|null $preferences The user's scheduling preferences (null for rows without stored preferences)
	 * @return array The full preferences shape exposed to controllers
	 */
	private function buildPreferencesPayload(HouseUser $user, ?array $preferences): array {
		$preferences = $preferences ?? [];

		return [
			'userId' => $user->getId(),
			'energyBudget' => $preferences['energyBudget'] ?? $this->getDefaultEnergyBudget(),
			'weekdayAvailability' => $preferences['weekdayAvailability'] ?? $this->getDefaultAvailability(),
			'maxDailyTasks' => $preferences['maxDailyTasks'] ?? PHP_INT_MAX,
			'todayConsumedEnergy' => $this->completedTaskMapper->getConsumedEnergyForUserOnDate(
				$user->getId(),
				new \DateTimeImmutable()
			),
		];
	}

	/**
	 * Get all member availability for a house
	 *
	 * @param int $houseId The house ID
	 * @return array Member availability data
	 */
	public function getMemberAvailability(int $houseId): array {
		$users = $this->houseUserMapper->findByHouseId($houseId);
		$availability = [];

		foreach ($users as $user) {
			$preferences = $user->getSchedulingPreferences();
			$availability[] = [
				'userId' => $user->getNextcloudUserId(),
				'houseUserId' => $user->getId(),
				'userName' => $user->getDisplayName(),
				'energyBudget' => $preferences['energyBudget'] ?? $this->getDefaultEnergyBudget(),
				'weekdayAvailability' => $preferences['weekdayAvailability'] ?? $this->getDefaultAvailability(),
			];
		}

		return $availability;
	}

	/**
	 * Update another member's availability (house owner only)
	 *
	 * @param int $houseId The current house ID
	 * @param int $targetUserId The house user ID to update
	 * @param array $updates Availability and energy updates
	 */
	public function updateMemberAvailability(int $houseId, int $targetUserId, array $updates): void {
		$user = $this->houseUserMapper->find($targetUserId);
		if ($user->getHouseId() !== $houseId) {
			throw new \InvalidArgumentException('Target user does not belong to this house');
		}

		$this->applyMemberUpdates($user, $updates);
	}

	/**
	 * Update a member's availability by Nextcloud user ID within a specific house.
	 *
	 * The lookup is scoped by houseId, so a user who is not a member of the
	 * house is not found (membership enforced at the persistence layer).
	 *
	 * @param int $houseId The current house ID
	 * @param string $nextcloudUserId The Nextcloud user ID to update
	 * @param array $updates Availability and energy updates
	 */
	public function updateMemberAvailabilityByNextcloudUserId(int $houseId, string $nextcloudUserId, array $updates): void {
		$user = $this->houseUserMapper->findByNextcloudUserId($houseId, $nextcloudUserId);
		$this->applyMemberUpdates($user, $updates);
	}

	/**
	 * Apply availability and energy-budget updates to a house user's preferences.
	 *
	 * Rejects out-of-range energy values instead of silently dropping them,
	 * so the caller never receives a success response for unpersisted data.
	 *
	 * @param HouseUser $user The house user to update
	 * @param array $updates Availability and energy updates keyed by weekday
	 * @throws \InvalidArgumentException When an energy value is not an integer in 1-20
	 */
	private function applyMemberUpdates(HouseUser $user, array $updates): void {
		$preferences = $user->getSchedulingPreferences();

		if (isset($updates['energyBudget'])) {
			foreach ($updates['energyBudget'] as $day => $energy) {
				if (!is_int($energy) || $energy < 1 || $energy > 20) {
					throw new \InvalidArgumentException('Energy budget must be between 1 and 20');
				}
				$preferences['energyBudget'][$day] = $energy;
			}
		}

		if (isset($updates['weekdayAvailability'])) {
			foreach ($updates['weekdayAvailability'] as $day => $available) {
				$preferences['weekdayAvailability'][$day] = (bool)$available;
			}
		}

		$user->setSchedulingPreferences($preferences);
		$this->houseUserMapper->update($user);
	}

	/**
	 * Get scheduling suggestions based on availability
	 *
	 * @param array $tasks Tasks to schedule
	 * @param int $houseId The house ID
	 * @return array Suggestions per day
	 */
	public function getSchedulingSuggestions(array $tasks, int $houseId): array {
		$members = $this->getMemberAvailability($houseId);
		$suggestions = [];

		$weekdays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

		foreach ($weekdays as $day) {
			$availableMembers = array_filter($members, function ($m) use ($day) {
				return $m['weekdayAvailability'][$day] ?? true;
			});

			$totalEnergy = array_reduce($availableMembers, function ($sum, $m) use ($day) {
				return $sum + ($m['energyBudget'][$day] ?? 10);
			}, 0);

			$suggestions[$day] = [
				'availableMembers' => count($availableMembers),
				'totalEnergy' => $totalEnergy,
				'recommendedTasks' => min(count($tasks), (int)($totalEnergy / 5)),
			];
		}

		return $suggestions;
	}

	/**
	 * Get default energy budget
	 */
	private function getDefaultEnergyBudget(): array {
		return [
			'monday' => 10,
			'tuesday' => 10,
			'wednesday' => 10,
			'thursday' => 10,
			'friday' => 10,
			'saturday' => 15,
			'sunday' => 15,
		];
	}

	/**
	 * Get default availability (all days available)
	 */
	private function getDefaultAvailability(): array {
		return [
			'monday' => true,
			'tuesday' => true,
			'wednesday' => true,
			'thursday' => true,
			'friday' => true,
			'saturday' => true,
			'sunday' => true,
		];
	}
}
