<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCP\IUserSession;

/**
 * Service for authorization checks across the application.
 *
 * Two membership primitives:
 *  - {@see ensureHouseMember()} MUTATES: it auto-creates a membership on first
 *    access (the bootstrap / join flow behind getOrCreateHouse). Use it only
 *    where auto-join is the intended behaviour.
 *  - {@see assertHouseMember()} is a pure, read-only check that throws
 *    {@see ForbiddenException} on a miss without side effects. Use it for
 *    ordinary authorization gates.
 *
 * All "require*" / "assert*" methods throw {@see ForbiddenException} so the
 * controllers can translate a denial into a 403 instead of a 500.
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
     * @throws ForbiddenException if there is no authenticated user
     */
    public function requireCurrentUserId(): string
    {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            $this->appLogger?->warning('Authorization check failed', [
                'service' => 'AuthorizationService',
                'action' => 'requireCurrentUserId',
            ]);
            throw new ForbiddenException('Not authenticated');
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
     * Ensure the current user is a member of the given house, auto-creating
     * the membership on first access (bootstrap / join flow).
     *
     * This MUTATES state on a miss: the first authenticated user to reach a
     * house becomes its owner, subsequent users become members. It must only
     * be used where auto-join is intended (the getOrCreateHouse flows). For a
     * pure authorization gate use {@see assertHouseMember()} instead.
     *
     * @throws ForbiddenException if there is no authenticated user
     */
    public function ensureHouseMember(int $houseId): HouseUser
    {
        $user = $this->userSession->getUser();
        $userId = $user?->getUID();
        if ($userId === null) {
            $this->appLogger?->warning('House membership required for anonymous access', [
                'service' => 'AuthorizationService',
                'action' => 'ensureHouseMember',
                'houseId' => $houseId,
            ]);
            throw new ForbiddenException('Not authenticated');
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
            'action' => 'ensureHouseMember',
            'houseId' => $houseId,
            'userId' => $userId,
            'role' => $role,
        ]);

        return $this->houseUserMapper->createOrGet($houseId, $userId, $displayName, $role);
    }

    /**
     * Assert (pure, read-only check) that the current user is a member of the
     * given house.
     *
     * Unlike {@see ensureHouseMember()} this NEVER auto-creates a membership:
     * a miss is an authorization failure. Use this for ordinary access gates.
     *
     * @throws ForbiddenException if the user is anonymous or not a member
     */
    public function assertHouseMember(int $houseId): HouseUser
    {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            $this->appLogger?->warning('House membership asserted for anonymous access', [
                'service' => 'AuthorizationService',
                'action' => 'assertHouseMember',
                'houseId' => $houseId,
            ]);
            throw new ForbiddenException('Not authenticated');
        }

        $houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
        if ($houseUser === null) {
            $this->appLogger?->securityWarning('House membership assertion failed', [
                'service' => 'AuthorizationService',
                'action' => 'assertHouseMember',
                'houseId' => $houseId,
                'userId' => $userId,
            ]);
            throw new ForbiddenException('Not a member of this house');
        }

        return $houseUser;
    }

    /**
     * Check if the current user has the owner role in the given house.
     *
     * Read-only: never auto-creates a membership (CR-SVC-06). A non-member is
     * simply reported as not-an-owner.
     */
    public function isHouseOwner(int $houseId): bool
    {
        try {
            $houseUser = $this->assertHouseMember($houseId);
            return $houseUser->isOwner();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Require the current user to be a house owner.
     *
     * Read-only: never auto-creates a membership (CR-SVC-06). Callers must
     * have ensured membership upstream (getOrCreateHouse + ensureHouseMember)
     * so the happy path is unchanged; a non-member now gets a 403 instead of a
     * phantom member row followed by a 403.
     *
     * @throws ForbiddenException if the user is not authenticated, not a member, or not an owner
     */
    public function requireHouseOwner(int $houseId): HouseUser
    {
        $houseUser = $this->assertHouseMember($houseId);

        if (!$houseUser->isOwner()) {
            $this->appLogger?->warning('Owner role required', [
                'service' => 'AuthorizationService',
                'action' => 'requireHouseOwner',
                'houseId' => $houseId,
                'userId' => $houseUser->getNextcloudUserId(),
            ]);
            throw new ForbiddenException('Owner role required');
        }

        return $houseUser;
    }

    /**
     * Assert that the given Nextcloud user ID is a member of the given house.
     *
     * Used to prevent cross-house data access on endpoints that accept an
     * arbitrary user ID (e.g. per-user stats lookups). The target user need
     * not be the current user; only membership is checked.
     *
     * @throws ForbiddenException if the target user is not a member of the house
     */
    public function assertUserInHouse(int $houseId, string $userId): void
    {
        $houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
        if ($houseUser === null) {
            $this->appLogger?->securityWarning('Cross-house user access denied', [
                'service' => 'AuthorizationService',
                'action' => 'assertUserInHouse',
                'houseId' => $houseId,
                'targetUserId' => $userId,
            ]);
            throw new ForbiddenException('User is not a member of this house');
        }
    }
}
