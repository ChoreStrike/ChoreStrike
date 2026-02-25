<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\SchedulingService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * API controller for scheduling preferences
 */
class SchedulingController extends OCSController {

	private SchedulingService $schedulingService;
	private HouseService $houseService;
	private AuthorizationService $authService;

	public function __construct(
		IRequest $request,
		SchedulingService $schedulingService,
		HouseService $houseService,
		AuthorizationService $authService
	) {
		parent::__construct('chorestrike', $request);
		$this->schedulingService = $schedulingService;
		$this->houseService = $houseService;
		$this->authService = $authService;
	}

	/**
	 * Get current user's scheduling preferences
	 *
	 * @NoAdminRequired
	 */
	public function getPreferences(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$userId = $this->authService->getCurrentUserId();
			if ($userId === null) {
				throw new \Exception('Not authenticated');
			}

			$this->authService->requireHouseMember($house->getId());
			$preferences = $this->schedulingService->getUserPreferencesByNextcloudUserId($house->getId(), $userId);

			return new JSONResponse([
				'success' => true,
				'data' => $preferences
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'PREFERENCES_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Update energy budget for a day
	 *
	 * @NoAdminRequired
	 */
	public function updateEnergy(string $day, int $energy): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$userId = $this->authService->getCurrentUserId();
			if ($userId === null) {
				throw new \Exception('Not authenticated');
			}

			$this->authService->requireHouseMember($house->getId());
			$this->schedulingService->updateEnergyBudgetByNextcloudUserId($house->getId(), $userId, $day, $energy);

			return new JSONResponse([
				'success' => true,
				'message' => 'Energy budget updated'
			]);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'VALIDATION_ERROR',
					'message' => $e->getMessage()
				]
			], 400);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'UPDATE_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Update availability for a day
	 *
	 * @NoAdminRequired
	 */
	public function updateAvailability(string $day, bool $available): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$userId = $this->authService->getCurrentUserId();
			if ($userId === null) {
				throw new \Exception('Not authenticated');
			}

			$this->authService->requireHouseMember($house->getId());
			$this->schedulingService->updateAvailabilityByNextcloudUserId($house->getId(), $userId, $day, $available);

			return new JSONResponse([
				'success' => true,
				'message' => 'Availability updated'
			]);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'VALIDATION_ERROR',
					'message' => $e->getMessage()
				]
			], 400);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'UPDATE_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Get all member availability (house owner only)
	 *
	 * @NoAdminRequired
	 */
	public function getMemberAvailability(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseOwner($house->getId());
			
			$availability = $this->schedulingService->getMemberAvailability($house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => $availability
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'MEMBER_AVAILABILITY_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Update member availability (admin only)
	 *
	 * @NoAdminRequired
	 */
	public function updateMemberAvailability(int $userId, array $updates): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseOwner($house->getId());

			$this->schedulingService->updateMemberAvailability($house->getId(), $userId, $updates);

			return new JSONResponse([
				'success' => true,
				'message' => 'Member availability updated'
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'UPDATE_MEMBER_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Get scheduling suggestions
	 *
	 * @NoAdminRequired
	 */
	public function getSuggestions(array $tasks): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());
			
			$suggestions = $this->schedulingService->getSchedulingSuggestions($tasks, $house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => $suggestions
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'SUGGESTIONS_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

}
