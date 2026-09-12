<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgress;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Owner of the completion/undo aggregates (CS-PLAN-064 WP5, decision D2).
 *
 * Every completion writes one immutable effect record — the ledger row with
 * `occurrence_due`, the authoritative `before_state` snapshot and every
 * applied `delta` (decision D1 / ADR-011) — and every undo restores exactly
 * that record ("restore, never recalculate", masterplan D2).
 *
 * Lock order (ADR-011, deterministic, deadlock-free against setup/creation/
 * assignment usage):
 *
 *     room_task → room → house_user
 *
 * The task definition is deliberately NOT locked (decision D12): it is
 * stable read-only input, read once before the mutations. Occurrence gates
 * (inactive → 409, day-duplicate → 403, visibility → 404/403) run before
 * any reward, cleanliness, checklist, or recurrence write. Post-commit
 * effects run inside a log-and-degrade boundary (decision D7): a committed
 * completion always answers success, never an ambiguous API failure.
 */
class CompletionService {
	private TransactionExecutor $transactions;
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private HouseMapper $houseMapper;
	private HouseUserMapper $houseUserMapper;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private RoomTaskChecklistProgressMapper $checklistProgressMapper;
	private GameLogicService $gameLogicService;
	private HouseService $houseService;
	private CalendarService $calendarService;
	private TaskAccessPolicy $taskAccessPolicy;
	private NotificationService $notificationService;
	private IL10N $l10n;
	private AppLogger $appLogger;

	public function __construct(
		TransactionExecutor $transactions,
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		RoomMapper $roomMapper,
		HouseMapper $houseMapper,
		HouseUserMapper $houseUserMapper,
		TaskAssigneeMapper $taskAssigneeMapper,
		CompletedTaskMapper $completedTaskMapper,
		RoomTaskChecklistProgressMapper $checklistProgressMapper,
		GameLogicService $gameLogicService,
		HouseService $houseService,
		CalendarService $calendarService,
		TaskAccessPolicy $taskAccessPolicy,
		NotificationService $notificationService,
		IL10N $l10n,
		AppLogger $appLogger,
	) {
		$this->transactions = $transactions;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->taskMapper = $taskMapper;
		$this->roomMapper = $roomMapper;
		$this->houseMapper = $houseMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->taskAssigneeMapper = $taskAssigneeMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->checklistProgressMapper = $checklistProgressMapper;
		$this->gameLogicService = $gameLogicService;
		$this->houseService = $houseService;
		$this->calendarService = $calendarService;
		$this->taskAccessPolicy = $taskAccessPolicy;
		$this->notificationService = $notificationService;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
	}

	/**
	 * Complete a room task as one transactional aggregate with a full
	 * effect record (CS-PLAN-064 WP5).
	 *
	 * @param int $houseId The caller's house ID (scopes the room task)
	 * @param string $userId The Nextcloud user ID completing the task
	 * @param int $roomTaskId The room task ID
	 * @return array{roomTask: RoomTask, room: Room, task: Task, rewards: array<string, mixed>}
	 * @throws NotFoundException unknown/invisible room task (404 anti-enumeration)
	 * @throws ForbiddenException non-member, day-duplicate
	 * @throws ConflictException inactive occurrence (409, decision D4)
	 */
	public function complete(int $houseId, string $userId, int $roomTaskId): array {
		// Pre-transaction resolution (existing behavior). The house-scoped
		// fetch is both the resolution and the ownership check: a room task
		// of another house is absent → clean 404.
		$roomTask = $this->findRoomTaskScoped($roomTaskId, $houseId);
		$task = $this->findTaskOrNotFound($roomTask->getTaskId());
		$room = $this->findRoomOrNotFound($roomTask->getRoomId());
		$house = $this->houseMapper->find($houseId);

		// CS-PLAN-057: resolve the house timezone once per request pass and
		// thread it explicitly into every day-boundary decision below.
		$timeZone = $this->calendarService->getTimeZone($house);
		[$dayStart, $dayEnd] = $this->calendarService->dayBounds(new \DateTime(), $timeZone);

		// CR-SVC-10: early membership mapping to the clean 403 (the
		// in-transaction re-check below stays the authoritative gate).
		$houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
		if ($houseUser === null) {
			throw new ForbiddenException($this->l10n->t('Only house members can complete tasks'));
		}

		// Captured inside the transaction if a takeover happens, dispatched
		// after commit so rollbacks never leave a stale notification.
		$takeoverNotification = null;
		$takeoverClaimed = false;
		$ledgerRow = new CompletedTask();
		$gameResult = [];
		$cleanlinessChange = 0;

		$this->transactions->begin();
		try {
			// CR-SVC-53: replace the pre-lock entity with the freshly locked
			// one — the FIRST lock of the documented order.
			$roomTask = $this->roomTaskMapper->lockForUpdate($roomTaskId);

			// CR-SVC-10: re-resolve membership under the lock — a member
			// removed between the pre-transaction fetch and this point must
			// yield a clean 403, not a dereference of a stale entity.
			$houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
			if ($houseUser === null) {
				throw new ForbiddenException($this->l10n->t('Only house members can complete tasks'));
			}

			// Canonical assignee set (CS-PLAN-060 WP1, D2) — visibility
			// matrix (ADR-010, 404 anti-enumeration) and teamwork input (D9).
			$assigneeUserIds = $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTaskId);
			$this->taskAccessPolicy->assertCanView($task, $houseUser, $assigneeUserIds);

			// Occurrence gate 1 (decision D4): an inactive room task must
			// never be completed or earn rewards — 409 before any mutation.
			if (!$roomTask->getIsActive()) {
				throw new ConflictException($this->l10n->t('Task is not active'));
			}

			// Occurrence gate 2: per-user house-local day duplicate
			// (counted over non-undone rows only, decision D11) → 403.
			$todayCount = $this->completedTaskMapper->countByRoomTaskAndUserOnDate(
				$roomTaskId,
				$houseUser->getId(),
				$dayStart,
				$dayEnd
			);
			if ($todayCount > 0) {
				throw new ForbiddenException($this->l10n->t('You already completed this task today'));
			}

			// SECOND lock (WP5): the room row. Two concurrent completions of
			// different tasks in the same room serialize here, so the
			// cleanliness read-modify-write can no longer clobber a
			// concurrent winner's delta.
			$room = $this->roomMapper->lockForUpdate($roomTask->getRoomId());

			// THIRD lock: the completer's reward row (same row the reward
			// phase locks — the snapshot below must read locked state).
			$completer = $this->lockCompleterOrForbidden(
				$houseUser->getId(),
				$this->l10n->t('Only house members can complete tasks')
			);

			// Snapshot the authoritative before-state BEFORE any mutation
			// (decision D1 — the exact undo inverse).
			$beforeState = $this->snapshotBeforeState($roomTask, $room, $completer);
			$ledgerRow->setBeforeState($beforeState);
			$ledgerRow->setOccurrenceDue($this->occurrenceDay($roomTask, $timeZone));

			// Overdue-takeover: only record a takeover when a NON-assignee
			// picks up an overdue task (decided on the fresh locked entity,
			// CR-SVC-53). Dispatch is deferred post-commit.
			if ($roomTask->isOverdue() && !in_array($userId, $assigneeUserIds, true)) {
				$originalAssignee = $assigneeUserIds[0] ?? null;
				if ($originalAssignee !== null && $originalAssignee !== '') {
					$roomTask->markClaimed($originalAssignee, $userId);
					$takeoverClaimed = true;
					$takeoverNotification = [
						'recipient' => $originalAssignee,
						'taskName' => $task->getName(),
						'claimerUserId' => $userId,
						'claimerDisplayName' => $houseUser->getDisplayName(),
						'roomTaskId' => $roomTaskId,
					];
				}
			}

			// Recurrence advance (unchanged entity logic).
			$preferences = $completer->getSchedulingPreferences();
			$roomTask->markCompleted(
				new \DateTime(),
				$task->getFrequencyType(),
				$task->getFrequencyValue(),
				$task->getRecurrenceMode(),
				$task->getSchedulingMode(),
				$task->getActiveUntil(),
				$task->getFixedScheduleTime(),
				$task->getFixedScheduleDays(),
				$task->getFlexDays(),
				$preferences['weekdayAvailability'] ?? [],
				$preferences['energyBudget'] ?? [],
				$timeZone
			);
			$this->roomTaskMapper->update($roomTask);

			// Occurrence-scoped checklist reset (decision D6): checked items
			// must not leak into the next occurrence — first caller of
			// resetProgress, inside the same transaction.
			$this->checklistProgressMapper->resetProgress($roomTaskId);

			// Cleanliness on the LOCKED room row (RMW seam closed).
			$cleanlinessChange = $task->getEnergyCost() * 10; // 10% per energy point
			$room->updateCleanliness($cleanlinessChange);
			$room = $this->roomMapper->update($room);

			// Reward phase (frozen formulas). The teamwork bonus is folded
			// into the single locked house-user write (decision D9); the
			// caller-owned ledger row is filled but NOT inserted here.
			$gameResult = $this->gameLogicService->handleTaskCompletion(
				$roomTask,
				$completer->getId(),
				$task,
				$timeZone,
				$assigneeUserIds,
				$ledgerRow
			);

			// Complete the delta record with everything the reward phase
			// produced, then insert ONE ledger row carrying before-state and
			// deltas (decision D1).
			$ledgerRow->setDeltas([
				'coinsAwarded' => ($gameResult['coins'] ?? 0) + ($gameResult['teamworkBonus'] ?? 0),
				'coins' => $gameResult['coins'] ?? 0,
				'teamworkBonus' => $gameResult['teamworkBonus'] ?? 0,
				'contributionAwarded' => $gameResult['contributionScore'] ?? 0,
				'streakBefore' => $beforeState['houseUser']['currentStreak'],
				'streakAfter' => $gameResult['currentStreak'] ?? 0,
				'freezeUsed' => (bool)($gameResult['streakFreezeUsed'] ?? false),
				'freezeAwarded' => (bool)($gameResult['comboBonusFreeze'] ?? false),
				'titlesAdded' => $gameResult['titlesAdded'] ?? [],
				'cleanlinessChange' => $cleanlinessChange,
				'takeoverClaimed' => $takeoverClaimed,
			]);
			$this->completedTaskMapper->insert($ledgerRow);

			$this->transactions->commit();
		} catch (\Throwable $e) {
			$this->transactions->rollBack();
			$this->appLogger->logException('CompletionService: task completion rolled back', $e, [
				'event' => 'completion_rollback',
				'service' => 'CompletionService',
				'houseId' => $houseId,
				'roomId' => $room->getId(),
				'roomTaskId' => $roomTaskId,
				'userId' => $userId,
			]);
			throw $e;
		}

		// Post-commit boundary (decision D7): the weekly-goal read, the
		// notification claims, and the dispatches are failure-isolated — a
		// committed completion always answers success, with
		// `rewards.degraded = true` if any post-commit effect failed.
		$degraded = false;
		$weeklyGoal = null;
		try {
			$weeklyGoal = $this->houseService->getWeeklyGoalProgress($houseId);

			$this->dispatchTakeoverNotification($takeoverNotification, $houseId);

			if ($weeklyGoal !== null && $weeklyGoal['achieved']) {
				$this->claimAndDispatchWeeklyGoal($houseId, $weeklyGoal);
			}

			$newTitle = $gameResult['newTitle'] ?? null;
			if ($newTitle !== null && $userId !== '') {
				$this->notificationService->notifyTitleUnlocked(
					$userId,
					$newTitle['pack'],
					$newTitle['labelKey'],
					$newTitle['packLabelKey'],
					$newTitle['tierIndex']
				);
			}
		} catch (\Throwable $e) {
			$degraded = true;
			$weeklyGoal = null;
			$this->appLogger->warning('CompletionService: post-commit effects degraded', [
				'event' => 'completion_post_commit_degraded',
				'service' => 'CompletionService',
				'houseId' => $houseId,
				'roomTaskId' => $roomTaskId,
				'userId' => $userId,
				'exception' => get_class($e),
			]);
		}

		$rewards = [
			'cleanlinessChange' => $cleanlinessChange,
			'energyCost' => $task->getEnergyCost(),
			'priority' => $task->getPriority(),
			'contributionScore' => $gameResult['contributionScore'],
			'coins' => $gameResult['coins'],
			'isCombo' => $gameResult['isCombo'],
			'currentStreak' => $gameResult['currentStreak'],
			'streakFreezeUsed' => $gameResult['streakFreezeUsed'] ?? false,
			'streakFreezesRemaining' => $gameResult['streakFreezesRemaining'] ?? 0,
			'comboBonusFreeze' => $gameResult['comboBonusFreeze'] ?? false,
			'teamworkBonus' => $gameResult['teamworkBonus'] ?? 0,
			'weeklyGoal' => $weeklyGoal,
			// CS-PLAN-066 §3.4: the computed unlock travels with the
			// successful completion response — explicit nullable so a
			// post-commit notification failure can never erase an already
			// computed unlock (decision D7 degrade boundary). Undo carries
			// no newTitle (ADR-011 title policy: undo emits no unlock).
			'newTitle' => $gameResult['newTitle'] ?? null,
			// Decision D10: authoritative totals so owning stores reconcile
			// without client-side arithmetic.
			'totalCoins' => $gameResult['totalCoins'],
			'totalContribution' => $gameResult['totalContribution'],
			'longestStreak' => $gameResult['longestStreak'],
		];
		if ($degraded) {
			$rewards['degraded'] = true;
		}

		return [
			'roomTask' => $roomTask,
			'room' => $room,
			'task' => $task,
			'rewards' => $rewards,
		];
	}

	/**
	 * Undo the newest revertible completion of a room task as one
	 * transactional aggregate (CS-PLAN-064 WP5, decision D3).
	 *
	 * Actor gate (masterplan D3): the acting user must be the recorded
	 * completer or a house owner — a child may undo their OWN completion,
	 * never someone else's. No revertible row → idempotent zero-write
	 * success with `undone: false`. Restore assigns every recorded
	 * before-state value; only the `deltas.titlesAdded` keys still present
	 * are removed from the current title set (later unlocks survive, LIFO).
	 * Documented non-retractions (decision D8): medals, the weekly-goal
	 * notification claim, and dispatched notifications.
	 *
	 * @param int $houseId The caller's house ID (scopes the room task)
	 * @param HouseUser $actor The acting user's house membership
	 * @param int $roomTaskId The room task ID
	 * @return array{roomTask: RoomTask, room: Room, task: Task, rewards: array<string, mixed>|null, undone: bool}
	 * @throws NotFoundException unknown/invisible room task
	 * @throws ForbiddenException actor gate denial
	 */
	public function undo(int $houseId, HouseUser $actor, int $roomTaskId): array {
		// Resolve + visibility first (ADR-010): an invisible id is a 404 —
		// the undo surface must not confirm that the room task exists.
		$roomTask = $this->findRoomTaskScoped($roomTaskId, $houseId);
		$task = $this->findTaskOrNotFound($roomTask->getTaskId());
		$room = $this->findRoomOrNotFound($roomTask->getRoomId());
		$this->taskAccessPolicy->assertCanView(
			$task,
			$actor,
			$this->taskAssigneeMapper->findUserIdsByRoomTask($roomTaskId)
		);

		// Idempotency probe (decision D3): nothing revertible → zero-write
		// success. The authoritative re-check happens under the lock below.
		$ledgerRow = $this->completedTaskMapper->findNewestRevertibleByRoomTask($roomTaskId);
		if ($ledgerRow === null) {
			return [
				'roomTask' => $roomTask,
				'room' => $room,
				'task' => $task,
				'rewards' => null,
				'undone' => false,
			];
		}

		// Actor gate: completer-or-owner (child-friendly self-recovery only).
		// RF-05: a completer whose membership was deleted after the
		// completion must yield a clean 403, not a DoesNotExistException 500.
		try {
			$completerUid = $this->houseUserMapper->find($ledgerRow->getUserId())->getNextcloudUserId();
		} catch (DoesNotExistException $e) {
			throw new ForbiddenException($this->l10n->t('The completer is no longer a member of this house'));
		}
		if ($actor->getNextcloudUserId() !== $completerUid && !$actor->isOwner()) {
			throw new ForbiddenException($this->l10n->t('Only the completer or a house owner can undo a completion'));
		}

		$house = $this->houseMapper->find($houseId);
		$timeZone = $this->calendarService->getTimeZone($house);

		$undone = false;
		$this->transactions->begin();
		try {
			// FIRST lock: the room task row.
			$roomTask = $this->roomTaskMapper->lockForUpdate($roomTaskId);

			// Under-lock revertible re-check: a concurrent undo winner makes
			// this invocation the idempotent no-op (zero writes).
			$current = $this->completedTaskMapper->findNewestRevertibleByRoomTask($roomTaskId);
			if ($current === null || $current->getId() !== $ledgerRow->getId()) {
				$this->transactions->rollBack();
				$this->appLogger->info('CompletionService: undo lost the race, no-op', [
					'event' => 'undo_concurrent_noop',
					'service' => 'CompletionService',
					'houseId' => $houseId,
					'roomTaskId' => $roomTaskId,
					'userId' => $actor->getNextcloudUserId(),
				]);

				return [
					'roomTask' => $roomTask,
					'room' => $room,
					'task' => $task,
					'rewards' => null,
					'undone' => false,
				];
			}

			// SECOND lock: the room row; THIRD lock: the COMPLETER's reward
			// row (the actor may differ from the completer).
			$room = $this->roomMapper->lockForUpdate($roomTask->getRoomId());
			$completer = $this->lockCompleterOrForbidden(
				$current->getUserId(),
				$this->l10n->t('The completer is no longer a member of this house')
			);

			$beforeState = $current->getBeforeState();
			$deltas = $current->getDeltas();

			// Restore, never recalculate (masterplan D2): assign every
			// recorded before-state value.
			$beforeRoomTask = $beforeState['roomTask'];
			$roomTask->setNextDue($this->dateOrNull($beforeRoomTask['nextDue'] ?? null));
			$roomTask->setIdealDueDate($this->dateOrNull($beforeRoomTask['idealDueDate'] ?? null));
			$roomTask->setIsActive((bool)($beforeRoomTask['isActive'] ?? true));
			$roomTask->setLastCompleted($this->dateOrNull($beforeRoomTask['lastCompleted'] ?? null));
			$roomTask->setWasClaimed((bool)($beforeRoomTask['wasClaimed'] ?? false));
			$roomTask->setOriginalAssignee($beforeRoomTask['originalAssignee'] ?? null);
			$roomTask->setClaimedByUser($beforeRoomTask['claimedByUser'] ?? null);
			$roomTask->setClaimedAt($this->dateOrNull($beforeRoomTask['claimedAt'] ?? null));
			$this->roomTaskMapper->update($roomTask);

			$beforeRoom = $beforeState['room'];
			$room->setCleanliness((int)($beforeRoom['cleanliness'] ?? $room->getCleanliness()));
			$room->setLastCleaned($this->dateOrNull($beforeRoom['lastCleaned'] ?? null));
			$room = $this->roomMapper->update($room);

			$beforeUser = $beforeState['houseUser'];
			$completer->setCoins((int)($beforeUser['coins'] ?? $completer->getCoins()));
			$completer->setContributionScore((int)($beforeUser['contributionScore'] ?? $completer->getContributionScore()));
			$completer->setCurrentStreak((int)($beforeUser['currentStreak'] ?? $completer->getCurrentStreak()));
			$completer->setLongestStreak((int)($beforeUser['longestStreak'] ?? $completer->getLongestStreak()));
			$completer->setStreakFreezes((int)($beforeUser['streakFreezes'] ?? $completer->getStreakFreezes()));
			$completer->setLastFreezeDate($this->dateOrNull($beforeUser['lastFreezeDate'] ?? null));
			// lastActivity drives the streak-absence calculation, so the
			// restore must rewind it too — a stale completion timestamp would
			// otherwise bridge the undone occurrence's gap (exact state
			// equality, WP5 exit gate).
			$completer->setLastActivity($this->dateOrNull($beforeUser['lastActivity'] ?? null));

			// Titles: remove exactly the deltas.titlesAdded ids STILL
			// present — titles unlocked by later completions survive (D8/LIFO).
			$tiersAdded = $deltas['titlesAdded'] ?? [];
			if (is_array($tiersAdded) && $tiersAdded !== []) {
				$completer->setUnlockedTitles(array_values(array_filter(
					$completer->getUnlockedTitles(),
					static fn (string $tierId): bool => !in_array($tierId, $tiersAdded, true)
				)));
			}
			$this->houseUserMapper->update($completer);

			// Checklist restore: clear whatever accumulated since, then
			// re-insert the snapshotted rows.
			$this->checklistProgressMapper->deleteByRoomTaskId($roomTaskId);
			$this->checklistProgressMapper->restoreProgress(
				$roomTaskId,
				is_array($beforeState['checklist'] ?? null) ? $beforeState['checklist'] : []
			);

			$this->completedTaskMapper->markUndone($current->getId(), new \DateTime());

			$this->transactions->commit();
			$undone = true;
		} catch (\Throwable $e) {
			$this->transactions->rollBack();
			$this->appLogger->logException('CompletionService: undo rolled back', $e, [
				'event' => 'undo_rollback',
				'service' => 'CompletionService',
				'houseId' => $houseId,
				'roomId' => $room->getId(),
				'roomTaskId' => $roomTaskId,
				'userId' => $actor->getNextcloudUserId(),
			]);
			throw $e;
		}

		// Post-commit weekly-goal refresh in the same log-and-degrade
		// boundary (D7); the weekly-goal claim itself is a non-retraction (D8).
		$weeklyGoal = null;
		$postUndoDegraded = false;
		try {
			$weeklyGoal = $this->houseService->getWeeklyGoalProgress($houseId);
		} catch (\Throwable $e) {
			$postUndoDegraded = true;
			$this->appLogger->warning('CompletionService: post-undo effects degraded', [
				'event' => 'undo_post_commit_degraded',
				'service' => 'CompletionService',
				'houseId' => $houseId,
				'roomTaskId' => $roomTaskId,
				'userId' => $actor->getNextcloudUserId(),
				'exception' => get_class($e),
			]);
		}

		$rewards = $this->revertedRewards($current, $beforeState, $task, $completer, $weeklyGoal);
		if ($postUndoDegraded) {
			$rewards['degraded'] = true;
		}

		return [
			'roomTask' => $roomTask,
			'room' => $room,
			'task' => $task,
			'rewards' => $rewards,
			'undone' => $undone,
		];
	}

	/**
	 * Build the reverted-delta rewards payload from the effect record — the
	 * same reconciliation inputs the completion response carries (D10),
	 * including `completerUserId` so the client knows whose user state the
	 * totals describe (the undo actor may be a house owner).
	 *
	 * @param array<string, mixed> $beforeState
	 * @param array<string, mixed>|null $weeklyGoal Post-undo weekly-goal read (degraded → null)
	 */
	private function revertedRewards(
		CompletedTask $ledgerRow,
		array $beforeState,
		Task $task,
		HouseUser $completer,
		?array $weeklyGoal,
	): array {
		$deltas = $ledgerRow->getDeltas();
		$beforeUser = $beforeState['houseUser'];

		return [
			'cleanlinessChange' => -(int)($deltas['cleanlinessChange'] ?? 0),
			'energyCost' => $task->getEnergyCost(),
			'priority' => $task->getPriority(),
			'contributionScore' => -(int)($deltas['contributionAwarded'] ?? 0),
			'coins' => -(int)($deltas['coinsAwarded'] ?? 0),
			'isCombo' => false,
			'currentStreak' => (int)($beforeUser['currentStreak'] ?? 0),
			'streakFreezeUsed' => false,
			'streakFreezesRemaining' => (int)($beforeUser['streakFreezes'] ?? 0),
			'comboBonusFreeze' => false,
			'teamworkBonus' => -(int)($deltas['teamworkBonus'] ?? 0),
			'weeklyGoal' => $weeklyGoal,
			'totalCoins' => (int)($beforeUser['coins'] ?? 0),
			'totalContribution' => (int)($beforeUser['contributionScore'] ?? 0),
			'longestStreak' => (int)($beforeUser['longestStreak'] ?? 0),
			'completerUserId' => $completer->getNextcloudUserId(),
			'revertedCoinsEarned' => (int)($deltas['coinsAwarded'] ?? 0),
			'ledgerRowId' => $ledgerRow->getId(),
		];
	}

	/**
	 * Dispatch the deferred overdue-takeover notification with the
	 * membership re-check at dispatch time (documented defense-in-depth).
	 *
	 * @param array<string, mixed>|null $takeoverNotification
	 */
	private function dispatchTakeoverNotification(?array $takeoverNotification, int $houseId): void {
		if ($takeoverNotification === null) {
			return;
		}

		$stillMember = $this->houseUserMapper->findByHouseAndUser(
			$houseId,
			(string)$takeoverNotification['recipient']
		);
		if ($stillMember === null) {
			return;
		}

		$this->notificationService->notifyOverdueTakenOver(
			(string)$takeoverNotification['recipient'],
			(string)$takeoverNotification['taskName'],
			(string)$takeoverNotification['claimerUserId'],
			(string)$takeoverNotification['claimerDisplayName'],
			(int)$takeoverNotification['roomTaskId']
		);
	}

	/**
	 * Claim the weekly-goal notification dispatch atomically (one bell per
	 * house per achievement week) and dispatch on the win.
	 *
	 * @param array<string, mixed> $weeklyGoal
	 */
	private function claimAndDispatchWeeklyGoal(int $houseId, array $weeklyGoal): void {
		$weekKey = substr((string)$weeklyGoal['weekStart'], 0, 10);
		$claimed = $this->houseMapper->claimWeeklyGoalNotification($houseId, $weekKey);
		if ($claimed) {
			$this->notificationService->notifyWeeklyGoalReachedForHouse(
				$houseId,
				(int)$weeklyGoal['percent'],
				(int)$weeklyGoal['targetPercent'],
				(string)$weeklyGoal['weekStart']
			);
		}
	}

	/**
	 * Lock the completer's house_user row, mapping a vanishing membership to
	 * the clean 403 with the caller's context wording (a completer removed
	 * mid-transaction must not 500 — RF-05: the undo context never answers
	 * with the completion-worded message).
	 */
	private function lockCompleterOrForbidden(int $houseUserId, string $forbiddenMessage): HouseUser {
		try {
			return $this->houseUserMapper->findForUpdate($houseUserId);
		} catch (DoesNotExistException $e) {
			throw new ForbiddenException($forbiddenMessage);
		}
	}

	/**
	 * Snapshot the authoritative before-state (decision D1 / ADR-011 JSON
	 * contract): room task fields, room cleanliness, completer reward
	 * fields, and the room task's checklist progress rows.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshotBeforeState(RoomTask $roomTask, Room $room, HouseUser $completer): array {
		$progress = [];
		foreach ($this->checklistProgressMapper->findByRoomTaskId($roomTask->getId()) as $row) {
			/** @var RoomTaskChecklistProgress $row */
			$progress[] = [
				'checklistItemId' => $row->getChecklistItemId(),
				'userId' => $row->getCheckedByUserId(),
				'checked' => $row->getIsChecked(),
				'checkedAt' => $row->getCheckedAt()?->format('c'),
			];
		}

		return [
			'roomTask' => [
				'nextDue' => $roomTask->getNextDue()?->format('c'),
				'idealDueDate' => $roomTask->getIdealDueDate()?->format('c'),
				'isActive' => $roomTask->getIsActive(),
				'lastCompleted' => $roomTask->getLastCompleted()?->format('c'),
				'wasClaimed' => $roomTask->getWasClaimed(),
				'originalAssignee' => $roomTask->getOriginalAssignee(),
				'claimedByUser' => $roomTask->getClaimedByUser(),
				'claimedAt' => $roomTask->getClaimedAt()?->format('c'),
			],
			'room' => [
				'cleanliness' => $room->getCleanliness(),
				'lastCleaned' => $room->getLastCleaned()?->format('c'),
			],
			'houseUser' => [
				'coins' => $completer->getCoins(),
				'contributionScore' => $completer->getContributionScore(),
				'currentStreak' => $completer->getCurrentStreak(),
				'longestStreak' => $completer->getLongestStreak(),
				'streakFreezes' => $completer->getStreakFreezes(),
				'lastFreezeDate' => $completer->getLastFreezeDate()?->format('c'),
				'unlockedTitles' => $completer->getUnlockedTitles(),
				'lastActivity' => $completer->getLastActivity()?->format('c'),
			],
			'checklist' => $progress,
		];
	}

	/**
	 * The house-local calendar day of the completed occurrence (ADR-009
	 * semantics): the room task's nextDue BEFORE the recurrence advance.
	 */
	private function occurrenceDay(RoomTask $roomTask, \DateTimeZone $timeZone): ?\DateTime {
		$nextDue = $roomTask->getNextDue();
		if ($nextDue === null) {
			return null;
		}

		return \DateTime::createFromInterface($nextDue)
			->setTimezone($timeZone)
			->setTime(0, 0, 0);
	}

	/**
	 * Parse a recorded ISO datetime string (nullable) back into a DateTime.
	 */
	private function dateOrNull(?string $value): ?\DateTime {
		if ($value === null || $value === '') {
			return null;
		}

		return new \DateTime($value);
	}

	/**
	 * Resolve a room task scoped to the house (resolution + ownership check
	 * in one query); a foreign/missing id is a clean 404.
	 */
	private function findRoomTaskScoped(int $roomTaskId, int $houseId): RoomTask {
		$scoped = $this->roomTaskMapper->findByIdsScopedToHouse([$roomTaskId], $houseId);
		$roomTask = $scoped[0] ?? null;
		if ($roomTask === null) {
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}

		return $roomTask;
	}

	/**
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
	 * @throws NotFoundException if not found
	 */
	private function findRoomOrNotFound(int $roomId): Room {
		try {
			return $this->roomMapper->find($roomId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Room not found'));
		}
	}
}
