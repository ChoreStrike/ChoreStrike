<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\SmartSchedulerService;
use OCA\ChoreStrike\Service\SchedulingService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for smart scheduling operations
 */
class SmartSchedulerController extends Controller
{
    use ControllerLoggingTrait;

    private SmartSchedulerService $smartSchedulerService;
    private SchedulingService $schedulingService;
    private HouseService $houseService;
    private IUserSession $userSession;
    private IL10N $l;

    public function __construct(
        IRequest $request,
        SmartSchedulerService $smartSchedulerService,
        SchedulingService $schedulingService,
        HouseService $houseService,
        IUserSession $userSession,
        IL10N $l,
        ?AppLogger $appLogger = null
    ) {
        parent::__construct($request);
        $this->smartSchedulerService = $smartSchedulerService;
        $this->schedulingService = $schedulingService;
        $this->houseService = $houseService;
        $this->userSession = $userSession;
        $this->l = $l;
        if ($appLogger !== null) {
            $this->initializeControllerLogger($appLogger);
        }
    }

    /**
     * Get schedule preview for the next N days
     *
     * @NoAdminRequired
     * @param int $days Number of days to preview (default: 7)
     * @return DataResponse
     */
    public function getSchedulePreview(int $days = 7): DataResponse
    {
        try {
            $houseId = $this->getCurrentHouseId();

            if (!$houseId) {
                return new DataResponse(['error' => $this->l->t('No house found for current user')], Http::STATUS_NOT_FOUND);
            }

            $preview = $this->smartSchedulerService->getSchedulePreview($houseId, $days);

            return new DataResponse([
                'success' => true,
                'data' => $preview,
            ]);

        } catch (\Exception $e) {
            $this->logControllerException('getSchedulePreview', $e, ['days' => $days]);
            return new DataResponse([
                'success' => false,
                'error' => ControllerErrorFormatter::safeMessage($e),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Generate complete schedule for a date range
     *
     * @NoAdminRequired
     * @param string $startDate Start date (Y-m-d format)
     * @param string $endDate End date (Y-m-d format)
     * @return DataResponse
     */
    public function generateSchedule(string $startDate, string $endDate): DataResponse
    {
        try {
            $houseId = $this->getCurrentHouseId();

            if (!$houseId) {
                return new DataResponse(['error' => $this->l->t('No house found for current user')], Http::STATUS_NOT_FOUND);
            }

            $start = new \DateTime($startDate);
            $end = new \DateTime($endDate);

            // Validate date range
            if ($start >= $end) {
                return new DataResponse(['error' => $this->l->t('Start date must be before end date')], Http::STATUS_BAD_REQUEST);
            }

            // Limit to reasonable range (max 30 days)
            $daysDiff = $end->diff($start)->days;
            if ($daysDiff > 30) {
                return new DataResponse(['error' => $this->l->t('Schedule range cannot exceed 30 days')], Http::STATUS_BAD_REQUEST);
            }

            $schedule = $this->smartSchedulerService->generateSchedule($houseId, $start, $end);

            return new DataResponse([
                'success' => true,
                'data' => $schedule,
            ]);

        } catch (\Exception $e) {
            $this->logControllerException('generateSchedule', $e, ['startDate' => $startDate, 'endDate' => $endDate]);
            return new DataResponse([
                'success' => false,
                'error' => ControllerErrorFormatter::safeMessage($e),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get member availability for the current house
     *
     * @NoAdminRequired
     * @return DataResponse
     */
    public function getMemberAvailability(): DataResponse
    {
        try {
            $houseId = $this->getCurrentHouseId();

            if (!$houseId) {
                return new DataResponse(['error' => $this->l->t('No house found for current user')], Http::STATUS_NOT_FOUND);
            }

            $availability = $this->schedulingService->getMemberAvailability($houseId);

            return new DataResponse([
                'success' => true,
                'data' => $availability,
            ]);

        } catch (\Exception $e) {
            $this->logControllerException('getMemberAvailability', $e);
            return new DataResponse([
                'success' => false,
                'error' => ControllerErrorFormatter::safeMessage($e),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update current user's availability
     *
     * @NoAdminRequired
     * @param array $availability Availability data per weekday
     * @return DataResponse
     */
    public function updateMyAvailability(array $availability): DataResponse
    {
        try {
            $houseId = $this->getCurrentHouseId();
            $userId = $this->getCurrentUserId();

            if (!$houseId || !$userId) {
                return new DataResponse(['error' => $this->l->t('User or house not found')], Http::STATUS_NOT_FOUND);
            }

            // Validate availability format
            $validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            foreach ($availability as $day => $available) {
                if (!in_array($day, $validDays)) {
                    return new DataResponse([
                        'error' => $this->l->t('Invalid day: {day}', ['day' => $day]),
                    ], Http::STATUS_BAD_REQUEST);
                }
                if (!is_bool($available)) {
                    return new DataResponse([
                        'error' => $this->l->t('Availability must be boolean for {day}', ['day' => $day]),
                    ], Http::STATUS_BAD_REQUEST);
                }
            }

            // Ensure at least one day is available
            $hasAvailableDay = array_filter($availability, function ($available) {
                return $available === true;
            });

            if (empty($hasAvailableDay)) {
                return new DataResponse(['error' => $this->l->t('At least one day must be available')], Http::STATUS_BAD_REQUEST);
            }

            // Update availability for each day
            foreach ($availability as $day => $available) {
                $this->schedulingService->updateAvailabilityByNextcloudUserId($houseId, $userId, $day, $available);
            }

            return new DataResponse([
                'success' => true,
                'message' => $this->l->t('Availability updated successfully'),
            ]);

        } catch (\Exception $e) {
            $this->logControllerException('updateMyAvailability', $e, ['availabilityDays' => array_keys($availability)]);
            return new DataResponse([
                'success' => false,
                'error' => ControllerErrorFormatter::safeMessage($e),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update current user's energy budget
     *
     * @NoAdminRequired
     * @param array $energyBudget Energy budget per weekday
     * @return DataResponse
     */
    public function updateMyEnergyBudget(array $energyBudget): DataResponse
    {
        try {
            $houseId = $this->getCurrentHouseId();
            $userId = $this->getCurrentUserId();

            if (!$houseId || !$userId) {
                return new DataResponse(['error' => $this->l->t('User or house not found')], Http::STATUS_NOT_FOUND);
            }

            // Validate energy budget format
            $validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            foreach ($energyBudget as $day => $energy) {
                if (!in_array($day, $validDays)) {
                    return new DataResponse([
                        'error' => $this->l->t('Invalid day: {day}', ['day' => $day]),
                    ], Http::STATUS_BAD_REQUEST);
                }
                if (!is_int($energy) || $energy < 1 || $energy > 20) {
                    return new DataResponse([
                        'error' => $this->l->t('Energy must be between 1 and 20 for {day}', ['day' => $day]),
                    ], Http::STATUS_BAD_REQUEST);
                }
            }

            // Update energy budget for each day
            foreach ($energyBudget as $day => $energy) {
                $this->schedulingService->updateEnergyBudgetByNextcloudUserId($houseId, $userId, $day, $energy);
            }

            return new DataResponse([
                'success' => true,
                'message' => $this->l->t('Energy budget updated successfully'),
            ]);

        } catch (\Exception $e) {
            $this->logControllerException('updateMyEnergyBudget', $e, ['energyBudgetDays' => array_keys($energyBudget)]);
            return new DataResponse([
                'success' => false,
                'error' => ControllerErrorFormatter::safeMessage($e),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get scheduling suggestions for tasks
     *
     * @NoAdminRequired
     * @return DataResponse
     */
    public function getSchedulingSuggestions(): DataResponse
    {
        try {
            $houseId = $this->getCurrentHouseId();

            if (!$houseId) {
                return new DataResponse(['error' => $this->l->t('No house found for current user')], Http::STATUS_NOT_FOUND);
            }

            $startDate = new \DateTime('today');
            $endDate = (clone $startDate)->modify('+6 days');
            $schedule = $this->smartSchedulerService->generateSchedule($houseId, $startDate, $endDate);
            $tasks = $schedule['assignments'] ?? [];
            $suggestions = $this->schedulingService->getSchedulingSuggestions($tasks, $houseId);

            return new DataResponse([
                'success' => true,
                'data' => $suggestions,
            ]);

        } catch (\Exception $e) {
            $this->logControllerException('getSchedulingSuggestions', $e);
            return new DataResponse([
                'success' => false,
                'error' => ControllerErrorFormatter::safeMessage($e),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Apply a scheduling suggestion (persist task assignment)
     *
     * @NoAdminRequired
     * @param int $taskId The task ID to assign
     * @param string $assignedTo The Nextcloud user ID to assign the task to
     * @param string $scheduledFor The scheduled date (Y-m-d format)
     * @return DataResponse
     */
    public function applySuggestion(int $taskId, string $assignedTo, string $scheduledFor): DataResponse
    {
        try {
            $houseId = $this->getCurrentHouseId();

            if (!$houseId) {
                return new DataResponse(['error' => $this->l->t('No house found for current user')], Http::STATUS_NOT_FOUND);
            }

            // Apply the suggestion using the service
            $task = $this->smartSchedulerService->applySuggestion($houseId, $taskId, $assignedTo, $scheduledFor);

            return new DataResponse([
                'success' => true,
                'message' => $this->l->t('Suggestion applied successfully'),
                'data' => [
                    'taskId' => $task->getId(),
                    'assignedTo' => $task->getAssignedToUserId(),
                    'scheduledFor' => $task->getNextDue()?->format('Y-m-d'),
                ],
            ]);

        } catch (\InvalidArgumentException $e) {
            $this->logControllerWarning('Smart scheduling suggestion validation failed', [
                'action' => 'applySuggestion',
                'taskId' => $taskId,
                'assignedTo' => $assignedTo,
            ]);
            return new DataResponse([
                'success' => false,
                'error' => ControllerErrorFormatter::safeMessage($e),
            ], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logControllerException('applySuggestion', $e, ['taskId' => $taskId, 'assignedTo' => $assignedTo]);
            return new DataResponse([
                'success' => false,
                'error' => ControllerErrorFormatter::safeMessage($e),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get current user's house ID
     *
     * @throws \Exception if house could not be resolved
     */
    private function getCurrentHouseId(): ?int
    {
        $instanceId = $this->houseService->getInstanceId();
        $house = $this->houseService->getOrCreateHouse($instanceId);

        return $house->getId();
    }

    /**
     * Get current user ID
     */
    private function getCurrentUserId(): ?string
    {
        $user = $this->userSession->getUser();
        return $user ? $user->getUID() : null;
    }
}
