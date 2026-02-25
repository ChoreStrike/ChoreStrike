<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\SchedulingService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * API controller for scheduling preferences
 */
class SchedulingController extends OCSController {

	private SchedulingService $schedulingService;

	public function __construct(
		IRequest $request,
		SchedulingService $schedulingService
	) {
		parent::__construct('chorestrike', $request);
		$this->schedulingService = $schedulingService;
	}

	/**
	 * Get current user's scheduling preferences
	 *
	 * @NoAdminRequired
	 */
	public function getPreferences(): JSONResponse {
		try {
			$userId = $this->getUserId();
			$preferences = $this->schedulingService->getUserPreferences($userId);

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
			$userId = $this->getUserId();
			$this->schedulingService->updateEnergyBudget($userId, $day, $energy);

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
			$userId = $this->getUserId();
			$this->schedulingService->updateAvailability($userId, $day, $available);

			return new JSONResponse([
				'success' => true,
				'message' => 'Availability updated'
			]);
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
			$houseId = $this->getHouseId();
			$availability = $this->schedulingService->getMemberAvailability($houseId);

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
			$this->schedulingService->updateMemberAvailability($userId, $updates);

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
			$houseId = $this->getHouseId();
			$suggestions = $this->schedulingService->getSchedulingSuggestions($tasks, $houseId);

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

	/**
	 * Get current user ID
	 */
	private function getUserId(): int {
		return 1; // Placeholder
	}

	/**
	 * Get current house ID
	 */
	private function getHouseId(): int {
		return 1; // Placeholder
	}
}
