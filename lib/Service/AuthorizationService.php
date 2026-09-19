<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * Service for authorization checks across the application.
 *
 * Membership model (ADR-010): the house is invite-only after the first
 * owner. {@see ensureHouseMember()} is the ONE-TIME bootstrap primitive —
 * it auto-creates a membership only while the house has zero members
 * (first owner); once members exist, a miss is a denial.
 * {@see assertHouseMember()} is the pure, read-only gate for every
 * ordinary access check.
 *
 * All "require*" / "assert*" methods throw {@see ForbiddenException} so the
 * controllers can translate a denial into a 403 instead of a 500.
 */
class AuthorizationService {
	private HouseUserMapper $houseUserMapper;
	private HouseMapper $houseMapper;
	private WalletService $walletService;
	private TransactionExecutor $transactions;
	private IUserSession $userSession;
	private IL10N $l10n;
	private AppLogger $appLogger;

	public function __construct(
		HouseUserMapper $houseUserMapper,
		HouseMapper $houseMapper,
		WalletService $walletService,
		TransactionExecutor $transactions,
		IUserSession $userSession,
		IL10N $l10n,
		AppLogger $appLogger,
	) {
		$this->houseUserMapper = $houseUserMapper;
		$this->houseMapper = $houseMapper;
		$this->walletService = $walletService;
		$this->transactions = $transactions;
		$this->userSession = $userSession;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
	}

	/**
	 * Get the current Nextcloud user ID.
	 */
	public function getCurrentUserId(): ?string {
		$user = $this->userSession->getUser();
		return $user?->getUID();
	}

	/**
	 * Require an authenticated user and return the current user ID.
	 *
	 * @throws ForbiddenException if there is no authenticated user
	 */
	public function requireCurrentUserId(): string {
		$userId = $this->getCurrentUserId();
		if ($userId === null) {
			$this->appLogger->warning('Authorization check failed', [
				'service' => 'AuthorizationService',
				'action' => 'requireCurrentUserId',
			]);
			throw new ForbiddenException($this->l10n->t('Not authenticated'));
		}

		return $userId;
	}

	/**
	 * Ensure the current user is a member of the given house — bootstrap-only
	 * (ADR-010, decision D1).
	 *
	 * Auto-create fires ONLY when the house has zero members: the first
	 * authenticated user to reach a designated bootstrap endpoint becomes its
	 * owner. Once members exist, a non-member is denied with
	 * {@see ForbiddenException} (same message as {@see assertHouseMember()},
	 * so no new enumeration vector opens up) and NO membership row is
	 * created — house membership is invite-only after the first owner, and
	 * member removal is durable.
	 *
	 * Only the designated bootstrap endpoints (user#current, house#get,
	 * user#invite, setup#catalog, setup#apply) may call this. For every
	 * ordinary authorization gate use {@see assertHouseMember()}.
	 *
	 * @throws ForbiddenException if there is no authenticated user or the
	 *                            house already has members not including the caller
	 */
	public function ensureHouseMember(int $houseId): HouseUser {
		$user = $this->userSession->getUser();
		$userId = $user?->getUID();
		if ($userId === null) {
			$this->appLogger->warning('House membership required for anonymous access', [
				'service' => 'AuthorizationService',
				'action' => 'ensureHouseMember',
				'houseId' => $houseId,
			]);
			throw new ForbiddenException($this->l10n->t('Not authenticated'));
		}

		$houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
		if ($houseUser !== null) {
			return $houseUser;
		}

		// One-time bootstrap path (ADR-010): lock the house row and compute
		// the role inside one transaction (CR-053-07). The lock serializes
		// concurrent first-touchers — the second counts the winner's
		// committed membership row, sees members exist, and is denied.
		// Exactly one owner exists; everyone after needs an invitation.
		$houseUser = $this->transactions->run(function () use ($houseId, $userId) {
			$this->houseMapper->findForUpdate($houseId);

			$existingUsers = $this->houseUserMapper->findByHouseId($houseId);
			if ($existingUsers !== []) {
				$this->appLogger->securityWarning('Bootstrap denied: house already has members', [
					'service' => 'AuthorizationService',
					'action' => 'ensureHouseMember',
					'houseId' => $houseId,
					'userId' => $userId,
				]);
				throw new ForbiddenException($this->l10n->t('Not a member of this house'));
			}

			$this->appLogger->info('First-owner bootstrap membership created', [
				'service' => 'AuthorizationService',
				'action' => 'ensureHouseMember',
				'houseId' => $houseId,
				'userId' => $userId,
				'role' => 'owner',
			]);

			$displayName = $this->userSession->getUser()?->getDisplayName() ?: $userId;
			$houseUser = $this->houseUserMapper->createOrGet($houseId, $userId, $displayName, 'owner');

			// CS-PLAN-071 WP0: the first owner gets their persistent wallet
			// inside the same house-first transaction — the financial
			// identity exists from the first membership write.
			return $this->walletService->ensureBinding($houseId, $houseUser);
		});

		return $houseUser;
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
	public function assertHouseMember(int $houseId): HouseUser {
		$userId = $this->getCurrentUserId();
		if ($userId === null) {
			$this->appLogger->warning('House membership asserted for anonymous access', [
				'service' => 'AuthorizationService',
				'action' => 'assertHouseMember',
				'houseId' => $houseId,
			]);
			throw new ForbiddenException($this->l10n->t('Not authenticated'));
		}

		$houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
		if ($houseUser === null) {
			$this->appLogger->securityWarning('House membership assertion failed', [
				'service' => 'AuthorizationService',
				'action' => 'assertHouseMember',
				'houseId' => $houseId,
				'userId' => $userId,
			]);
			throw new ForbiddenException($this->l10n->t('Not a member of this house'));
		}

		return $houseUser;
	}

	/**
	 * Check if the current user has the owner role in the given house.
	 *
	 * Read-only: never auto-creates a membership (CR-SVC-06). A non-member is
	 * simply reported as not-an-owner.
	 *
	 * @param int $houseId The house ID
	 * @return bool True if the current user is a member with the owner role
	 */
	public function isHouseOwner(int $houseId): bool {
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
	public function requireHouseOwner(int $houseId): HouseUser {
		$houseUser = $this->assertHouseMember($houseId);

		if (!$houseUser->isOwner()) {
			$this->appLogger->warning('Owner role required', [
				'service' => 'AuthorizationService',
				'action' => 'requireHouseOwner',
				'houseId' => $houseId,
				'userId' => $houseUser->getNextcloudUserId(),
			]);
			throw new ForbiddenException($this->l10n->t('Owner role required'));
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
	public function assertUserInHouse(int $houseId, string $userId): void {
		$houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
		if ($houseUser === null) {
			$this->appLogger->securityWarning('Cross-house user access denied', [
				'service' => 'AuthorizationService',
				'action' => 'assertUserInHouse',
				'houseId' => $houseId,
				'targetUserId' => $userId,
			]);
			throw new ForbiddenException($this->l10n->t('User is not a member of this house'));
		}
	}

	/**
	 * Assert the acting user may manage the task domain (CS-PLAN-061 §3.4,
	 * decision D5): owners and members may create/update/delete/assign tasks;
	 * children have the execution surface only (complete, checklist progress,
	 * own preferences/minigames).
	 *
	 * @param HouseUser $houseUser The acting user's house membership
	 * @throws ForbiddenException if the actor has the child role
	 */
	public function assertCanManageTasks(HouseUser $houseUser): void {
		if ($houseUser->isChild()) {
			$this->appLogger->warning('Task management denied for child role', [
				'service' => 'AuthorizationService',
				'action' => 'assertCanManageTasks',
				'userId' => $houseUser->getNextcloudUserId(),
			]);
			throw new ForbiddenException($this->l10n->t('Children may view and complete tasks, but not manage them'));
		}
	}
}
