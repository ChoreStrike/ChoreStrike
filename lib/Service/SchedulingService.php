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
use OCP\IL10N;

/**
 * Service for managing member scheduling preferences (availability and energy budget)
 */
class SchedulingService {
	/** Inclusive lower bound for a daily energy budget (CR-016-15). */
	public const ENERGY_BUDGET_MIN = 1;
	/** Inclusive upper bound for a daily energy budget (CR-016-15). */
	public const ENERGY_BUDGET_MAX = 20;

	private HouseUserMapper $houseUserMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private IL10N $l10n;
	private \OCA\ChoreStrike\Db\HouseMapper $houseMapper;
	private CalendarService $calendarService;

	public function __construct(
		HouseUserMapper $houseUserMapper,
		CompletedTaskMapper $completedTaskMapper,
		IL10N $l10n,
		\OCA\ChoreStrike\Db\HouseMapper $houseMapper,
		CalendarService $calendarService,
	) {
		$this->houseUserMapper = $houseUserMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->l10n = $l10n;
		$this->houseMapper = $houseMapper;
		$this->calendarService = $calendarService;
	}

	/**
	 * Assert a single energy-budget value is an integer within 1-20.
	 *
	 * Single source for the energy bounds (CR-016-15): the self-service
	 * bulk save, the owner member update, and the controller section
	 * validation all delegate here so a bounds change can never drift
	 * between the self path and the owner path.
	 *
	 * @param mixed $energy The candidate value
	 * @throws \InvalidArgumentException When the value is not an integer in 1-20
	 */
	public function assertValidEnergyBudget($energy): void {
		if (!is_int($energy) || $energy < self::ENERGY_BUDGET_MIN || $energy > self::ENERGY_BUDGET_MAX) {
			throw new \InvalidArgumentException($this->l10n->t('Energy budget must be between 1 and 20'));
		}
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
				$this->assertValidEnergyBudget($energy);
			}
			$preferences['energyBudget'] = self::normalizeWeekdayKeys($energyBudget);
		}

		if ($weekdayAvailability !== null) {
			$preferences['weekdayAvailability'] = self::normalizeWeekdayKeys($weekdayAvailability);
		}

		$user->setSchedulingPreferences($preferences);
		$this->houseUserMapper->update($user);

		return $this->buildPreferencesPayload($user, $user->getSchedulingPreferences());
	}

	/**
	 * Normalize weekday keys to lowercase before persisting (CS-PLAN-061
	 * §3.7): every reader looks up lowercase day keys, so a 'Monday' write
	 * must not silently disable the availability filter.
	 *
	 * @param array $section Section keyed by weekday
	 * @return array Section keyed by lowercase weekday
	 */
	public static function normalizeWeekdayKeys(array $section): array {
		$normalized = [];
		foreach ($section as $day => $value) {
			$normalized[strtolower((string)$day)] = $value;
		}
		return $normalized;
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

		// CS-PLAN-057: "today" is the house-local calendar day.
		$tz = $this->calendarService->getTimeZone($this->houseMapper->find($user->getHouseId()));
		[$dayStart, $dayEnd] = $this->calendarService->dayBounds(new \DateTime(), $tz);

		return [
			'userId' => $user->getId(),
			'energyBudget' => $preferences['energyBudget'] ?? $this->getDefaultEnergyBudget(),
			'weekdayAvailability' => $preferences['weekdayAvailability'] ?? $this->getDefaultAvailability(),
			'maxDailyTasks' => $preferences['maxDailyTasks'] ?? PHP_INT_MAX,
			'todayConsumedEnergy' => $this->completedTaskMapper->getConsumedEnergyForUserOnDate(
				$user->getId(),
				$dayStart,
				$dayEnd
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
				$this->assertValidEnergyBudget($energy);
				$preferences['energyBudget'][strtolower((string)$day)] = $energy;
			}
		}

		if (isset($updates['weekdayAvailability'])) {
			foreach ($updates['weekdayAvailability'] as $day => $available) {
				$preferences['weekdayAvailability'][strtolower((string)$day)] = (bool)$available;
			}
		}

		$user->setSchedulingPreferences($preferences);
		$this->houseUserMapper->update($user);
	}

	/**
	 * Get scheduling suggestions based on availability
	 *
	 * The caller only ever needed the number of schedulable tasks, so the
	 * contract carries `taskCount` instead of a full task array
	 * (CR-016-13c).
	 *
	 * @param int $taskCount Number of tasks to schedule
	 * @param int $houseId The house ID
	 * @return array Suggestions per day
	 */
	public function getSchedulingSuggestions(int $taskCount, int $houseId): array {
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
				'recommendedTasks' => min($taskCount, (int)($totalEnergy / 5)),
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
