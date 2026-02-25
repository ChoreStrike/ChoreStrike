<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * API controller for house-related endpoints
 */
class HouseController extends OCSController {

	private HouseService $houseService;

	public function __construct(
		IRequest $request,
		HouseService $houseService
	) {
		parent::__construct('chorewars', $request);
		$this->houseService = $houseService;
	}

	/**
	 * Get current house
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function get(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			
			return new JSONResponse([
				'success' => true,
				'data' => $house->jsonSerialize()
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'HOUSE_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Update house name
	 *
	 * @PublicPage
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
			$updatedHouse = $this->houseService->updateHouseName($house->getId(), $name);
			
			return new JSONResponse([
				'success' => true,
				'data' => $updatedHouse->jsonSerialize()
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'HOUSE_UPDATE_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Get house statistics
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function stats(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$stats = $this->houseService->getHouseStats($house->getId());
			
			return new JSONResponse([
				'success' => true,
				'data' => [
					'house' => $stats['house']->jsonSerialize(),
					'stats' => $stats['stats']
				]
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'STATS_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Calculate and update house health
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function health(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$health = $this->houseService->calculateHouseHealth($house->getId());
			
			return new JSONResponse([
				'success' => true,
				'data' => [
					'houseHealth' => $health
				]
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'HEALTH_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Reset house to initial state
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function reset(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->houseService->resetHouse($house->getId());
			
			// Return updated house
			$updatedHouse = $this->houseService->getOrCreateHouse($instanceId);
			
			return new JSONResponse([
				'success' => true,
				'data' => $updatedHouse->jsonSerialize(),
				'message' => 'House has been reset to initial state'
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'RESET_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}
}
