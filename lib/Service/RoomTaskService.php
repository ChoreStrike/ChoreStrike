<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssignee;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Service for room task business logic: ownership validation, lifecycle
 * mutations and the room-scoped aggregates (stats, calendar range). The
 * batched READ/enrichment cluster lives in {@see RoomTaskReadService}
 * (CS-PLAN-067 §3.1); this service delegates to it instead of duplicating
 * the assembly. Since CS-PLAN-072 WP1 the due-date/assignee writers route
 * through the occurrence agreement writer ({@see OccurrenceService}) —
 * no path writes `next_due` or participants outside an agreement.
 */
class RoomTaskService {
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private HouseMapper $houseMapper;
	private RoomTaskReadService $readService;
	private RoomService $roomService;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private HouseUserMapper $houseUserMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private TransactionExecutor $transactions;
	private IL10N $l10n;
	private AppLogger $appLogger;
	private TaskAccessPolicy $taskAccessPolicy;
	private AuthorizationService $authorization;
	private CalendarService $calendarService;
	private OccurrenceService $occurrenceService;

	public function __construct(
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		RoomMapper $roomMapper,
		HouseMapper $houseMapper,
		RoomTaskReadService $readService,
		RoomService $roomService,
		TaskAssigneeMapper $taskAssigneeMapper,
		HouseUserMapper $houseUserMapper,
		CompletedTaskMapper $completedTaskMapper,
		TransactionExecutor $transactions,
		IL10N $l10n,
		AppLogger $appLogger,
		TaskAccessPolicy $taskAccessPolicy,
		AuthorizationService $authorization,
		CalendarService $calendarService,
		OccurrenceService $occurrenceService,
	) {
		$this->roomTaskMapper = $roomTaskMapper;
		$this->taskMapper = $taskMapper;
		$this->roomMapper = $roomMapper;
		$this->houseMapper = $houseMapper;
		$this->readService = $readService;
		$this->roomService = $roomService;
		$this->taskAssigneeMapper = $taskAssigneeMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->transactions = $transactions;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
		$this->taskAccessPolicy = $taskAccessPolicy;
		$this->authorization = $authorization;
		$this->calendarService = $calendarService;
		$this->occurrenceService = $occurrenceService;
	}

	/**
	 * Validate that a room task belongs to the specified house
	 *
	 * The room→floor→house walk is owned by {@see RoomService::validateRoomOwnership()}
	 * (one canonical implementation, CS-PLAN-063 §3.4 — CR-SVC-41 dedup);
	 * this wrapper resolves the room task's room and translates any miss
	 * back into the room-task 404 so callers keep one denial shape.
	 *
	 * @param int $roomTaskId The room task ID
	 * @param int $houseId The house ID to validate against
	 * @throws NotFoundException if room task doesn't belong to house
	 */
	public function validateRoomTaskOwnership(int $roomTaskId, int $houseId): void {
		try {
			$roomTask = $this->roomTaskMapper->find($roomTaskId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}

		// Q-70-01 (CS-PLAN-071 WP0): a tombstoned room task is gone from
		// active planning — mutation/validation surfaces treat it exactly
		// like a missing id (404), never as a writable target.
		if ($roomTask->getDeletedAt() !== null) {
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}

		try {
			$this->roomService->validateRoomOwnership($roomTask->getRoomId(), $houseId);
		} catch (NotFoundException $e) {
			$this->appLogger->securityWarning('Room task ownership validation failed', [
				'service' => 'RoomTaskService',
				'roomTaskId' => $roomTaskId,
				'houseId' => $houseId,
			]);
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}
	}

	/**
	 * Validate that a room belongs to the specified house.
	 *
	 * Delegates to RoomService so the from-template endpoint can reject a
	 * cross-house roomId before materializing/creating any task.
	 *
	 * @param int $roomId The room ID
	 * @param int $houseId The house ID to validate against
	 * @throws NotFoundException if the room doesn't belong to the house
	 */
	public function validateRoomOwnership(int $roomId, int $houseId): void {
		$this->roomService->validateRoomOwnership($roomId, $houseId);
	}

	/**
	 * Get room task by ID
	 *
	 * @param int $roomTaskId The room task ID
	 * @throws NotFoundException if not found
	 */
	public function getRoomTask(int $roomTaskId): RoomTask {
		return $this->findRoomTaskOrNotFound($roomTaskId);
	}

	/**
	 * Fetch a room task or translate the mapper's DoesNotExistException into
	 * the app NotFoundException (404, CS-PLAN-061 §3.5) — a raw framework
	 * exception would surface as a 500.
	 *
	 * @throws NotFoundException if not found
	 */
	private function findRoomTaskOrNotFound(int $roomTaskId): RoomTask {
		try {
			return $this->roomTaskMapper->find($roomTaskId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}
	}

	// CS-PLAN-067 §3.1: the batched READ cluster (house/room/scope/daily
	// fetches, visibility filtering, task/room enrichment, filtered-daily
	// assembly, claimable/due-within reads, execution-row aggregation) lives
	// in RoomTaskReadService — callers consume it directly, this service
	// keeps only the writer/mutation aggregate and the room-scoped stats and
	// calendar projections.

	// CS-PLAN-063 (CR-WF-03): the former createRoomTask/createFromTemplate
	// pair — sequential auto-commit writes that could strand an orphan task
	// definition — was replaced by the atomic TaskCreationService aggregate
	// (lib/Service/TaskCreationService.php). Both create routes route through
	// it; setup keeps its own internal aggregate (CS-PLAN-063 D9).

	/**
	 * Replace the assignee set inside one transaction under the single
	 * house-first order (CS-PLAN-071 WP0): the house row lock is acquired
	 * first, then the room-task row lock, every target membership is
	 * revalidated under the locks, then the atomic delete-then-insert runs.
	 * A membership miss rolls back with zero writes (→ 400).
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string[] $uniqueIds Deduplicated, non-empty user IDs
	 * @param int $houseId The house ID for membership validation
	 * @param string $assignedBy The Nextcloud user ID who assigned
	 * @param bool $clearClaimMarker Whether a stale claim marker is cleared
	 *                               (the single-assignee path clears it; the multi-assign path does not)
	 * @return TaskAssignee[] The resulting assignee list
	 * @throws \InvalidArgumentException if a target user is not a house member
	 */
	private function replaceAssigneesUnderLock(
		int $roomTaskId,
		array $uniqueIds,
		int $houseId,
		string $assignedBy,
		bool $clearClaimMarker,
	): array {
		return $this->transactions->run(function () use ($roomTaskId, $uniqueIds, $houseId, $assignedBy, $clearClaimMarker) {
			// FIRST lock of the documented order (house → room_task).
			$this->houseMapper->findForUpdate($houseId);
			$roomTask = $this->roomTaskMapper->lockForUpdate($roomTaskId);
			if ($roomTask->getDeletedAt() !== null) {
				// Authoritative tombstone gate (Q-70-01): covers every
				// caller (single, bulk, claim) even if a pre-check missed
				// a just-deleted row — deleted planning state never gets
				// assignee writes.
				throw new NotFoundException($this->l10n->t('Room task not found'));
			}

			foreach (array_unique($uniqueIds) as $userId) {
				if ($this->houseUserMapper->findByHouseAndUser($houseId, $userId) === null) {
					throw new \InvalidArgumentException($this->l10n->t('User is not a member of this house: %1$s', [$userId]));
				}
			}

			$this->taskAssigneeMapper->deleteByRoomTask($roomTaskId);
			foreach ($uniqueIds as $userId) {
				$this->taskAssigneeMapper->addAssignee($roomTaskId, $userId, $assignedBy);
			}

			if ($clearClaimMarker && $roomTask->getClaimedByUser() !== null) {
				$roomTask->setClaimedByUser(null);
				$this->roomTaskMapper->update($roomTask);
			}

			// CS-PLAN-072 WP1 (Q-072-01): participant writes route through
			// the common agreement writer — the new snapshot becomes the
			// revision default (close + append), affected future unfulfilled
			// occurrences get new occ_members plus agreement evidence rows,
			// and the C2h child day limit is enforced before any write.
			// Orphaned definitions (no task row) keep the join-table write
			// as their only authority.
			$definition = $roomTask->getTaskId() !== null
				? $this->findTaskOrNull((int)$roomTask->getTaskId())
				: null;
			if ($definition instanceof Task) {
				$this->occurrenceService->amendParticipantsUnderLock(
					$houseId,
					$roomTask,
					$definition,
					$this->houseMapper->find($houseId) ?? new House(),
					$this->actorHouseUserIdFor($assignedBy, $houseId),
					$uniqueIds,
					new \DateTime(),
				);
			}

			return $this->taskAssigneeMapper->findByRoomTask($roomTaskId);
		});
	}

	/**
	 * Resolve the acting membership id for agreement evidence rows (null
	 * for system/test writers — evidence facts never require an actor).
	 */
	private function actorHouseUserIdFor(?string $assignedBy, int $houseId): ?int {
		if ($assignedBy === null || $assignedBy === '') {
			return null;
		}
		$member = $this->houseUserMapper->findByHouseAndUser($houseId, $assignedBy);
		return $member?->getId();
	}

	/**
	 * Task definition lookup tolerant of orphaned room tasks (mocked and
	 * deleted definitions): returns null instead of throwing.
	 */
	private function findTaskOrNull(int $taskId): ?Task {
		try {
			return $this->taskMapper->find($taskId);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Write the canonical single-assignee state: the assignee set becomes
	 * exactly [$userId] (empty string = unassign) and a stale claim marker
	 * is cleared — the same effect the former legacy-column UPDATE had.
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string $userId The assignee (empty = clear)
	 */
	private function replaceSingleAssignee(int $roomTaskId, string $userId, int $houseId, string $assignedBy): void {
		$userIds = $userId === '' ? [] : [$userId];
		$this->replaceAssigneesUnderLock($roomTaskId, $userIds, $houseId, $assignedBy, true);
	}

	/**
	 * Validate that every Nextcloud user ID is a member of the given house.
	 *
	 * @param string[] $userIds The Nextcloud user IDs to validate
	 * @param int $houseId The house ID to validate against
	 * @throws \InvalidArgumentException if any user is not a house member
	 */
	private function assertHouseMembers(array $userIds, int $houseId): void {
		$members = $this->houseUserMapper->findByHouseId($houseId);
		$memberIds = array_map(fn ($user) => $user->getNextcloudUserId(), $members);

		foreach ($userIds as $userId) {
			if (!is_string($userId) || trim($userId) === '') {
				continue;
			}
			if (!in_array($userId, $memberIds, true)) {
				throw new \InvalidArgumentException($this->l10n->t('User is not a member of this house: %1$s', [$userId]));
			}
		}
	}

	/**
	 * Get the Nextcloud user IDs assigned to a room task (canonical join
	 * table, CS-PLAN-060 WP1 D2).
	 *
	 * Visibility-gated (CS-PLAN-061 review fix): the assignee list of a task
	 * the actor cannot see is a 404 — reading the assignees of an invisible
	 * private task would leak its assignment graph.
	 *
	 * @param int $roomTaskId The room task ID
	 * @param HouseUser $actor The acting user's house membership
	 * @return string[] Unique Nextcloud user IDs
	 * @throws NotFoundException if the room task or its definition is invisible to the actor
	 */
	public function getAssigneeUserIds(int $roomTaskId, HouseUser $actor): array {
		$this->assertRoomTaskVisible($roomTaskId, $actor);
		return $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTaskId);
	}

	/**
	 * Replace the full set of assignees for a room task.
	 *
	 * Service-level gates (ADR-006, CS-PLAN-061 §3.4): the room task must
	 * belong to the caller's house (floor-chain ownership, defense-in-depth
	 * against a forgotten controller preamble), be visible to the acting
	 * user (404 anti-enumeration for invisible tasks), and the actor must be
	 * owner or member (children → 403). Every target user ID must belong to
	 * the given house — checked before the write and revalidated under the
	 * room-task row lock (CS-PLAN-063 §3.4); a miss rolls back with zero
	 * writes (→ 400).
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string[] $userIds Nextcloud user IDs to assign
	 * @param int $houseId The house ID for membership validation
	 * @param HouseUser $actor The acting user's house membership
	 * @return TaskAssignee[] The resulting assignee list
	 * @throws NotFoundException if the room task does not exist, is foreign, or is invisible to the actor
	 * @throws ForbiddenException if the actor is a child
	 */
	public function setAssignees(int $roomTaskId, array $userIds, int $houseId, HouseUser $actor): array {
		$this->validateRoomTaskOwnership($roomTaskId, $houseId);
		$this->assertRoomTaskVisible($roomTaskId, $actor);
		$this->authorization->assertCanManageTasks($actor);
		$this->assertHouseMembers($userIds, $houseId);

		$uniqueIds = array_values(array_unique(array_filter(
			$userIds,
			fn ($id) => is_string($id) && trim($id) !== ''
		)));

		return $this->replaceAssigneesUnderLock($roomTaskId, $uniqueIds, $houseId, $actor->getNextcloudUserId(), false);
	}

	/**
	 * Assert the room task's underlying definition is visible to the actor
	 * (404 anti-enumeration, decision D3).
	 *
	 * @throws NotFoundException when the matrix denies the actor
	 */
	private function assertRoomTaskVisible(int $roomTaskId, HouseUser $actor): void {
		$roomTask = $this->findRoomTaskOrNotFound($roomTaskId);
		if ($roomTask->getDeletedAt() !== null) {
			// Tombstoned = gone from active planning (Q-70-01): 404, the
			// same denial shape as an invisible id.
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}
		$task = $this->findTaskOrNotFound($roomTask->getTaskId());
		$this->taskAccessPolicy->assertCanView($task, $actor, $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTaskId));
	}

	/**
	 * Fetch a task definition or translate the mapper's DoesNotExistException
	 * into the app NotFoundException (404, CS-PLAN-061 §3.5).
	 *
	 * @throws NotFoundException if not found
	 */
	private function findTaskOrNotFound(int $taskId): Task {
		try {
			return $this->taskMapper->find($taskId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Task not found'));
		}
	}

	/**
	 * Update room task due date
	 *
	 * Writer seam (CS-PLAN-065 §3.2, ADR-012; CS-PLAN-071 WP0): the write
	 * runs in one transaction under the single house-first order — the
	 * owning house row lock (resolved via room → floor → house) first,
	 * then the room-task row lock — so a concurrent scheduling apply,
	 * completion or member removal serializes instead of interleaving with
	 * the raw read-modify-write.
	 *
	 * CS-PLAN-067 §3.3: the entity produced INSIDE the transaction is the
	 * authoritative response — the row is locked and re-read under the lock,
	 * so the after-commit re-fetch the former shape used was a redundant
	 * read (removed; response equality pinned by tests).
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string $nextDue The new due date: a house-local `Y-m-d` label
	 *                        (resolved to house-local midnight, ADR-009) or any
	 *                        other parseable datetime interpreted as an instant
	 * @param HouseUser $houseUser The acting user's house membership
	 */
	public function updateDueDate(int $roomTaskId, string $nextDue, HouseUser $houseUser): RoomTask {
		$roomTask = $this->findRoomTaskOrNotFound($roomTaskId);
		$this->assertLifecycleVisibility($roomTask, $this->findTaskOrNotFound($roomTask->getTaskId()), $houseUser);
		$nextDueDate = $this->parseDueDate(
			$nextDue,
			fn (): \DateTimeZone => $this->readService->timeZoneForRoom($roomTask->getRoomId())
		);
		$owningHouseId = $this->readService->houseIdForRoom($roomTask->getRoomId());
		$house = $this->houseMapper->find($owningHouseId);
		$newDateKey = $this->calendarService->dayKey($nextDueDate, $this->calendarService->getTimeZone($house));

		// CS-PLAN-072 WP1 (Q-072-01): due-date edits are AGREED amendments
		// through the common writer — the target keeps its occurrence
		// identity; only unfulfilled occurrences before their old deadline
		// may move; the agreement evidence row records old/new state; the
		// C2h child day limit is checked for the new date before any write.
		$this->transactions->run(function () use ($roomTaskId, $owningHouseId, $houseUser, $newDateKey, $house): void {
			$this->houseMapper->findForUpdate($owningHouseId);
			$roomTask = $this->roomTaskMapper->lockForUpdate($roomTaskId);
			if ($roomTask->getDeletedAt() !== null) {
				// Authoritative tombstone gate (Q-70-01): the amendment
				// may land only on live planning state.
				throw new NotFoundException($this->l10n->t('Room task not found'));
			}
			$this->assertChildCapacityForDate($owningHouseId, $roomTask, $house, $newDateKey);
			$this->occurrenceService->amendOccurrenceDateUnderLock(
				$roomTask,
				$houseUser->getId(),
				null,
				$newDateKey,
				new \DateTime(),
			);
		});

		// Authoritative persisted row (CS-PLAN-067 §3.3): the response is
		// the re-read row, not the caller's stale entity.
		return $this->roomTaskMapper->find($roomTaskId);
	}

	/**
	 * C2h whole-day child limit for a date amendment: no child ASSIGNED to
	 * this task may exceed its maxDailyTasks on the new date (counting the
	 * other agreed occurrences planned that day). Unlimited children never
	 * gate here. RF-072-WP1-11: counts through the ONE shared occurrence-
	 * based counter (open, unfulfilled/uncovered occurrences with
	 * open-or-future windows per member wallet — batched, never
	 * `next_due`-equality, which missed same-day occurrences and
	 * disagreed with the participant-amendment guard).
	 */
	private function assertChildCapacityForDate(int $houseId, RoomTask $roomTask, House $house, string $newDateKey): void {
		$assigneeIds = $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTask->getId());
		if ($assigneeIds === []) {
			return;
		}
		$membersByUserId = [];
		foreach ($this->houseUserMapper->findByHouseId($houseId) as $candidate) {
			$membersByUserId[$candidate->getNextcloudUserId()] = $candidate;
		}

		$otherTaskIds = [];
		foreach ($this->roomTaskMapper->findByHouse($houseId, true) as $otherTask) {
			if ($otherTask->getId() !== $roomTask->getId()) {
				$otherTaskIds[] = $otherTask->getId();
			}
		}
		$dayLoad = $this->occurrenceService->openOccurrenceDayLoadByWallet($otherTaskIds, new \DateTime());

		foreach ($assigneeIds as $userId) {
			$member = $membersByUserId[$userId] ?? null;
			if ($member === null || $member->getRole() !== 'child' || $member->getWalletId() === null) {
				continue;
			}
			$preferences = $member->getSchedulingPreferences();
			$maxDaily = $preferences['maxDailyTasks'] ?? null;
			$walletLoad = $dayLoad[(int)$member->getWalletId()][$newDateKey] ?? 0;
			if (is_int($maxDaily) && $maxDaily > 0 && $walletLoad + 1 > $maxDaily) {
				throw new ConflictException($this->l10n->t('Moving this task would exceed a daily task limit'));
			}
		}
	}

	/**
	 * Parse a due-date input into the persisted instant (RF-058-01): a bare
	 * house-local `Y-m-d` label denotes house-local midnight (ADR-009 —
	 * tagging it at 00:00 UTC/server-TZ would land one local day early for
	 * houses west of UTC); any other parseable datetime is an absolute or
	 * relative instant interpreted as-is. The house timezone is resolved
	 * lazily, only for day-key labels. Unparseable input → 400.
	 *
	 * @param \Closure():\DateTimeZone $houseTzResolver Resolves the owning house's timezone
	 */
	private function parseDueDate(string $nextDue, \Closure $houseTzResolver): \DateTime {
		try {
			if ($this->calendarService->isDayKey($nextDue)) {
				return $this->calendarService->dayStartInstant($nextDue, $houseTzResolver());
			}
			return new \DateTime($nextDue);
		} catch (\Exception $e) {
			throw new \InvalidArgumentException($this->l10n->t('Invalid nextDue date format'));
		}
	}

	/**
	 * Tombstone a room task (CS-PLAN-071 WP0, Q-070-01 / RF-070-16).
	 *
	 * Structural deletion keeps the evidence: inside one house-first
	 * transaction the row is tombstoned (`deleted_at` set, `deleted_token
	 * = id`, `is_active = 0`), its `task_assignees` and checklist-progress
	 * rows are deleted (active-planning state), and its `completed_tasks`
	 * rows are RETAINED (minimum history, existing visibility, valid earned
	 * effects). No production path hard-deletes structural rows outside the
	 * test-mode reset (decision D4).
	 *
	 * Service-level gates (CS-PLAN-061 §3.4): the acting user must be able
	 * to see the room task's definition (404 for invisible tasks) and may
	 * not be a child (403). Tombstone deletion stays owner/managers-only
	 * via the same actor policy as before.
	 *
	 * @param int $roomTaskId The room task ID
	 * @param HouseUser $actor The acting user's house membership
	 * @throws NotFoundException if the room task or its definition is invisible to the actor
	 * @throws ForbiddenException if the actor is a child
	 */
	public function deleteRoomTask(int $roomTaskId, HouseUser $actor): void {
		$this->assertRoomTaskVisible($roomTaskId, $actor);
		$this->authorization->assertCanManageTasks($actor);
		$roomTask = $this->findRoomTaskOrNotFound($roomTaskId);
		$owningHouseId = $this->readService->houseIdForRoom($roomTask->getRoomId());

		$this->transactions->run(function () use ($roomTaskId, $owningHouseId): void {
			// FIRST lock of the documented order: the house row.
			$this->houseMapper->findForUpdate($owningHouseId);

			$locked = $this->roomTaskMapper->lockForUpdate($roomTaskId);
			if ($locked->getDeletedAt() !== null) {
				// Authoritative tombstone gate (Q-70-01): a concurrent
				// delete winner makes this invocation a clean 404 with
				// zero writes — the original tombstone evidence survives.
				throw new NotFoundException($this->l10n->t('Room task not found'));
			}
			$this->roomService->deleteRoomTaskPlanningState([$roomTaskId]);
			$locked->tombstone(new \DateTime());
			$this->roomTaskMapper->update($locked);

			// CS-PLAN-072 WP1: structural deletion stops scheduling — the
			// live revision closes; occurrence/completion evidence stays
			// (minimum history retention, parent §3.3 rule 1).
			$this->occurrenceService->stopSchedulingUnderLock($roomTaskId, new \DateTime());
		});
	}

	/**
	 * Get task statistics for a room, restricted to the viewer's visibility
	 * (CS-PLAN-061 review fix): counts are computed over the visibility-
	 * filtered room-task set, so the stats of a room with invisible private
	 * tasks neither include them nor confirm that they exist.
	 *
	 * CR-SVC-31 root fix (CS-PLAN-040): completionRate counts REAL
	 * completions — the share of the room's current tasks with at least
	 * one completion row — instead of `(total − active)/total`, which
	 * counted deactivated/skipped tasks as completed. An empty room
	 * returns 0 (not 100).
	 *
	 * @param int $roomId The room ID
	 * @param HouseUser $viewer The acting user's house membership
	 */
	public function getRoomTaskStats(int $roomId, HouseUser $viewer): array {
		$allTasks = $this->roomTaskMapper->findByRoomId($roomId, false);
		$visibleTasks = $this->readService->visibleRoomTasks($allTasks, $viewer);
		$tz = $this->readService->timeZoneForRoom($roomId);
		$activeTasks = array_filter($visibleTasks, fn ($task) => $task->getIsActive());
		$overdueTasks = array_filter($activeTasks, fn ($task) => $task->isOverdue());
		$dueTodayTasks = array_filter($activeTasks, fn ($task) => $task->isDueToday($tz));
		$dueSoonTasks = array_filter($activeTasks, fn ($task) => $task->isDueWithinDays(3));

		$visibleIds = array_map(static fn (RoomTask $roomTask): int => $roomTask->getId(), $visibleTasks);
		$completedCount = $this->completedTaskMapper->countDistinctCompletedRoomTasksInSet($visibleIds);

		return [
			'totalTasks' => count($visibleTasks),
			'activeTasks' => count($activeTasks),
			'overdueTasks' => count($overdueTasks),
			'dueTodayTasks' => count($dueTodayTasks),
			'dueSoonTasks' => count($dueSoonTasks),
			'completionRate' => count($visibleTasks) > 0
				? round(min($completedCount, count($visibleTasks)) / count($visibleTasks) * 100, 2)
				: 0
		];
	}

	/**
	 * Bulk assign tasks to user
	 *
	 * Batch pre-validation (CS-PLAN-037, CR-SVC-60): one house-scoped
	 * fetch ({@see RoomTaskMapper::findByIdsScopedToHouse}) replaces the
	 * per-item 3-query ownership chain, and one assertHouseMembers call
	 * replaces the per-item membership fetch (≈7N → 3 + N queries). The
	 * target user is a request-level parameter, so a non-member fails
	 * the whole call up front (→ 400, zero writes); per-ITEM isolation is
	 * preserved — ids that are missing or belong to another house are
	 * skipped and logged without the raw target userId (privacy), and a
	 * failing assignment write never aborts the batch.
	 *
	 * Actor policy + visibility (CS-PLAN-061 §3.4): the acting user must be
	 * owner or member (child → 403), and every requested id that IS scoped
	 * to the house but invisible to the actor fails the whole request up
	 * front with a 404 (anti-enumeration — "first assertCanView"). Items
	 * missing from the house keep their skip isolation.
	 *
	 * Lock discipline (CS-PLAN-063 §3.4, D5): each item write runs in its
	 * own transaction with the room_task lock acquired in ascending-id
	 * order across the batch (deadlock-free), and the target membership is
	 * revalidated under the first lock — an under-lock miss (membership
	 * removed mid-batch) rolls that item back with zero writes and fails
	 * the request with 400 instead of being swallowed as an item skip.
	 *
	 * @param array $roomTaskIds Array of room task IDs
	 * @param string $userId The user ID (must be a house member)
	 * @param int $houseId The caller's house ID (scopes the room tasks)
	 * @param HouseUser $actor The acting user's house membership
	 * @return RoomTask[] The successfully assigned room tasks, in input order
	 * @throws NotFoundException if a house-scoped id is invisible to the actor
	 * @throws ForbiddenException if the actor is a child
	 * @throws \InvalidArgumentException if the target user is not a member (pre-check or under-lock)
	 */
	public function bulkAssignTasks(array $roomTaskIds, string $userId, int $houseId, HouseUser $actor): array {
		$this->authorization->assertCanManageTasks($actor);
		$this->assertHouseMembers([$userId], $houseId);

		$scopedById = [];
		foreach ($this->roomTaskMapper->findByIdsScopedToHouse($roomTaskIds, $houseId) as $scopedRoomTask) {
			$scopedById[$scopedRoomTask->getId()] = $scopedRoomTask;
		}

		// Visibility pre-check for everything that IS in the house: one
		// batched assignee fetch (no N+1), 404 on the first invisible task.
		if ($scopedById !== []) {
			$tasksById = $this->readService->tasksByIdForRoomTasks(array_values($scopedById));
			$assigneesByRoomTask = $this->taskAssigneeMapper->findUserIdsByRoomTasks(array_keys($scopedById));
			foreach ($scopedById as $scopedId => $scopedRoomTask) {
				$task = $tasksById[$scopedRoomTask->getTaskId()] ?? null;
				if ($task === null
					|| !$this->taskAccessPolicy->canView($task, $actor, $assigneesByRoomTask[$scopedId] ?? [])) {
					throw new NotFoundException($this->l10n->t('Room task not found'));
				}
			}
		}

		$scopedIds = array_fill_keys(array_keys($scopedById), true);

		// Ascending-id write order (deadlock-free batch, CS-PLAN-063 §3.4);
		// the response stays in input order.
		$writeOrder = array_values(array_unique(array_map('intval', $roomTaskIds)));
		sort($writeOrder);

		$assignedIds = [];
		foreach ($writeOrder as $roomTaskId) {
			if (!isset($scopedIds[$roomTaskId])) {
				$this->appLogger->warning('Bulk room task assignment skipped failed item', [
					'service' => 'RoomTaskService',
					'roomTaskId' => $roomTaskId,
					'houseId' => $houseId,
					'reason' => 'not_found_in_house',
				]);
				continue;
			}

			try {
				$this->replaceSingleAssignee($roomTaskId, $userId, $houseId, $actor->getNextcloudUserId());
				$assignedIds[] = $roomTaskId;
			} catch (\InvalidArgumentException $e) {
				// Under-lock membership miss (or an equivalent curated 400):
				// the item transaction rolled back with zero writes and the
				// request fails fast instead of silently skipping (D5).
				throw $e;
			} catch (\Exception $e) {
				$this->appLogger->warning('Bulk room task assignment skipped failed item', [
					'service' => 'RoomTaskService',
					'roomTaskId' => $roomTaskId,
					'houseId' => $houseId,
					'exception' => get_class($e),
				]);
				continue;
			}
		}

		if ($assignedIds === []) {
			return [];
		}

		// One re-fetch of the successfully-assigned ids builds the return
		// payload with the persisted assignment state, in input order.
		$freshById = [];
		foreach ($this->roomTaskMapper->findByIdsScopedToHouse($assignedIds, $houseId) as $freshRoomTask) {
			$freshById[$freshRoomTask->getId()] = $freshRoomTask;
		}

		$assignedTasks = [];
		foreach ($roomTaskIds as $roomTaskId) {
			$roomTaskId = (int)$roomTaskId;
			if (isset($freshById[$roomTaskId])) {
				$assignedTasks[] = $freshById[$roomTaskId];
			}
		}

		return $assignedTasks;
	}

	/**
	 * Get scheduled and completed tasks for a house within a date range.
	 *
	 * Occurrence-authority projection (CS-PLAN-072 WP1; supersedes the
	 * whole-room-task projectOccurrences engine): the calendar is built
	 * from AGREED occurrences (materialized rows plus virtual keys the
	 * frontier covers — write-free) plus write-free FUTURE PROJECTIONS
	 * beyond the frontier. Agreed entries carry their public
	 * `<scheduleRevisionId>:<ordinal>` key, version and 'agreed'
	 * classification; projections carry 'projection' and are never
	 * completable obligations. The compact `occurrences` Y-m-d list stays
	 * for response-shape stability.
	 *
	 * @param int $houseId The house ID
	 * @param \DateTime $from Inclusive range start
	 * @param \DateTime $to Inclusive range end
	 * @param HouseUser $viewer The acting user's house membership
	 * @return array{scheduled: array<int, array{roomTask: array, task: array, room: array, flexWindow: array, occurrences: array<int, string>, occurrenceDetails: array<int, array{date: string, occurrenceId: ?string, classification: string, version: ?int, fulfilled: ?bool}>}>, completed: array<int, array>}
	 */
	public function getRangeTasks(int $houseId, \DateTime $from, \DateTime $to, HouseUser $viewer): array {
		// CS-PLAN-057: reinterpret the controller's Y-m-d keys as house-local
		// calendar bounds (UTC-normalized instants for the SQL comparison).
		// CS-PLAN-060 §3.6: the completion window is half-open [start, end)
		// — the end bound is the NEXT day's 00:00:00 (exclusive), matching
		// the window-query convention.
		$tz = $this->readService->timeZoneForHouse($houseId);
		$fromKey = $from->format('Y-m-d');
		$toKey = $to->format('Y-m-d');
		$fromBound = (new \DateTime($fromKey . ' 00:00:00', $tz))
			->setTimezone(new \DateTimeZone('UTC'));
		$toBoundExclusive = (new \DateTime($toKey . ' 00:00:00', $tz))
			->modify('+1 day')
			->setTimezone(new \DateTimeZone('UTC'));

		// Base set: every active task of the house (the occurrence
		// authority decides which recur into the window — tombstoned and
		// inactive rows cannot agree on anything).
		$roomTasks = $this->roomTaskMapper->findByHouse($houseId, true);
		$completions = $this->completedTaskMapper->findCompletionsBetweenByHouse($houseId, $fromBound, $toBoundExclusive);
		$completions = $this->redactInvisibleCompletionRows($completions, $viewer);

		$taskIds = [];
		$roomIds = [];
		foreach ($roomTasks as $rt) {
			$taskIds[$rt->getTaskId()] = true;
			$roomIds[$rt->getRoomId()] = true;
		}

		$tasksById = $this->taskMapper->findByIds(array_keys($taskIds));
		$roomsById = $this->roomMapper->findByIds(array_keys($roomIds));

		// Visibility filter (CS-PLAN-061 §3.3): private/admin-only base rows
		// must not reach the projection — their full task definition is
		// serialized into the response.
		$roomTasks = $this->taskAccessPolicy->filterVisibleRoomTasks($roomTasks, $tasksById, $viewer);

		// One batched agreed derivation for the whole window.
		$agreedByTask = $this->occurrenceService->agreedOccurrencesForHouseTasks($houseId, $roomTasks, $fromKey, $toKey);

		$scheduled = [];
		foreach ($roomTasks as $rt) {
			$task = $tasksById[$rt->getTaskId()] ?? null;
			$room = $roomsById[$rt->getRoomId()] ?? null;
			if ($task === null || $room === null) {
				// Orphaned row (task/room definition gone) — skip it.
				continue;
			}

			$details = [];
			foreach ($agreedByTask[$rt->getId()] ?? [] as $entry) {
				$occurrence = $entry['occurrence'];
				$details[] = [
					'date' => $occurrence->getPlannedDate()->format('Y-m-d'),
					'occurrenceId' => $occurrence->toPublicKey(),
					'classification' => 'agreed',
					'version' => $occurrence->getVersion(),
					'fulfilled' => $occurrence->getCompletionId() !== null,
				];
			}

			// Write-free future projections beyond the frontier.
			foreach ($this->occurrenceService->projectionsForTask($rt, $fromKey, $toKey) as $projection) {
				$details[] = [
					'date' => $projection['date'],
					'occurrenceId' => null,
					'classification' => 'projection',
					'version' => null,
					'fulfilled' => null,
				];
			}

			usort($details, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

			// Skip tasks that produce no occurrence inside the window.
			if ($details === []) {
				continue;
			}

			$flexWindow = $rt->getFlexWindowBounds($task->getFlexDays());

			$scheduled[] = [
				'roomTask' => $rt->jsonSerialize($tz),
				'task' => $task->jsonSerialize(),
				'room' => $room->jsonSerialize(),
				'flexWindow' => [
					'earliest' => $flexWindow['earliest']?->format('Y-m-d'),
					'ideal' => $flexWindow['ideal']?->format('Y-m-d'),
					'latest' => $flexWindow['latest']?->format('Y-m-d'),
				],
				// House-local Y-m-d day keys (CS-PLAN-057) — the calendar
				// bucketing convention shared with the frontend.
				'occurrences' => array_map(
					static fn (array $detail): string => $detail['date'],
					$details
				),
				'occurrenceDetails' => $details,
			];
		}

		return [
			'scheduled' => $scheduled,
			'completed' => $completions,
		];
	}

	/**
	 * Redact the task content of completion-history rows the viewer may not
	 * see (CS-PLAN-061 review fix): rows stay house-scoped (decision D9 —
	 * aggregate calendar history), but the task NAME of an invisible
	 * private/admin-only task is nulled instead of leaking into the
	 * response. One batched task fetch + one batched assignee fetch (no
	 * N+1).
	 *
	 * @param array<int, array<string, mixed>> $completions Flat completion rows
	 * @param HouseUser $viewer The acting user's house membership
	 * @return array<int, array<string, mixed>> Rows with redacted task names
	 */
	private function redactInvisibleCompletionRows(array $completions, HouseUser $viewer): array {
		if ($completions === []) {
			return [];
		}

		$taskIdSet = [];
		foreach ($completions as $row) {
			$taskIdSet[(int)$row['taskId']] = true;
		}
		$taskIds = array_keys($taskIdSet);

		$tasksById = $this->taskMapper->findByIds($taskIds);
		$assigneesByTask = $this->taskAssigneeMapper->findUserIdsByTaskIds($taskIds);

		foreach ($completions as &$row) {
			$taskId = (int)$row['taskId'];
			$task = $tasksById[$taskId] ?? null;
			$visible = $task !== null
				&& $this->taskAccessPolicy->canView($task, $viewer, $assigneesByTask[$taskId] ?? []);
			if (!$visible) {
				$row['taskName'] = null;
			}
		}
		unset($row);

		return $completions;
	}

	/**
	 * Enforce the task visibility matrix for lifecycle mutations (CS-PLAN-056,
	 * CR-053-06; unified via {@see TaskAccessPolicy}, CS-PLAN-061 §3.2).
	 *
	 * One matrix everywhere (decision D2):
	 * - public tasks: any house member
	 * - private tasks: creator ∪ explicit assignees (canonical join table)
	 * - admin-only tasks: house owners
	 *
	 * A non-viewer is denied with the app NotFoundException — the 404
	 * anti-enumeration standard (decision D3): the lifecycle surface must not
	 * confirm that an invisible room-task id exists.
	 *
	 * @param RoomTask $roomTask The room task being mutated
	 * @param Task $task The task definition (visibility source)
	 * @param HouseUser $houseUser The acting user's house membership
	 * @throws NotFoundException if the matrix denies the actor
	 */
	public function assertLifecycleVisibility(RoomTask $roomTask, Task $task, HouseUser $houseUser): void {
		if ($roomTask->getDeletedAt() !== null) {
			// Tombstoned = gone from active planning (Q-70-01): lifecycle
			// mutations (due date, …) answer 404 like an invisible id.
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}
		$assigneeUserIds = $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTask->getId());
		$this->taskAccessPolicy->assertCanView($task, $houseUser, $assigneeUserIds);
	}
}
