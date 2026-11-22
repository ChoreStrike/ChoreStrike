<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\CompletionRevision;
use OCA\ChoreStrike\Db\DigestChange;
use OCA\ChoreStrike\Db\DigestChangeMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\Wallet;
use OCA\ChoreStrike\Db\WorkShare;
use OCA\ChoreStrike\Db\WorkShareMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\IL10N;

/**
 * Owner of the completion, undo and attribution aggregates (CS-PLAN-070
 * WP3 §3.3, ADR-021; supersedes the CS-PLAN-064 WP5 snapshot-restore
 * model). The date correction is {@see CompletionDateCommand}; the reads
 * are {@see CompletionReadModel}.
 *
 * Every aggregate runs as ONE CommandService transaction: rechecks the
 * actor/visibility/version under the WP0 house-first lock, writes the
 * immutable evidence (completion row, work_shares, completion_revs), the
 * additive wallet entries, the room journal, the closed cycle with its
 * projections, the dependent recognition and the receipt together. There
 * is NO before-state restore authority: undo and attribution re-derive
 * every dependent projection from valid history (C3e/C3f). The house state
 * version is bumped so cached views become stale.
 *
 * Lock order (single house-first order; CommandService holds the house
 * row): room_task → room → active memberships (actor + actual/responsible
 * members, stable id) → wallets (actual shares, stable id) → cycle/
 * completion rows → their dependent rows. Lower services (WalletService,
 * RoomReplayService, RhythmService) never call this coordinator back.
 *
 * Money: the initial credit is the selected allocation, each share
 * `10 * E * P * factor * bonusPercent / 100` coin minor with the two
 * multipliers frozen on the completion (ADR-021 decision 11). An
 * attribution writes only `newAllocation - oldAllocation` for the UNION of
 * participants (removal = a new allocation of zero), priced with the
 * frozen multipliers. Undo reverses the LATEST selected allocation once,
 * exactly as its share rows recorded it. Unrelated entries remain
 * unchanged; negative balances are valid correction results.
 */
class CompletionService {
	public function __construct(
		private CommandService $commandService,
		private CompletionAccess $access,
		private CompletionLocks $locks,
		private CompletionEvidence $evidence,
		private CompletionRecognition $recognition,
		private CompletionReadModel $readModel,
		private CompletionPostCommitService $postCommit,
		private ParticipantAllocation $allocations,
		private CreditPolicy $creditPolicy,
		private CoinMultiplierService $multipliers,
		private RoomTaskMapper $roomTaskMapper,
		private RoomMapper $roomMapper,
		private HouseMapper $houseMapper,
		private HouseUserMapper $houseUserMapper,
		private TaskAssigneeMapper $taskAssigneeMapper,
		private CompletedTaskMapper $completedTaskMapper,
		private WorkShareMapper $workShareMapper,
		private DigestChangeMapper $digestChangeMapper,
		private DigestScheduleService $digestSchedule,
		private WalletService $walletService,
		private RoomReplayService $roomReplay,
		private CalendarService $calendar,
		private CycleService $cycles,
		private TaskResponsibility $responsibility,
		private ChecklistService $checklists,
		private IL10N $l10n,
		private AppLogger $appLogger,
	) {
	}

	/**
	 * Complete the CURRENT cycle of a room task as one transactional
	 * aggregate with a command receipt (ADR-021 decision 4 + WP3 actual
	 * work).
	 *
	 * The client sends the cycle key and cycle version it showed plus the
	 * ACTUAL participants (`[{userId, sharePct}]`, optional). The target is
	 * the current cycle at any time — before, inside or after its window —
	 * except on the house-local day of the room task's latest valid
	 * completion. A bonus task has no cycle and pays each person once per
	 * day (decision 8); a sleeping task wakes with its completion
	 * (D-091-22). Without explicit participants the assignees are credited
	 * in equal shares, else the completer alone; a deviation from that
	 * requires the explicit `allocationReviewed` confirmation. The recorder
	 * need not take part: anyone records for anyone who may see the task
	 * (decision 10, D-091-20). Every sub-task of the cycle is marked done
	 * with it. Time, rate, multipliers and total work are server-owned.
	 *
	 * @param string|null $cycleKey The shown cycle; null for a bonus or sleeping task
	 * @param array<int, array{userId: string, sharePct: int}> $participants
	 * @throws NotFoundException unknown/invisible room task (404 anti-enumeration)
	 * @throws ForbiddenException non-member, or a credited member who may not see the task
	 * @throws ConflictException stale target, completed cycle, same day, a bonus task that paid today, inactive task (409)
	 */
	public function complete(
		int $houseId,
		string $userId,
		int $roomTaskId,
		string $commandId,
		?string $cycleKey,
		int $expectedVersion,
		array $participants = [],
		bool $allocationReviewed = false,
	): array {
		$participants = $this->allocations->canonicalize($participants);

		$roomTask = $this->access->roomTask($roomTaskId, $houseId);
		$this->access->task($roomTask->getTaskId());
		$house = $this->houseMapper->find($houseId);

		$actorId = (int)$this->access->member($houseId, $userId, null, $this->l10n->t('Only house members can complete tasks'))->getId();

		$newTitle = null;
		try {
			$command = $this->commandService->runCommand(
				$houseId,
				$actorId,
				$commandId,
				'room_task.complete',
				[
					'roomTaskId' => $roomTaskId,
					'cycleKey' => $cycleKey,
					'expectedVersion' => $expectedVersion,
					'participants' => $participants,
					'allocationReviewed' => $allocationReviewed,
				],
				function () use ($houseId, $userId, $actorId, $roomTaskId, $commandId, $cycleKey, $expectedVersion, $participants, $allocationReviewed, &$newTitle): array {
					return $this->completeAggregate(
						$houseId, $userId, $actorId, $roomTaskId, $commandId, $cycleKey, $expectedVersion, $participants, $allocationReviewed, $newTitle
					);
				},
			);
		} catch (\Throwable $e) {
			$this->appLogger->logException('CompletionService: task completion rolled back', $e, [
				'event' => 'completion_rollback',
				'service' => 'CompletionService',
				'houseId' => $houseId,
				'roomId' => $roomTask->getRoomId(),
				'roomTaskId' => $roomTaskId,
				'userId' => $userId,
			]);
			throw $e;
		}

		// Dispatch only on the first commit, even if a subsequent fresh
		// response read cannot complete; receipt replay never redispatches.
		$post = $command['replay'] ? null : $this->postCommit->runForCompletion($houseId, $roomTaskId, $userId);

		$freshRoomTask = $this->access->roomTask($roomTaskId, $houseId);
		$task = $this->access->visibleTask($freshRoomTask, $this->access->member($houseId, $userId, $actorId, $this->l10n->t('Only house members can complete tasks')));
		// The receipt names an entry of this room task, which is scoped above.
		$completion = $this->completedTaskMapper->find((int)$command['result']['completionId']);
		$response = $this->readModel->response(
			$house, $userId, $completion, $freshRoomTask, $this->access->room($freshRoomTask->getRoomId()), $command['replay'], $task
		);
		// The fresh target rides along on a replay too: the first attempt
		// committed, so the cycle the client showed is closed.
		$response['cycle'] = $this->readModel->cycleBlock($freshRoomTask, $house, $task);
		if ($post !== null) {
			$response['rewards']['newTitle'] = $newTitle;
		}
		return CompletionPostCommitService::merge($response, $post);
	}

	/**
	 * The first-execution completion aggregate inside the command
	 * transaction (the house lock is already held).
	 *
	 * @param list<array{userId: string, sharePct: int}> $participants
	 * @param array<string, mixed>|null $newTitle Out: the recorder's newly crossed title tier
	 * @return array<string, mixed> The stored receipt identity
	 */
	private function completeAggregate(
		int $houseId,
		string $userId,
		int $actorId,
		int $roomTaskId,
		string $commandId,
		?string $cycleKey,
		int $expectedVersion,
		array $participants,
		bool $allocationReviewed,
		?array &$newTitle,
	): array {
		$roomTask = $this->roomTaskMapper->lockForUpdate($roomTaskId);

		$houseUser = $this->access->member($houseId, $userId, $actorId, $this->l10n->t('Only house members can complete tasks'));
		$assigneeUserIds = $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTaskId);
		$task = $this->access->visibleTask($roomTask, $houseUser, $assigneeUserIds);

		if (!$roomTask->getIsActive()) {
			throw new ConflictException($this->l10n->t('Task is not active'));
		}

		$now = new \DateTime();
		// Read under the house lock: a zone edit is a house command too.
		$timeZone = $this->calendar->getTimeZone($this->houseMapper->find($houseId));
		// The current cycle at the shown version; null for a bonus task.
		$cycle = $this->cycles->resolveTargetUnderLock($houseId, $roomTask, $task, $cycleKey, $expectedVersion, $timeZone, $now);
		$workDateKey = $this->calendar->dayKey($now, $timeZone);
		$classification = $cycle === null ? CompletedTask::CLASS_BONUS : $cycle->classify($workDateKey);

		// NEXT lock: the room row (documented order: rooms BEFORE
		// memberships/wallets — same-room completions serialize here so
		// cleanliness cannot clobber).
		$room = $this->roomMapper->lockForUpdate($roomTask->getRoomId());

		// The proposal: the assignees in equal shares, else the completer.
		$members = $this->houseUserMapper->findByHouseId($houseId);
		$allocation = $this->allocations->proposal($assigneeUserIds, $members, $userId);
		if ($participants !== []) {
			if (!$allocationReviewed && !$this->allocations->same($participants, $allocation)) {
				throw new ConflictException($this->l10n->t('The shares differ from the suggested split and must be confirmed'));
			}
			$allocation = $this->allocations->fromParticipants($participants, $members);
		}

		// Lock actual workers AND the responsible assignees in member-id
		// order; only actual workers gain share/coin credit, and each of
		// them must be allowed to see the task (D-091-20). Then their
		// wallets in stable id order, before any completion write.
		$recognitionMembers = $this->locks->participants($allocation, $task, $assigneeUserIds, $members);
		$walletsByUserId = $this->locks->wallets($houseId, array_keys($allocation), $members);
		$walletIds = array_map(static fn (Wallet $wallet): int => (int)$wallet->getId(), array_values($walletsByUserId));
		if ($classification === CompletedTask::CLASS_BONUS) {
			// A bonus task pays a person once per day, whoever records it.
			$this->creditPolicy->assertBonusOncePerDay($roomTaskId, $workDateKey, $walletIds);
		}

		// Overdue-takeover: LATE work (its window ended in the house
		// timezone — never work still due today, CS-PLAN-084 D-084-08) of
		// an assigned task that none of its assignees is credited for. It
		// was taken over by the recorder, or by whom the recorder credits.
		$originalAssignee = $assigneeUserIds[0] ?? '';
		$takeover = $classification === CompletedTask::CLASS_LATE && $originalAssignee !== ''
			&& array_intersect(array_keys($allocation), $assigneeUserIds) === [];
		if ($takeover) {
			$roomTask->markClaimed($originalAssignee, isset($allocation[$userId]) ? $userId : (string)array_key_first($allocation));
		}

		// The evidence row: frozen work facts + the cycle it fulfilled +
		// the recorder (the audit actor stays immutable across later
		// corrections; the people credited are the share rows). Open work
		// is not frozen: the energy is the definition's at completion.
		$frozen = $this->multipliers->freeze($houseId, $members, $walletsByUserId, $workDateKey);
		$ledgerRow = new CompletedTask();
		$ledgerRow->setRoomTaskId($roomTaskId);
		$ledgerRow->setUserId($actorId);
		$ledgerRow->setCompletedAt($now);
		$ledgerRow->setTotalEnergy($task->energy());
		$ledgerRow->setRateMinor(EffortAllocationService::COIN_MINOR_PER_ENERGY);
		$ledgerRow->setCompletionVersion(0);
		$ledgerRow->setAllocationRevision(1);
		$ledgerRow->setCommandUuid($commandId);
		$ledgerRow->setWorkDate(RecurrenceCalculator::dayKeyToDate($workDateKey));
		$ledgerRow->setWorkZone($timeZone->getName());
		$ledgerRow->setCompletionClass($classification);
		$ledgerRow->setBonusPercent($frozen['percent']);
		$ledgerRow->setCoinFactors($frozen['factors']);
		if ($cycle !== null) {
			$ledgerRow->setCycleKey($cycle->key);
			$ledgerRow->setWindowStartOn(RecurrenceCalculator::dayKeyToDate($cycle->startOn));
			$ledgerRow->setWindowEndOn(RecurrenceCalculator::dayKeyToDate($cycle->endOn));
		}
		$completion = $this->completedTaskMapper->insert($ledgerRow);
		$completionId = (int)$completion->getId();

		if ($takeover) {
			// RF-076-02: the queue and completion commit together. Use the
			// immutable completion identity, so an undone takeover is resolvable.
			$this->queueTakeover($houseId, $originalAssignee, $completionId, $now);
			$roomTask->setClaimedSourceCompletionId($completionId);
			// The timeline projection may be unchanged and skip its update.
			$this->roomTaskMapper->update($roomTask);
		}

		// Immutable allocation evidence (revision 1) with the exact minor
		// amounts per participant, the audit row, and the initial credit:
		// the selected allocation as additive ledger entries.
		$coins = $this->evidence->writeShares($completion, 1, $allocation, $walletsByUserId, $now);
		$this->evidence->writeRevision($completion, 0, CompletionRevision::ACTION_COMPLETE, 1, $actorId, null, $commandId);
		$this->walletService->postEntries(
			$houseId, WalletService::SOURCE_COMPLETION, $completionId, 1, $coins, WalletService::REASON_COMPLETION, $actorId
		);
		// Derived contribution caches from the valid selected shares, by
		// the ids of the LOCKED wallets (freshly ensured bindings).
		$this->walletService->recalcContributionCache($walletIds);

		// Room journal: ONE total-room effect per completion, independent
		// of the participant count; the replay rebuilds the room cache
		// (requested delta, clamps, work instant).
		$this->roomReplay->recordCompletion((int)$room->getId(), $completionId, $completion->getTotalEnergy() * 10, $now);
		$this->roomReplay->resyncRoomCache($room);

		// The cycle (ADR-021 decision 4): mark its sub-tasks done, close
		// exactly this one with its frozen classification (its postponement
		// stays with its key), end a one-time task and refresh the derived
		// next_due projection. A bonus task has no cycle; only its
		// last-completed mark moves, its version stays (D-091-84).
		if ($cycle !== null) {
			$this->checklists->markCycleDoneUnderLock($roomTaskId, (int)$task->getId(), $cycle->key, $actorId);
			$this->cycles->closeUnderLock($houseId, $roomTask, $task, $cycle, $completion, $timeZone, $now);
		} else {
			$this->cycles->bonusDoneUnderLock($roomTask, $now);
		}

		$newTitle = $this->recognition->recompute($houseId, $recognitionMembers, $now, $userId);
		return ['completionId' => $completionId];
	}

	/** The digest receipt of a takeover for the member whose red entry someone else did. */
	private function queueTakeover(int $houseId, string $originalAssignee, int $completionId, \DateTime $now): void {
		$member = $this->houseUserMapper->findByHouseAndUser($houseId, $originalAssignee);
		if ($member === null) {
			return;
		}
		$change = new DigestChange();
		$change->setHouseId($houseId);
		$change->setHouseUserId((int)$member->getId());
		$change->setObjectType('takeover');
		$change->setObjectId((string)$completionId);
		$change->setObjectRevision(0);
		$change->setCategory(DigestChange::CATEGORY_ORG);
		$change->setChangeTime($now);
		$change->setTargetSlot($this->digestSchedule->resolveTargetSlotUnderLock($houseId, (int)$member->getId(), $now));
		$change->setDisposition(DigestChange::DISPOSITION_QUEUED);
		$change->setCreatedAt($now);
		$change->setUpdatedAt($now);
		$this->digestChangeMapper->queueOrCoalesce($change);
	}

	/**
	 * Undo ONE specific completion (WP3 §3.4 targeted correction): the
	 * exact completion id. The owner deletes any entry, every other member
	 * only the latest valid entry of the task that they recorded (ADR-021
	 * decision 9). Participation alone is no authority. Reverses the LATEST
	 * selected allocation once; an already-undone target is a stable
	 * no-op; a stale live target conflicts.
	 *
	 * @throws NotFoundException invisible/foreign completion
	 * @throws ForbiddenException no correction authority
	 * @throws ConflictException stale expectedVersion
	 */
	public function undo(int $houseId, HouseUser $actor, int $completionId, string $commandId, int $expectedVersion, ?string $reasonCode): array {
		[$completion, $roomTask] = $this->access->target($completionId, $houseId);
		$this->access->authorize($houseId, $actor, $completion, $roomTask, CompletionAccess::RIGHTS_LATEST);
		$house = $this->houseMapper->find($houseId);

		try {
			$command = $this->commandService->runCommand(
				$houseId,
				(int)$actor->getId(),
				$commandId,
				'completion.undo',
				['completionId' => $completionId, 'expectedVersion' => $expectedVersion, 'reasonCode' => $reasonCode],
				fn (): array => $this->undoAggregate($houseId, $actor, $completionId, $commandId, $expectedVersion, $reasonCode),
			);
		} catch (\Throwable $e) {
			$this->appLogger->logException('CompletionService: undo rolled back', $e, [
				'event' => 'undo_rollback',
				'service' => 'CompletionService',
				'houseId' => $houseId,
				'completionId' => $completionId,
				'userId' => $actor->getNextcloudUserId(),
			]);
			throw $e;
		}

		$noop = (bool)($command['result']['noop'] ?? false);
		$post = $command['replay'] || $noop
			? null
			: $this->postCommit->runForCorrection($houseId, $completion->getRoomTaskId(), $actor->getNextcloudUserId());
		[$fresh, $freshRoomTask] = $this->access->target($completionId, $houseId);
		$task = $this->access->authorize($houseId, $actor, $fresh, $freshRoomTask, CompletionAccess::RIGHTS_LATEST);
		$response = $this->readModel->undoResponse($house, $fresh, $freshRoomTask, $command['replay'], $task);
		// §3.4 idempotent no-op: an already-undone target answers `undone:
		// false` (THIS command undid nothing) without post-commit effects.
		if ($noop) {
			$response['undone'] = false;
		}
		return CompletionPostCommitService::merge($response, $post);
	}

	/**
	 * The undo aggregate inside the command transaction.
	 *
	 * @return array<string, mixed>
	 */
	private function undoAggregate(int $houseId, HouseUser $actor, int $completionId, string $commandId, int $expectedVersion, ?string $reasonCode): array {
		$target = $this->access->lockTarget($houseId, $actor, $completionId, CompletionAccess::RIGHTS_LATEST);
		$completion = $target->completion;
		// Terminal handling BEFORE version handling (WP3 §3.4): a new
		// command on an already-undone target has no additional effect —
		// a stable no-op with the current state.
		if ($completion->getUndoneAt() !== null) {
			return ['completionId' => $completionId, 'noop' => true];
		}
		$this->access->assertVersion($completion, $expectedVersion);

		// The LATEST selected allocation: its people and the responsible
		// assignees (stable member id order), then their wallets. The ids
		// come from the SHARE rows (persisted bindings), so a departed
		// participant's retained wallet reverses like any other.
		$roomTask = $target->roomTask;
		['shares' => $latestShares, 'members' => $members] = $this->locks->latestAllocation($houseId, $completion, (int)$roomTask->getId());
		$walletIds = array_map(static fn (WorkShare $share): int => (int)$share->getWalletId(), $latestShares);
		$this->locks->walletsById($walletIds);

		$now = new \DateTime();

		// Mark invalid BEFORE re-deriving projections: the undone row is
		// not a completion anywhere (D11).
		$this->completedTaskMapper->markUndone($completionId, $now);
		$newVersion = $completion->getCompletionVersion() + 1;
		$this->completedTaskMapper->advanceVersion($completionId, $newVersion, $completion->getAllocationRevision());

		// Append-only audit: action undo, no new allocation revision.
		$this->evidence->writeRevision($completion, $newVersion, CompletionRevision::ACTION_UNDO, null, (int)$actor->getId(), $reasonCode, $commandId);

		// Reverse the LATEST selected allocation once, exactly as its share
		// rows recorded it; unrelated debits/credits remain unchanged.
		$deltas = [];
		foreach ($latestShares as $share) {
			$deltas[(int)$share->getWalletId()] = -1 * $share->getCoinMinor();
		}
		$this->walletService->postEntries(
			$houseId, WalletService::SOURCE_COMPLETION_UNDO, $completionId, $newVersion,
			$deltas, WalletService::REASON_UNDO, (int)$actor->getId()
		);
		// Derived caches from the remaining valid history.
		$this->walletService->recalcContributionCache($walletIds);

		// Claim provenance: the takeover claim of THIS completion reverts
		// only while no later valid claim supersedes it.
		$this->revertClaimIfUnsuperseded($roomTask, $completion);

		// Room journal: the completion's event becomes invalid; replay
		// rebuilds the room (later valid work and resets survive; the
		// correction execution time never becomes last_cleaned).
		$this->roomReplay->resyncRoomCache($target->room);

		// Cycle seam: the completion's done row goes, its cycle reopens and
		// the current cycle derives again (later valid work survives — §3.4).
		$this->cycles->reopenUnderLock(
			$houseId, $roomTask, $target->task, $completion, $this->calendar->getTimeZone($this->houseMapper->find($houseId)), $now
		);

		// The recognitions of the workers and of responsible members who did not work.
		$this->recognition->recompute($houseId, $members, $now);

		return ['completionId' => $completionId, 'noop' => false, 'version' => $newVersion];
	}

	/**
	 * Correct the actual participants/shares of ONE completion (WP3 §3.4
	 * attribution): the owner alone adjusts the allocation (ADR-021
	 * decision 9), while the real work time, saved zone, cycle, frozen
	 * total effort, rate and multipliers and the room event stay immutable.
	 * Writes only `newAllocation - oldAllocation` for the union of
	 * participants (removal = a new allocation of zero).
	 *
	 * @param array<int, array{userId: string, sharePct: int}> $participants
	 * @throws NotFoundException invisible/foreign completion
	 * @throws ForbiddenException no correction authority
	 * @throws ConflictException stale version / unconfirmed deviation
	 */
	public function attribute(
		int $houseId,
		HouseUser $actor,
		int $completionId,
		string $commandId,
		int $expectedVersion,
		array $participants,
		bool $allocationReviewed,
		?string $reasonCode,
	): array {
		$participants = $this->allocations->canonicalize($participants);

		[$completion, $roomTask] = $this->access->target($completionId, $houseId);
		$this->access->authorize($houseId, $actor, $completion, $roomTask, CompletionAccess::RIGHTS_ATTRIBUTE);
		$house = $this->houseMapper->find($houseId);

		try {
			$command = $this->commandService->runCommand(
				$houseId,
				(int)$actor->getId(),
				$commandId,
				'completion.attribute',
				[
					'completionId' => $completionId,
					'expectedVersion' => $expectedVersion,
					'participants' => $participants,
					'allocationReviewed' => $allocationReviewed,
					'reasonCode' => $reasonCode,
				],
				fn (): array => $this->attributeAggregate(
					$houseId, $actor, $completionId, $commandId, $expectedVersion, $participants, $allocationReviewed, $reasonCode
				),
			);
		} catch (\Throwable $e) {
			$this->appLogger->logException('CompletionService: attribution rolled back', $e, [
				'event' => 'attribution_rollback',
				'service' => 'CompletionService',
				'houseId' => $houseId,
				'completionId' => $completionId,
				'userId' => $actor->getNextcloudUserId(),
			]);
			throw $e;
		}

		[$fresh, $freshRoomTask] = $this->access->target($completionId, $houseId);
		$task = $this->access->authorize($houseId, $actor, $fresh, $freshRoomTask, CompletionAccess::RIGHTS_ATTRIBUTE);
		// No `cycle` key: an attribution moves no cycle, and a null block
		// would wipe the target the client holds for the room task.
		return $this->readModel->response(
			$house, $actor->getNextcloudUserId(), $fresh, $freshRoomTask, $this->access->room($freshRoomTask->getRoomId()), $command['replay'], $task
		);
	}

	/**
	 * The attribution aggregate inside the command transaction.
	 *
	 * @param list<array{userId: string, sharePct: int}> $participants
	 * @return array<string, mixed>
	 */
	private function attributeAggregate(
		int $houseId,
		HouseUser $actor,
		int $completionId,
		string $commandId,
		int $expectedVersion,
		array $participants,
		bool $allocationReviewed,
		?string $reasonCode,
	): array {
		$target = $this->access->lockTarget($houseId, $actor, $completionId, CompletionAccess::RIGHTS_ATTRIBUTE);
		$completion = $target->completion;
		if ($completion->getUndoneAt() !== null) {
			return ['completionId' => $completionId, 'noop' => true];
		}
		$this->access->assertVersion($completion, $expectedVersion);

		// The reference allocation: the previous selected revision, keyed
		// by WALLET id from the share rows — the rows are the authority.
		// The default proposal is NOT re-applied: attribution corrects
		// toward the ACTUAL people, the deviation confirmation documents
		// the review.
		$members = $this->houseUserMapper->findByHouseId($houseId);
		$membersByUserId = [];
		$userIdByWallet = [];
		foreach ($members as $member) {
			$membersByUserId[$member->getNextcloudUserId()] = $member;
			if ($member->getWalletId() !== null) {
				$userIdByWallet[(int)$member->getWalletId()] = $member->getNextcloudUserId();
			}
		}
		$previousCoins = [];
		$previousPresentByUserId = [];
		foreach ($this->workShareMapper->findByCompletionAndRevision($completionId, $completion->getAllocationRevision()) as $share) {
			if ($share->getWalletId() === null) {
				continue;
			}
			$previousCoins[(int)$share->getWalletId()] = $share->getCoinMinor();
			$uid = $userIdByWallet[(int)$share->getWalletId()] ?? null;
			if ($uid !== null) {
				$previousPresentByUserId[$uid] = $share->getSharePct();
			}
		}

		// The new allocation: current house members only (exact whole
		// percents summing to 100).
		$newAllocation = $this->allocations->fromParticipants($participants, $members);

		// Deviation review (WP3 §3.2): an explicit allocation differing
		// from the previous one requires the allocationReviewed
		// confirmation — a same-allocation submission stays valid without
		// it. Departed previous participants cannot be named in a new
		// allocation and never count as an unreviewed deviation.
		if (!$allocationReviewed && !$this->allocations->same($participants, $previousPresentByUserId)) {
			throw new ConflictException($this->l10n->t('The share allocation deviates from the recorded allocation and must be confirmed'));
		}

		// Memberships (documented order: after the room row): every NEW
		// participant is a live member; a departed PREVIOUS participant
		// contributes the retained wallet to the union below — never a
		// membership row (WP3 §3.6).
		$roomTaskId = (int)$target->roomTask->getId();
		$assigneeUserIds = $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTaskId);
		$recognitionMembers = $this->locks->membersOfWallets(
			$houseId,
			array_keys($previousCoins),
			array_merge(
				array_map(static fn (string $uid): HouseUser => $membersByUserId[$uid], array_keys($newAllocation)),
				$this->responsibility->membersOf($houseId, $roomTaskId)
			)
		);

		// Bindings first (deterministic creation), then ONE sorted lock
		// pass over the whole wallet union.
		$walletIdByUserId = [];
		$newWalletIds = [];
		foreach ($recognitionMembers as $member) {
			$uid = $member->getNextcloudUserId();
			if (!isset($newAllocation[$uid])) {
				continue;
			}
			$isNew = !isset($previousCoins[(int)$member->getWalletId()]);
			if ($isNew) {
				// Visibility revalidated under lock for whoever joins (D-091-56).
				$this->creditPolicy->assertVisibleTo($target->task, $member, $assigneeUserIds);
			}
			$walletIdByUserId[$uid] = (int)$this->walletService->ensureBinding($houseId, $member)->getWalletId();
			if ($isNew) {
				$newWalletIds[] = $walletIdByUserId[$uid];
			}
		}
		$unionWalletIds = array_values(array_unique([...array_keys($previousCoins), ...array_values($walletIdByUserId)]));
		sort($unionWalletIds);
		$lockedByWalletId = $this->locks->walletsById($unionWalletIds);
		if ($completion->getCompletionClass() === CompletedTask::CLASS_BONUS && $completion->getWorkDate() !== null) {
			// A bonus task pays a person once per day, whoever records it.
			$this->creditPolicy->assertBonusOncePerDay($roomTaskId, $completion->getWorkDate()->format('Y-m-d'), $newWalletIds, $completionId);
		}

		$now = new \DateTime();
		$newRevision = $completion->getAllocationRevision() + 1;
		$newVersion = $completion->getCompletionVersion() + 1;

		// New allocation evidence (old revisions remain evidence, never
		// counted again — the row's revision pointer selects revision N),
		// priced with the multipliers frozen on the completion.
		$newCoins = $this->evidence->writeShares(
			$completion, $newRevision, $newAllocation,
			array_map(static fn (int $walletId): Wallet => $lockedByWalletId[$walletId], $walletIdByUserId), $now
		);
		$this->completedTaskMapper->advanceVersion($completionId, $newVersion, $newRevision);
		$this->evidence->writeRevision(
			$completion, $newVersion, CompletionRevision::ACTION_ATTRIBUTION, $newRevision, (int)$actor->getId(), $reasonCode, $commandId
		);

		// Exact deltas for the UNION over wallet ids: what the new shares
		// pay minus what the old ones paid; removal means zero. A departed
		// previous participant's retained wallet reverses like an active
		// member's.
		$deltas = [];
		foreach ($unionWalletIds as $walletId) {
			$deltas[$walletId] = ($newCoins[$walletId] ?? 0) - ($previousCoins[$walletId] ?? 0);
		}
		$this->walletService->postEntries(
			$houseId, WalletService::SOURCE_COMPLETION, $completionId, $newRevision,
			$deltas, WalletService::REASON_ATTRIBUTION, (int)$actor->getId()
		);
		$this->walletService->recalcContributionCache($unionWalletIds);

		// The room event, work time, saved zone, cycle and frozen total
		// effort/rate stay immutable (C3f) — no room/cycle writes here.
		// The recognitions of changed workers and responsible non-workers
		// derive again; departed wallets have no membership to refresh.
		$this->recognition->recompute($houseId, $recognitionMembers, $now);

		return ['completionId' => $completionId, 'noop' => false, 'version' => $newVersion];
	}

	/**
	 * The takeover claim of the undone completion reverts only while no
	 * later valid work superseded it — claim state derives from surviving
	 * provenance (WP3 §3.4), never restored over a later valid claim.
	 */
	private function revertClaimIfUnsuperseded(RoomTask $roomTask, CompletedTask $completion): void {
		if (!$roomTask->getWasClaimed() || $roomTask->getClaimedSourceCompletionId() !== (int)$completion->getId()) {
			return;
		}
		$roomTask->setWasClaimed(false);
		$roomTask->setClaimedByUser(null);
		$roomTask->setClaimedAt(null);
		$roomTask->setClaimedSourceCompletionId(null);
		$roomTask->setOriginalAssignee(null);
		$this->roomTaskMapper->update($roomTask);
	}
}
