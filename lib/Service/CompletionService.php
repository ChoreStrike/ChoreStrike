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
use OCA\ChoreStrike\Db\CompletionRevisionMapper;
use OCA\ChoreStrike\Db\DigestChange;
use OCA\ChoreStrike\Db\DigestChangeMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMember;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\ScheduleRevision;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Db\WorkShare;
use OCA\ChoreStrike\Db\WorkShareMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\IL10N;

/**
 * Owner of the completion/undo/attribution aggregates (CS-PLAN-070 WP3
 * §3.3; supersedes the CS-PLAN-064 WP5 snapshot-restore model).
 *
 * Every aggregate runs as ONE CommandService transaction: rechecks the
 * actor/visibility/version under the WP0 house-first lock, writes the
 * immutable evidence (completion row, work_shares, completion_revs), the
 * additive wallet entries, the room journal, the occurrence/capacity
 * projections, the dependent recognition (RhythmService valid-history
 * evaluation) and the receipt together. There is NO before-state restore
 * authority: undo and attribution re-derive every dependent projection
 * from valid history (C3e/C3f). The house state version is bumped so
 * scheduling previews become stale.
 *
 * Lock order (single house-first order; CommandService holds the house
 * row): room_task → room → active memberships (actor + actual/obligated
 * members, stable id) → wallets (actual shares, stable id) → occurrence/
 * completion rows → their dependent rows. Lower services (WalletService,
 * RoomReplayService, RhythmService) never call this coordinator back.
 *
 * Money (WP3): the initial credit is the selected allocation
 * (`energyMinor = E * P`, `coinMinor = 10 * E * P`, frozen rate). An
 * attribution writes only `newAllocation - oldAllocation` for the UNION of
 * participants (removal = a new allocation of zero). Undo reverses the
 * LATEST selected allocation once. Unrelated entries remain unchanged;
 * negative balances are valid correction results.
 */
class CompletionService {
	private TransactionExecutor $transactions;
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private HouseMapper $houseMapper;
	private HouseUserMapper $houseUserMapper;
	private WalletMapper $walletMapper;
	private OccurrenceMemberMapper $occMemberMapper;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private WorkShareMapper $workShareMapper;
	private CompletionRevisionMapper $completionRevisionMapper;
	private CompletionPostCommitService $postCommitService;
	private DigestChangeMapper $digestChangeMapper;
	private DigestScheduleService $digestScheduleService;
	private WalletService $walletService;
	private RoomReplayService $roomReplayService;
	private EffortAllocationService $allocationService;
	private RhythmService $rhythmService;
	private ?TitleService $titleService;
	private RewardConfigService $configService;
	private CalendarService $calendarService;
	private TaskAccessPolicy $taskAccessPolicy;
	private OccurrenceService $occurrenceService;
	private CommandService $commandService;
	private IL10N $l10n;
	private AppLogger $appLogger;

	/** Command context threaded into the running aggregate (audit rows). */
	private string $currentCommandUuid = '';
	private ?int $currentActorHouseUserId = null;

	public function __construct(
		TransactionExecutor $transactions,
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		RoomMapper $roomMapper,
		HouseMapper $houseMapper,
		HouseUserMapper $houseUserMapper,
		WalletMapper $walletMapper,
		OccurrenceMemberMapper $occMemberMapper,
		TaskAssigneeMapper $taskAssigneeMapper,
		CompletedTaskMapper $completedTaskMapper,
		WorkShareMapper $workShareMapper,
		CompletionRevisionMapper $completionRevisionMapper,
		CompletionPostCommitService $postCommitService,
		DigestChangeMapper $digestChangeMapper,
		DigestScheduleService $digestScheduleService,
		WalletService $walletService,
		RoomReplayService $roomReplayService,
		EffortAllocationService $allocationService,
		RhythmService $rhythmService,
		?TitleService $titleService,
		RewardConfigService $configService,
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
		$this->walletMapper = $walletMapper;
		$this->occMemberMapper = $occMemberMapper;
		$this->taskAssigneeMapper = $taskAssigneeMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->workShareMapper = $workShareMapper;
		$this->completionRevisionMapper = $completionRevisionMapper;
		$this->postCommitService = $postCommitService;
		$this->digestChangeMapper = $digestChangeMapper;
		$this->digestScheduleService = $digestScheduleService;
		$this->walletService = $walletService;
		$this->roomReplayService = $roomReplayService;
		$this->allocationService = $allocationService;
		$this->rhythmService = $rhythmService;
		$this->titleService = $titleService;
		$this->configService = $configService;
		$this->calendarService = $calendarService;
		$this->taskAccessPolicy = $taskAccessPolicy;
		$this->occurrenceService = $occurrenceService;
		$this->commandService = $commandService;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
	}

	/**
	 * Complete one SHOWN occurrence as one transactional aggregate with a
	 * command receipt (CS-PLAN-072 WP1 occurrence contract + WP3 actual
	 * work).
	 *
	 * The client sends the shown target's occurrence receipt plus the
	 * ACTUAL participants (`[{userId, sharePct}]`, optional). Without
	 * explicit participants the effective concrete agreement is credited
	 * (agreed occurrence shares, else equal wallet-order assignee shares,
	 * else solo 100%); a deviation from it requires the explicit
	 * `allocationReviewed` confirmation (C2a — no per-person confirmation
	 * queue). Initial completion requires self-participation (Q-074-02);
	 * time, rate and total work are server-owned.
	 *
	 * @param array<int, array{userId: string, sharePct: int}> $participants
	 * @throws NotFoundException unknown/invisible room task (404 anti-enumeration)
	 * @throws ForbiddenException non-member / non-participating actor
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
		array $participants = [],
		bool $allocationReviewed = false,
	): array {
		Occurrence::parsePublicKey($occurrenceId);
		$participants = $this->canonicalizeParticipants($participants);

		$roomTask = $this->findRoomTaskScoped($roomTaskId, $houseId);
		$task = $this->findTaskOrNotFound($roomTask->getTaskId());
		$room = $this->findRoomOrNotFound($roomTask->getRoomId());
		$house = $this->houseMapper->find($houseId);
		$timeZone = $this->calendarService->getTimeZone($house);

		$houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
		if ($houseUser === null) {
			throw new ForbiddenException($this->l10n->t('Only house members can complete tasks'));
		}

		try {
			$this->currentCommandUuid = $commandId;
			$this->currentActorHouseUserId = (int)$houseUser->getId();
			$postCommitMetadata = [];
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
					'participants' => $participants,
					'allocationReviewed' => $allocationReviewed,
				],
				function () use ($houseId, $userId, $roomTaskId, $timeZone, $occurrenceId, $expectedVersion, $selectionToken, $participants, $allocationReviewed, &$postCommitMetadata): array {
					return $this->completeAggregate(
						$houseId, $userId, $roomTaskId, $timeZone, $occurrenceId,
						$expectedVersion, $selectionToken, $participants, $allocationReviewed, $postCommitMetadata
					);
				},
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

		// Dispatch only on the first commit, even if a subsequent fresh
		// response read cannot complete; receipt replay never redispatches.
		if (!$command['replay']) {
			$post = $this->postCommitService->runForCompletion(
				$houseId,
				$roomTaskId,
				$userId
			);
		}

		$freshRoomTask = $this->findRoomTaskScoped($roomTaskId, $houseId);
		$freshHouseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
		if ($freshHouseUser === null || (int)$freshHouseUser->getId() !== (int)$houseUser->getId()) {
			throw new ForbiddenException($this->l10n->t('Only house members can complete tasks'));
		}
		$this->taskAccessPolicy->assertCanView(
			$this->findTaskOrNotFound($freshRoomTask->getTaskId()),
			$freshHouseUser,
			$this->taskAssigneeMapper->findUserIdsByRoomTask($roomTaskId)
		);
		$completion = $this->findCompletionScoped((int)$command['result']['completionId'], $houseId);
		$response = $this->completionResponse(
			$house, $userId, $completion, $freshRoomTask,
			$this->findRoomOrNotFound($freshRoomTask->getRoomId()), $command['replay']
		);
		if ($command['replay']) {
			return $response;
		}

		$response['rewards']['weeklyGoal'] = $post['weeklyGoal'];
		$response['rewards']['newTitle'] = $postCommitMetadata['newTitle'];
		if ($post['degraded']) {
			$response['rewards']['degraded'] = true;
		}
		$response['occurrence'] = $this->selectionBlock($freshRoomTask, $house);
		return $response;
	}

	/**
	 * The first-execution completion aggregate inside the command
	 * transaction (the house lock is already held).
	 *
	 * @param array<int, array{userId: string, sharePct: int}> $participants
	 * @return array<string, mixed> The stored receipt identity
	 */
	private function completeAggregate(
		int $houseId,
		string $userId,
		int $roomTaskId,
		\DateTimeZone $timeZone,
		string $occurrenceId,
		int $expectedVersion,
		string $selectionToken,
		array $participants,
		bool $allocationReviewed,
		array &$postCommitMetadata,
	): array {
		$roomTask = $this->roomTaskMapper->lockForUpdate($roomTaskId);

		$houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
		if ($houseUser === null || (int)$houseUser->getId() !== $this->currentActorHouseUserId) {
			throw new ForbiddenException($this->l10n->t('Only house members can complete tasks'));
		}

		$assigneeUserIds = $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTaskId);
		$task = $this->findTaskOrNotFound($roomTask->getTaskId());
		$this->taskAccessPolicy->assertCanView($task, $houseUser, $assigneeUserIds);

		if (!$roomTask->getIsActive()) {
			throw new ConflictException($this->l10n->t('Task is not active'));
		}

		$now = new \DateTime();
		$resolved = $this->occurrenceService->resolveForCompletionUnderLock(
			$houseId, $roomTask, $occurrenceId, $expectedVersion, $selectionToken, $now
		);
		/** @var Occurrence $occurrence */
		$occurrence = $resolved['occurrence'];
		/** @var ScheduleRevision $revision */
		$revision = $resolved['revision'];
		$classification = $resolved['classification'];

		// NEXT lock: the room row (documented order: rooms BEFORE
		// memberships/wallets — same-room completions serialize here so
		// cleanliness cannot clobber).
		$room = $this->roomMapper->lockForUpdate($roomTask->getRoomId());

		// The effective concrete agreement (WP3 §3.2) — agreed occurrence
		// shares first, never today's task-assignee defaults when an
		// agreement exists.
		$members = $this->houseUserMapper->findByHouseId($houseId);
		$occurrenceMembers = $this->occMemberMapper->findByOccurrence((int)$occurrence->getId());
		$proposal = $this->effectiveProposal($occurrenceMembers, $assigneeUserIds, $members, $userId);
		$explicit = $participants !== [];
		if ($explicit) {
			if (!$allocationReviewed && !$this->sameAllocation($participants, $proposal, $members)) {
				throw new ConflictException($this->l10n->t('The share allocation deviates from the agreement and must be confirmed'));
			}
			$allocation = $this->allocationFromParticipants($participants, $members);
		} else {
			$allocation = $proposal;
		}

		// Q-074-02: initial completion requires self-participation — never
		// manufacture work merely to satisfy an actor rule.
		if (!isset($allocation[$userId])) {
			throw new ForbiddenException($this->l10n->t('The completer must take part in the work'));
		}

		// Lock actual workers AND obligated non-workers in member-id order;
		// only actual workers gain share/coin credit or private-task access.
		$recognitionMembers = $this->lockParticipantMembers(
			$allocation, $task, $assigneeUserIds,
			$this->obligatedWalletIds($occurrenceMembers), $members
		);

		// Wallet locks in stable id order (after the memberships, before
		// any completion write).
		$walletsByUserId = $this->lockWallets($houseId, array_keys($allocation));

		$totalEnergy = max(1, $occurrence->getEnergy());
		$rateMinor = EffortAllocationService::COIN_MINOR_PER_ENERGY;

		// Overdue-takeover: only record a takeover when a NON-assignee
		// picks up an overdue task (fresh locked entity).
		$takeoverClaimed = false;
		$originalMember = null;
		if ($roomTask->isOverdue() && !in_array($userId, $assigneeUserIds, true)) {
			$originalAssignee = $assigneeUserIds[0] ?? null;
			if ($originalAssignee !== null && $originalAssignee !== '') {
				$roomTask->markClaimed($originalAssignee, $userId);
				$takeoverClaimed = true;
				$originalMember = $this->houseUserMapper->findByHouseAndUser($houseId, $originalAssignee);
			}
		}

		// The evidence row: frozen work facts + occurrence key + audit
		// completer (Q-074-02: the audit actor stays immutable across
		// later corrections).
		$ledgerRow = new CompletedTask();
		$ledgerRow->setRoomTaskId($roomTaskId);
		$ledgerRow->setUserId((int)$houseUser->getId());
		$ledgerRow->setCompletedAt($now);
		$ledgerRow->setTotalEnergy($totalEnergy);
		$ledgerRow->setRateMinor($rateMinor);
		$ledgerRow->setCompletionVersion(0);
		$ledgerRow->setAllocationRevision(1);
		$ledgerRow->setCommandUuid($this->currentCommandUuid ?? '');
		$ledgerRow->setOccurrenceDue(\DateTime::createFromInterface($occurrence->getPlannedDate()));

		$savedZone = new \DateTimeZone($occurrence->getTimezone());
		$workDateKey = $this->calendarService->dayKey($now, $savedZone);
		$ledgerRow->setScheduleRevId($occurrence->getScheduleRevId());
		$ledgerRow->setOccurrenceOrdinal($occurrence->getOrdinal());
		$ledgerRow->setWorkDate(\DateTime::createFromFormat('!Y-m-d', $workDateKey));
		$ledgerRow->setWorkZone($occurrence->getTimezone());
		$ledgerRow->setCompletionClass($classification);

		$inserted = $this->completedTaskMapper->insert($ledgerRow);
		$completionId = (int)$inserted->getId();
		// RF-076-02: the queue and completion commit together. Use the
		// immutable completion identity, so an undone takeover is resolvable.
		if ($originalMember !== null) {
			$change = new DigestChange();
			$change->setHouseId($houseId);
			$change->setHouseUserId((int)$originalMember->getId());
			$change->setObjectType('takeover');
			$change->setObjectId((string)$completionId);
			$change->setObjectRevision(0);
			$change->setCategory(DigestChange::CATEGORY_ORG);
			$change->setChangeTime($now);
			$change->setTargetSlot($this->digestScheduleService->resolveTargetSlotUnderLock($houseId, (int)$originalMember->getId(), $now));
			$change->setDisposition(DigestChange::DISPOSITION_QUEUED);
			$change->setCreatedAt($now);
			$change->setUpdatedAt($now);
			$this->digestChangeMapper->queueOrCoalesce($change);
		}
		if ($takeoverClaimed) {
			$roomTask->setClaimedSourceCompletionId($completionId);
			// The timeline projection may be unchanged and skip its update.
			$this->roomTaskMapper->update($roomTask);
		}

		// Immutable allocation evidence: one selected revision (1) with the
		// exact minor amounts per participant.
		$this->writeShares($inserted, 1, $allocation, $totalEnergy, $walletsByUserId);

		// Append-only audit: revision 0, action complete.
		$this->writeRevision($inserted, 0, CompletionRevision::ACTION_COMPLETE, 1, (int)$houseUser->getId(), null);

		// Initial credit = the selected allocation (additive entries; the
		// booked cache moves in the same transaction).
		$deltas = [];
		foreach ($allocation as $participantUserId => $sharePct) {
			$amounts = $this->allocationService->allocate($totalEnergy, $sharePct);
			$deltas[(int)$walletsByUserId[$participantUserId]->getId()] = $amounts['coinMinor'];
		}
		$this->walletService->postEntries(
			$houseId, WalletService::SOURCE_COMPLETION, $completionId, 1,
			$deltas, WalletService::REASON_COMPLETION, (int)$houseUser->getId()
		);

		// Derived contribution caches from the valid selected shares. The
		// wallet ids come from the LOCKED allocation wallets (freshly
		// ensured bindings) — the participant member entities were loaded
		// before lockWallets and may still carry a null walletId snapshot.
		$this->walletService->recalcContributionCache(array_map(
			static fn (\OCA\ChoreStrike\Db\Wallet $wallet): int => (int)$wallet->getId(),
			array_values($walletsByUserId)
		));

		// Room journal: ONE total-room effect per completion, independent
		// of the participant count; the replay rebuilds the room cache
		// (requested delta, clamps, work instant).
		$this->roomReplayService->recordCompletion(
			(int)$room->getId(), $completionId, $totalEnergy * 10, $now
		);
		$room = $this->roomReplayService->resyncRoomCache($room);

		// Timeline effects (WP1): fulfill exactly this occurrence, cover
		// the older catch-up miss, anchor the interval successor and
		// refresh the derived next_due projection.
		$this->occurrenceService->applyCompletionUnderLock(
			$houseId, $occurrence, $revision, $roomTask, $completionId, $workDateKey, $classification, $now
		);

		// Retained recognition: re-derive streaks from valid history, then
		// detect newly crossed title tiers on the fresh stats (never a
		// zero/empty placeholder). The members are RE-LOADED here: the
		// locked entities predate the wallet/contribution writes, so their
		// cached stats would be stale for the tier comparison.
		$freshParticipants = array_map(
			fn (HouseUser $m): HouseUser => $this->houseUserMapper->find((int)$m->getId()),
			$recognitionMembers
		);
		$this->rhythmService->recomputeForMembers($houseId, $freshParticipants, $now);
		$newTitle = $this->reconcileRecognition($freshParticipants, $userId);

		// Bump the house state version: scheduling previews become stale.
		$this->bumpHouseStateVersion($houseId);

		$postCommitMetadata = ['newTitle' => $newTitle];
		return ['completionId' => $completionId];
	}

	/**
	 * Undo ONE specific completion (WP3 §3.4 targeted correction): the
	 * exact completion id — including older completions of the same
	 * recurring task — by its completer or a current authorized owner.
	 * Participation alone is no correction authority. Reverses the LATEST
	 * selected allocation once; an already-undone target is a stable
	 * no-op; a stale live target conflicts.
	 *
	 * @throws NotFoundException invisible/foreign completion
	 * @throws ForbiddenException no correction authority
	 * @throws ConflictException stale expectedVersion
	 */
	public function undo(
		int $houseId,
		HouseUser $actor,
		int $completionId,
		string $commandId,
		int $expectedVersion,
		?string $reasonCode,
	): array {
		$completion = $this->findCompletionScoped($completionId, $houseId);
		$roomTask = $this->findRoomTaskScoped($completion->getRoomTaskId(), $houseId);
		$this->authorizeCorrection($houseId, $actor, $completion, $roomTask, false);

		$house = $this->houseMapper->find($houseId);
		$timeZone = $this->calendarService->getTimeZone($house);

		try {
			$this->currentCommandUuid = $commandId;
			$this->currentActorHouseUserId = (int)$actor->getId();
			$command = $this->commandService->runCommand(
				$houseId,
				(int)$actor->getId(),
				$commandId,
				'completion.undo',
				[
					'completionId' => $completionId,
					'expectedVersion' => $expectedVersion,
					'reasonCode' => $reasonCode,
				],
				fn (): array => $this->undoAggregate($houseId, $actor, $completionId, $expectedVersion, $reasonCode),
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

		$outcome = $command['result'];
		if (!$command['replay'] && !($outcome['noop'] ?? false)) {
			$post = $this->postCommitService->runForUndo($houseId, $completion->getRoomTaskId(), $actor->getNextcloudUserId());
		}
		$fresh = $this->findCompletionScoped($completionId, $houseId);
		$freshRoomTask = $this->findRoomTaskScoped($fresh->getRoomTaskId(), $houseId);
		$this->authorizeCorrection($houseId, $actor, $fresh, $freshRoomTask, false);
		$response = $this->undoResponse($house, $fresh, $command['replay'], $freshRoomTask);
		// §3.4 idempotent no-op: an already-undone target answers `undone:
		// false` (THIS command undid nothing) without post-commit effects.
		if ($outcome['noop'] ?? false) {
			$response['undone'] = false;
			return $response;
		}
		if ($command['replay']) {
			return $response;
		}

		$response['rewards']['weeklyGoal'] = $post['weeklyGoal'];
		if ($post['degraded']) {
			$response['rewards']['degraded'] = true;
		}
		return $response;
	}

	/**
	 * The undo aggregate inside the command transaction.
	 *
	 * @return array<string, mixed>
	 */
	private function undoAggregate(int $houseId, HouseUser $actor, int $completionId, int $expectedVersion, ?string $reasonCode): array {
		// The room-task lock is the serialization point: re-read the
		// completion UNDER it so a concurrently committed undo mark is
		// visible here (a pre-lock read races the winner's commit).
		$completionProbe = $this->findCompletionScoped($completionId, $houseId);
		$roomTask = $this->roomTaskMapper->lockForUpdate($completionProbe->getRoomTaskId());
		$completion = $this->findCompletionScoped($completionId, $houseId);
		$room = $this->roomMapper->lockForUpdate($roomTask->getRoomId());
		$this->authorizeCorrection($houseId, $actor, $completion, $roomTask, false);

		// Terminal handling BEFORE version handling (WP3 §3.4): a new
		// command on an already-undone target has no additional effect —
		// a stable no-op with the current state.
		if ($completion->getUndoneAt() !== null) {
			return ['completionId' => $completionId, 'noop' => true];
		}
		if ($completion->getCompletionVersion() !== $expectedVersion) {
			throw new ConflictException($this->l10n->t('The completion changed since it was displayed'));
		}

		// Lock the room row BEFORE the memberships/wallets (documented
		// order), then the union of the LATEST selected allocation's
		// participants (stable id order).
		$latestShares = $this->workShareMapper->findByCompletionAndRevision($completionId, $completion->getAllocationRevision());
		$walletIds = [];
		foreach ($latestShares as $share) {
			if ($share->getWalletId() !== null) {
				$walletIds[] = (int)$share->getWalletId();
			}
		}
		$occurrence = $this->occurrenceService->occurrenceOfCompletion($completion);
		$members = $this->lockMembersByWallets($houseId, array_merge(
			$walletIds, $this->obligatedWalletIds($occurrence !== null ? $this->occMemberMapper->findByOccurrence((int)$occurrence->getId()) : [])
		));
		$this->lockWallets($houseId, array_map(
			static fn (HouseUser $m) => $m->getNextcloudUserId(),
			array_values(array_filter($members, static fn (HouseUser $m): bool => in_array((int)$m->getWalletId(), $walletIds, true)))
		));

		$now = new \DateTime();

		// Mark invalid BEFORE re-deriving projections: the undone row is
		// not a completion anywhere (D11).
		$this->completedTaskMapper->markUndone($completionId, $now);
		$newVersion = $completion->getCompletionVersion() + 1;
		$this->completedTaskMapper->advanceVersion($completionId, $newVersion, $completion->getAllocationRevision());

		// Append-only audit: action undo, no new allocation revision.
		$this->writeRevision($completion, $newVersion, CompletionRevision::ACTION_UNDO, null, $this->currentActorHouseUserId, $reasonCode);

		// Reverse the LATEST selected allocation once (union of
		// participants; unrelated debits/credits remain unchanged).
		$deltas = [];
		foreach ($latestShares as $share) {
			if ($share->getWalletId() === null) {
				continue;
			}
			$deltas[(int)$share->getWalletId()] = -1 * $share->getCoinMinor();
		}
		$this->walletService->postEntries(
			$houseId, WalletService::SOURCE_COMPLETION_UNDO, $completionId, $newVersion,
			$deltas, WalletService::REASON_UNDO, $this->currentActorHouseUserId
		);

		// Derived caches from the remaining valid history. Wallet ids come
		// from the SHARE rows (persisted bindings), never from possibly
		// stale member entity snapshots.
		$this->walletService->recalcContributionCache(array_map(
			static fn (\OCA\ChoreStrike\Db\WorkShare $share): int => (int)$share->getWalletId(),
			array_values(array_filter($latestShares, static fn (\OCA\ChoreStrike\Db\WorkShare $share): bool => $share->getWalletId() !== null))
		));

		// Claim provenance: the takeover claim of THIS completion reverts
		// only while no later valid claim supersedes it — current claim
		// state derives from surviving agreement/work provenance.
		$this->revertClaimIfUnsuperseded($roomTask, $completion);

		// Room journal: the completion's event becomes invalid; replay
		// rebuilds the room (later valid work and resets survive; the
		// correction execution time never becomes last_cleaned).
		$room = $this->roomReplayService->resyncRoomCache($room);

		// Occurrence seam: release the recorded occurrence, restore
		// catch-up coverage and re-derive open successors (later valid
		// work, explicit amendments and restarts survive — §3.4).
		if ($occurrence !== null) {
			$this->occurrenceService->releaseCompletionUnderLock(
				$houseId, $occurrence, $this->roomTaskMapper->find($roomTask->getId()), $completion, $now
			);
		}

		// Recognition re-evaluation includes absent obligated members. The
		// members are RE-LOADED here: the locked entities predate the
		// wallet/contribution writes, so their cached stats would be stale
		// for the tier comparison.
		$freshMembers = array_map(
			fn (HouseUser $m): HouseUser => $this->houseUserMapper->find((int)$m->getId()),
			$members
		);
		$this->rhythmService->recomputeForMembers($houseId, $freshMembers, $now);
		$this->reconcileRecognition($freshMembers, null);

		$this->bumpHouseStateVersion($houseId);

		return ['completionId' => $completionId, 'noop' => false, 'version' => $newVersion];
	}

	/**
	 * Correct the actual participants/shares of ONE completion (WP3 §3.4
	 * attribution; Q-074-02): the completer or an authorized owner may
	 * adjust the allocation while the real work time, saved zone,
	 * occurrence, frozen total effort/rate and room event stay immutable.
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
		$participants = $this->canonicalizeParticipants($participants);

		$completion = $this->findCompletionScoped($completionId, $houseId);
		$roomTask = $this->findRoomTaskScoped($completion->getRoomTaskId(), $houseId);
		$this->authorizeCorrection($houseId, $actor, $completion, $roomTask, true);

		$house = $this->houseMapper->find($houseId);

		try {
			$this->currentCommandUuid = $commandId;
			$this->currentActorHouseUserId = (int)$actor->getId();
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
					$houseId, $actor, $completionId, $expectedVersion, $participants, $allocationReviewed, $reasonCode
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

		$fresh = $this->findCompletionScoped($completionId, $houseId);
		$freshRoomTask = $this->findRoomTaskScoped($fresh->getRoomTaskId(), $houseId);
		$this->authorizeCorrection($houseId, $actor, $fresh, $freshRoomTask, true);
		$response = $this->completionResponse(
			$house, $actor->getNextcloudUserId(), $fresh, $freshRoomTask,
			$this->findRoomOrNotFound($freshRoomTask->getRoomId()), $command['replay']
		);
		$response['occurrence'] = null;
		return $response;
	}

	/**
	 * The attribution aggregate inside the command transaction.
	 *
	 * @param array<int, array{userId: string, sharePct: int}> $participants
	 * @return array<string, mixed>
	 */
	private function attributeAggregate(
		int $houseId,
		HouseUser $actor,
		int $completionId,
		int $expectedVersion,
		array $participants,
		bool $allocationReviewed,
		?string $reasonCode,
	): array {
		$completionProbe = $this->findCompletionScoped($completionId, $houseId);
		$roomTask = $this->roomTaskMapper->lockForUpdate($completionProbe->getRoomTaskId());
		$completion = $this->findCompletionScoped($completionId, $houseId);
		$this->roomMapper->lockForUpdate($roomTask->getRoomId());
		$this->authorizeCorrection($houseId, $actor, $completion, $roomTask, true);

		if ($completion->getUndoneAt() !== null) {
			return ['completionId' => $completionId, 'noop' => true];
		}
		if ($completion->getCompletionVersion() !== $expectedVersion) {
			throw new ConflictException($this->l10n->t('The completion changed since it was displayed'));
		}

		// The reference allocation: the previous selected revision, keyed
		// by WALLET id from the share rows — the rows are the authority.
		// The original agreement proposal is NOT re-applied: attribution
		// corrects toward the ACTUAL people, the deviation confirmation
		// documents the review.
		$members = $this->houseUserMapper->findByHouseId($houseId);
		$membersByUserId = [];
		$userIdByWallet = [];
		foreach ($members as $member) {
			$membersByUserId[$member->getNextcloudUserId()] = $member;
			if ($member->getWalletId() !== null) {
				$userIdByWallet[(int)$member->getWalletId()] = $member->getNextcloudUserId();
			}
		}
		$previousShares = $this->workShareMapper->findByCompletionAndRevision($completionId, $completion->getAllocationRevision());
		$previousByWallet = [];
		foreach ($previousShares as $share) {
			if ($share->getWalletId() !== null) {
				$previousByWallet[(int)$share->getWalletId()] = $share->getSharePct();
			}
		}

		// The new allocation: current house members only (exact whole
		// percents summing to 100 — allocationFromParticipants validates).
		$newAllocation = $this->allocationFromParticipants($participants, $members);

		// Deviation review (WP3 §3.2): an explicit allocation differing
		// from the previous one requires the allocationReviewed
		// confirmation — a same-allocation submission stays valid without
		// it (an explicit identity correction). Departed previous
		// participants cannot be named in a new allocation and never
		// count as an unreviewed deviation.
		if (!$allocationReviewed) {
			$previousPresentByUserId = [];
			foreach ($previousShares as $share) {
				$uid = $share->getWalletId() !== null ? ($userIdByWallet[(int)$share->getWalletId()] ?? null) : null;
				if ($uid !== null) {
					$previousPresentByUserId[$uid] = $share->getSharePct();
				}
			}
			if (!$this->sameAllocation($participants, $previousPresentByUserId, $members)) {
				throw new ConflictException($this->l10n->t('The share allocation deviates from the recorded allocation and must be confirmed'));
			}
		}

		// Lock the room row BEFORE memberships/wallets (documented order),
		// then the union of old/new participant wallets in stable id order.
		$assigneeUserIds = $this->taskAssigneeMapper->findUserIdsByRoomTask($completion->getRoomTaskId());
		$task = $this->findTaskOrNotFound($roomTask->getTaskId());

		// Every NEW participant must be a currently eligible member (live
		// membership + task visibility revalidated under lock). A departed
		// PREVIOUS participant contributes their retained wallet to the
		// union below — never a membership row (WP3 §3.6: later correction
		// adjusts the retained wallet without recreating membership).
		$newMembers = [];
		foreach ($newAllocation as $uid => $_pct) {
			$member = $membersByUserId[$uid] ?? null;
			if ($member === null) {
				throw new ConflictException($this->l10n->t('Participants must be house members'));
			}
			$newMembers[] = $member;
		}
		$occurrence = $this->occurrenceService->occurrenceOfCompletion($completion);
		$recognitionMembers = $this->lockMembersByWallets($houseId, array_merge(
			array_keys($previousByWallet),
			$this->obligatedWalletIds($occurrence !== null ? $this->occMemberMapper->findByOccurrence((int)$occurrence->getId()) : [])
		), $newMembers);
		$newMembers = array_values(array_filter(
			$recognitionMembers,
			static fn (HouseUser $member): bool => isset($newAllocation[$member->getNextcloudUserId()])
		));

		// Bindings first (deterministic creation), then ONE sorted lock
		// pass over the whole wallet union — the departed participant's
		// wallet is locked by id like any other.
		$unionWalletIds = array_keys($previousByWallet);
		$walletIdByUserId = [];
		foreach ($newMembers as $member) {
			$uid = $member->getNextcloudUserId();
			if (!isset($previousByWallet[(int)$member->getWalletId()])) {
				$this->taskAccessPolicy->assertCanView($task, $member, $assigneeUserIds);
			}
			$bound = $this->walletService->ensureBinding($houseId, $member);
			$walletId = (int)$bound->getWalletId();
			$walletIdByUserId[$uid] = $walletId;
			$unionWalletIds[] = $walletId;
		}
		$unionWalletIds = array_values(array_unique($unionWalletIds));
		sort($unionWalletIds);
		$lockedByWalletId = [];
		foreach ($unionWalletIds as $walletId) {
			$lockedByWalletId[$walletId] = $this->walletMapper->findForUpdate($walletId);
		}
		$walletsByUserId = [];
		foreach ($walletIdByUserId as $uid => $walletId) {
			$walletsByUserId[$uid] = $lockedByWalletId[$walletId];
		}

		$now = new \DateTime();
		$totalEnergy = $completion->getTotalEnergy();

		$newRevision = $completion->getAllocationRevision() + 1;
		$newVersion = $completion->getCompletionVersion() + 1;

		// New allocation evidence (old revisions remain evidence, never
		// counted again — the row's revision pointer selects revision N).
		$this->writeSharesFromEntity($completion, $newRevision, $newAllocation, $totalEnergy, $walletsByUserId, $now);
		$this->completedTaskMapper->advanceVersion($completionId, $newVersion, $newRevision);
		$this->writeRevision($completion, $newVersion, CompletionRevision::ACTION_ATTRIBUTION, $newRevision, $this->currentActorHouseUserId, $reasonCode);

		// Exact deltas for the UNION over wallet ids: newAllocation -
		// oldAllocation; removal means a new allocation of zero. A
		// departed previous participant's retained wallet reverses
		// exactly like an active member's — the frozen total is conserved.
		$newByWallet = [];
		foreach ($newAllocation as $uid => $pct) {
			$newByWallet[$walletIdByUserId[$uid]] = $pct;
		}
		$deltas = [];
		foreach ($unionWalletIds as $walletId) {
			$oldCoin = isset($previousByWallet[$walletId])
				? $this->allocationService->allocate($totalEnergy, $previousByWallet[$walletId])['coinMinor']
				: 0;
			$newCoin = isset($newByWallet[$walletId])
				? $this->allocationService->allocate($totalEnergy, $newByWallet[$walletId])['coinMinor']
				: 0;
			$deltas[$walletId] = $newCoin - $oldCoin;
		}
		$this->walletService->postEntries(
			$houseId, WalletService::SOURCE_COMPLETION, $completionId, $newRevision,
			$deltas, WalletService::REASON_ATTRIBUTION, $this->currentActorHouseUserId
		);

		$this->walletService->recalcContributionCache($unionWalletIds);

		// The room event, work time, saved zone, occurrence and frozen
		// total effort/rate stay immutable (C3f) — no room/occurrence
		// writes here.

		// Recompute changed workers and obligated non-workers from valid
		// history; only the new allocation above receives monetary credit.
		// Departed wallets have no membership projection to refresh.
		$freshUnion = array_map(
			fn (HouseUser $m): HouseUser => $this->houseUserMapper->find((int)$m->getId()),
			$recognitionMembers
		);
		$this->rhythmService->recomputeForMembers($houseId, $freshUnion, $now);
		$this->reconcileRecognition($freshUnion, null);

		$this->bumpHouseStateVersion($houseId);

		return ['completionId' => $completionId, 'noop' => false, 'version' => $newVersion];
	}

	// ------------------------------------------------------------------
	// Allocation helpers
	// ------------------------------------------------------------------

	/**
	 * Canonicalize + validate the client participant list: unique user
	 * ids, positive whole percents. Order-independent identity: sorted by
	 * user id for the command payload.
	 *
	 * @param array<int, array{userId: string, sharePct: int}> $participants
	 * @return array<int, array{userId: string, sharePct: int}>
	 */
	private function canonicalizeParticipants(array $participants): array {
		$seen = [];
		foreach ($participants as $participant) {
			$uid = (string)($participant['userId'] ?? '');
			$pct = $participant['sharePct'] ?? null;
			if ($uid === '' || !is_numeric($pct) || (int)$pct != $pct || (int)$pct < 1 || (int)$pct > 100) {
				throw new \InvalidArgumentException($this->l10n->t('Every participant needs a user id and a whole percent share'));
			}
			if (isset($seen[$uid])) {
				throw new \InvalidArgumentException($this->l10n->t('Participants must be unique'));
			}
			$seen[$uid] = ['userId' => $uid, 'sharePct' => (int)$pct];
		}
		usort($seen, static fn (array $a, array $b): int => $a['userId'] <=> $b['userId']);
		return $seen;
	}

	/**
	 * The effective concrete agreement (WP3 §3.2): agreed occurrence
	 * member shares first; without member rows the equal wallet-order
	 * assignee shares; unassigned solo work starts at 100%.
	 *
	 * @param OccurrenceMember[] $occurrenceMembers
	 * @param string[] $assigneeUserIds
	 * @param HouseUser[] $members
	 * @return array<string, int> userId => sharePct
	 */
	private function effectiveProposal(array $occurrenceMembers, array $assigneeUserIds, array $members, string $actorUserId): array {
		$membersByUserId = [];
		$userIdByWallet = [];
		foreach ($members as $member) {
			$membersByUserId[$member->getNextcloudUserId()] = $member;
			if ($member->getWalletId() !== null) {
				$userIdByWallet[(int)$member->getWalletId()] = $member->getNextcloudUserId();
			}
		}

		// Shared derivation with the read-side proposal block (WP3 §3.2):
		// agreed occurrence member shares first.
		$agreed = $this->allocationService->agreementShares(
			$occurrenceMembers,
			$userIdByWallet
		);
		if ($agreed !== []) {
			return $agreed;
		}

		if ($assigneeUserIds !== []) {
			$subjects = [];
			foreach ($assigneeUserIds as $uid) {
				$subjects[$uid] = $membersByUserId[$uid]?->getWalletId();
			}
			return $this->allocationService->equalShares($subjects);
		}

		return [$actorUserId => 100];
	}

	/**
	 * Whether an explicit participant list equals a reference allocation
	 * (order-insensitive).
	 *
	 * @param array<int, array{userId: string, sharePct: int}> $participants
	 * @param array<string, int> $reference
	 * @param HouseUser[] $members
	 */
	private function sameAllocation(array $participants, array $reference, array $members): bool {
		if (count($participants) !== count($reference)) {
			return false;
		}
		foreach ($participants as $participant) {
			if (($reference[$participant['userId']] ?? null) !== $participant['sharePct']) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Build + validate the allocation map from explicit participants
	 * (userId => sharePct, sum exactly 100, members only).
	 *
	 * @param array<int, array{userId: string, sharePct: int}> $participants
	 * @param HouseUser[] $members
	 * @return array<string, int>
	 */
	private function allocationFromParticipants(array $participants, array $members): array {
		$membersByUserId = [];
		foreach ($members as $member) {
			$membersByUserId[$member->getNextcloudUserId()] = $member;
		}
		$allocation = [];
		foreach ($participants as $participant) {
			if (!isset($membersByUserId[$participant['userId']])) {
				throw new ConflictException($this->l10n->t('Participants must be house members'));
			}
			$allocation[$participant['userId']] = $participant['sharePct'];
		}
		$this->allocationService->validateShares($allocation);
		return $allocation;
	}

	/**
	 * Lock actual workers and current obligated members in ascending member
	 * id order. Only actual workers are checked for task visibility.
	 *
	 * @param string[] $assigneeUserIds
	 * @param int[] $obligatedWalletIds
	 * @param HouseUser[] $all
	 * @return HouseUser[]
	 */
	private function lockParticipantMembers(array $allocation, Task $task, array $assigneeUserIds, array $obligatedWalletIds, array $all): array {
		$membersByUserId = [];
		$membersByWallet = [];
		foreach ($all as $member) {
			$membersByUserId[$member->getNextcloudUserId()] = $member;
			if ($member->getWalletId() !== null) {
				$membersByWallet[(int)$member->getWalletId()] = $member;
			}
		}

		$ids = [];
		foreach (array_keys($allocation) as $uid) {
			$member = $membersByUserId[$uid] ?? null;
			if ($member === null) {
				throw new ConflictException($this->l10n->t('Participants must be house members'));
			}
			$ids[(int)$member->getId()] = true;
		}
		foreach ($obligatedWalletIds as $walletId) {
			if (isset($membersByWallet[$walletId])) {
				$ids[(int)$membersByWallet[$walletId]->getId()] = true;
			}
		}
		ksort($ids, SORT_NUMERIC);
		$locked = [];
		foreach (array_keys($ids) as $id) {
			$member = $this->houseUserMapper->findForUpdate($id);
			if (isset($allocation[$member->getNextcloudUserId()])) {
				$this->taskAccessPolicy->assertCanView($task, $member, $assigneeUserIds);
			}
			$locked[] = $member;
		}
		return $locked;
	}

	/**
	 * Lock the live wallet-backed members and new workers by member id,
	 * before any wallet locks (departed wallets have no member projection).
	 *
	 * @param int[] $walletIds
	 * @param HouseUser[] $additionalMembers
	 * @return HouseUser[]
	 */
	private function lockMembersByWallets(int $houseId, array $walletIds, array $additionalMembers = []): array {
		$walletIds = array_fill_keys($walletIds, true);
		$ids = [];
		foreach ($this->houseUserMapper->findByHouseId($houseId) as $member) {
			if ($member->getWalletId() !== null && isset($walletIds[(int)$member->getWalletId()])) {
				$ids[(int)$member->getId()] = true;
			}
		}
		foreach ($additionalMembers as $member) {
			$ids[(int)$member->getId()] = true;
		}
		ksort($ids, SORT_NUMERIC);
		$members = [];
		foreach (array_keys($ids) as $id) {
			$members[] = $this->houseUserMapper->findForUpdate($id);
		}
		return $members;
	}

	/** @param OccurrenceMember[] $rows @return int[] */
	private function obligatedWalletIds(array $rows): array {
		$walletIds = [];
		foreach ($rows as $row) {
			if ($row->getObligated() && $row->getDisposition() === OccurrenceMember::DISPOSITION_ACTIVE) {
				$walletIds[(int)$row->getWalletId()] = (int)$row->getWalletId();
			}
		}
		return array_values($walletIds);
	}

	/**
	 * Lock the wallets of the given users in stable id order and return
	 * them by user id (WP3 §3.3: acquire each category in stable ID
	 * order; never acquire a room lock after wallets inside the
	 * aggregate flow above).
	 *
	 * @param string[] $userIds
	 * @return array<string, \OCA\ChoreStrike\Db\Wallet>
	 */
	private function lockWallets(int $houseId, array $userIds): array {
		$members = $this->houseUserMapper->findByHouseId($houseId);
		$walletIdByUser = [];
		foreach ($members as $member) {
			if (in_array($member->getNextcloudUserId(), $userIds, true)) {
				// A participant without a binding (raw fixture row / legacy
				// pre-WP0 membership) gets the wallet created and bound
				// HERE — participation is the binding trigger, so a null
				// wallet can never reach the share writer.
				$bound = $member->getWalletId() !== null
					? $member
					: $this->walletService->ensureBinding($houseId, $member);
				$walletIdByUser[$member->getNextcloudUserId()] = (int)$bound->getWalletId();
			}
		}
		$ids = array_values(array_unique(array_values($walletIdByUser)));
		sort($ids);
		$wallets = [];
		foreach ($ids as $id) {
			$wallets[$id] = $this->walletMapper->findForUpdate($id);
		}
		$byUser = [];
		foreach ($walletIdByUser as $uid => $walletId) {
			$byUser[$uid] = $wallets[$walletId];
		}
		return $byUser;
	}

	/**
	 * The (already locked) wallet of one member, binding first if needed.
	 * {@see WalletService::ensureBinding()} returns the (possibly updated)
	 * MEMBERSHIP row — its walletId is the binding.
	 */
	private function walletFor(int $houseId, HouseUser $member): \OCA\ChoreStrike\Db\Wallet {
		$bound = $this->walletService->ensureBinding($houseId, $member);
		return $this->walletMapper->findForUpdate((int)$bound->getWalletId());
	}

	private function userIdOfWallet(array $membersByUserId, ?int $walletId): ?string {
		if ($walletId === null) {
			return null;
		}
		foreach ($membersByUserId as $member) {
			if ((int)$member->getWalletId() === $walletId) {
				return $member->getNextcloudUserId();
			}
		}
		return null;
	}

	private function writeShares(CompletedTask $completion, int $revision, array $allocation, int $totalEnergy, array $walletsByUserId): void {
		$this->writeSharesFromEntity($completion, $revision, $allocation, $totalEnergy, $walletsByUserId, new \DateTime());
	}

	/**
	 * @param array<string, int> $allocation
	 * @param array<string, \OCA\ChoreStrike\Db\Wallet> $walletsByUserId
	 */
	private function writeSharesFromEntity(CompletedTask $completion, int $revision, array $allocation, int $totalEnergy, array $walletsByUserId, \DateTime $now): void {
		$this->allocationService->validateShares($allocation);
		foreach ($allocation as $userId => $sharePct) {
			$amounts = $this->allocationService->allocate($totalEnergy, $sharePct);
			$share = new WorkShare();
			$share->setCompletionId((int)$completion->getId());
			$share->setAllocationRevision($revision);
			$share->setWalletId((int)$walletsByUserId[$userId]->getId());
			$share->setSharePct($sharePct);
			$share->setEnergyMinor($amounts['energyMinor']);
			$share->setCoinMinor($amounts['coinMinor']);
			$share->setCreatedAt($now);
			$this->workShareMapper->insert($share);
		}
	}

	private function writeRevision(CompletedTask $completion, int $revision, string $action, ?int $allocationRevision, ?int $actorHouseUserId, ?string $reasonCode): void {
		$audit = new CompletionRevision();
		$audit->setCompletionId((int)$completion->getId());
		$audit->setRevision($revision);
		$audit->setAction($action);
		$audit->setAllocationRevision($allocationRevision);
		$audit->setActorHouseUserId($actorHouseUserId);
		$audit->setReasonCode($reasonCode);
		$audit->setCommandUuid($this->currentCommandUuid ?? '');
		$audit->setCreatedAt(new \DateTime());
		$this->completionRevisionMapper->insert($audit);
	}

	/**
	 * The takeover claim of the undone completion reverts only while no
	 * later valid work superseded it — claim state derives from surviving
	 * provenance (WP3 §3.4), never restored over a later valid claim.
	 */
	private function revertClaimIfUnsuperseded(RoomTask $roomTask, CompletedTask $completion): void {
		if (!$roomTask->getWasClaimed()) {
			return;
		}
		if ($roomTask->getClaimedSourceCompletionId() !== (int)$completion->getId()) {
			return;
		}
		$roomTask->setWasClaimed(false);
		$roomTask->setClaimedByUser(null);
		$roomTask->setClaimedAt(null);
		$roomTask->setClaimedSourceCompletionId(null);
		$roomTask->setOriginalAssignee(null);
		$this->roomTaskMapper->update($roomTask);
	}

	/**
	 * Retained recognition (Q-074-01A): re-derive title tiers from the
	 * fresh valid-history stats — add newly crossed tiers, remove tiers
	 * the corrected valid history never reached (C3g). Returns the actor's
	 * new unlock for the celebration identity.
	 *
	 * @param HouseUser[] $members
	 */
	private function reconcileRecognition(array $members, ?string $actorUserId): ?array {
		if ($this->titleService === null || !$this->configService->getTitlesEnabled()) {
			return null;
		}
		$newTitle = null;
		foreach ($members as $member) {
			$unlocked = $this->titleService->detectUnlock($member);
			$removed = $this->titleService->reconcileUnlocked($member);
			// The marker mutation (newly added OR reconciled-away tiers)
			// persists here — the correction must survive the transaction.
			if ($unlocked !== [] || $removed !== []) {
				$this->houseUserMapper->update($member);
			}
			// The single retained series has one tier per rung: the actor's
			// new unlock is the newly crossed tier (no pack preference since
			// Q-075-02 A).
			if ($actorUserId !== null && $member->getNextcloudUserId() === $actorUserId && $unlocked !== []) {
				$newTitle = $unlocked[0];
			}
		}
		return $newTitle;
	}

	private function bumpHouseStateVersion(int $houseId): void {
		$house = $this->houseMapper->findForUpdate($houseId);
		$house->setStateVersion($house->getStateVersion() + 1);
		$house->touch();
		$this->houseMapper->update($house);
	}

	// ------------------------------------------------------------------
	// Response shaping
	// ------------------------------------------------------------------

	/**
	 * The read model of ONE completion (WP3 §3.6 `GET /completions/{id}`):
	 * versioned original work, the selected allocation, the authorized
	 * correction history and `canCorrect` — no foreign wallet totals, no
	 * raw audit snapshots; write-free.
	 *
	 * @return array<string, mixed>
	 */
	public function showCompletion(int $houseId, HouseUser $viewer, int $completionId): array {
		$completion = $this->findCompletionScoped($completionId, $houseId);
		$roomTask = $this->findRoomTaskScoped($completion->getRoomTaskId(), $houseId);
		$task = $this->findTaskOrNotFound($roomTask->getTaskId());
		$this->taskAccessPolicy->assertCanView(
			$task, $viewer, $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTask->getId())
		);

		$members = $this->houseUserMapper->findByHouseId($houseId);
		$membersByUserId = [];
		$userIdByWallet = [];
		foreach ($members as $member) {
			$membersByUserId[$member->getNextcloudUserId()] = $member;
			if ($member->getWalletId() !== null) {
				$userIdByWallet[(int)$member->getWalletId()] = $member;
			}
		}

		$allocation = [];
		foreach ($this->workShareMapper->findByCompletionAndRevision($completionId, $completion->getAllocationRevision()) as $share) {
			$member = $share->getWalletId() !== null ? ($userIdByWallet[(int)$share->getWalletId()] ?? null) : null;
			$allocation[] = [
				'userId' => $member?->getNextcloudUserId(),
				'displayName' => $member?->getDisplayName() ?? $this->l10n->t('Removed member'),
				'sharePct' => $share->getSharePct(),
				'energyMinor' => $share->getEnergyMinor(),
				'coinMinor' => $share->getCoinMinor(),
			];
		}

		$history = [];
		foreach ($this->completionRevisionMapper->findByCompletionId($completionId) as $revision) {
			// A departed (not erased) corrector leaves a dangling audit
			// actor id: the identity stays immutable, the read model
			// renders the localized placeholder (same guard as the
			// undo/attribute authorization paths).
			$actor = null;
			if ($revision->getActorHouseUserId() !== null) {
				try {
					$actor = $this->houseUserMapper->find($revision->getActorHouseUserId());
				} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
					$actor = null;
				}
			}
			$history[] = [
				'revision' => $revision->getRevision(),
				'action' => $revision->getAction(),
				'allocationRevision' => $revision->getAllocationRevision(),
				'actor' => $actor?->getNextcloudUserId(),
				'actorDisplayName' => $actor?->getDisplayName() ?? $this->l10n->t('Removed member'),
				'reasonCode' => $revision->getReasonCode(),
				'createdAt' => $revision->getCreatedAt()?->format('c'),
			];
		}

		$canCorrect = $completion->getUndoneAt() === null
			&& ($viewer->isOwner() || (
				$completion->getUserId() !== null
				&& ($membersByUserId[$viewer->getNextcloudUserId()]?->getId() === $completion->getUserId())
			));

		return [
			'id' => (int)$completion->getId(),
			'roomTaskId' => $completion->getRoomTaskId(),
			'taskName' => $task->getName(),
			'completedAt' => $completion->getCompletedAt()?->format('c'),
			'totalEnergy' => $completion->getTotalEnergy(),
			'occurrenceId' => $completion->getScheduleRevId() !== null && $completion->getOccurrenceOrdinal() !== null
				? $completion->getScheduleRevId() . ':' . $completion->getOccurrenceOrdinal()
				: null,
			'workDate' => $completion->getWorkDate()?->format('Y-m-d'),
			'workZone' => $completion->getWorkZone(),
			'completionClass' => $completion->getCompletionClass(),
			'version' => $completion->getCompletionVersion(),
			'allocationRevision' => $completion->getAllocationRevision(),
			'undoneAt' => $completion->getUndoneAt()?->format('c'),
			'allocation' => $allocation,
			'history' => $history,
			'canCorrect' => $canCorrect,
		];
	}

	/** Selected work evidence, joined only to live membership identities (never wallet balances). */
	private function selectedParticipantView(int $houseId, CompletedTask $completion): array {
		$membersByWallet = [];
		foreach ($this->houseUserMapper->findByHouseId($houseId) as $member) {
			if ($member->getWalletId() !== null) {
				$membersByWallet[(int)$member->getWalletId()] = $member;
			}
		}
		$participants = [];
		foreach ($this->workShareMapper->findByCompletionAndRevision((int)$completion->getId(), $completion->getAllocationRevision()) as $share) {
			$member = $membersByWallet[(int)$share->getWalletId()] ?? null;
			$participants[] = [
				'userId' => $member?->getNextcloudUserId(),
				'displayName' => $member?->getDisplayName() ?? $this->l10n->t('Removed member'),
				'sharePct' => $share->getSharePct(),
				'energyMinor' => $share->getEnergyMinor(),
				'coinMinor' => $share->getCoinMinor(),
			];
		}
		return $participants;
	}

	/**
	 * The shared completion response shape (WP3 §3.6): versioned identity,
	 * caller-safe projections, own wallet in minor units, participant
	 * shares; no foreign wallet totals.
	 *
	 */
	private function completionResponse(
		House $house,
		string $viewerUserId,
		CompletedTask $completion,
		RoomTask $roomTask,
		?Room $room,
		bool $replay,
	): array {
		$task = $this->findTaskOrNotFound($roomTask->getTaskId());
		$walletBlock = null;
		$contributionMinor = null;
		$member = $this->houseUserMapper->findByHouseAndUser((int)$house->getId(), $viewerUserId);
		if ($member !== null) {
			$contributionMinor = $member->getContributionMinor();
			$wallet = $member->getWalletId() !== null ? $this->walletMapper->findByHouseAndUser((int)$house->getId(), $viewerUserId) : null;
			$walletBlock = $wallet !== null ? $this->walletService->serializeOwnWallet($wallet) : null;
		}

		$rewards = [
			'completion' => [
				'id' => (int)$completion->getId(),
				'version' => $completion->getCompletionVersion(),
				'allocationRevision' => $completion->getAllocationRevision(),
				'totalEnergy' => $completion->getTotalEnergy(),
				'completedAt' => $completion->getCompletedAt()?->format('c'),
				'undoneAt' => $completion->getUndoneAt()?->format('c'),
			],
			'participants' => $completion->getUndoneAt() === null ? $this->selectedParticipantView((int)$house->getId(), $completion) : [],
			'wallet' => $walletBlock,
			'contributionMinor' => $contributionMinor,
			// The requested journal delta of THIS completion (the client
			// renders the observed cleanliness from the room block).
			'cleanlinessChange' => $completion->getUndoneAt() === null ? $completion->getTotalEnergy() * 10 : -1 * $completion->getTotalEnergy() * 10,
			'energyCost' => $task->getEnergyCost(),
			'priority' => $task->getPriority(),
		];

		return [
			'roomTask' => $roomTask,
			'room' => $room,
			'task' => $task,
			'rewards' => $rewards,
			'completionId' => (int)$completion->getId(),
			'replay' => $replay,
		];
	}

	/**
	 * The undo response shape (the completion is marked undone; current
	 * projections ride along).
	 */
	private function undoResponse(House $house, CompletedTask $completion, bool $replay, ?RoomTask $roomTask = null): array {
		$roomTask = $roomTask ?? $this->findRoomTaskScoped($completion->getRoomTaskId(), (int)$house->getId());
		$response = $this->completionResponse($house, '', $completion, $roomTask, null, $replay);
		$response['room'] = $this->findRoomOrNotFound($roomTask->getRoomId());
		$response['undone'] = $completion->getUndoneAt() !== null;
		$response['occurrence'] = $this->selectionBlock($roomTask, $house);
		return $response;
	}

	/**
	 * Fresh, write-free selection block of a room task's next completion
	 * target (WP1 DTO enrichment).
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
	 * Resolve a completion scoped to the house through the room task →
	 * room → floor chain; a foreign/missing id is a clean 404.
	 */
	private function findCompletionScoped(int $completionId, int $houseId): CompletedTask {
		try {
			$completion = $this->completedTaskMapper->find($completionId);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Completion not found'));
		}
		$scoped = $this->roomTaskMapper->findByIdsScopedToHouse([$completion->getRoomTaskId()], $houseId);
		if ($scoped === []) {
			throw new NotFoundException($this->l10n->t('Completion not found'));
		}
		return $completion;
	}

	/**
	 * Re-read authorization from live rows for the initial gate, the
	 * house-locked correction, and receipt replays. Never use the actor
	 * entity supplied before the lock to decide current owner status.
	 */
	private function authorizeCorrection(int $houseId, HouseUser $actor, CompletedTask $completion, RoomTask $roomTask, bool $attribution): void {
		$liveActor = $this->houseUserMapper->findByHouseAndUser($houseId, $actor->getNextcloudUserId());
		if ($liveActor === null || (int)$liveActor->getId() !== (int)$actor->getId()) {
			throw new ForbiddenException($this->l10n->t('Not a member of this house'));
		}
		$this->taskAccessPolicy->assertCanView(
			$this->findTaskOrNotFound($roomTask->getTaskId()),
			$liveActor,
			$this->taskAssigneeMapper->findUserIdsByRoomTask((int)$roomTask->getId())
		);
		$completerUid = null;
		if ($completion->getUserId() !== null) {
			try {
				$completerUid = $this->houseUserMapper->find((int)$completion->getUserId())->getNextcloudUserId();
			} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
				// An erased audit actor leaves only the current owner route.
			}
		}
		if ($completerUid !== $liveActor->getNextcloudUserId() && !$liveActor->isOwner()) {
			throw new ForbiddenException($attribution
				? $this->l10n->t('Only the completer or a house owner can correct a completion')
				: $this->l10n->t('Only the completer or a house owner can undo a completion'));
		}
	}

	/**
	 * @throws NotFoundException if not found
	 */
	private function findTaskOrNotFound(int $taskId): Task {
		try {
			return $this->taskMapper->find($taskId);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Task not found'));
		}
	}

	/**
	 * @throws NotFoundException if not found
	 */
	private function findRoomOrNotFound(int $roomId): Room {
		try {
			return $this->roomMapper->find($roomId);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Room not found'));
		}
	}
}
