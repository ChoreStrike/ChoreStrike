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
use OCP\IL10N;
use OCP\IRequest;

/**
 * API controller for the scheduling preferences: the personal and the
 * owner's per-member availability and energy surface. The owner's preview
 * and apply went with ADR-022 (CS-PLAN-091): nothing is applied as an
 * agreement any more.
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
	private IL10N $l;

	public function __construct(
		IRequest $request,
		SchedulingService $schedulingService,
		HouseService $houseService,
		AuthorizationService $authService,
		IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->schedulingService = $schedulingService;
		$this->houseService = $houseService;
		$this->authService = $authService;
		$this->l = $l;
		$this->initializeControllerLogger($appLogger);
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
			// 403, not 500 (CS-PLAN-061 §3.5): requireCurrentUserId throws the
			// ForbiddenException the catch below maps to a clean denial.
			$userId = $this->authService->requireCurrentUserId();

			$this->authService->assertHouseMember($house->getId());
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
	 * The personal endpoint can never change the child daily-task limit
	 * (decision D5) — a `maxDailyTasks` key is rejected outright.
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
			// 403, not 500 (CS-PLAN-061 §3.5): requireCurrentUserId throws the
			// ForbiddenException the catch below maps to a clean denial.
			$userId = $this->authService->requireCurrentUserId();

			$this->authService->assertHouseMember($house->getId());
			if ($this->request->getParam('maxDailyTasks') !== null) {
				throw new \InvalidArgumentException($this->l->t('maxDailyTasks can only be changed by a house owner'));
			}
			if ($weekdayAvailability === null && $energyBudget === null) {
				throw new \InvalidArgumentException($this->l->t('At least one section (weekdayAvailability or energyBudget) must be provided'));
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
			// Pure membership gate + owner requirement (ADR-010: bootstrap
			// happens on the designated endpoints only).
			$this->authService->assertHouseMember($house->getId());
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
	 * The payload may carry the member's `maxDailyTasks` child limit
	 * (decision D5): omitted = unchanged, `null` = unlimited, integer 1-100
	 * = finite limit.
	 *
	 * The closed top-level payload is read from the request parameters
	 * (RF-065-05): a dispatcher-resolved `array $updates` argument is bound
	 * to a body key literally named `updates`, which the documented
	 * top-level section contract never sends — every real HTTP call died on
	 * a TypeError (500) before the controller body ran.
	 *
	 * @NoAdminRequired
	 * @return JSONResponse
	 */
	public function updateMemberAvailability(string $userId): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			// Pure membership gate + owner requirement (ADR-010: bootstrap
			// happens on the designated endpoints only).
			$this->authService->assertHouseMember($house->getId());
			$this->authService->requireHouseOwner($house->getId());

			$updates = $this->memberUpdatesPayload();
			$this->validateMemberUpdates($updates);
			$this->schedulingService->updateMemberAvailabilityByNextcloudUserId($house->getId(), $userId, $updates);

			return new JSONResponse([
				'success' => true,
				'message' => $this->l->t('Member availability updated')
			]);
		} catch (\InvalidArgumentException $e) {
			// Curated weekday/limit messages — safe to surface verbatim.
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
	 * Validate that a day string is a recognized weekday name.
	 *
	 * @param string $day Weekday name (case-insensitive)
	 * @throws \InvalidArgumentException When the day is not a valid weekday
	 */
	private function validateWeekday(string $day): void {
		if (!in_array(strtolower($day), self::VALID_WEEKDAYS, true)) {
			throw new \InvalidArgumentException($this->l->t('Invalid weekday: %1$s', [$day]));
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

		// Case-insensitive (CS-PLAN-061 §3.7): the writer path lowercases
		// weekday keys before persisting, so validation must accept 'Monday'
		// exactly like 'monday' — otherwise the two paths diverge again.
		$keys = array_map(static fn ($key) => strtolower((string)$key), array_keys($section));
		foreach (array_diff($keys, self::VALID_WEEKDAYS) as $unknownDay) {
			throw new \InvalidArgumentException($this->l->t('Invalid weekday: %1$s', [$unknownDay]));
		}
		$missingDays = array_diff(self::VALID_WEEKDAYS, $keys);
		if (count($missingDays) > 0) {
			throw new \InvalidArgumentException($this->l->t('%1$s must contain all seven weekdays (missing: %2$s)', [$sectionName, implode(', ', $missingDays)]));
		}

		foreach ($section as $day => $value) {
			if ($sectionName === 'weekdayAvailability') {
				if (!is_bool($value)) {
					throw new \InvalidArgumentException($this->l->t('Availability values must be booleans'));
				}
			} else {
				// CR-016-15: bounds live in SchedulingService (single source).
				$this->schedulingService->assertValidEnergyBudget($value);
			}
		}
	}

	/**
	 * The closed member-update payload (RF-065-05): every top-level request
	 * key except the route internals (`userId`, `_route`). Unknown keys stay
	 * in the array so validateMemberUpdates rejects them (closed shape, D5);
	 * an explicit `maxDailyTasks: null` survives as a present key, an omitted
	 * one stays absent.
	 *
	 * @return array<string, mixed>
	 */
	private function memberUpdatesPayload(): array {
		$params = $this->request->getParams();
		unset($params['userId'], $params['_route']);
		return $params;
	}

	/**
	 * Validate the keys of a member-availability update payload: weekday
	 * names inside the availability/energy sections plus the optional
	 * `maxDailyTasks` child limit (decision D5).
	 *
	 * @param array $updates Availability/energy/limit updates
	 * @throws \InvalidArgumentException When any key or value is invalid
	 */
	private function validateMemberUpdates(array $updates): void {
		foreach (['weekdayAvailability', 'energyBudget'] as $section) {
			if (isset($updates[$section]) && is_array($updates[$section])) {
				foreach (array_keys($updates[$section]) as $day) {
					$this->validateWeekday((string)$day);
				}
			}
		}

		if (array_key_exists('maxDailyTasks', $updates) && $updates['maxDailyTasks'] !== null) {
			$this->schedulingService->assertValidMaxDailyTasks($updates['maxDailyTasks']);
		}

		$allowedKeys = ['weekdayAvailability', 'energyBudget', 'maxDailyTasks'];
		foreach (array_keys($updates) as $key) {
			if (!in_array($key, $allowedKeys, true)) {
				throw new \InvalidArgumentException($this->l->t('Unknown update field: %1$s', [(string)$key]));
			}
		}
	}
}
