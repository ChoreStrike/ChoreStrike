<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\AgreementMapper;
use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper;
use OCA\ChoreStrike\Db\ScheduleRevision;
use OCA\ChoreStrike\Db\ScheduleRevisionMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Exception\ConflictException;
use OCP\IL10N;

/**
 * Completion resolution and undo seam of the occurrence timeline
 * (CS-PLAN-072 WP1; parent CS-PLAN-070 §3.3, T1; RF-072-WP1-18 split of
 * the former OccurrenceService monolith).
 *
 * Resolves an agreed occurrence key UNDER LOCK (optionally materializing
 * the virtual agreed key at version 0), verifies version/token/canonical
 * selection for the completion aggregate, applies one completion's
 * timeline effects (fulfill, cover the latest older miss, anchor the
 * interval successor in the saved local basis) and releases them again
 * on undo — later valid work always survives. WP3 (§3.4): the open-
 * successor re-derivation deletes ONLY still-open automatically derived
 * rows — an explicit agreement (amendment/catch-up/restart) or surviving
 * occurrence-scoped checklist progress pins the row and survives.
 */
class OccurrenceCompletionService {
	private ScheduleRevisionMapper $revisionMapper;
	private OccurrenceMapper $occurrenceMapper;
	private OccurrenceMemberMapper $occMemberMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private AgreementMapper $agreementMapper;
	private RoomTaskChecklistProgressMapper $checklistProgressMapper;
	private RecurrenceCalculator $calculator;
	private CalendarService $calendar;
	private IL10N $l10n;
	private OccurrenceReconcileService $reconcile;
	private OccurrenceSelectionService $selection;
	private OccurrenceRowFactory $rowFactory;

	public function __construct(
		ScheduleRevisionMapper $revisionMapper,
		OccurrenceMapper $occurrenceMapper,
		OccurrenceMemberMapper $occMemberMapper,
		CompletedTaskMapper $completedTaskMapper,
		AgreementMapper $agreementMapper,
		RoomTaskChecklistProgressMapper $checklistProgressMapper,
		RecurrenceCalculator $calculator,
		CalendarService $calendar,
		IL10N $l10n,
		OccurrenceReconcileService $reconcile,
		OccurrenceSelectionService $selection,
		OccurrenceRowFactory $rowFactory,
	) {
		$this->revisionMapper = $revisionMapper;
		$this->occurrenceMapper = $occurrenceMapper;
		$this->occMemberMapper = $occMemberMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->agreementMapper = $agreementMapper;
		$this->checklistProgressMapper = $checklistProgressMapper;
		$this->calculator = $calculator;
		$this->calendar = $calendar;
		$this->l10n = $l10n;
		$this->reconcile = $reconcile;
		$this->selection = $selection;
		$this->rowFactory = $rowFactory;
	}

	/**
	 * Resolve an agreed occurrence key UNDER LOCK, optionally materializing
	 * a virtual agreed key (version 0) through the same generator. Shared
	 * by the completion and checklist resolvers — persisted and virtual
	 * representations produce the same public key.
	 *
	 * @return array{occurrence: Occurrence, revision: ScheduleRevision}
	 * @throws ConflictException when the key does not resolve to an agreed
	 *                           occurrence of this room task
	 */
	public function resolveAgreedKeyUnderLock(int $houseId, RoomTask $roomTask, string $occurrenceKey, \DateTime $now, bool $materialize): array {
		[$revId, $ordinal] = Occurrence::parsePublicKey($occurrenceKey);

		$revision = null;
		foreach ($this->revisionMapper->findByRoomTask($roomTask->getId()) as $candidate) {
			if ($candidate->getId() === $revId) {
				$revision = $candidate;
				break;
			}
		}
		if ($revision === null) {
			throw new ConflictException($this->l10n->t('The selected occurrence is no longer valid'));
		}

		try {
			$occurrence = $this->occurrenceMapper->findByKey($roomTask->getId(), $revId, $ordinal);
			return ['occurrence' => $occurrence, 'revision' => $revision];
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			// Virtual key: must be agreed (fixed rules enumerate through the
			// frontier; interval rules materialize successors at completion).
			if ($revision->getCadenceMode() !== ScheduleRevision::CADENCE_FIXED) {
				throw new ConflictException($this->l10n->t('The selected occurrence is no longer valid'));
			}
			$frontierKey = $revision->getAgreedThrough()->format('Y-m-d');
			$lowerKey = $this->reconcile->fixedLowerBoundKey($revision);
			if ($frontierKey < $lowerKey) {
				throw new ConflictException($this->l10n->t('The selected occurrence is no longer valid'));
			}
			$entry = null;
			foreach ($this->calculator->enumerate($revision, $lowerKey, $frontierKey) as $candidate) {
				if ($candidate['ordinal'] === $ordinal) {
					$entry = $candidate;
					break;
				}
			}
			if ($entry === null) {
				throw new ConflictException($this->l10n->t('The selected occurrence is no longer valid'));
			}
			if (!$materialize) {
				return ['occurrence' => $this->rowFactory->virtualOccurrence($revision, $entry['ordinal'], $entry['date']), 'revision' => $revision];
			}
			$rows = $this->reconcile->materializeEntriesUnderLock($houseId, $roomTask->getId(), $revision, [$entry], $now);
			return ['occurrence' => $rows[0] ?? $this->occurrenceMapper->findByKey($roomTask->getId(), $revId, $ordinal), 'revision' => $revision];
		}
	}

	/**
	 * Resolve the shown target for completion UNDER LOCK (the completion
	 * aggregate holds the house-first transaction): parse and house-scope
	 * the public key, materialize a virtual agreed key at version 0
	 * (insert-or-get under the natural unique constraint), verify the
	 * expected version, the shown selection token and the canonical
	 * selection.
	 *
	 * @return array{occurrence: Occurrence, revision: ScheduleRevision, classification: string}
	 * @throws ConflictException stale version/token/selection (409)
	 */
	public function resolveForCompletionUnderLock(
		int $houseId,
		RoomTask $roomTask,
		string $occurrenceKey,
		int $expectedVersion,
		?string $selectionToken,
		\DateTime $now,
	): array {
		$resolved = $this->resolveAgreedKeyUnderLock($houseId, $roomTask, $occurrenceKey, $now, true);
		$occurrence = $resolved['occurrence'];
		$revision = $resolved['revision'];

		if ($occurrence->getCompletionId() !== null) {
			throw new ConflictException($this->l10n->t('This occurrence is already completed'));
		}
		if ($occurrence->getVersion() !== $expectedVersion) {
			throw new ConflictException($this->l10n->t('The task changed since it was displayed. Reload and try again.'));
		}

		// Canonical selection check: completing anything other than the
		// shown/canonical target must 409, never silently credit another
		// occurrence. The bounded selection builder is the SAME derivation
		// the read paths used to show the target (RF-072-WP1-10), so a
		// shown selection always re-resolves identically — including the
		// WP2 neutral-suspension inputs.
		$selection = $this->selection->selectionFor($roomTask, $now, $houseId);
		$voluntary = $occurrence->getKind() === Occurrence::KIND_VOLUNTARY;
		if ($selection === null
			|| ($selection['occurrence']->toPublicKey() !== $occurrence->toPublicKey() && !$voluntary)) {
			throw new ConflictException($this->l10n->t('The task changed since it was displayed. Reload and try again.'));
		}
		$classification = $voluntary ? 'voluntary' : $selection['classification'];

		// The resolved raw row is not projection-stamped: restore the same
		// effective pause inputs (suspension, agreed work appointment) the
		// read path stamped onto its block, so the token comparison below
		// is apples-to-apples.
		$workAppointment = $this->selection->stampProjection($houseId, $occurrence, $revision);

		// Selection token check (parent §3.4): the fingerprint must match
		// the server-computed token of the same target/classification — a
		// stale shown state conflicts instead of crediting another window.
		// WP2: the token fingerprints the effective pause inputs too, so a
		// pause change behind the client's back is a 409, never a
		// completion of neutralized work.
		if ($selectionToken !== null
			&& !hash_equals($this->selection->selectionToken($occurrence, $revision, $classification, $houseId, null, $workAppointment), $selectionToken)) {
			throw new ConflictException($this->l10n->t('The task changed since it was displayed. Reload and try again.'));
		}

		return ['occurrence' => $occurrence, 'revision' => $revision, 'classification' => $classification];
	}

	/**
	 * Apply one completion's timeline effects UNDER LOCK: fulfill the
	 * target, cover the latest older catch-up miss (current work covers,
	 * never fulfills, historical misses), anchor the interval successor in
	 * the saved local basis, apply end-of-life deactivation and refresh
	 * the derived `next_due` projection.
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
		$occurrence->setCompletionId($completionRowId);
		$occurrence->setVersion($occurrence->getVersion() + 1);
		$this->occurrenceMapper->update($occurrence);

		// C1d: current work covers the stale catch-up card (only an older
		// miss; the target itself is fulfilled by this completion).
		if ($classification === 'agreed') {
			$miss = $this->occurrenceMapper->findLatestMissedByRoomTask($roomTask->getId(), $now);
			// Planned-date comparison, never window instants (RF-072-WP1-03):
			// adjacent default (flex=0) windows share the boundary instant
			// (miss.windowEnd == target.windowStart), so a window comparison
			// would never cover the previous day's miss.
			if ($miss !== null
				&& $miss->toPublicKey() !== $occurrence->toPublicKey()
				&& $miss->getPlannedDate() < $occurrence->getPlannedDate()) {
				$miss->setCoveredByCompletionId($completionRowId);
				$miss->setVersion($miss->getVersion() + 1);
				$this->occurrenceMapper->update($miss);
			}
		}

		try {
			$current = $this->revisionMapper->findCurrentByRoomTask($roomTask->getId());
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			$current = $revision;
		}

		// Interval successor: anchored on the completion's local work date.
		// A 'once' rule owns exactly one occurrence — no successor exists.
		// A bounded rule stops at its active bound.
		$createSuccessor = false;
		if ($current->getCadenceMode() === ScheduleRevision::CADENCE_INTERVAL
			&& $current->getLifetime() !== Task::RECURRENCE_ONCE) {
			$successorKey = $this->calculator->nextIntervalDate($workDateKey, $current->getCadenceUnit(), $current->getCadenceInterval());
			$createSuccessor = $current->getLifetime() !== Task::RECURRENCE_BOUNDED
				|| $current->getActiveUntil() === null
				|| $successorKey <= $current->getActiveUntil()->format('Y-m-d');
		}
		if ($createSuccessor) {
			$this->deleteFutureUnfulfilledOfRevisionFrom($current, $successorKey);
			$maxOrdinal = $this->reconcile->maxOrdinalOfRevision($current->getId());
			$successor = $this->rowFactory->buildOccurrence($houseId, $roomTask->getId(), $current, $maxOrdinal + 1, $successorKey, $now);
			$successor->setSourceCompletionId($completionRowId);
			$successor = $this->occurrenceMapper->insertOrGet($successor);
			$this->reconcile->attachMembersUnderLock($current, $successor);
			$this->revisionMapper->advanceFrontier(
				$current,
				RecurrenceCalculator::dayKeyToDate($this->calendar->dayKey($successor->getDayStart(), new \DateTimeZone($current->getTimezone())))
			);
		}

		$this->reconcile->applyEndOfLifeUnderLock($roomTask, $current, $now);

		$roomTask->setLastCompleted($now);
		$this->reconcile->updateNextDueProjectionUnderLock($roomTask, $now);
	}

	/**
	 * Undo seam UNDER LOCK: release the occurrence (only if this
	 * completion is still its valid completion), restore catch-up
	 * coverage, re-derive open interval successors anchored on the undone
	 * completion (later valid work survives) and refresh projections.
	 */
	public function releaseCompletionUnderLock(
		int $houseId,
		Occurrence $occurrence,
		RoomTask $roomTask,
		CompletedTask $completion,
		\DateTime $now,
	): void {
		if ($occurrence->getCompletionId() === $completion->getId()) {
			$occurrence->setCompletionId(null);
			$occurrence->setVersion($occurrence->getVersion() + 1);
			$this->occurrenceMapper->update($occurrence);
		}

		// The catch-up card this completion covered returns.
		$covered = $this->findCoveredByCompletion($roomTask->getId(), $completion->getId());
		if ($covered !== null) {
			$covered->setCoveredByCompletionId(null);
			$covered->setVersion($covered->getVersion() + 1);
			$this->occurrenceMapper->update($covered);
		}

		try {
			$current = $this->revisionMapper->findCurrentByRoomTask($roomTask->getId());
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			$current = null;
		}

		if ($current !== null && $current->getCadenceMode() === ScheduleRevision::CADENCE_INTERVAL) {
			// Open (unfulfilled) successors anchored on the undone
			// completion are re-derived from the remaining history.
			// RF-072-WP1-06: the match spans ALL revisions of the room
			// task — a revision edit between completion and undo moves the
			// successor's revision, and a current-revision-only match left
			// TWO open interval pendings behind.
			// WP3 §3.4 provenance check: an explicit agreement
			// (amendment/catch-up/restart), a restart link, or surviving
			// occurrence-scoped checklist progress pins the row — the
			// re-derivation never deletes member/checklist evidence for a
			// convenient recurrence result.
			foreach ($this->occurrenceMapper->findByRoomTask($roomTask->getId()) as $row) {
				if ($row->getSourceCompletionId() === $completion->getId()
					&& $row->getCompletionId() === null
					&& $row->getCoveredByCompletionId() === null) {
					if ($this->hasExplicitProvenance($row)) {
						continue;
					}
					$this->occMemberMapper->deleteByOccurrenceIds([$row->getId()]);
					$this->occurrenceMapper->delete($row);
				}
			}
			$this->reconcile->ensureIntervalPendingUnderLock($houseId, $roomTask, $current, $now);
		}

		// A once/bounded deactivation caused by this completion reverts
		// when an unfulfilled scheduled obligation survives the undo —
		// including an expired one (the catch-up card returns).
		if (!$roomTask->getIsActive() && $this->hasOpenObligation($roomTask)) {
			$roomTask->setIsActive(true);
		}
		$roomTask->setLastCompleted($this->latestValidCompletionTime($roomTask->getId()));
		$this->reconcile->updateNextDueProjectionUnderLock($roomTask, $now);
	}

	/**
	 * The occurrence a ledger row was recorded against (undo target),
	 * resolved from the frozen timeline evidence.
	 */
	public function occurrenceOfCompletion(CompletedTask $completion): ?Occurrence {
		$revId = $completion->getScheduleRevId();
		$ordinal = $completion->getOccurrenceOrdinal();
		if ($revId === null || $ordinal === null) {
			return null;
		}
		try {
			return $this->occurrenceMapper->findByKey($completion->getRoomTaskId(), $revId, $ordinal);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return null;
		}
	}

	private function deleteFutureUnfulfilledOfRevisionFrom(ScheduleRevision $revision, string $successorKey): void {
		$this->occurrenceMapper->deleteFutureUnfulfilledOfRevision($revision, RecurrenceCalculator::dayKeyToDate(RecurrenceCalculator::dayBefore($successorKey)));
	}

	/**
	 * WP3 §3.4: explicit-agreement/progress provenance of an occurrence —
	 * an owner-recorded agreement (any kind), a restart link, or surviving
	 * occurrence-scoped checklist progress. Such rows are NEVER deleted by
	 * the undo re-derivation.
	 */
	private function hasExplicitProvenance(Occurrence $row): bool {
		if ($row->getRestartOfId() !== null) {
			return true;
		}
		if ($this->agreementMapper->hasAgreementFor((int)$row->getId())) {
			return true;
		}
		return $this->checklistProgressMapper->findByOccurrence(
			$row->getRoomTaskId(),
			$row->getScheduleRevId(),
			$row->getOrdinal()
		) !== [];
	}

	/**
	 * Any unfulfilled, uncovered scheduled obligation — open, future OR
	 * expired (the catch-up card). Undo reactivation uses this: reopening
	 * a past once-occurrence restores the obligation.
	 */
	private function hasOpenObligation(RoomTask $roomTask): bool {
		foreach ($this->occurrenceMapper->findByRoomTask($roomTask->getId()) as $row) {
			if ($row->getCompletionId() === null
				&& $row->getCoveredByCompletionId() === null
				&& $row->getKind() === Occurrence::KIND_SCHEDULED) {
				return true;
			}
		}
		return false;
	}

	private function findCoveredByCompletion(int $roomTaskId, int $completionId): ?Occurrence {
		foreach ($this->occurrenceMapper->findByRoomTask($roomTaskId) as $row) {
			if ($row->getCoveredByCompletionId() === $completionId) {
				return $row;
			}
		}
		return null;
	}

	private function latestValidCompletionTime(int $roomTaskId): ?\DateTime {
		$latest = $this->completedTaskMapper->findNewestRevertibleByRoomTask($roomTaskId);
		return $latest?->getCompletedAt();
	}
}
