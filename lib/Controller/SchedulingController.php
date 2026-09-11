<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\SchedulePlanService;
use OCA\ChoreStrike\Service\SchedulingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * API controller for scheduling preferences and the owner plan aggregate
 * (CS-PLAN-065, ADR-012): personal/member preference surface plus the
 * owner-only preview (`POST /scheduling/suggestions`) and apply
 * (`POST /scheduling/apply`) operations.
 */
class SchedulingController extends Controller {
	use ControllerLoggingTrait;

	/** @var list<string> */
	private const VALID_WEEKDAYS = [
		'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
	];

	private SchedulingService $schedulingService;
	private SchedulePlanService $schedulePlanService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IL10N $l;

	public function __construct(
		IRequest $request,
		SchedulingService $schedulingService,
		SchedulePlanService $schedulePlanService,
		HouseService $houseService,
		AuthorizationService $authService,
		IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->schedulingService = $schedulingService;
		$this->schedulePlanService = $schedulePlanService;
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
	 * Preview the deterministic plan over the selected real room tasks
	 * (owner only, CS-PLAN-065 / ADR-012).
	 *
	 * The retired taskCount-only advice contract is gone: a legacy payload
	 * without `startDate`/`roomTaskIds` fails the closed shape validation
	 * with a 400 — no compatibility fallback.
	 *
	 * @NoAdminRequired
	 * @return JSONResponse
	 */
	public function preview(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			[$startDate, $roomTaskIds, $overrides] = $this->parsePlanRequest();

			$plan = $this->schedulePlanService->preview(
				$house->getId(),
				$houseUser,
				$startDate,
				$roomTaskIds,
				$overrides
			);

			return new JSONResponse([
				'success' => true,
				'data' => $plan
			]);
		} catch (\InvalidArgumentException $e) {
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('preview', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'PLAN_PREVIEW_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Apply the confirmed plan as one all-or-nothing aggregate (owner only).
	 *
	 * Request: the exact normalized preview input plus its
	 * `previewVersion`. A stale confirmation is a 409; a confirmed no-change
	 * plan performs zero writes.
	 *
	 * @NoAdminRequired
	 * @return JSONResponse
	 */
	public function apply(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			[$startDate, $roomTaskIds, $overrides] = $this->parsePlanRequest();
			$previewVersion = $this->request->getParam('previewVersion');
			if (!is_string($previewVersion) || $previewVersion === '') {
				throw new \InvalidArgumentException($this->l->t('previewVersion is required'));
			}

			$result = $this->schedulePlanService->apply(
				$house->getId(),
				$houseUser,
				$startDate,
				$roomTaskIds,
				$overrides,
				$previewVersion
			);

			return new JSONResponse([
				'success' => true,
				'data' => $result
			]);
		} catch (\InvalidArgumentException $e) {
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('apply', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'PLAN_APPLY_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Parse the shared preview/apply request shape: `startDate`,
	 * unique positive `roomTaskIds` (bounded), optional per-row `overrides`
	 * and (apply only) `previewVersion`.
	 *
	 * @return array{0: string, 1: list<int>, 2: array<int, array{assigneeUserIds?: list<string>, date?: string}>}
	 */
	private function parsePlanRequest(): array {
		$startDate = $this->request->getParam('startDate');
		if (!is_string($startDate)) {
			throw new \InvalidArgumentException($this->l->t('startDate must be a valid Y-m-d date'));
		}

		$rawIds = $this->request->getParam('roomTaskIds');
		if (!is_array($rawIds)) {
			throw new \InvalidArgumentException($this->l->t('roomTaskIds must be an array of room task IDs'));
		}
		$roomTaskIds = [];
		foreach ($rawIds as $rawId) {
			if (!is_numeric($rawId) || (int)$rawId < 1) {
				throw new \InvalidArgumentException($this->l->t('roomTaskIds must contain positive integers'));
			}
			$roomTaskIds[] = (int)$rawId;
		}

		$rawOverrides = $this->request->getParam('overrides');
		$overrides = [];
		if ($rawOverrides !== null) {
			if (!is_array($rawOverrides)) {
				throw new \InvalidArgumentException($this->l->t('overrides must be an object keyed by room task ID'));
			}
			foreach ($rawOverrides as $overrideId => $override) {
				if (!is_numeric($overrideId) || (int)$overrideId < 1) {
					throw new \InvalidArgumentException($this->l->t('Overrides must reference a selected room task'));
				}
				if (!is_array($override)) {
					throw new \InvalidArgumentException($this->l->t('Each override must be an object'));
				}
				$clean = [];
				if (array_key_exists('assigneeUserIds', $override)) {
					if (!is_array($override['assigneeUserIds'])) {
						throw new \InvalidArgumentException($this->l->t('assigneeUserIds must be a non-empty array of user IDs'));
					}
					$clean['assigneeUserIds'] = $override['assigneeUserIds'];
				}
				if (array_key_exists('date', $override)) {
					$clean['date'] = $override['date'];
				}
				$overrides[(int)$overrideId] = $clean;
			}
		}

		return [$startDate, $roomTaskIds, $overrides];
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
