<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\DigestChangeMapper;
use OCA\ChoreStrike\Db\DigestSlotMapper;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MemberPreferenceMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\CannotDemoteSelfException;
use OCA\ChoreStrike\Exception\CannotRemoveOwnerException;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Service for house-related business logic
 */
class HouseService {
	/** Roles a house user can hold (BUG-SEC-002 role-guard whitelist). */
	public const VALID_ROLES = ['owner', 'member', 'child'];

	private HouseMapper $houseMapper;
	private FloorMapper $floorMapper;
	private RoomMapper $roomMapper;
	private RoomTaskMapper $roomTaskMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private HouseUserMapper $houseUserMapper;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private WalletService $walletService;
	private RewardConfigService $rewardConfigService;
	private OccurrenceService $occurrenceService;
	private PauseService $pauseService;
	private RoomReplayService $roomReplayService;
	private CommandService $commandService;
	private WeeklyGoalService $weeklyGoalService;
	private MemberPreferenceMapper $memberPreferenceMapper;
	private DigestSlotMapper $digestSlotMapper;
	private DigestChangeMapper $digestChangeMapper;
	private DigestScheduleService $digestScheduleService;
	private IConfig $config;
	private TransactionExecutor $transactions;
	private IL10N $l10n;
	private AppLogger $appLogger;
	private IUserSession $userSession;
	private CalendarService $calendarService;
	private AuthorizationService $authorization;
	private IUserManager $userManager;
	private RewardService $rewardService;
	private RewardRequestService $rewardRequestService;

	public function __construct(
		HouseMapper $houseMapper,
		FloorMapper $floorMapper,
		RoomMapper $roomMapper,
		RoomTaskMapper $roomTaskMapper,
		CompletedTaskMapper $completedTaskMapper,
		HouseUserMapper $houseUserMapper,
		TaskAssigneeMapper $taskAssigneeMapper,
		WalletService $walletService,
		RewardConfigService $rewardConfigService,
		OccurrenceService $occurrenceService,
		PauseService $pauseService,
		RoomReplayService $roomReplayService,
		CommandService $commandService,
		WeeklyGoalService $weeklyGoalService,
		MemberPreferenceMapper $memberPreferenceMapper,
		DigestSlotMapper $digestSlotMapper,
		DigestChangeMapper $digestChangeMapper,
		DigestScheduleService $digestScheduleService,
		IConfig $config,
		TransactionExecutor $transactions,
		IL10N $l10n,
		AppLogger $appLogger,
		IUserSession $userSession,
		CalendarService $calendarService,
		AuthorizationService $authorization,
		IUserManager $userManager,
		RewardService $rewardService,
		RewardRequestService $rewardRequestService,
	) {
		$this->houseMapper = $houseMapper;
		$this->floorMapper = $floorMapper;
		$this->roomMapper = $roomMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->taskAssigneeMapper = $taskAssigneeMapper;
		$this->walletService = $walletService;
		$this->rewardConfigService = $rewardConfigService;
		$this->occurrenceService = $occurrenceService;
		$this->pauseService = $pauseService;
		$this->roomReplayService = $roomReplayService;
		$this->commandService = $commandService;
		$this->weeklyGoalService = $weeklyGoalService;
		$this->memberPreferenceMapper = $memberPreferenceMapper;
		$this->digestSlotMapper = $digestSlotMapper;
		$this->digestChangeMapper = $digestChangeMapper;
		$this->digestScheduleService = $digestScheduleService;
		$this->config = $config;
		$this->transactions = $transactions;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
		$this->userSession = $userSession;
		$this->calendarService = $calendarService;
		$this->authorization = $authorization;
		$this->userManager = $userManager;
		$this->rewardService = $rewardService;
		$this->rewardRequestService = $rewardRequestService;
	}

	/**
	 * Invite a Nextcloud account into the house by email address
	 * (RF-058-04: the membership write moved here from UserController —
	 * controllers must not persist through mappers directly; same
	 * ownership contract as removeHouseMember/updateUserRole).
	 *
	 * The owner authority is enforced SERVICE-SIDE here (CR-SEC-05 /
	 * CS-PLAN-081 H3): the gate runs before the account/email lookup so a
	 * non-owner cannot probe which Nextcloud accounts exist for a given
	 * email (404 vs 403 oracle, CS-PLAN-061), and it is re-checked on the
	 * house row inside the transaction. The HTTP layer may gate again —
	 * defense in depth, no anonymous compatibility path.
	 *
	 * CS-PLAN-071 WP0: the invite runs as one house-first aggregate and
	 * creates the member's persistent wallet inside it — the financial
	 * identity exists from the first membership write. Re-inviting a
	 * departed account reattaches the preserved wallet via
	 * (house_id, nextcloud_user_id); balances stay untouched (dormant
	 * until WP3) and the wallet never grants access: authorization remains
	 * solely the active house_users membership.
	 *
	 * @param int $houseId The house ID
	 * @param string $email Trimmed email address of the account to invite
	 * @param string $role One of VALID_ROLES
	 * @return HouseUser The invited (or already-member) house user
	 * @throws ForbiddenException when the current user is not a house owner
	 * @throws NotFoundException when no Nextcloud account matches the email
	 */
	public function inviteMember(int $houseId, string $email, string $role): HouseUser {
		// CS-PLAN-081 H3: owner authority BEFORE the account lookup (the
		// lookup itself must not leak account existence to non-owners).
		$this->authorization->requireHouseOwner($houseId);

		$ncUsers = $this->userManager->getByEmail($email);
		if (empty($ncUsers)) {
			throw new NotFoundException($this->l10n->t('No Nextcloud user found with this email address'));
		}

		// Use the first matching user if multiple accounts share the same email.
		$ncUser = $ncUsers[0];
		$uid = $ncUser->getUID();
		$displayName = $ncUser->getDisplayName();

		$created = false;
		$actorId = 0;
		$houseUser = $this->transactions->run(function () use ($houseId, $uid, $displayName, $role, &$created, &$actorId): HouseUser {
			// FIRST lock of the documented order (house → membership → wallet).
			$this->houseMapper->findForUpdate($houseId);
			// Owner authority re-checked on the locked house row: a demotion
			// racing the invite can never slip a write through.
			$actorId = (int)$this->authorization->requireHouseOwner($houseId)->getId();

			$created = $this->houseUserMapper->findByHouseAndUser($houseId, $uid) === null;
			$houseUser = $this->houseUserMapper->createOrGet($houseId, $uid, $displayName, $role);
			return $this->walletService->ensureBinding($houseId, $houseUser);
		});

		// Q-081-03 operational audit: success only AFTER the transaction
		// committed; a no-op re-invite is distinguishable by the outcome.
		$this->appLogger->audit('House member invite completed', [
			'event' => 'house.member.invited',
			'service' => 'HouseService',
			'houseId' => $houseId,
			'actorId' => $actorId,
			'memberId' => (int)$houseUser->getId(),
			'role' => $houseUser->getRole(),
			'outcome' => $created ? 'invited' : 'already-member',
		]);
		return $houseUser;
	}

	/**
	 * Look a house-user id up scoped to the given house (RF-058-04: moved
	 * from UserController — persistence reads belong to services).
	 *
	 * A nonexistent id and a foreign-house id both yield null (→ 404), so
	 * callers cannot enumerate which global house_user ids exist
	 * (CS-PLAN-061 §3.1 anti-enumeration).
	 */
	public function findHouseUserScopedToHouse(int $id, int $houseId): ?HouseUser {
		try {
			$houseUser = $this->houseUserMapper->find($id);
		} catch (DoesNotExistException $e) {
			return null;
		}

		return $houseUser->getHouseId() === $houseId ? $houseUser : null;
	}

	/**
	 * All members of the house (RF-058-04: moved from UserController).
	 *
	 * @return HouseUser[]
	 */
	public function getHouseMembers(int $houseId): array {
		return $this->houseUserMapper->findByHouseId($houseId);
	}

	/**
	 * Remove a member from the house and clean up their task references
	 * transactionally (CR-SVC-26).
	 *
	 * House membership is a house concern, so the whole removal runs as one
	 * house-first aggregate operation (CS-PLAN-071 WP0): under the house
	 * row lock, delete the member's `task_assignees` rows (the canonical
	 * assignee store, scoped to this house — another house's assignments
	 * are untouched) and delete the `house_users` row. The member's WALLET
	 * ROW IS PRESERVED — financial identity/history is separate from active
	 * authorization (parent C4 preamble); a re-invitation reattaches it and
	 * only account erasure removes it. The C4e departure semantics
	 * (cancelling every unfulfilled reward request and releasing its
	 * reservation before access ends) is implemented by the WP7 hook below.
	 *
	 * The owner authority is enforced SERVICE-SIDE here (CR-SEC-05 /
	 * CS-PLAN-081 H3) before the transaction and re-checked on the locked
	 * house row inside it; the actor for the C4e departure hook is the
	 * authoritative owner from that gate, never an unchecked caller
	 * parameter. The HTTP layer may gate again — defense in depth, no
	 * anonymous compatibility path.
	 *
	 * The method guards its own invariant: an owner may remove any other
	 * member, other owners included, but not themselves (throws
	 * CannotRemoveOwnerException so the API can map its CANNOT_REMOVE_OWNER
	 * 403). The acting owner remains, so the house always keeps an owner.
	 *
	 * @param int $houseUserId The house user ID of the member to remove
	 * @throws CannotRemoveOwnerException if the acting owner targets themselves
	 * @throws ForbiddenException if the current user is not a house owner
	 * @throws DoesNotExistException if the house user does not exist
	 */
	public function removeHouseMember(int $houseUserId): void {
		$houseUser = $this->houseUserMapper->find($houseUserId);

		// CS-PLAN-081 H3: service-side owner gate before any write.
		$actor = $this->authorization->requireHouseOwner($houseUser->getHouseId());
		$this->assertRemovableBy($houseUser, $actor);

		$ncUserId = $houseUser->getNextcloudUserId();
		$houseId = $houseUser->getHouseId();
		$walletId = $houseUser->getWalletId();

		$this->transactions->run(function () use ($houseUserId, $ncUserId, $houseId, $walletId, &$actor): void {
			// FIRST lock of the documented order: the house row, then the
			// membership row — the owner invariants are re-checked on the
			// LOCKED rows (a promotion/demotion racing the read can never
			// slip through).
			$this->houseMapper->findForUpdate($houseId);
			$actor = $this->authorization->requireHouseOwner($houseId);
			$houseUser = $this->houseUserMapper->findForUpdate($houseUserId);
			$this->assertRemovableBy($houseUser, $actor);

			// CS-PLAN-070 WP7 (CS-PLAN-078 §3.4, C4): cancel every
			// unfulfilled reward request and release its reservation in the
			// SAME source aggregate, BEFORE access ends — with the acting
			// owner as the audit/digest actor. Booked value and terminal
			// history are preserved for the retained wallet.
			if ($walletId !== null) {
				$this->rewardRequestService->handleMembershipRemovedUnderLock($houseId, $walletId, $actor);
			}

			$this->taskAssigneeMapper->deleteByUserForHouse($ncUserId, $houseId);
			$this->houseUserMapper->delete($houseUser);

			// CS-PLAN-070 WP4 (Q-075-01 A) / WP5 (CS-PLAN-076 §3.7): the
			// preference row AND both digest tables are membership-keyed —
			// they die with the membership; a rejoin starts quiet/off
			// (C5h).
			$this->memberPreferenceMapper->deleteByHouseAndUser($houseId, (int)$houseUser->getId());
			$this->digestSlotMapper->deleteByHouseAndUser($houseId, (int)$houseUser->getId());
			$this->digestChangeMapper->deleteByHouseAndUser($houseId, (int)$houseUser->getId());

			// CS-PLAN-073 WP2 (Q-073-03): cancel the departing member's
			// pending personal pause requests and converge the suspension
			// facts — future personal-pause effects stop at departure
			// (already neutral history stays), rejoin requires a new pause.
			if ($walletId !== null) {
				$this->pauseService->handleMembershipRemovedUnderLock($houseId, $walletId);
			}
		});

		// Q-081-03 operational audit: success only AFTER the transaction
		// committed, with the authoritative actor from the locked gate.
		$this->appLogger->audit('House member removal completed', [
			'event' => 'house.member.removed',
			'service' => 'HouseService',
			'houseId' => $houseId,
			'actorId' => (int)$actor->getId(),
			'memberId' => $houseUserId,
			'outcome' => 'removed',
		]);
	}

	/**
	 * FEAT-UX-015 (CS-PLAN-088 D-088-19): the acting owner may remove another
	 * owner — they remain an owner themselves, so the D6 last-owner invariant
	 * holds. Removing oneself is the separate self-service departure and
	 * stays refused.
	 *
	 * @throws CannotRemoveOwnerException if the acting owner targets themselves
	 */
	private function assertRemovableBy(HouseUser $target, HouseUser $actor): void {
		if ((int)$target->getId() === (int)$actor->getId()) {
			throw new CannotRemoveOwnerException($this->l10n->t('You cannot remove yourself from the house'));
		}
	}

	/**
	 * Update a member's role with the two owner invariants (BUG-SEC-002).
	 *
	 * Role mutation is a house concern, so it lives here next to the sibling
	 * removeHouseMember invariant instead of in the controller. Guards:
	 *  - a user may not demote themselves (throws ForbiddenException so the
	 *    API can map its CANNOT_DEMOTE_SELF 403); assigning oneself 'owner'
	 *    is a no-op promotion and stays allowed;
	 *  - the last remaining owner may not be demoted (throws
	 *    ConflictException so the API can map CANNOT_DEMOTE_LAST_OWNER 409).
	 *
	 * The owner authority is enforced SERVICE-SIDE here (CR-SEC-05 /
	 * CS-PLAN-081 H3) before the transaction and re-checked on the locked
	 * house row inside it. The self-demotion comparison uses the current
	 * session identity from the authoritative authorization service —
	 * never an unchecked caller parameter. The HTTP layer may gate again —
	 * defense in depth, no anonymous compatibility path.
	 *
	 * @param int $houseUserId The house user ID of the member to change
	 * @param string $role The new role (owner, member, child)
	 * @throws \InvalidArgumentException if the role is not in the whitelist
	 * @throws ForbiddenException if the caller demotes themselves or is not a house owner
	 * @throws ConflictException if the target is the last remaining owner
	 * @throws DoesNotExistException if the house user does not exist
	 */
	public function updateUserRole(int $houseUserId, string $role): HouseUser {
		if (!in_array($role, self::VALID_ROLES, true)) {
			throw new \InvalidArgumentException('Invalid role. Must be owner, member, or child');
		}

		$houseUser = $this->houseUserMapper->find($houseUserId);
		$houseId = $houseUser->getHouseId();

		// CS-PLAN-081 H3: service-side owner gate before the transaction.
		$this->authorization->requireHouseOwner($houseId);
		// The actor identity is the authoritative session user (CR-SEC-05:
		// not an unchecked caller parameter).
		$callerUserId = $this->authorization->requireCurrentUserId();
		$actor = null;
		$changed = false;

		$updated = $this->transactions->run(function () use ($houseUserId, $role, $callerUserId, $houseId, &$actor, &$changed): HouseUser {
			// FIRST lock of the documented order: the house row, then the
			// membership row — the guards below evaluate the FRESH state.
			$this->houseMapper->findForUpdate($houseId);
			$actor = $this->authorization->requireHouseOwner($houseId);
			$houseUser = $this->houseUserMapper->findForUpdate($houseUserId);

			if ($role !== 'owner') {
				if ($houseUser->getNextcloudUserId() === $callerUserId) {
					throw new CannotDemoteSelfException($this->l10n->t('Cannot demote yourself'));
				}
				if ($houseUser->isOwner()
					&& $this->houseUserMapper->countOwnersForHouse($houseUser->getHouseId()) <= 1) {
					throw new ConflictException('Cannot demote the last house owner');
				}
			}

			$oldRole = $houseUser->getRole();
			$oldRoleRevision = $houseUser->getRoleRevision();
			$changed = $oldRole !== $role;
			$houseUser->setRole($role);
			if ($changed) {
				// CS-PLAN-070 WP7 (CS-PLAN-078 §3.4): only a REAL role change
				// advances the revision — a no-op write never invalidates a
				// fresh owner approval through the request hooks below.
				$houseUser->setRoleRevision($oldRoleRevision + 1);
			}
			$updated = $this->houseUserMapper->update($houseUser);

			if ($changed) {
				$this->rewardRequestService->handleRequesterRoleChangedUnderLock(
					$houseId,
					$updated,
					$oldRole,
					$oldRoleRevision,
					$actor
				);
			}
			return $updated;
		});

		// Q-081-03 operational audit: success only AFTER the transaction
		// committed; a no-op role write is distinguishable by the outcome.
		$this->appLogger->audit('House member role change completed', [
			'event' => 'house.member.role_changed',
			'service' => 'HouseService',
			'houseId' => $houseId,
			'actorId' => (int)$actor->getId(),
			'memberId' => $houseUserId,
			'role' => $updated->getRole(),
			'outcome' => $changed ? 'changed' : 'no-change',
		]);
		return $updated;
	}

	/**
	 * Owner-controlled reward-catalog gate (CS-PLAN-070 WP7, C4;
	 * CS-PLAN-078 §3.3). Deliberately narrow: the only catalog writer is
	 * PUT /house/catalog with the house `stateVersion` contract — the
	 * shared PUT /house settings writer stays untouched. Delegates to
	 * {@see RewardService::setCatalogEnabled()}; personal visibility
	 * preferences and existing requests are never touched.
	 *
	 * @return array{catalogEnabled: bool, stateVersion: int}
	 */
	public function setCatalogEnabled(int $houseId, bool $catalogEnabled, int $expectedVersion, string $commandId): array {
		return $this->rewardService->setCatalogEnabled($houseId, $catalogEnabled, $expectedVersion, $commandId);
	}

	/**
	 * Get or create house for current Nextcloud instance
	 *
	 * @param string $instanceId The Nextcloud instance ID
	 * @param string|null $houseName Optional house name
	 */
	public function getOrCreateHouse(string $instanceId, ?string $houseName = null): House {
		try {
			return $this->houseMapper->findByInstanceId($instanceId);
		} catch (DoesNotExistException $e) {
			$houseName ??= $this->l10n->t('My House');
			// Log hygiene (CS-PLAN-061 §3.6): the house name is user-authored
			// content — only stable ids go into the log context.
			$this->appLogger->info('Creating house for instance', [
				'service' => 'HouseService',
				'instanceId' => $instanceId,
			]);
			// CS-PLAN-057 D1: capture the creator's Nextcloud timezone at
			// house creation so day bucketing is locally correct from the
			// first request (never a bare UTC default when avoidable).
			return $this->houseMapper->createForInstance($instanceId, $houseName, $this->resolveCreationTimezone());
		}
	}

	/**
	 * Resolve the creator's timezone for a fresh house (CS-PLAN-057 D1).
	 *
	 * Prefers the Nextcloud user preference (`core.timezone`, set by the
	 * client-side detection in the personal settings); falls back to the
	 * server default timezone, which on Nextcloud is UTC.
	 */
	private function resolveCreationTimezone(): string {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$tz = $this->config->getUserValue($user->getUID(), 'core', 'timezone', '');
			if (is_string($tz) && $tz !== '' && $this->calendarService->isValidTimeZone($tz)) {
				return $tz;
			}
		}
		return date_default_timezone_get();
	}

	/**
	 * Update house name, timezone and (optionally) the ranking flag
	 * (owner-only, CS-PLAN-061 §3.4 / ADR-006 — controller checks remain
	 * as defense in depth).
	 *
	 * CS-PLAN-071 WP0: the mutation runs under the house row lock and
	 * bumps `timezone_version` on an actual timezone change (decision D7 —
	 * the first consumer of the version counters; saved occurrence facts
	 * keep their zone, WP1 owns the recurrence rewiring).
	 *
	 * CS-PLAN-070 WP4 (C5i/D10b): `rankingEnabled` is the owner-only
	 * ranking gate on the existing update path. Disabling removes all
	 * visible participation immediately and invalidates every consent
	 * generation; re-enabling starts everyone unconsented. Default off.
	 *
	 * @param int $houseId The house ID
	 * @param string $name The new name
	 * @param string|null $timezone The new IANA timezone id (null = leave unchanged)
	 * @param bool|null $rankingEnabled The new ranking flag (null = leave unchanged)
	 * @throws \OCA\ChoreStrike\Exception\ForbiddenException if the caller is not the house owner
	 */
	public function updateHouse(int $houseId, string $name, ?string $timezone = null, ?bool $rankingEnabled = null): House {
		$actorId = (int)$this->authorization->requireHouseOwner($houseId)->getId();

		$changed = false;
		$updated = $this->transactions->run(function () use ($houseId, $name, $timezone, $rankingEnabled, &$actorId, &$changed): House {
			$house = $this->houseMapper->findForUpdate($houseId);
			$actorId = (int)$this->authorization->requireHouseOwner($houseId)->getId();
			$changed = $house->getName() !== $name
				|| ($timezone !== null && $house->getTimezone() !== $timezone)
				|| ($rankingEnabled !== null && $house->getRankingEnabled() !== $rankingEnabled);
			$house->setName($name);
			if ($timezone !== null) {
				if ($house->getTimezone() !== $timezone) {
					$house->setTimezone($timezone);
					$house->bumpTimezoneVersion();
					// CS-PLAN-072 WP1 (C5k): the zone change propagates to
					// every schedule of the house INSIDE the same house-first
					// transaction — saved occurrences keep their zone and
					// deadlines; only new agreements resolve in the new zone.
					$this->occurrenceService->reviseForZoneChangeUnderLock($houseId, $timezone, new \DateTime());
					// CS-PLAN-070 WP5 (C5k): pending digest slots re-resolve
					// in the new zone under the 24-elapsed-hour spacing rule.
					$this->digestScheduleService->reviseForZoneChangeUnderLock($houseId, $timezone, new \DateTime());
				}
			}
			if ($rankingEnabled !== null && $rankingEnabled !== $house->getRankingEnabled()) {
				$house->setRankingEnabled($rankingEnabled);
				if (!$rankingEnabled) {
					// Q-076-03 A: disable invalidates every generation by
					// resetting ONLY the consent columns — delivery
					// preferences survive; nobody re-enters ranked without
					// a fresh consent after re-enabling.
					$this->memberPreferenceMapper->clearRankingConsentByHouseId($houseId, new \DateTime());
				}
			}
			$house->touch();
			return $this->houseMapper->update($house);
		});

		// Q-081-03 operational audit: success only AFTER the commit; the
		// user-authored house name never enters the context.
		$this->appLogger->audit('House settings update completed', [
			'event' => 'house.settings.updated',
			'service' => 'HouseService',
			'houseId' => $houseId,
			'actorId' => $actorId,
			'outcome' => $changed ? 'changed' : 'no-change',
		]);
		return $updated;
	}

	/**
	 * Calculate overall house health based on room cleanliness
	 *
	 * One house-scoped room fetch (CS-PLAN-036) instead of a
	 * floors → findByFloorId loop (1 + N_floors → 1 query).
	 *
	 * @param int $houseId The house ID
	 */
	public function calculateHouseHealth(int $houseId): int {
		$rooms = $this->roomMapper->findByHouseId($houseId);
		$roomCount = count($rooms);

		if ($roomCount === 0) {
			return 100; // Empty house is perfectly clean
		}

		$totalCleanliness = 0;
		foreach ($rooms as $room) {
			$totalCleanliness += $room->getCleanliness();
		}

		$averageCleanliness = (int)round($totalCleanliness / $roomCount);
		$this->houseMapper->updateHealth($houseId, $averageCleanliness);

		return $averageCleanliness;
	}

	/**
	 * Get house statistics
	 *
	 * Rooms come from one house-scoped fetch (CS-PLAN-036) instead of a
	 * per-floor loop (1 + N_floors → 2 queries); the floors list stays in
	 * the payload.
	 *
	 * @param int $houseId The house ID
	 */
	public function getHouseStats(int $houseId): array {
		$house = $this->houseMapper->find($houseId);
		$floors = $this->floorMapper->findByHouseId($houseId);
		$rooms = $this->roomMapper->findByHouseId($houseId);

		$roomCount = 0;
		$cleanRooms = 0;
		$dirtyRooms = 0;
		$roomsNeedingCleaning = 0;

		foreach ($rooms as $room) {
			$roomCount++;

			if ($room->getCleanliness() >= 60) {
				$cleanRooms++;
			} else {
				$dirtyRooms++;
			}

			if ($room->needsCleaning()) {
				$roomsNeedingCleaning++;
			}
		}

		return [
			'house' => $house,
			'floors' => $floors,
			'stats' => [
				'totalRooms' => $roomCount,
				'cleanRooms' => $cleanRooms,
				'dirtyRooms' => $dirtyRooms,
				'roomsNeedingCleaning' => $roomsNeedingCleaning,
				'houseHealth' => $house->getHouseHealth(),
			]
		];
	}

	/**
	 * Reset house to initial state
	 *
	 * Owner-only (ADR-006 service-side enforcement, RF-058-02): the
	 * controller check stays as defense in depth, mirroring
	 * {@see updateHouse()} — the most destructive house operation must not
	 * depend on a single controller gate.
	 *
	 * CS-PLAN-071 WP0: the reset runs as one house-first transaction
	 * (house row lock first). The contract is preserved: cleanliness 100,
	 * monsters 0, `last_cleaned` null, health 100; NO work/finance
	 * clearing — wallets and command receipts are financial identity, not
	 * room state, and survive a production house reset. Tombstoned rooms
	 * are not reset (they are gone from the active structure).
	 *
	 * CS-PLAN-070 WP3 (§3.5): the reset journals ONE absolute reset event
	 * per live room (idempotent through the command UUID — a replayed
	 * command never re-resets over later work) and rebuilds the room
	 * caches by the chronological replay. A later reset remains
	 * authoritative over earlier corrections.
	 *
	 * @param int $houseId The house ID
	 * @param string $commandId Client idempotency UUID
	 * @param int|null $expectedVersion Displayed house state version (stale → 409)
	 * @throws \OCA\ChoreStrike\Exception\ForbiddenException if the caller is not the house owner
	 * @throws \OCA\ChoreStrike\Exception\ConflictException on a stale state version
	 */
	public function resetHouse(int $houseId, string $commandId, ?int $expectedVersion = null): void {
		$actor = $this->authorization->requireHouseOwner($houseId);
		$command = $this->commandService->runCommand($houseId, (int)$actor->getId(), $commandId, 'house.reset', [
			'expectedVersion' => $expectedVersion,
		], function () use ($houseId, $commandId, $expectedVersion): array {
			// The receipt holds the house lock; recheck role before any write.
			$this->authorization->requireHouseOwner($houseId);
			$house = $this->houseMapper->find($houseId);
			if ($expectedVersion !== null && $house->getStateVersion() !== $expectedVersion) {
				throw new ConflictException($this->l10n->t('The house state changed since it was displayed'));
			}
			$at = new \DateTime();
			foreach ($this->roomMapper->findByHouseId($houseId) as $room) {
				if ($room->getDeletedAt() !== null) {
					continue;
				}
				$this->roomReplayService->recordReset((int)$room->getId(), $commandId, $at);
				$this->roomReplayService->resyncRoomCache($room);
			}

			$house->setHouseHealth(100);
			$house->setStateVersion($house->getStateVersion() + 1);
			$house->touch();
			$this->houseMapper->update($house);
			return ['houseId' => $houseId];
		}, function (int $lockedHouseId, int $actorId): void {
			$owner = $this->authorization->requireHouseOwner($lockedHouseId);
			if ((int)$owner->getId() !== $actorId) {
				throw new ForbiddenException($this->l10n->t('Not a member of this house'));
			}
		});
	}

	/**
	 * Get instance ID from Nextcloud config
	 */
	public function getInstanceId(): string {
		return $this->config->getSystemValue('instanceid', 'default');
	}

	/**
	 * Whether the house has any floors yet.
	 *
	 * Used by the first-run setup gate (the wizard is only available while the
	 * house is structurally empty — decision D3). Delegates to the COUNT
	 * primitive (CS-PLAN-062 §3.1) instead of materializing every floor
	 * entity for a boolean.
	 *
	 * @param int $houseId The house ID
	 */
	public function hasFloors(int $houseId): bool {
		return $this->floorMapper->countByHouseId($houseId) > 0;
	}

	/**
	 * Compute the weekly household goal progress for a house.
	 *
	 * WP3 (Q-074-01A): the evaluation moved to the dedicated
	 * {@see WeeklyGoalService} — correction-safe by construction, because
	 * the input is always the valid (non-undone) completion set. This
	 * delegating method stays for the existing controller/post-commit
	 * consumers.
	 *
	 * @param int $houseId The house ID
	 * @return array{dueCount: int, completedCount: int, percent: int, targetPercent: int, achieved: bool, weekStart: string, weekEnd: string}
	 */
	public function getWeeklyGoalProgress(int $houseId): array {
		return $this->weeklyGoalService->getWeeklyGoalProgress($houseId);
	}
}
