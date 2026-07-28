<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\SchedulingService;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * API controller for scheduling preferences
 */
class SchedulingController extends OCSController
{
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
        ?AppLogger $appLogger = null
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
    public function getPreferences(): JSONResponse
    {
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
     * Update energy budget for a day
     *
     * @NoAdminRequired
     * @return JSONResponse
     */
    public function updateEnergy(string $day, int $energy): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $userId = $this->authService->getCurrentUserId();
            if ($userId === null) {
                throw new \Exception('Not authenticated');
            }

            $this->authService->ensureHouseMember($house->getId());
            $this->validateWeekday($day);
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
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 400);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
            $this->logControllerException('updateEnergy', $e, ['day' => $day]);
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
     * Update availability for a day
     *
     * @NoAdminRequired
     * @return JSONResponse
     */
    public function updateAvailability(string $day, bool $available): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $userId = $this->authService->getCurrentUserId();
            if ($userId === null) {
                throw new \Exception('Not authenticated');
            }

            $this->authService->ensureHouseMember($house->getId());
            $this->validateWeekday($day);
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
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 400);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
            $this->logControllerException('updateAvailability', $e, ['day' => $day]);
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
    public function getMemberAvailability(): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
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
    public function updateMemberAvailability(string $userId, array $updates): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseOwner($house->getId());

            $this->validateMemberUpdates($updates);
            $this->schedulingService->updateMemberAvailabilityByNextcloudUserId($house->getId(), $userId, $updates);

            return new JSONResponse([
                'success' => true,
                'message' => 'Member availability updated'
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 400);
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
     * @NoAdminRequired
     * @return JSONResponse
     */
    public function getSuggestions(array $tasks): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->ensureHouseMember($house->getId());

            $suggestions = $this->schedulingService->getSchedulingSuggestions($tasks, $house->getId());

            return new JSONResponse([
                'success' => true,
                'data' => $suggestions
            ]);
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
    private function validateWeekday(string $day): void
    {
        if (!in_array(strtolower($day), self::VALID_WEEKDAYS, true)) {
            throw new \InvalidArgumentException('Invalid weekday: ' . $day);
        }
    }

    /**
     * Validate the keys of a member-availability update payload.
     *
     * @param array $updates Availability/energy updates keyed by weekday
     * @throws \InvalidArgumentException When any key is not a valid weekday
     */
    private function validateMemberUpdates(array $updates): void
    {
        foreach (['weekdayAvailability', 'energyBudget'] as $section) {
            if (isset($updates[$section]) && is_array($updates[$section])) {
                foreach (array_keys($updates[$section]) as $day) {
                    $this->validateWeekday((string) $day);
                }
            }
        }
    }
}
