<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\ScheduleRevision;
use OCA\ChoreStrike\Db\Task;

/**
 * Owner of the occurrence timeline (CS-PLAN-072 WP1; parent
 * CS-PLAN-070 §3.2/§3.3): selection, reconciliation, materialization
 * and revision of durable occurrence agreements.
 *
 * RF-072-WP1-18: the implementation is split into cohesive
 * collaborators, this class remaining the stable single-recurrence-
 * authority seam every consumer and test talks to:
 * - {@see OccurrenceRowFactory} — occurrence row drafts (materialized or
 *   virtual) with resolved saved-zone windows;
 * - {@see OccurrenceReconcileService} — frontier, reconciliation,
 *   materialization, interval pending, end-of-life, `next_due`
 *   projection, timeline job pass;
 * - {@see OccurrenceSelectionService} — write-free selection/read
 *   derivation, occurrence DTO block, selection token, projections;
 * - {@see OccurrenceRevisionService} — rule inputs, schedule creation
 *   and the revision lifecycle (edit/zone/tombstone boundaries);
 * - {@see OccurrenceCompletionService} — completion resolution, timeline
 *   effects and the undo seam;
 * - {@see OccurrenceAgreementService} — C2 date/participant amendments
 *   and the shared whole-day child-capacity counter.
 *
 * {@see RecurrenceCalculator} provides the pure date enumeration.
 *
 * Transaction convention: every public mutator opens its own house-first
 * transaction (house row lock first). Methods suffixed `UnderLock` run
 * inside a CALLER-owned open transaction (creation/edit/completion
 * aggregates that already hold the house lock) and never begin one.
 */
class OccurrenceService {
	/** Agreement horizon: planned local days covered by the frontier. */
	public const HORIZON_DAYS = OccurrenceReconcileService::HORIZON_DAYS;

	/** Cursor batch size of the timeline job pass. */
	public const JOB_BATCH_SIZE = OccurrenceReconcileService::JOB_BATCH_SIZE;

	/** Per-pass materialization budget of the job reconciliation (RF-072-WP1-08). */
	public const JOB_MATERIALIZE_BUDGET = OccurrenceReconcileService::JOB_MATERIALIZE_BUDGET;

	/** Bounded lookback of the selection derivation (RF-072-WP1-10). */
	public const SELECTION_LOOKBACK_DAYS = OccurrenceSelectionService::SELECTION_LOOKBACK_DAYS;

	private OccurrenceReconcileService $reconcile;
	private OccurrenceSelectionService $selection;
	private OccurrenceRevisionService $revisions;
	private OccurrenceCompletionService $completion;
	private OccurrenceAgreementService $agreements;

	public function __construct(
		OccurrenceReconcileService $reconcile,
		OccurrenceSelectionService $selection,
		OccurrenceRevisionService $revisions,
		OccurrenceCompletionService $completion,
		OccurrenceAgreementService $agreements,
	) {
		$this->reconcile = $reconcile;
		$this->selection = $selection;
		$this->revisions = $revisions;
		$this->completion = $completion;
		$this->agreements = $agreements;
	}

	// ------------------------------------------------------------------
	// Rule building and creation ({@see OccurrenceRevisionService})
	// ------------------------------------------------------------------

	/**
	 * Map a task definition onto rule inputs for a new schedule revision.
	 *
	 * @return array{cadenceMode: string, cadenceUnit: string, cadenceInterval: int, weekdays: ?string, lifetime: string, activeFrom: ?\DateTime, activeUntil: ?\DateTime, early: int, late: int, energy: int, isPublic: bool, isVoluntary: bool}
	 */
	public function ruleInputsFromTask(Task $task): array {
		return $this->revisions->ruleInputsFromTask($task);
	}

	/**
	 * The default member snapshot from the canonical assignee set.
	 *
	 * @param string[] $assigneeUserIds
	 * @return list<array{walletId: int, userId: string, role: string, obligated: bool, sharePct: int}>|null
	 */
	public function buildDefaultMembers(int $houseId, array $assigneeUserIds): ?array {
		return $this->revisions->buildDefaultMembers($houseId, $assigneeUserIds);
	}

	/**
	 * Create revision 1 plus its initial agreed occurrences for a freshly
	 * created room task UNDER LOCK (caller-owned house-first transaction).
	 */
	public function initializeScheduleUnderLock(
		int $houseId,
		RoomTask $roomTask,
		Task $task,
		string $timezoneId,
		?\DateTime $explicitNextDue,
		array $assigneeUserIds,
		\DateTime $now,
	): ScheduleRevision {
		return $this->revisions->initializeScheduleUnderLock($houseId, $roomTask, $task, $timezoneId, $explicitNextDue, $assigneeUserIds, $now);
	}

	/**
	 * Transaction-owning wrapper of {@see initializeScheduleUnderLock()}
	 * for seed/setup paths outside a creation aggregate.
	 */
	public function initializeSchedule(
		int $houseId,
		RoomTask $roomTask,
		Task $task,
		string $timezoneId,
		?\DateTime $explicitNextDue,
		array $assigneeUserIds,
		\DateTime $now,
	): ScheduleRevision {
		return $this->revisions->initializeSchedule($houseId, $roomTask, $task, $timezoneId, $explicitNextDue, $assigneeUserIds, $now);
	}

	// ------------------------------------------------------------------
	// Reconciliation, frontier and timeline job ({@see OccurrenceReconcileService})
	// ------------------------------------------------------------------

	/**
	 * Advance one room task's current revision to the agreement horizon
	 * (own house-first transaction; idempotent).
	 */
	public function reconcileRoomTask(int $houseId, int $roomTaskId, ?\DateTime $now = null): int {
		return $this->reconcile->reconcileRoomTask($houseId, $roomTaskId, $now);
	}

	/**
	 * Reconcile one room task UNDER LOCK (caller owns the house-first
	 * transaction).
	 */
	public function reconcileUnderLock(int $houseId, RoomTask $roomTask, \DateTime $now): int {
		return $this->reconcile->reconcileUnderLock($houseId, $roomTask, $now);
	}

	/**
	 * Reconcile one room task only through a bounded frontier (edit
	 * preamble; fails atomically beyond the enforced generation cap).
	 */
	public function reconcileThroughUnderLock(int $houseId, RoomTask $roomTask, string $throughKey, \DateTime $now): int {
		return $this->reconcile->reconcileThroughUnderLock($houseId, $roomTask, $throughKey, $now);
	}

	/**
	 * The timeline job pass over the instance house (per-task house-first
	 * transactions; no awards, credits or notifications).
	 *
	 * @return array{houses: int, tasks: int, materialized: int}
	 */
	public function reconcileHouseForJobs(?\DateTime $now = null): array {
		return $this->reconcile->reconcileHouseForJobs($now);
	}

	/**
	 * Refresh the derived `next_due`/`ideal_due_date` read projections
	 * from the occurrence authority UNDER LOCK.
	 */
	public function updateNextDueProjectionUnderLock(RoomTask $roomTask, \DateTime $now): void {
		$this->reconcile->updateNextDueProjectionUnderLock($roomTask, $now);
	}

	/**
	 * Persist the in-memory stamped suspension facts of a materialized
	 * occurrence row UNDER LOCK (RF-073-WP2-05 command-boundary stamping;
	 * bumps the row version — run staleness gates first).
	 */
	public function persistSuspensionStampUnderLock(Occurrence $occurrence): void {
		$this->reconcile->persistSuspensionStampUnderLock($occurrence);
	}

	// ------------------------------------------------------------------
	// Revision closure — edits, zone changes, tombstones ({@see OccurrenceRevisionService})
	// ------------------------------------------------------------------

	/**
	 * Revise one room task UNDER LOCK: reconcile elapsed agreements, close
	 * the old revision, append the new one.
	 */
	public function reviseUnderLock(
		int $houseId,
		RoomTask $roomTask,
		Task $task,
		string $timezoneId,
		?array $forceParticipants,
		\DateTime $now,
	): ScheduleRevision {
		return $this->revisions->reviseUnderLock($houseId, $roomTask, $task, $timezoneId, $forceParticipants, $now);
	}

	/**
	 * Zone-change revision pass UNDER LOCK across every room task of the
	 * house.
	 */
	public function reviseForZoneChangeUnderLock(int $houseId, string $newTimezoneId, \DateTime $now): void {
		$this->revisions->reviseForZoneChangeUnderLock($houseId, $newTimezoneId, $now);
	}

	/**
	 * Stop scheduling for a tombstoned room task UNDER LOCK (close the
	 * live revision; occurrence/completion evidence stays).
	 */
	public function stopSchedulingUnderLock(int $roomTaskId, \DateTime $now): void {
		$this->revisions->stopSchedulingUnderLock($roomTaskId, $now);
	}

	// ------------------------------------------------------------------
	// Selection and read derivation ({@see OccurrenceSelectionService})
	// ------------------------------------------------------------------

	/**
	 * The canonical completion target of a room task (open window, else
	 * latest miss; never a future projection). Neutral suspension is
	 * honored when the house context is supplied.
	 *
	 * @return array{occurrence: Occurrence, revision: ScheduleRevision, classification: string}|null
	 */
	public function selectionFor(RoomTask $roomTask, \DateTime $now, int $houseId = 0): ?array {
		return $this->selection->selectionFor($roomTask, $now, $houseId);
	}

	/**
	 * @param list<array{occurrence: Occurrence, revision: ScheduleRevision}> $agreed
	 */
	public function selectFromAgreed(array $agreed, \DateTime $now): ?array {
		return $this->selection->selectFromAgreed($agreed, $now);
	}

	/**
	 * Stamp the WP2 projection facts of ONE occurrence in memory and
	 * return its derived work appointment (write-free; the command
	 * boundary's twin of the batched read stamping — RF-073-WP2-05/22).
	 *
	 * @return array{date: ?string, zone: string}
	 */
	public function stampProjection(int $houseId, Occurrence $occurrence, ScheduleRevision $revision): array {
		return $this->selection->stampProjection($houseId, $occurrence, $revision);
	}

	/**
	 * All AGREED occurrences of a room task (write-free), optionally
	 * filtered to a planned-date window.
	 *
	 * @return list<array{occurrence: Occurrence, revision: ScheduleRevision}>
	 */
	public function agreedOccurrencesForTask(RoomTask $roomTask, ?string $fromKey, ?string $toKey): array {
		return $this->selection->agreedOccurrencesForTask($roomTask, $fromKey, $toKey);
	}

	/**
	 * Batched house-wide agreed-occurrence derivation for the read paths.
	 *
	 * @param RoomTask[] $roomTasks
	 * @return array<int, list<array{occurrence: Occurrence, revision: ScheduleRevision}>>
	 */
	public function agreedOccurrencesForHouseTasks(int $houseId, array $roomTasks, ?string $fromKey, ?string $toKey): array {
		return $this->selection->agreedOccurrencesForHouseTasks($houseId, $roomTasks, $fromKey, $toKey);
	}

	/**
	 * Batched canonical selection per room task (write-free DTO
	 * enrichment).
	 *
	 * @param RoomTask[] $roomTasks
	 * @return array<int, array{occurrence: Occurrence, revision: ScheduleRevision, classification: string}|null>
	 */
	public function selectionsForHouseTasks(int $houseId, array $roomTasks, \DateTime $now): array {
		return $this->selection->selectionsForHouseTasks($houseId, $roomTasks, $now);
	}

	/**
	 * The occurrence DTO block of one entry (shared by every read path);
	 * carries the WP2 neutral-suspension facts and the agreed work
	 * appointment.
	 *
	 * @param array{date: ?string, zone: string}|null $workAppointment
	 * @return array<string, mixed>
	 */
	public function occurrenceBlock(Occurrence $occurrence, ScheduleRevision $revision, string $classification, int $houseId = 0, ?string $pauseFingerprint = null, ?array $workAppointment = null): array {
		return $this->selection->occurrenceBlock($occurrence, $revision, $classification, $houseId, $pauseFingerprint, $workAppointment);
	}

	/**
	 * Deterministic selection token (parent §3.4); fingerprints the
	 * effective pause inputs of the house context when supplied.
	 *
	 * @param array{date: ?string, zone: string}|null $workAppointment
	 */
	public function selectionToken(Occurrence $occurrence, ScheduleRevision $revision, string $classification, int $houseId = 0, ?string $pauseFingerprint = null, ?array $workAppointment = null): string {
		return $this->selection->selectionToken($occurrence, $revision, $classification, $houseId, $pauseFingerprint, $workAppointment);
	}

	/**
	 * FUTURE projection dates of a room task beyond the frontier.
	 *
	 * @return list<array{date: string, key: string}>
	 */
	public function projectionsForTask(RoomTask $roomTask, string $fromKey, string $toKey): array {
		return $this->selection->projectionsForTask($roomTask, $fromKey, $toKey);
	}

	// ------------------------------------------------------------------
	// Completion resolution and effects ({@see OccurrenceCompletionService})
	// ------------------------------------------------------------------

	/**
	 * Resolve an agreed occurrence key UNDER LOCK, optionally
	 * materializing a virtual agreed key (version 0).
	 *
	 * @return array{occurrence: Occurrence, revision: ScheduleRevision}
	 */
	public function resolveAgreedKeyUnderLock(int $houseId, RoomTask $roomTask, string $occurrenceKey, \DateTime $now, bool $materialize): array {
		return $this->completion->resolveAgreedKeyUnderLock($houseId, $roomTask, $occurrenceKey, $now, $materialize);
	}

	/**
	 * Resolve the shown target for completion UNDER LOCK (version +
	 * canonical-selection + selection-token check).
	 *
	 * @return array{occurrence: Occurrence, revision: ScheduleRevision, classification: string}
	 */
	public function resolveForCompletionUnderLock(
		int $houseId,
		RoomTask $roomTask,
		string $occurrenceKey,
		int $expectedVersion,
		?string $selectionToken,
		\DateTime $now,
	): array {
		return $this->completion->resolveForCompletionUnderLock($houseId, $roomTask, $occurrenceKey, $expectedVersion, $selectionToken, $now);
	}

	/**
	 * Apply one completion's timeline effects UNDER LOCK.
	 */
	public function applyCompletionUnderLock(
		int $houseId,
		Occurrence $occurrence,
		ScheduleRevision $revision,
		RoomTask $roomTask,
		int $completionRowId,
		string $workDateKey,
		string $classification,
		\DateTime $now,
	): void {
		$this->completion->applyCompletionUnderLock($houseId, $occurrence, $revision, $roomTask, $completionRowId, $workDateKey, $classification, $now);
	}

	/**
	 * Undo seam UNDER LOCK: release the occurrence, restore catch-up
	 * coverage, re-derive open interval successors.
	 */
	public function releaseCompletionUnderLock(
		int $houseId,
		Occurrence $occurrence,
		RoomTask $roomTask,
		CompletedTask $completion,
		\DateTime $now,
	): void {
		$this->completion->releaseCompletionUnderLock($houseId, $occurrence, $roomTask, $completion, $now);
	}

	/**
	 * The occurrence a ledger row was recorded against (undo target).
	 */
	public function occurrenceOfCompletion(CompletedTask $completion): ?Occurrence {
		return $this->completion->occurrenceOfCompletion($completion);
	}

	// ------------------------------------------------------------------
	// Agreement writer (Q-072-01 Option A; {@see OccurrenceAgreementService})
	// ------------------------------------------------------------------

	/**
	 * Record an agreed date/window amendment (C2c) UNDER LOCK on the
	 * earliest unfulfilled occurrence.
	 */
	public function amendOccurrenceDateUnderLock(
		RoomTask $roomTask,
		?int $actorHouseUserId,
		?array $affectedWalletIds,
		string $newDateKey,
		\DateTime $now,
		?array $consent = null,
	): Occurrence {
		return $this->agreements->amendOccurrenceDateUnderLock($roomTask, $actorHouseUserId, $affectedWalletIds, $newDateKey, $now, $consent);
	}

	/**
	 * WP2 explicit-target date amendment UNDER LOCK (RF-073-WP2-01):
	 * moves EXACTLY the resolved occurrence, never a re-selected one.
	 */
	public function amendOccurrenceDateForTargetUnderLock(
		Occurrence $occurrence,
		ScheduleRevision $revision,
		RoomTask $roomTask,
		?int $actorHouseUserId,
		?array $affectedWalletIds,
		string $newDateKey,
		\DateTime $now,
		?array $consent = null,
	): Occurrence {
		return $this->agreements->amendOccurrenceDateForTargetUnderLock($occurrence, $revision, $roomTask, $actorHouseUserId, $affectedWalletIds, $newDateKey, $now, $consent);
	}

	/**
	 * WP2 single-occurrence participant amendment UNDER LOCK (handover /
	 * share) — future revision defaults stay untouched; former participants
	 * are persisted as neutral releases (RF-073-WP2-02) and the evidence
	 * kind is derived from the actual responsibility change.
	 *
	 * @param list<array{walletId: int, userId?: string, role: string, obligated: bool, sharePct: int}> $participants
	 */
	public function amendOccurrenceMembersUnderLock(
		Occurrence $occurrence,
		RoomTask $roomTask,
		?int $actorHouseUserId,
		array $participants,
		array $consentSubjects,
		\DateTime $now,
	): Occurrence {
		return $this->agreements->amendOccurrenceMembersUnderLock($occurrence, $roomTask, $actorHouseUserId, $participants, $consentSubjects, $now);
	}

	/**
	 * WP2 catch-up work-appointment writer UNDER LOCK.
	 *
	 * @param list<array{walletId: int, role: string, obligated: bool, sharePct: int}> $participants
	 */
	public function setWorkAppointmentUnderLock(
		Occurrence $occurrence,
		RoomTask $roomTask,
		?int $actorHouseUserId,
		string $workDateKey,
		string $workZone,
		array $participants,
		array $consentSubjects,
		\DateTime $now,
	): Occurrence {
		return $this->agreements->setWorkAppointmentUnderLock($occurrence, $roomTask, $actorHouseUserId, $workDateKey, $workZone, $participants, $consentSubjects, $now);
	}

	/**
	 * WP2 linked interval restart writer UNDER LOCK.
	 */
	public function createLinkedRestartUnderLock(
		int $houseId,
		Occurrence $predecessor,
		RoomTask $roomTask,
		ScheduleRevision $revision,
		string $plannedDateKey,
		?int $actorHouseUserId,
		array $consentSubjects,
		\DateTime $now,
	): Occurrence {
		return $this->agreements->createLinkedRestartUnderLock($houseId, $predecessor, $roomTask, $revision, $plannedDateKey, $actorHouseUserId, $consentSubjects, $now);
	}

	/**
	 * Record an agreed participant amendment (C2b/C2a handover) UNDER
	 * LOCK with the C2h child-capacity gate.
	 *
	 * @param string[] $newAssigneeUserIds
	 * @return array{revision: ScheduleRevision, amended: int}
	 */
	public function amendParticipantsUnderLock(
		int $houseId,
		RoomTask $roomTask,
		Task $task,
		House $house,
		?int $actorHouseUserId,
		array $newAssigneeUserIds,
		\DateTime $now,
		?array $consent = null,
	): array {
		return $this->agreements->amendParticipantsUnderLock($houseId, $roomTask, $task, $house, $actorHouseUserId, $newAssigneeUserIds, $now, $consent);
	}

	/**
	 * The ONE shared C2h whole-day child-capacity counter
	 * (RF-072-WP1-11).
	 *
	 * @param int[] $roomTaskIds
	 * @return array<int, array<string, int>>
	 */
	public function openOccurrenceDayLoadByWallet(array $roomTaskIds, \DateTime $now): array {
		return $this->agreements->openOccurrenceDayLoadByWallet($roomTaskIds, $now);
	}
}
