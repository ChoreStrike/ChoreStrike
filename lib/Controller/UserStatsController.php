<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\HouseUserService;
use OCA\ChoreStrike\Service\UserStatsService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * API controller for user statistics and management
 */
class UserStatsController extends OCSController
{
    public function __construct(
        IRequest $request,
        private HouseService $houseService,
        private HouseUserService $houseUserService,
        private UserStatsService $userStatsService,
        private AuthorizationService $authService
    ) {
        parent::__construct('chorestrike', $request);
    }

    /**
     * Get leaderboard for current house
     *
     * @NoAdminRequired
     * @param int $limit Maximum number of users to return
     */
    public function leaderboard(int $limit = 10): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $leaderboard = $this->userStatsService->getLeaderboard($house->getId(), $limit);

            return new JSONResponse([
                'success' => true,
                'data' => array_map(fn ($stats) => $stats->jsonSerialize(), $leaderboard)
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'LEADERBOARD_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get streak leaderboard
     *
     * @NoAdminRequired
     * @param int $limit Maximum number of users to return
     */
    public function streakLeaderboard(int $limit = 10): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $leaderboard = $this->userStatsService->getStreakLeaderboard($house->getId(), $limit);

            return new JSONResponse([
                'success' => true,
                'data' => array_map(fn ($stats) => $stats->jsonSerialize(), $leaderboard)
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'LEADERBOARD_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get tasks completed leaderboard
     *
     * @NoAdminRequired
     * @param int $limit Maximum number of users to return
     */
    public function tasksLeaderboard(int $limit = 10): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $leaderboard = $this->userStatsService->getTasksLeaderboard($house->getId(), $limit);

            return new JSONResponse([
                'success' => true,
                'data' => array_map(fn ($stats) => $stats->jsonSerialize(), $leaderboard)
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'LEADERBOARD_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get user statistics
     *
     * @NoAdminRequired
     * @param string $userId User ID
     */
    public function userStats(string $userId): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $stats = $this->userStatsService->getOrCreateStats($house->getId(), $userId);

            return new JSONResponse([
                'success' => true,
                'data' => $stats->jsonSerialize()
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
     * Get all house users
     *
     * @NoAdminRequired
     */
    public function houseUsers(): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $users = $this->houseUserService->getHouseUsers($house->getId());

            return new JSONResponse([
                'success' => true,
                'data' => array_map(fn ($user) => $user->jsonSerialize(), $users)
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'USERS_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Add user to house
     *
     * @NoAdminRequired
     * @param string $userId User ID
     * @param string $displayName Display name
     * @param string $role User role (owner, member, child)
     */
    public function addUser(string $userId, string $displayName, string $role = 'member'): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseOwner($house->getId());

            if (empty($userId) || empty($displayName)) {
                return new JSONResponse([
                    'success' => false,
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'User ID and display name are required'
                    ]
                ], 400);
            }

            if (!in_array($role, ['owner', 'member', 'child'])) {
                return new JSONResponse([
                    'success' => false,
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'Invalid role'
                    ]
                ], 400);
            }

            $houseUser = $this->houseUserService->addUserToHouse($house->getId(), $userId, $displayName, $role);

            return new JSONResponse([
                'success' => true,
                'data' => $houseUser->jsonSerialize()
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'ADD_USER_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Remove user from house
     *
     * @NoAdminRequired
     * @param string $userId User ID
     */
    public function removeUser(string $userId): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseOwner($house->getId());

            $this->houseUserService->removeUserFromHouse($house->getId(), $userId);

            return new JSONResponse([
                'success' => true,
                'message' => 'User removed from house'
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'REMOVE_USER_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Update user role
     *
     * @NoAdminRequired
     * @param string $userId User ID
     * @param string $role New role
     */
    public function updateUserRole(string $userId, string $role): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseOwner($house->getId());

            if (!in_array($role, ['owner', 'member', 'child'])) {
                return new JSONResponse([
                    'success' => false,
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'Invalid role'
                    ]
                ], 400);
            }

            $houseUser = $this->houseUserService->updateUserRole($house->getId(), $userId, $role);

            return new JSONResponse([
                'success' => true,
                'data' => $houseUser->jsonSerialize()
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'UPDATE_ROLE_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }
}
