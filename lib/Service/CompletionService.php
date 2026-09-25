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
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\ScheduleRevision;
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
 * Owner of the completion/undo aggregates (CS-PLAN-064 WP5, decision D2;
 * CS-PLAN-067 §3.2 decomposition).
 *
 * Every completion writes one immutable effect record — the ledger row with
 * `occurrence_due`, the authoritative `before_state` snapshot and every
 * applied `delta` (decision D1 / ADR-011) — and every undo restores exactly
 * that record ("restore, never recalculate", masterplan D2).
 *
 * This service stays the visible aggregate owner: the transaction boundary,
 * the lock order and the occurrence/actor gates live HERE. Cohesive
 * responsibilities are extracted:
 * - {@see CompletionEffectService}: effect-record snapshot/restore
 * - {@see CompletionPostCommitService}: the guarded post-commit phase
 *
 * Lock order (single house-first order, CS-PLAN-070 §3.5 / CS-PLAN-071
 * WP0 — supersedes the ADR-011/012 completion-only entry order, which is
 * preserved as historical context in the ADR supersession notes):
 *
 *     house → room_task → room → house_user
 *
 * Completion locks the house row first (one serialization point for ALL
 * house mutations), then the room task (fresh entity, ADR-007 #3), then the
 * room row (RoomMapper::lockForUpdate) — two same-room completions serialize
 * on it so cleanliness cannot clobber — then the completer's house_user row
 * (the same row GameLogicService locks for the reward write). Undo follows
 * the identical order, locking the COMPLETER's house_user (the actor may
 * differ). The task definition is deliberately not locked (stable read-only
 * input; ratified deviation, decision D12). Occurrence gates (inactive → 409,
 * stale selection/version/target → 409, visibility → 404/403) run before any
 * reward, cleanliness, or timeline write; occurrence validity replaces the
 * former per-person/day duplicate guard (CS-PLAN-072 WP1: two legitimate
 * occurrences on one day are permitted, two completions of one target are
 * not). Post-commit effects run
 * inside a log-and-degrade boundary (decision D7): a committed completion
 * always answers success, never an ambiguous API failure.
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
	private CompletionEffectService $effectService;
	private CompletionPostCommitService $postCommitService;
	private GameLogicService $gameLogicService;
	private CalendarService $calendarService;
	private TaskAccessPolicy $taskAccessPolicy;
	private OccurrenceService $occurrenceService;
	private CommandService $commandService;
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
		CompletionEffectService $effectService,
		CompletionPostCommitService $postCommitService,
		GameLogicService $gameLogicService,
		CalendarService $calendarService,
		TaskAccessPolicy $taskAccessPolicy,
		OccurrenceService $occurrenceService,
		CommandService $commandService,
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
		$this->effectService = $effectService;
		$this->postCommitService = $postCommitService;
		$this->gameLogicService = $gameLogicService;
		$this->calendarService = $calendarService;
		$this->taskAccessPolicy = $taskAccessPolicy;
		$this->occurrenceService = $occurrenceService;
		$this->commandService = $commandService;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
	}

	/**
	 * Complete one SHOWN occurrence as one transactional aggregate with a
	 * command receipt (CS-PLAN-064 WP5; CS-PLAN-072 WP1 occurrence
	 * contract; parent CS-PLAN-070 T1).
	 *
	 * The client sends the shown target's public occurrence key, its
	 * version and the server-computed selection token; the server owns
	 * work time and target effort. The aggregate resolves a virtual
	 * agreed key through the same generator (materializing at version 0),
	 * rechecks membership/visibility/tombstones under the WP0 house-first
	 * lock, inserts the completion once and fulfills exactly that
	 * occurrence. Occurrence validity replaces the per-person/day
	 * duplicate guard: two legitimate occurrences on one day are
	 * permitted, two completions of one target are not.
	 *
	 * Replay semantics (command receipt): the same (commandId, payload)
	 * returns the original completion identity plus freshly read,
	 * authorized projections — authorization runs on the receipt-hit path
	 * too — with a `replay` marker and WITHOUT repeating post-commit
	 * notifications/celebration. A changed payload or a new command on a
	 * fulfilled target conflicts (409).
	 *
	 * @param int $houseId The caller's house ID (scopes the room task)
	 * @param string $userId The Nextcloud user ID completing the task
	 * @param int $roomTaskId The room task ID
	 * @param string $commandId Client-supplied idempotency UUID
	 * @param string $occurrenceId The shown target's public occurrence key
	 * @param int $expectedVersion The shown target's occurrence version
	 * @param string $selectionToken The shown target's selection token
	 * @return array{roomTask: RoomTask, room: Room, task: Task, rewards: array<string, mixed>, completionId: ?int, occurrence: ?array, replay: bool}
	 * @throws NotFoundException unknown/invisible room task (404 anti-enumeration)
	 * @throws ForbiddenException non-member
	 * @throws ConflictException stale selection/version, fulfilled target, inactive task (409)
	 */
	public function complete(
		int $houseId,
		string $userId,
		int $roomTaskId,
		string $commandId,
		string $occurrenceId,
		int $expectedVersion,
		string $selectionToken,
	): array {
		// Input validation before any side effect (parent §3.4): the
		// public key is canonically parsed here so a malformed occurrenceId
		// is a clean 400, never a 409 or a 500.
		Occurrence::parsePublicKey($occurrenceId);

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

		// CR-SVC-10: early membership mapping to the clean 403 (the
		// in-transaction re-check below stays the authoritative gate).
		$houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
		if ($houseUser === null) {
			throw new ForbiddenException($this->l10n->t('Only house members can complete tasks'));
		}

		try {
			$command = $this->commandService->runCommand(
				$houseId,
				(int)$houseUser->getId(),
				$commandId,
				'room_task.complete',
				[
					'roomTaskId' => $roomTaskId,
					'occurrenceId' => $occurrenceId,
					'expectedVersion' => $expectedVersion,
					'selectionToken' => $selectionToken,
				],
				function () use ($houseId, $userId, $roomTaskId, $timeZone, $occurrenceId, $expectedVersion, $selectionToken): array {
					return $this->completeAggregate($houseId, $userId, $roomTaskId, $timeZone, $occurrenceId, $expectedVersion, $selectionToken);
				}
			);
		} catch (\Throwable $e) {
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

		if ($command['replay']) {
			// Authorization runs on the receipt-hit path as well: membership
			// and visibility are rechecked NOW, against fresh rows, before
			// any projection is returned. The original completion identity
			// comes back with fresh projections and a replay marker; no
			// post-commit notification or celebration repeats.
			$freshRoomTask = $this->findRoomTaskScoped($roomTaskId, $houseId);
			$freshHouseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
			if ($freshHouseUser === null) {
				throw new ForbiddenException($this->l10n->t('Only house members can complete tasks'));
			}
			$this->taskAccessPolicy->assertCanView(
				$this->findTaskOrNotFound($freshRoomTask->getTaskId()),
				$freshHouseUser,
				$this->taskAssigneeMapper->findUserIdsByRoomTask($roomTaskId)
			);

			return [
				'roomTask' => $freshRoomTask,
				'room' => $this->findRoomOrNotFound($freshRoomTask->getRoomId()),
				'task' => $task,
				'rewards' => [],
				'completionId' => (int)($command['result']['completionId'] ?? 0) ?: null,
				'occurrence' => $this->selectionBlock($freshRoomTask, $house),
				'replay' => true,
			];
		}

		$outcome = $command['result']['aggregate'];
		$completionRowId = (int)($command['result']['completionId'] ?? 0);

		$roomTask = $outcome['roomTask'];
		$room = $outcome['room'];
		$gameResult = $outcome['gameResult'];
		$cleanlinessChange = $outcome['cleanlinessChange'];
		$takeoverNotification = $outcome['takeoverNotification'];

		// Post-commit boundary (decision D7): the weekly-goal read, the
		// notification claims, and the dispatches are failure-isolated — a
		// committed completion always answers success, with
		// `rewards.degraded = true` if any post-commit effect failed.
		$post = $this->postCommitService->runForCompletion(
			$houseId,
			$roomTaskId,
			$userId,
			$gameResult,
			$takeoverNotification
		);
		$weeklyGoal = $post['weeklyGoal'];
		$degraded = $post['degraded'];

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
			'completionId' => $completionRowId ?: null,
			'occurrence' => $this->selectionBlock($roomTask, $house),
			'replay' => false,
		];
	}

	/**
	 * The first-execution aggregate inside the command transaction. The
	 * house lock is already held (CommandService::runCommand); this body
	 * follows the documented order room_task → occurrence → room →
	 * house_user and returns the result identity.
	 *
	 * @return array{completionId: int, aggregate: array<string, mixed>}
	 */
	private function completeAggregate(int $houseId, string $userId, int $roomTaskId, \DateTimeZone $timeZone, string $occurrenceId, int $expectedVersion, string $selectionToken): array {
		// CR-SVC-53: replace the pre-lock entity with the freshly locked
		// one.
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
		$task = $this->findTaskOrNotFound($roomTask->getTaskId());
		$this->taskAccessPolicy->assertCanView($task, $houseUser, $assigneeUserIds);

		// Occurrence gate 1 (decision D4): an inactive room task must
		// never be completed or earn rewards — 409 before any mutation.
		if (!$roomTask->getIsActive()) {
			throw new ConflictException($this->l10n->t('Task is not active'));
		}

		$now = new \DateTime();

		// Occurrence gate 2 (WP1): resolve the SHOWN target — a virtual
		// agreed key materializes at version 0 through the same generator,
		// then version/token/selection are verified. Stale state is a 409;
		// a fulfilled target is a 409; the server never silently credits
		// another occurrence.
		$resolved = $this->occurrenceService->resolveForCompletionUnderLock(
			$houseId,
			$roomTask,
			$occurrenceId,
			$expectedVersion,
			$selectionToken,
			$now
		);
		/** @var Occurrence $occurrence */
		$occurrence = $resolved['occurrence'];
		/** @var ScheduleRevision $revision */
		$revision = $resolved['revision'];
		$classification = $resolved['classification'];

		// NEXT lock: the room row. Two concurrent completions of
		// different tasks in the same room serialize here, so the
		// cleanliness read-modify-write can no longer clobber a
		// concurrent winner's delta.
		$room = $this->roomMapper->lockForUpdate($roomTask->getRoomId());

		// FINAL lock: the completer's reward row (same row the reward
		// phase locks — the snapshot below must read locked state).
		$completer = $this->lockCompleterOrForbidden(
			$houseUser->getId(),
			$this->l10n->t('Only house members can complete tasks')
		);

		// The ledger row is created per attempt inside the
		// transaction so a transient-lock retry re-runs a clean
		// aggregate (the executor's bounded retry contract).
		$ledgerRow = new CompletedTask();

		// Snapshot the authoritative before-state BEFORE any mutation
		// (decision D1 — the exact undo inverse).
		$beforeState = $this->effectService->snapshot($roomTask, $room, $completer);
		$ledgerRow->setBeforeState($beforeState);
		$ledgerRow->setOccurrenceDue(\DateTime::createFromInterface($occurrence->getPlannedDate()));

		// Frozen timeline evidence (WP1): public key parts, the server-
		// owned work time basis and the completion classification.
		$savedZone = new \DateTimeZone($occurrence->getTimezone());
		$workDateKey = $this->calendarService->dayKey($now, $savedZone);
		$ledgerRow->setScheduleRevId($occurrence->getScheduleRevId());
		$ledgerRow->setOccurrenceOrdinal($occurrence->getOrdinal());
		$ledgerRow->setWorkDate(\DateTime::createFromFormat('!Y-m-d', $workDateKey));
		$ledgerRow->setWorkZone($occurrence->getTimezone());
		$ledgerRow->setCompletionClass($classification);

		// Overdue-takeover: only record a takeover when a NON-assignee
		// picks up an overdue task (decided on the fresh locked entity,
		// CR-SVC-53). Dispatch is deferred post-commit.
		$takeoverNotification = null;
		$takeoverClaimed = false;
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
		$inserted = $this->completedTaskMapper->insert($ledgerRow);

		// Timeline effects (WP1): fulfill exactly this occurrence, cover
		// the older catch-up miss, anchor the interval successor in the
		// saved local basis and refresh the derived next_due projection.
		$this->occurrenceService->applyCompletionUnderLock(
			$houseId,
			$occurrence,
			$revision,
			$roomTask,
			(int)$inserted->getId(),
			$workDateKey,
			$classification,
			$now
		);

		return [
			'completionId' => (int)$inserted->getId(),
			'aggregate' => [
				'roomTask' => $this->roomTaskMapper->find($roomTaskId),
				'room' => $room,
				'gameResult' => $gameResult,
				'cleanlinessChange' => $cleanlinessChange,
				'takeoverNotification' => $takeoverNotification,
			],
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
	 * CS-PLAN-071 WP0 (Q-070-01): undo stays authorized across a
	 * TOMBSTONED room task — the row still loads (the house-scoped
	 * resolution is not active-filtered), and completer/owner rights are
	 * unchanged; the retained `completed_tasks` evidence stays revertible.
	 * Full targeted-undo semantics remain WP3.
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
		// Q-70-02 (CS-PLAN-071): an ERASED completer (NULL user_id) gets the
		// same clean 403 — a null id must never reach the int-typed mapper.
		if ($ledgerRow->getUserId() === null) {
			throw new ForbiddenException($this->l10n->t('The completer is no longer a member of this house'));
		}
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
		try {
			$outcome = $this->transactions->run(function () use ($houseId, $roomTaskId, $ledgerRow): ?array {
				// FIRST lock: the house row (single house-first order,
				// CS-PLAN-071 WP0); SECOND lock: the room task row.
				$this->houseMapper->findForUpdate($houseId);
				$lockedRoomTask = $this->roomTaskMapper->lockForUpdate($roomTaskId);

				// Under-lock revertible re-check: a concurrent undo winner makes
				// this invocation the idempotent no-op (zero writes).
				$current = $this->completedTaskMapper->findNewestRevertibleByRoomTask($roomTaskId);
				if ($current === null || $current->getId() !== $ledgerRow->getId()) {
					return ['noop' => true, 'roomTask' => $lockedRoomTask];
				}

				// Erased completer (NULL actor, Q-70-02): no reward row
				// exists to restore — the same clean 403 as above, never a
				// TypeError on the null user id.
				if ($current->getUserId() === null) {
					throw new ForbiddenException($this->l10n->t('The completer is no longer a member of this house'));
				}

				// NEXT lock: the room row; FINAL lock: the COMPLETER's reward
				// row (the actor may differ from the completer).
				$lockedRoom = $this->roomMapper->lockForUpdate($lockedRoomTask->getRoomId());
				$completer = $this->lockCompleterOrForbidden(
					$current->getUserId(),
					$this->l10n->t('The completer is no longer a member of this house')
				);

				// Monetary/room/claim restores (snapshot-authoritative until
				// the WP3 cutover; recurrence projection fields and
				// checklist progress are no longer restored from the
				// snapshot — the timeline re-derives them).
				$this->effectService->restore($lockedRoomTask, $lockedRoom, $completer, $current->getBeforeState(), $current->getDeltas());

				// Mark undone BEFORE the timeline release so the
				// re-derivation (lastCompleted, pending successor) reads
				// the remaining VALID history — the undone row is not a
				// completion anywhere (D11).
				$this->completedTaskMapper->markUndone($current->getId(), new \DateTime());

				// Timeline seam (CS-PLAN-072 WP1): resolve the RECORDED
				// occurrence, release it, restore catch-up coverage and
				// re-derive open interval successors anchored on this
				// completion — later valid work always survives.
				$occurrence = $this->occurrenceService->occurrenceOfCompletion($current);
				if ($occurrence !== null) {
					$this->occurrenceService->releaseCompletionUnderLock(
						$houseId,
						$occurrence,
						$this->roomTaskMapper->find($roomTaskId),
						$current,
						new \DateTime()
					);
				}

				return [
					'noop' => false,
					'roomTask' => $this->roomTaskMapper->find($roomTaskId),
					'room' => $lockedRoom,
					'current' => $current,
					'completer' => $completer,
				];
			});
		} catch (\Throwable $e) {
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

		if ($outcome['noop']) {
			$roomTask = $outcome['roomTask'];
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
				'occurrence' => $this->selectionBlock($roomTask, $this->houseMapper->find($houseId)),
			];
		}

		$undone = true;
		$roomTask = $outcome['roomTask'];
		$room = $outcome['room'];
		$current = $outcome['current'];
		$completer = $outcome['completer'];

		$beforeState = $current->getBeforeState();

		// Post-commit weekly-goal refresh in the same log-and-degrade
		// boundary (D7); the weekly-goal claim itself is a non-retraction (D8).
		$post = $this->postCommitService->runForUndo($houseId, $roomTaskId, $actor->getNextcloudUserId());
		$weeklyGoal = $post['weeklyGoal'];
		$postUndoDegraded = $post['degraded'];

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
			'occurrence' => $this->selectionBlock($roomTask, $this->houseMapper->find($houseId)),
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
	 * Fresh, write-free selection block of a room task's next completion
	 * target (WP1 DTO enrichment). RF-072-WP1-15: ONE builder —
	 * {@see OccurrenceService::occurrenceBlock()} produces the block the
	 * completion endpoint, the enriched row reads and this selection block
	 * all emit into the same frontend cache slot; this wrapper only adds
	 * the house-relative saved-zone flag.
	 */
	private function selectionBlock(RoomTask $roomTask, House $house): ?array {
		$houseId = (int)$house->getId();
		$selection = $this->occurrenceService->selectionFor($roomTask, new \DateTime(), $houseId);
		if ($selection === null) {
			return null;
		}
		return $this->occurrenceService->occurrenceBlock(
			$selection['occurrence'],
			$selection['revision'],
			$selection['classification'],
			$houseId,
			null,
			$selection['workAppointment'] ?? null
		) + [
			'savedZoneDiffers' => $selection['occurrence']->getTimezone() !== $this->calendarService->getTimeZone($house)->getName(),
		];
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
