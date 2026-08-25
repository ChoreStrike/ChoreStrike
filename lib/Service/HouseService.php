<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;

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
	private RewardConfigService $rewardConfigService;
	private IConfig $config;
	private IDBConnection $db;
	private IL10N $l10n;
	private ?AppLogger $appLogger;

	public function __construct(
		HouseMapper $houseMapper,
		FloorMapper $floorMapper,
		RoomMapper $roomMapper,
		RoomTaskMapper $roomTaskMapper,
		CompletedTaskMapper $completedTaskMapper,
		HouseUserMapper $houseUserMapper,
		TaskAssigneeMapper $taskAssigneeMapper,
		RewardConfigService $rewardConfigService,
		IConfig $config,
		IDBConnection $db,
		IL10N $l10n,
		?AppLogger $appLogger = null,
	) {
		$this->houseMapper = $houseMapper;
		$this->floorMapper = $floorMapper;
		$this->roomMapper = $roomMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->taskAssigneeMapper = $taskAssigneeMapper;
		$this->rewardConfigService = $rewardConfigService;
		$this->config = $config;
		$this->db = $db;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
	}

	/**
	 * Remove a member from the house and clean up their task references
	 * transactionally (CR-SVC-26).
	 *
	 * House membership is a house concern, so the whole removal runs as one
	 * aggregate operation: null `room_tasks.assigned_to_user_id`, delete
	 * `task_assignees` rows, and delete the `house_users` row — all scoped
	 * to the member's house (another house's assignments are untouched).
	 * Before the fix the controller deleted only the house_users row and
	 * left orphaned assignee references to a former member behind.
	 *
	 * The caller (controller) is responsible for the requireHouseOwner
	 * gate; this method guards its own invariant: the target must not be
	 * an owner (throws ForbiddenException so the API can map its
	 * CANNOT_REMOVE_OWNER 403).
	 *
	 * @param int $houseUserId The house user ID of the member to remove
	 * @throws ForbiddenException if the target is the house owner
	 * @throws DoesNotExistException if the house user does not exist
	 */
	public function removeHouseMember(int $houseUserId): void {
		$houseUser = $this->houseUserMapper->find($houseUserId);

		if ($houseUser->isOwner()) {
			throw new ForbiddenException($this->l10n->t('Cannot remove the house owner'));
		}

		$ncUserId = $houseUser->getNextcloudUserId();
		$houseId = $houseUser->getHouseId();

		$this->db->beginTransaction();
		try {
			$this->roomTaskMapper->clearAssignedUserForHouse($ncUserId, $houseId);
			$this->taskAssigneeMapper->deleteByUserForHouse($ncUserId, $houseId);
			$this->houseUserMapper->delete($houseUser);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
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
	 * The caller (controller) is responsible for the requireHouseOwner gate.
	 *
	 * @param int $houseUserId The house user ID of the member to change
	 * @param string $role The new role (owner, member, child)
	 * @param string $callerUserId Nextcloud user ID of the acting user
	 * @throws \InvalidArgumentException if the role is not in the whitelist
	 * @throws ForbiddenException if the caller demotes themselves
	 * @throws ConflictException if the target is the last remaining owner
	 * @throws DoesNotExistException if the house user does not exist
	 */
	public function updateUserRole(int $houseUserId, string $role, string $callerUserId): HouseUser {
		if (!in_array($role, self::VALID_ROLES, true)) {
			throw new \InvalidArgumentException('Invalid role. Must be owner, member, or child');
		}

		$houseUser = $this->houseUserMapper->find($houseUserId);

		if ($role !== 'owner') {
			if ($houseUser->getNextcloudUserId() === $callerUserId) {
				throw new ForbiddenException($this->l10n->t('Cannot demote yourself'));
			}
			if ($houseUser->isOwner()
				&& $this->houseUserMapper->countOwnersForHouse($houseUser->getHouseId()) <= 1) {
				throw new ConflictException('Cannot demote the last house owner');
			}
		}

		$houseUser->setRole($role);
		return $this->houseUserMapper->update($houseUser);
	}

	/**
	 * Get or create house for current Nextcloud instance
	 *
	 * @param string $instanceId The Nextcloud instance ID
	 * @param string $houseName Optional house name
	 */
	public function getOrCreateHouse(string $instanceId, string $houseName = 'My House'): House {
		try {
			return $this->houseMapper->findByInstanceId($instanceId);
		} catch (DoesNotExistException $e) {
			$this->appLogger?->info('Creating house for instance', [
				'service' => 'HouseService',
				'instanceId' => $instanceId,
				'houseName' => $houseName,
			]);
			return $this->houseMapper->createForInstance($instanceId, $houseName);
		}
	}

	/**
	 * Update house name
	 *
	 * @param int $houseId The house ID
	 * @param string $name The new name
	 */
	public function updateHouseName(int $houseId, string $name): House {
		$house = $this->houseMapper->find($houseId);
		$house->setName($name);
		$house->touch();
		return $this->houseMapper->update($house);
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
	 * All rooms reset via one house-scoped subquery UPDATE
	 * (CS-PLAN-037, CR-SVC-60) instead of the floors → rooms double
	 * loop (3 + N_floors + N_rooms → 3 queries).
	 *
	 * @param int $houseId The house ID
	 */
	public function resetHouse(int $houseId): void {
		$this->appLogger?->warning('Resetting house to initial state', [
			'service' => 'HouseService',
			'houseId' => $houseId,
		]);
		$house = $this->houseMapper->find($houseId);
		$house->setHouseHealth(100);
		$house->touch();
		$this->houseMapper->update($house);

		$this->roomMapper->resetByHouseId($houseId);
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
	 * house is structurally empty — decision D3).
	 *
	 * @param int $houseId The house ID
	 */
	public function hasFloors(int $houseId): bool {
		return $this->floorMapper->findByHouseId($houseId) !== [];
	}

	/**
	 * Compute the weekly household goal progress for a house.
	 *
	 * The household "reaches" its goal when the share of completed work meets
	 * the configured target percent (RewardConfigService key
	 * `weekly_goal_target_percent`, default 80).
	 *
	 * Metric (KISS, no extra schema):
	 *   - dueSet         = (active room_tasks with next_due in this week) ∪
	 *                      (distinct room_tasks completed this week)
	 *   - dueCount       = |dueSet|
	 *   - completedCount = distinct room_tasks completed this week (⊆ dueSet
	 *                      by construction, so completedCount ≤ dueCount always)
	 *   - percent        = dueCount > 0 ? round(completedCount / dueCount * 100) : …
	 *   - achieved       = percent >= targetPercent AND (dueCount > 0 OR completedCount > 0)
	 *
	 * Why the UNION: a recurring task that is completed shifts its next_due
	 * forward (often out of this week) and a one-time task deactivates on
	 * completion — both leave the "active-due-this-week" set even though they
	 * are part of this week's planned work. Counting only active-due as the
	 * denominator would shrink it as the week progresses and inflate the
	 * percent prematurely (e.g. 4 of 5 done → dueCount=1, completedCount=4 →
	 * bogus 400%). Unioning with completed-this-week keeps the denominator
	 * stable for the whole week.
	 *
	 * Distinct room_task_id on both sides: a daily task completed three times
	 * this week counts once (matches "this task was done this week", not "we
	 * did N chores"), and a multi-assign task completed by three members
	 * counts once for the household.
	 *
	 * The achieved-flag explicitly requires at least one due or completed task
	 * so a brand-new empty house doesn't trigger a spurious celebration.
	 *
	 * Week bounds are Monday 00:00:00 → Sunday 23:59:59 in the server timezone
	 * (consistent with GameLogicService::getWeekStart's Monday-start).
	 *
	 * @param int $houseId The house ID
	 * @return array{dueCount: int, completedCount: int, percent: int, targetPercent: int, achieved: bool, weekStart: string, weekEnd: string}
	 */
	public function getWeeklyGoalProgress(int $houseId): array {
		[$weekStart, $weekEnd] = $this->getWeekBounds();

		$activeDueIds = $this->roomTaskMapper->findActiveDueIdsBetweenByHouse($houseId, $weekStart, $weekEnd);
		$completedIds = $this->completedTaskMapper->findCompletedRoomTaskIdsBetweenByHouse($houseId, $weekStart, $weekEnd);
		$targetPercent = $this->rewardConfigService->getWeeklyGoalTargetPercent();

		// dueSet = active-due ∪ completed-this-week. array_flip+keys gives
		// O(n) dedup on integer IDs without string-coercion surprises.
		$dueSet = array_keys(array_flip(array_merge($activeDueIds, $completedIds)));
		$dueCount = count($dueSet);
		$completedCount = count($completedIds);

		if ($dueCount === 0) {
			// Cannot happen given the UNION (completedIds would be in dueSet),
			// but defensive: empty house reports 0%.
			$percent = 0;
		} else {
			// completedCount ≤ dueCount by construction, so no clamp needed.
			$percent = (int)round(($completedCount / $dueCount) * 100);
		}

		$hasActivity = $dueCount > 0 || $completedCount > 0;

		return [
			'dueCount' => $dueCount,
			'completedCount' => $completedCount,
			'percent' => $percent,
			'targetPercent' => $targetPercent,
			'achieved' => $hasActivity && $percent >= $targetPercent,
			'weekStart' => $weekStart->format('c'),
			'weekEnd' => $weekEnd->format('c'),
		];
	}

	/**
	 * Get the current week's Monday 00:00:00 → Sunday 23:59:59 bounds.
	 *
	 * @return array{\DateTimeImmutable, \DateTimeImmutable}
	 */
	private function getWeekBounds(): array {
		$today = new \DateTimeImmutable('today');
		$dayOfWeek = (int)$today->format('N'); // 1 (Mon) .. 7 (Sun)
		$monday = $today->modify('-' . ($dayOfWeek - 1) . ' days')->setTime(0, 0, 0);
		$sunday = $monday->modify('+6 days')->setTime(23, 59, 59);
		return [$monday, $sunday];
	}
}
