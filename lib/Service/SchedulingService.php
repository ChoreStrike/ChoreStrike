<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;

/**
 * Service for managing member scheduling preferences (availability and energy budget)
 */
class SchedulingService {

	private HouseUserMapper $houseUserMapper;

	public function __construct(HouseUserMapper $houseUserMapper) {
		$this->houseUserMapper = $houseUserMapper;
	}

	/**
	 * Get scheduling preferences for a user
	 *
	 * @param int $userId The house user ID
	 * @return array Scheduling preferences
	 */
	public function getUserPreferences(int $userId): array {
		$user = $this->houseUserMapper->find($userId);

		// Get stored preferences or return defaults
		$preferences = $user->getSchedulingPreferences();

		return [
			'userId' => $userId,
			'energyBudget' => $preferences['energyBudget'] ?? $this->getDefaultEnergyBudget(),
			'weekdayAvailability' => $preferences['weekdayAvailability'] ?? $this->getDefaultAvailability(),
		];
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
	 * Update energy budget for a specific day
	 *
	 * @param int $userId The house user ID
	 * @param string $day Weekday name (e.g., 'monday')
	 * @param int $energy Energy points (1-20)
	 */
	public function updateEnergyBudget(int $userId, string $day, int $energy): void {
		if ($energy < 1 || $energy > 20) {
			throw new \InvalidArgumentException('Energy budget must be between 1 and 20');
		}

		$user = $this->houseUserMapper->find($userId);
		$preferences = $user->getSchedulingPreferences();

		if (!isset($preferences['energyBudget'])) {
			$preferences['energyBudget'] = $this->getDefaultEnergyBudget();
		}

		$preferences['energyBudget'][$day] = $energy;
		$user->setSchedulingPreferences($preferences);

		$this->houseUserMapper->update($user);
	}

	/**
	 * Update availability for a Nextcloud user within a specific house.
	 *
	 * @param int $houseId The house ID
	 * @param string $nextcloudUserId The Nextcloud user ID
	 * @param string $day Weekday name
	 * @param bool $available Whether user is available
	 */
	public function updateAvailabilityByNextcloudUserId(int $houseId, string $nextcloudUserId, string $day, bool $available): void {
		$user = $this->houseUserMapper->findByNextcloudUserId($houseId, $nextcloudUserId);

		$this->updateAvailability($user->getId(), $day, $available);
	}

	/**
	 * Update energy budget for a Nextcloud user within a specific house.
	 *
	 * @param int $houseId The house ID
	 * @param string $nextcloudUserId The Nextcloud user ID
	 * @param string $day Weekday name (e.g., 'monday')
	 * @param int $energy Energy points (1-20)
	 */
	public function updateEnergyBudgetByNextcloudUserId(int $houseId, string $nextcloudUserId, string $day, int $energy): void {
		$user = $this->houseUserMapper->findByNextcloudUserId($houseId, $nextcloudUserId);

		$this->updateEnergyBudget($user->getId(), $day, $energy);
	}

	/**
	 * Update weekday availability
	 *
	 * @param int $userId The house user ID
	 * @param string $day Weekday name
	 * @param bool $available Whether user is available
	 */
	public function updateAvailability(int $userId, string $day, bool $available): void {
		$user = $this->houseUserMapper->find($userId);
		$preferences = $user->getSchedulingPreferences();

		if (!isset($preferences['weekdayAvailability'])) {
			$preferences['weekdayAvailability'] = $this->getDefaultAvailability();
		}

		$preferences['weekdayAvailability'][$day] = $available;
		$user->setSchedulingPreferences($preferences);

		$this->houseUserMapper->update($user);
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
				'userId' => $user->getId(),
				'userName' => $user->getDisplayName(),
				'energyBudget' => $preferences['energyBudget'] ?? $this->getDefaultEnergyBudget(),
				'weekdayAvailability' => $preferences['weekdayAvailability'] ?? $this->getDefaultAvailability(),
			];
		}

		return $availability;
	}

	/**
	 * Update another member's availability (admin only)
	 *
	 * @param int $houseId The current house ID
	 * @param int $targetUserId The user to update
	 * @param array $updates Availability and energy updates
	 */
	public function updateMemberAvailability(int $houseId, int $targetUserId, array $updates): void {
		$user = $this->houseUserMapper->find($targetUserId);
		if ($user->getHouseId() !== $houseId) {
			throw new \InvalidArgumentException('Target user does not belong to this house');
		}

		$preferences = $user->getSchedulingPreferences();

		if (isset($updates['energyBudget'])) {
			foreach ($updates['energyBudget'] as $day => $energy) {
				if ($energy >= 1 && $energy <= 20) {
					$preferences['energyBudget'][$day] = $energy;
				}
			}
		}

		if (isset($updates['weekdayAvailability'])) {
			foreach ($updates['weekdayAvailability'] as $day => $available) {
				$preferences['weekdayAvailability'][$day] = (bool) $available;
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
				'recommendedTasks' => min(count($tasks), (int) ($totalEnergy / 5)),
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
