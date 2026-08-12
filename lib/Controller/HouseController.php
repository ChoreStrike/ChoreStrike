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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * API controller for house-related endpoints
 */
class HouseController extends Controller {
	use ControllerLoggingTrait;

	private HouseService $houseService;
	private AuthorizationService $authService;

	public function __construct(
		IRequest $request,
		HouseService $houseService,
		AuthorizationService $authService,
		?AppLogger $appLogger = null,
	) {
		parent::__construct('chorestrike', $request);
		$this->houseService = $houseService;
		$this->authService = $authService;
		if ($appLogger !== null) {
			$this->initializeControllerLogger($appLogger);
		}
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
	 * Update house name
	 *
	 * @NoAdminRequired
	 */
	public function update(string $name): JSONResponse {
		try {
			if (empty(trim($name))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'House name is required'
					]
				], 400);
			}

			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);

			// CR-SVC-47: bootstrap membership before the owner check so the
			// first user on a fresh instance becomes the owner (pre-CS-025
			// behaviour). requireHouseOwner itself stays read-only (CR-SVC-06).
			$this->authService->ensureHouseMember($house->getId());

			// Require house ownership for update
			$this->authService->requireHouseOwner($house->getId());

			$updatedHouse = $this->houseService->updateHouseName($house->getId(), $name);

			return new JSONResponse([
				'success' => true,
				'data' => $updatedHouse->jsonSerialize()
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('update', $e, ['houseName' => $name]);
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
			$this->authService->ensureHouseMember($house->getId());

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
	 * Calculate and update house health
	 *
	 * @NoAdminRequired
	 */
	public function health(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);

			// Require house membership
			$this->authService->ensureHouseMember($house->getId());

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

			// CR-SVC-47: bootstrap membership before the owner check so the
			// first user on a fresh instance becomes the owner.
			$this->authService->ensureHouseMember($house->getId());

			// Require house ownership for reset
			$this->authService->requireHouseOwner($house->getId());

			$this->houseService->resetHouse($house->getId());

			// Return updated house
			$updatedHouse = $this->houseService->getOrCreateHouse($instanceId);

			return new JSONResponse([
				'success' => true,
				'data' => $updatedHouse->jsonSerialize(),
				'message' => 'House has been reset to initial state'
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
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
