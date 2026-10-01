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
use OCA\ChoreStrike\Service\ActivityService;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * API controller for house-related endpoints
 */
class HouseController extends Controller {
	use ControllerLoggingTrait;

	private HouseService $houseService;
	private AuthorizationService $authService;
	private ActivityService $activityService;
	private IL10N $l;
	private \OCA\ChoreStrike\Service\CalendarService $calendarService;

	public function __construct(
		IRequest $request,
		HouseService $houseService,
		AuthorizationService $authService,
		ActivityService $activityService,
		IL10N $l,
		AppLogger $appLogger,
		\OCA\ChoreStrike\Service\CalendarService $calendarService,
	) {
		parent::__construct('chorestrike', $request);
		$this->houseService = $houseService;
		$this->authService = $authService;
		$this->activityService = $activityService;
		$this->l = $l;
		$this->calendarService = $calendarService;
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * Get current house
	 *
	 * @NoAdminRequired
	 */
	public function get(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			// Designated bootstrap endpoint (ADR-010, D1): reading the house
			// is a first-journey entry point, so a fresh instance bootstraps
			// its first owner here. Afterwards membership is invite-only.
			$this->authService->ensureHouseMember($house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => $house->jsonSerialize()
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('get', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'HOUSE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Update house name, (optionally) timezone and (optionally) the
	 * owner-only ranking flag (CS-PLAN-070 WP4, C5i/D10b).
	 *
	 * @NoAdminRequired
	 */
	public function update(string $name, string $timezone = '', ?bool $rankingEnabled = null): JSONResponse {
		try {
			if (empty(trim($name))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('House name is required')
					]
				], 400);
			}

			// CS-PLAN-057: closed-set validation against the IANA identifier
			// list — unknown ids are rejected, empty means "leave unchanged".
			if ($timezone !== '' && !$this->calendarService->isValidTimeZone($timezone)) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('Unknown timezone')
					]
				], 400);
			}

			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);

			// Pure membership gate (ADR-010: bootstrap happens on the
			// designated endpoints); the owner check below denies everyone else.
			$this->authService->assertHouseMember($house->getId());

			// Require house ownership for update
			$this->authService->requireHouseOwner($house->getId());

			$updatedHouse = $this->houseService->updateHouse(
				$house->getId(),
				$name,
				$timezone !== '' ? $timezone : null,
				$rankingEnabled
			);

			return new JSONResponse([
				'success' => true,
				'data' => $updatedHouse->jsonSerialize()
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			// Log hygiene (CS-PLAN-061 §3.6): the house name is user-authored
			// content — only stable context keys belong in the log.
			$this->logControllerException('update', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'HOUSE_UPDATE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Owner-only reward-catalog gate (CS-PLAN-070 WP7, C4; CS-PLAN-078
	 * §3.3): the narrow versioned writer — `expectedVersion` is the house
	 * `stateVersion`, `commandId` makes the toggle idempotent. Personal
	 * visibility preferences and existing requests are never touched.
	 *
	 * @NoAdminRequired
	 */
	public function updateCatalog(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->assertHouseMember($house->getId());
			$this->authService->requireHouseOwner($house->getId());

			$catalogEnabled = $this->request->getParam('catalogEnabled');
			if (!is_bool($catalogEnabled)) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('catalogEnabled must be a boolean')],
				], 400);
			}
			$expectedVersionRaw = $this->request->getParam('expectedVersion');
			if (!is_int($expectedVersionRaw) || $expectedVersionRaw < 0) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('A valid expectedVersion is required')],
				], 400);
			}
			$commandId = (string)$this->request->getParam('commandId', '');
			if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $commandId)) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('A valid commandId (UUID) is required')],
				], 400);
			}

			$this->houseService->setCatalogEnabled($house->getId(), $catalogEnabled, $expectedVersionRaw, strtolower($commandId));

			$updatedHouse = $this->houseService->getOrCreateHouse($instanceId);
			return new JSONResponse([
				'success' => true,
				'data' => $updatedHouse->jsonSerialize(),
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('updateCatalog', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'HOUSE_UPDATE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get house statistics
	 *
	 * @NoAdminRequired
	 */
	public function stats(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);

			// Require house membership
			$this->authService->assertHouseMember($house->getId());

			$stats = $this->houseService->getHouseStats($house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => [
					'house' => $stats['house']->jsonSerialize(),
					'stats' => $stats['stats']
				]
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('stats', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'STATS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get the house completion activity feed ("who did what when",
	 * CS-PLAN-040 / FEAT-UX-016). Also the per-member completed-energy
	 * source for the Insights fairness table.
	 *
	 * @NoAdminRequired
	 */
	public function activity(?int $days = null, ?int $limit = null, ?string $userId = null): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);

			// Require house membership
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$data = $this->activityService->getActivity(
				$house->getId(),
				$days ?? ActivityService::DEFAULT_DAYS,
				$limit ?? ActivityService::DEFAULT_LIMIT,
				$userId,
				$houseUser,
			);

			return new JSONResponse([
				'success' => true,
				'data' => $data
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (NotFoundException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'USER_NOT_FOUND',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 404);
		} catch (\Exception $e) {
			$this->logControllerException('activity', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ACTIVITY_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Calculate and update house health
	 *
	 * @NoAdminRequired
	 */
	public function health(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);

			// Require house membership
			$this->authService->assertHouseMember($house->getId());

			$health = $this->houseService->calculateHouseHealth($house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => [
					'houseHealth' => $health
				]
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('health', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'HEALTH_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Reset house to initial state
	 *
	 * @NoAdminRequired
	 */
	public function reset(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);

			// Pure membership gate + owner requirement (ADR-010: bootstrap
			// happens on the designated endpoints only).
			$this->authService->assertHouseMember($house->getId());

			// Require house ownership for reset
			$this->authService->requireHouseOwner($house->getId());

			// WP3 §3.6: the reset is a receipt-protected command — the
			// journal and the receipt commit together and a replayed
			// command never re-resets over later work.
			$commandId = (string)$this->request->getParam('commandId', '');
			if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $commandId)) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('A valid commandId (UUID) is required')],
				], 400);
			}
			$expectedVersionRaw = $this->request->getParam('expectedVersion');
			$expectedVersion = null;
			if ($expectedVersionRaw !== null && $expectedVersionRaw !== '') {
				if (!is_numeric($expectedVersionRaw) || (int)$expectedVersionRaw < 0) {
					return new JSONResponse([
						'success' => false,
						'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('A valid expectedVersion is required')],
					], 400);
				}
				$expectedVersion = (int)$expectedVersionRaw;
			}

			$this->houseService->resetHouse($house->getId(), strtolower($commandId), $expectedVersion);

			// Return updated house
			$updatedHouse = $this->houseService->getOrCreateHouse($instanceId);

			return new JSONResponse([
				'success' => true,
				'data' => $updatedHouse->jsonSerialize(),
				'message' => $this->l->t('House has been reset to initial state')
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('reset', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'RESET_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}
}
