<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCP\IUserSession;

/**
 * Service for authorization checks across the application.
 */
class AuthorizationService
{
    private HouseUserMapper $houseUserMapper;
    private IUserSession $userSession;
    private ?AppLogger $appLogger;

    public function __construct(
        HouseUserMapper $houseUserMapper,
        IUserSession $userSession,
        ?AppLogger $appLogger = null
    ) {
        $this->houseUserMapper = $houseUserMapper;
        $this->userSession = $userSession;
        $this->appLogger = $appLogger;
    }

    /**
     * Check if the current user is authenticated.
     */
    public function isAuthenticated(): bool
    {
        $user = $this->userSession->getUser();
        return $user !== null;
    }

    /**
     * Get the current Nextcloud user ID.
     */
    public function getCurrentUserId(): ?string
    {
        $user = $this->userSession->getUser();
        return $user?->getUID();
    }

    /**
     * Require an authenticated user and return the current user ID.
     *
     * @throws \RuntimeException
     */
    public function requireCurrentUserId(): string
    {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            $this->appLogger?->warning('Authorization check failed', [
                'service' => 'AuthorizationService',
                'action' => 'requireCurrentUserId',
            ]);
            throw new \RuntimeException('Not authenticated');
        }

        return $userId;
    }

    /**
     * Check if the current user is a member of the given house.
     */
    public function isHouseMember(int $houseId): bool
    {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return false;
        }

        try {
            $houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
            return $houseUser !== null;
        } catch (\Exception $e) {
            $this->appLogger?->warning('House membership lookup failed', [
                'service' => 'AuthorizationService',
                'action' => 'isHouseMember',
                'houseId' => $houseId,
                'userId' => $userId,
                'exception' => get_class($e),
            ]);
            return false;
        }
    }

    /**
     * Require the current user to be a house member.
     *
     * @throws \RuntimeException
     */
    public function requireHouseMember(int $houseId): HouseUser
    {
        $user = $this->userSession->getUser();
        $userId = $user?->getUID();
        if ($userId === null) {
            $this->appLogger?->warning('House membership required for anonymous access', [
                'service' => 'AuthorizationService',
                'action' => 'requireHouseMember',
                'houseId' => $houseId,
            ]);
            throw new \RuntimeException('Not authenticated');
        }

        $houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
        if ($houseUser !== null) {
            return $houseUser;
        }

        $existingUsers = $this->houseUserMapper->findByHouseId($houseId);
        $role = count($existingUsers) === 0 ? 'owner' : 'member';
        $displayName = $user->getDisplayName() ?: $userId;
        $this->appLogger?->info('House membership auto-created for current user', [
            'service' => 'AuthorizationService',
            'action' => 'requireHouseMember',
            'houseId' => $houseId,
            'userId' => $userId,
            'role' => $role,
        ]);

        return $this->houseUserMapper->createOrGet($houseId, $userId, $displayName, $role);
    }

    /**
     * Check if the current user has the owner role in the given house.
     */
    public function isHouseOwner(int $houseId): bool
    {
        try {
            $houseUser = $this->requireHouseMember($houseId);
            return $houseUser->isOwner();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Require the current user to be a house owner.
     *
     * @throws \RuntimeException
     */
    public function requireHouseOwner(int $houseId): HouseUser
    {
        $houseUser = $this->requireHouseMember($houseId);

        if (!$houseUser->isOwner()) {
            $this->appLogger?->warning('Owner role required', [
                'service' => 'AuthorizationService',
                'action' => 'requireHouseOwner',
                'houseId' => $houseId,
                'userId' => $houseUser->getNextcloudUserId(),
            ]);
            throw new \RuntimeException('Owner role required');
        }

        return $houseUser;
    }
}
