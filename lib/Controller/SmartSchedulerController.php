<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\SmartSchedulerService;
use OCA\ChoreStrike\Service\SchedulingService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for smart scheduling operations
 */
class SmartSchedulerController extends Controller {

	private SmartSchedulerService $smartSchedulerService;
	private SchedulingService $schedulingService;
	private HouseService $houseService;
	private IUserSession $userSession;

	public function __construct(
		IRequest $request,
		SmartSchedulerService $smartSchedulerService,
		SchedulingService $schedulingService,
		HouseService $houseService,
		IUserSession $userSession
	) {
		parent::__construct($request);
		$this->smartSchedulerService = $smartSchedulerService;
		$this->schedulingService = $schedulingService;
		$this->houseService = $houseService;
		$this->userSession = $userSession;
	}

	/**
	 * Get schedule preview for the next N days
	 *
	 * @PublicPage
	 * @param int $days Number of days to preview (default: 7)
	 * @return DataResponse
	 */
	public function getSchedulePreview(int $days = 7): DataResponse {
		try {
			$houseId = $this->getCurrentHouseId();
			
			if (!$houseId) {
				return new DataResponse(['error' => 'No house found for current user'], Http::STATUS_NOT_FOUND);
			}

			$preview = $this->smartSchedulerService->getSchedulePreview($houseId, $days);
			
			return new DataResponse([
				'success' => true,
				'data' => $preview,
			]);
			
		} catch (\Exception $e) {
			return new DataResponse([
				'success' => false,
				'error' => $e->getMessage(),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Generate complete schedule for a date range
	 *
	 * @PublicPage
	 * @param string $startDate Start date (Y-m-d format)
	 * @param string $endDate End date (Y-m-d format)
	 * @return DataResponse
	 */
	public function generateSchedule(string $startDate, string $endDate): DataResponse {
		try {
			$houseId = $this->getCurrentHouseId();
			
			if (!$houseId) {
				return new DataResponse(['error' => 'No house found for current user'], Http::STATUS_NOT_FOUND);
			}

			$start = new \DateTime($startDate);
			$end = new \DateTime($endDate);
			
			// Validate date range
			if ($start >= $end) {
				return new DataResponse(['error' => 'Start date must be before end date'], Http::STATUS_BAD_REQUEST);
			}
			
			// Limit to reasonable range (max 30 days)
			$daysDiff = $end->diff($start)->days;
			if ($daysDiff > 30) {
				return new DataResponse(['error' => 'Schedule range cannot exceed 30 days'], Http::STATUS_BAD_REQUEST);
			}

			$schedule = $this->smartSchedulerService->generateSchedule($houseId, $start, $end);
			
			return new DataResponse([
				'success' => true,
				'data' => $schedule,
			]);
			
		} catch (\Exception $e) {
			return new DataResponse([
				'success' => false,
				'error' => $e->getMessage(),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get member availability for the current house
	 *
	 * @PublicPage
	 * @return DataResponse
	 */
	public function getMemberAvailability(): DataResponse {
		try {
			$houseId = $this->getCurrentHouseId();
			
			if (!$houseId) {
				return new DataResponse(['error' => 'No house found for current user'], Http::STATUS_NOT_FOUND);
			}

			$availability = $this->schedulingService->getMemberAvailability($houseId);
			
			return new DataResponse([
				'success' => true,
				'data' => $availability,
			]);
			
		} catch (\Exception $e) {
			return new DataResponse([
				'success' => false,
				'error' => $e->getMessage(),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Update current user's availability
	 *
	 * @PublicPage
	 * @param array $availability Availability data per weekday
	 * @return DataResponse
	 */
	public function updateMyAvailability(array $availability): DataResponse {
		try {
			$houseId = $this->getCurrentHouseId();
			$userId = $this->getCurrentUserId();
			
			if (!$houseId || !$userId) {
				return new DataResponse(['error' => 'User or house not found'], Http::STATUS_NOT_FOUND);
			}

			// Validate availability format
			$validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
			foreach ($availability as $day => $available) {
				if (!in_array($day, $validDays)) {
					return new DataResponse(['error' => "Invalid day: {$day}"], Http::STATUS_BAD_REQUEST);
				}
				if (!is_bool($available)) {
					return new DataResponse(['error' => "Availability must be boolean for {$day}"], Http::STATUS_BAD_REQUEST);
				}
			}

			// Ensure at least one day is available
			$hasAvailableDay = array_filter($availability, function($available) {
				return $available === true;
			});
			
			if (empty($hasAvailableDay)) {
				return new DataResponse(['error' => 'At least one day must be available'], Http::STATUS_BAD_REQUEST);
			}

			// Update availability for each day
			foreach ($availability as $day => $available) {
				$this->schedulingService->updateAvailabilityByNextcloudUserId($houseId, $userId, $day, $available);
			}
			
			return new DataResponse([
				'success' => true,
				'message' => 'Availability updated successfully',
			]);
			
		} catch (\Exception $e) {
			return new DataResponse([
				'success' => false,
				'error' => $e->getMessage(),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Update current user's energy budget
	 *
	 * @PublicPage
	 * @param array $energyBudget Energy budget per weekday
	 * @return DataResponse
	 */
	public function updateMyEnergyBudget(array $energyBudget): DataResponse {
		try {
			$houseId = $this->getCurrentHouseId();
			$userId = $this->getCurrentUserId();
			
			if (!$houseId || !$userId) {
				return new DataResponse(['error' => 'User or house not found'], Http::STATUS_NOT_FOUND);
			}

			// Validate energy budget format
			$validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
			foreach ($energyBudget as $day => $energy) {
				if (!in_array($day, $validDays)) {
					return new DataResponse(['error' => "Invalid day: {$day}"], Http::STATUS_BAD_REQUEST);
				}
				if (!is_int($energy) || $energy < 1 || $energy > 20) {
					return new DataResponse(['error' => "Energy must be between 1 and 20 for {$day}"], Http::STATUS_BAD_REQUEST);
				}
			}

			// Update energy budget for each day
			foreach ($energyBudget as $day => $energy) {
				$this->schedulingService->updateEnergyBudgetByNextcloudUserId($houseId, $userId, $day, $energy);
			}
			
			return new DataResponse([
				'success' => true,
				'message' => 'Energy budget updated successfully',
			]);
			
		} catch (\Exception $e) {
			return new DataResponse([
				'success' => false,
				'error' => $e->getMessage(),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get scheduling suggestions for tasks
	 *
	 * @PublicPage
	 * @return DataResponse
	 */
	public function getSchedulingSuggestions(): DataResponse {
		try {
			$houseId = $this->getCurrentHouseId();
			
			if (!$houseId) {
				return new DataResponse(['error' => 'No house found for current user'], Http::STATUS_NOT_FOUND);
			}

			// Get unscheduled tasks (this would need to be implemented)
			$tasks = []; // TODO: Get actual unscheduled tasks
			$suggestions = $this->schedulingService->getSchedulingSuggestions($tasks, $houseId);
			
			return new DataResponse([
				'success' => true,
				'data' => $suggestions,
			]);
			
		} catch (\Exception $e) {
			return new DataResponse([
				'success' => false,
				'error' => $e->getMessage(),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get current user's house ID
	 *
	 * @throws \Exception if house could not be resolved
	 */
	private function getCurrentHouseId(): ?int {
		$instanceId = $this->houseService->getInstanceId();
		$house = $this->houseService->getOrCreateHouse($instanceId);

		return $house->getId();
	}

	/**
	 * Get current user ID
	 */
	private function getCurrentUserId(): ?string {
		$user = $this->userSession->getUser();
		return $user ? $user->getUID() : null;
	}
}
