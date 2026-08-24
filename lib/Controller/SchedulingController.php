<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\SchedulingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * API controller for scheduling preferences
 */
class SchedulingController extends Controller {
	use ControllerLoggingTrait;

	/** @var list<string> */
	private const VALID_WEEKDAYS = [
		'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
	];

	private SchedulingService $schedulingService;
	private HouseService $houseService;
	private AuthorizationService $authService;

	public function __construct(
		IRequest $request,
		SchedulingService $schedulingService,
		HouseService $houseService,
		AuthorizationService $authService,
		?AppLogger $appLogger = null,
	) {
		parent::__construct('chorestrike', $request);
		$this->schedulingService = $schedulingService;
		$this->houseService = $houseService;
		$this->authService = $authService;
		if ($appLogger !== null) {
			$this->initializeControllerLogger($appLogger);
		}
	}

	/**
	 * Get current user's scheduling preferences
	 *
	 * @NoAdminRequired
	 * @return JSONResponse
	 */
	public function getPreferences(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$userId = $this->authService->getCurrentUserId();
			if ($userId === null) {
				throw new \Exception('Not authenticated');
			}

			$this->authService->ensureHouseMember($house->getId());
			$preferences = $this->schedulingService->getUserPreferencesByNextcloudUserId($house->getId(), $userId);

			return new JSONResponse([
				'success' => true,
				'data' => $preferences
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('getPreferences', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'PREFERENCES_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Update the current user's scheduling preferences in bulk
	 *
	 * Each provided section must be a complete week (exact 7-day key set);
	 * absent sections are left unchanged. At least one section is required.
	 *
	 * @NoAdminRequired
	 * @param array|null $weekdayAvailability Full week of availability flags
	 * @param array|null $energyBudget Full week of energy budgets (1-20)
	 * @return JSONResponse
	 */
	public function updatePreferences(?array $weekdayAvailability, ?array $energyBudget): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$userId = $this->authService->getCurrentUserId();
			if ($userId === null) {
				throw new \Exception('Not authenticated');
			}

			$this->authService->ensureHouseMember($house->getId());
			if ($weekdayAvailability === null && $energyBudget === null) {
				throw new \InvalidArgumentException('At least one section (weekdayAvailability or energyBudget) must be provided');
			}
			$this->validatePreferencesSection($weekdayAvailability, 'weekdayAvailability');
			$this->validatePreferencesSection($energyBudget, 'energyBudget');
			$preferences = $this->schedulingService->updatePreferencesByNextcloudUserId(
				$house->getId(),
				$userId,
				$weekdayAvailability,
				$energyBudget
			);

			return new JSONResponse([
				'success' => true,
				'data' => $preferences
			]);
		} catch (\InvalidArgumentException $e) {
			// Curated section/weekday/bounds messages — safe to surface verbatim.
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('updatePreferences', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'UPDATE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get all member availability (house owner only)
	 *
	 * @NoAdminRequired
	 * @return JSONResponse
	 */
	public function getMemberAvailability(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			// CR-SVC-47: bootstrap membership before the owner check so the
			// first user on a fresh instance becomes the owner.
			$this->authService->ensureHouseMember($house->getId());
			$this->authService->requireHouseOwner($house->getId());

			$availability = $this->schedulingService->getMemberAvailability($house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => $availability
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('getMemberAvailability', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'MEMBER_AVAILABILITY_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Update member availability (house owner only)
	 *
	 * @NoAdminRequired
	 * @return JSONResponse
	 */
	public function updateMemberAvailability(string $userId, array $updates): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			// CR-SVC-47: bootstrap membership before the owner check so the
			// first user on a fresh instance becomes the owner.
			$this->authService->ensureHouseMember($house->getId());
			$this->authService->requireHouseOwner($house->getId());

			$this->validateMemberUpdates($updates);
			$this->schedulingService->updateMemberAvailabilityByNextcloudUserId($house->getId(), $userId, $updates);

			return new JSONResponse([
				'success' => true,
				'message' => 'Member availability updated'
			]);
		} catch (\InvalidArgumentException $e) {
			// Curated weekday message — safe to surface verbatim.
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('updateMemberAvailability', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'UPDATE_MEMBER_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get scheduling suggestions
	 *
	 * Accepts only the number of schedulable tasks — the backend never used
	 * more than `count($tasks)` (CR-016-13c).
	 *
	 * @NoAdminRequired
	 * @return JSONResponse
	 */
	public function getSuggestions(int $taskCount): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());

			if ($taskCount < 0) {
				throw new \InvalidArgumentException('taskCount must not be negative');
			}

			$suggestions = $this->schedulingService->getSchedulingSuggestions($taskCount, $house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => $suggestions
			]);
		} catch (\InvalidArgumentException $e) {
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('getSuggestions', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'SUGGESTIONS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Validate that a day string is a recognized weekday name.
	 *
	 * @param string $day Weekday name (case-insensitive)
	 * @throws \InvalidArgumentException When the day is not a valid weekday
	 */
	private function validateWeekday(string $day): void {
		if (!in_array(strtolower($day), self::VALID_WEEKDAYS, true)) {
			throw new \InvalidArgumentException('Invalid weekday: ' . $day);
		}
	}

	/**
	 * Validate one bulk-update section: a provided section must be exactly
	 * the 7 canonical weekday keys (no extra, no missing) and carry only
	 * bool availability flags or int energy budgets within 1-20.
	 *
	 * @param array|null $section Section payload keyed by weekday (null = absent)
	 * @param string $sectionName 'weekdayAvailability' or 'energyBudget'
	 * @throws \InvalidArgumentException When the section is not a complete, well-typed week
	 */
	private function validatePreferencesSection(?array $section, string $sectionName): void {
		if ($section === null) {
			return;
		}

		$keys = array_map('strval', array_keys($section));
		foreach (array_diff($keys, self::VALID_WEEKDAYS) as $unknownDay) {
			throw new \InvalidArgumentException('Invalid weekday: ' . $unknownDay);
		}
		$missingDays = array_diff(self::VALID_WEEKDAYS, $keys);
		if (count($missingDays) > 0) {
			throw new \InvalidArgumentException($sectionName . ' must contain all seven weekdays (missing: ' . implode(', ', $missingDays) . ')');
		}

		foreach ($section as $day => $value) {
			if ($sectionName === 'weekdayAvailability') {
				if (!is_bool($value)) {
					throw new \InvalidArgumentException('Availability values must be booleans');
				}
			} elseif (!is_int($value) || $value < 1 || $value > 20) {
				throw new \InvalidArgumentException('Energy budget must be between 1 and 20');
			}
		}
	}

	/**
	 * Validate the keys of a member-availability update payload.
	 *
	 * @param array $updates Availability/energy updates keyed by weekday
	 * @throws \InvalidArgumentException When any key is not a valid weekday
	 */
	private function validateMemberUpdates(array $updates): void {
		foreach (['weekdayAvailability', 'energyBudget'] as $section) {
			if (isset($updates[$section]) && is_array($updates[$section])) {
				foreach (array_keys($updates[$section]) as $day) {
					$this->validateWeekday((string)$day);
				}
			}
		}
	}
}
