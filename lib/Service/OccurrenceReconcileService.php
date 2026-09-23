<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\OccurrenceMember;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\ScheduleRevision;
use OCA\ChoreStrike\Db\ScheduleRevisionMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ConflictException;
use OCP\IL10N;

/**
 * Frontier, reconciliation and materialization authority of the
 * occurrence timeline (CS-PLAN-072 WP1; parent CS-PLAN-070 §3.2;
 * RF-072-WP1-18 split of the former OccurrenceService monolith).
 *
 * Advances the agreement frontier to the horizon, materializes agreed
 * occurrence rows (version 0, members from the revision snapshot),
 * guarantees the interval pending, applies end-of-life deactivation and
 * refreshes the derived `next_due` read projection.
 * {@see RecurrenceCalculator} provides the pure enumeration;
 * {@see OccurrenceRowFactory} builds the rows.
 *
 * Transaction convention: the transaction-owning entry points
 * ({@see reconcileRoomTask()}, {@see reconcileHouseForJobs()}) open
 * their own house-first transaction (house row lock first); methods
 * suffixed `UnderLock` run inside a CALLER-owned open transaction and
 * never begin one. Natural (revision, ordinal) uniqueness plus frontier
 * monotonicity make every pass idempotent — no fabricated member actor
 * is ever needed.
 */
class OccurrenceReconcileService {
	/** Agreement horizon: planned local days covered by the frontier. */
	public const HORIZON_DAYS = 7;

	/** Cursor batch size of the timeline job pass. */
	public const JOB_BATCH_SIZE = 50;

	/**
	 * Per-pass materialization budget of the job reconciliation
	 * (RF-072-WP1-08): a large historical gap materializes in bounded
	 * passes — the frontier advances only through what the pass actually
	 * reconciled and converges over subsequent runs.
	 */
	public const JOB_MATERIALIZE_BUDGET = 250;

	private TransactionExecutor $transactions;
	private ScheduleRevisionMapper $revisionMapper;
	private OccurrenceMapper $occurrenceMapper;
	private OccurrenceMemberMapper $occMemberMapper;
	private RoomTaskMapper $roomTaskMapper;
	private HouseMapper $houseMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private RecurrenceCalculator $calculator;
	private CalendarService $calendar;
	private IL10N $l10n;
	private AppLogger $appLogger;
	private OccurrenceRowFactory $rowFactory;

	public function __construct(
		TransactionExecutor $transactions,
		ScheduleRevisionMapper $revisionMapper,
		OccurrenceMapper $occurrenceMapper,
		OccurrenceMemberMapper $occMemberMapper,
		RoomTaskMapper $roomTaskMapper,
		HouseMapper $houseMapper,
		CompletedTaskMapper $completedTaskMapper,
		RecurrenceCalculator $calculator,
		CalendarService $calendar,
		IL10N $l10n,
		AppLogger $appLogger,
		OccurrenceRowFactory $rowFactory,
	) {
		$this->transactions = $transactions;
		$this->revisionMapper = $revisionMapper;
		$this->occurrenceMapper = $occurrenceMapper;
		$this->occMemberMapper = $occMemberMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->houseMapper = $houseMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->calculator = $calculator;
		$this->calendar = $calendar;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
		$this->rowFactory = $rowFactory;
	}

	/**
	 * Advance one room task's current revision to the agreement horizon,
	 * materializing missing occurrences (version 0). Own house-first
	 * transaction. Idempotent; returns the materialized-row count.
	 */
	public function reconcileRoomTask(int $houseId, int $roomTaskId, ?\DateTime $now = null): int {
		$now ??= new \DateTime();
		return $this->transactions->run(function () use ($houseId, $roomTaskId, $now): int {
			$this->houseMapper->findForUpdate($houseId);
			$roomTask = $this->roomTaskMapper->lockForUpdate($roomTaskId);
			if (!$roomTask->getIsActive() || $roomTask->getDeletedAt() !== null) {
				return 0;
			}
			return $this->reconcileUnderLock($houseId, $roomTask, $now);
		});
	}

	/**
	 * Reconcile one room task UNDER LOCK (caller owns the house-first
	 * transaction). Fixed rules extend to the saved-zone horizon (clamped
	 * by the active bounds); interval rules only guarantee that exactly
	 * one pending occurrence exists. Also applies end-of-life
	 * deactivation when a bounded/once rule can produce no further
	 * obligation.
	 */
	public function reconcileUnderLock(int $houseId, RoomTask $roomTask, \DateTime $now): int {
		try {
			$revision = $this->revisionMapper->findCurrentByRoomTask($roomTask->getId());
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return 0;
		}

		$materialized = 0;
		if ($revision->getCadenceMode() === ScheduleRevision::CADENCE_INTERVAL) {
			$materialized += $this->ensureIntervalPendingUnderLock($houseId, $roomTask, $revision, $now);
		} else {
			$horizonKey = $this->horizonKey($now, new \DateTimeZone($revision->getTimezone()));
			$targetKey = $horizonKey;
			if ($revision->getActiveUntil() !== null) {
				$targetKey = min($targetKey, $revision->getActiveUntil()->format('Y-m-d'));
			}
			$frontierKey = $revision->getAgreedThrough()->format('Y-m-d');
			if ($targetKey > $frontierKey) {
				// Enumeration lower bound (RF-072-WP1-02/-08): the revision
				// owns only dates beyond its predecessor's frozen frontier,
				// and every date up to its own agreed frontier already has
				// a row (materialization and frontier advance share one
				// transaction) — so a job pass enumerates and materializes
				// ONLY the missing gap, bounded per pass.
				$lowerKey = max($this->fixedLowerBoundKey($revision), RecurrenceCalculator::dayAfter($frontierKey));
				$entries = $this->calculator->enumerate($revision, $lowerKey, $targetKey);
				$budgeted = array_slice($entries, 0, self::JOB_MATERIALIZE_BUDGET);
				$materialized += count($this->materializeEntriesUnderLock($houseId, $roomTask->getId(), $revision, $budgeted, $now));
				// The frontier advances only through what this pass actually
				// reconciled; a truncated pass converges over subsequent runs.
				$throughKey = $budgeted === [] ? $targetKey : end($budgeted)['date'];
				$this->revisionMapper->advanceFrontier($revision, RecurrenceCalculator::dayKeyToDate($throughKey));
			}
		}

		$this->applyEndOfLifeUnderLock($roomTask, $revision, $now);
		$this->updateNextDueProjectionUnderLock($roomTask, $now);
		return $materialized;
	}

	/**
	 * Reconcile one room task only through a bounded frontier (edit
	 * preamble: elapsed agreements under the OLD immutable inputs).
	 * Mutation-time reconciliation must be complete through its edit
	 * instant — a gap beyond the enforced generation cap fails atomically
	 * with an explicit error instead of publishing a partially revised
	 * rule (parent §3.2 rule 5; RF-072-WP1-08).
	 */
	public function reconcileThroughUnderLock(int $houseId, RoomTask $roomTask, string $throughKey, \DateTime $now): int {
		try {
			$revision = $this->revisionMapper->findCurrentByRoomTask($roomTask->getId());
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return 0;
		}
		if ($revision->getCadenceMode() === ScheduleRevision::CADENCE_INTERVAL) {
			return 0;
		}

		$frontierKey = $revision->getAgreedThrough()->format('Y-m-d');
		$targetKey = $throughKey;
		if ($revision->getActiveUntil() !== null) {
			$targetKey = min($targetKey, $revision->getActiveUntil()->format('Y-m-d'));
		}
		if ($targetKey <= $frontierKey) {
			return 0;
		}

		$lowerKey = max($this->fixedLowerBoundKey($revision), RecurrenceCalculator::dayAfter($frontierKey));
		$entries = $this->calculator->enumerate($revision, $lowerKey, $targetKey);
		if (count($entries) > OccurrenceMapper::MAX_GENERATE_OCCURRENCES) {
			throw new ConflictException($this->l10n->t('The task has too many unreconciled occurrences to be edited safely. Run the timeline job first.'));
		}
		$materialized = count($this->materializeEntriesUnderLock($houseId, $roomTask->getId(), $revision, $entries, $now));
		$this->revisionMapper->advanceFrontier($revision, RecurrenceCalculator::dayKeyToDate($targetKey));
		$this->updateNextDueProjectionUnderLock($roomTask, $now);
		return $materialized;
	}

	/**
	 * Inclusive enumeration lower bound of a FIXED revision
	 * (RF-072-WP1-02): its anchor, or the day after its predecessor's
	 * frozen frontier when later. A keep-anchor revision keeps the original
	 * anchor for ordinal stability but owns only dates beyond the closed
	 * predecessor's agreed range — re-deriving the predecessor's history
	 * would duplicate every agreed occurrence (including fulfilled ones)
	 * as completable twins under the new revision key.
	 */
	public function fixedLowerBoundKey(ScheduleRevision $revision): string {
		$anchorKey = $revision->getAnchorDate()->format('Y-m-d');
		$predecessor = $this->revisionMapper->findPredecessor($revision->getRoomTaskId(), $revision->getRevision());
		if ($predecessor === null) {
			return $anchorKey;
		}
		return max($anchorKey, RecurrenceCalculator::dayAfter($predecessor->getAgreedThrough()->format('Y-m-d')));
	}

	/**
	 * The timeline job pass over the instance house (CS-PLAN-072 WP1;
	 * parent §3.3 rule 2): advance every active room task's current
	 * revision to the agreement horizon. Each task reconciles inside its
	 * own house-first transaction (bounded lock hold); natural
	 * revision/ordinal uniqueness plus frontier monotonicity make repeated
	 * or interrupted runs converge. A GET never calls this — a timeline
	 * job, not a read, extends the horizon. No awards, credits or
	 * notifications are minted here (WP4 adds the rhythm consumer).
	 *
	 * @return array{houses: int, tasks: int, materialized: int}
	 */
	public function reconcileHouseForJobs(?\DateTime $now = null): array {
		$now ??= new \DateTime();
		$house = $this->houseMapper->findOneForJobs();
		if ($house === null) {
			return ['houses' => 0, 'tasks' => 0, 'materialized' => 0];
		}

		$stats = ['houses' => 1, 'tasks' => 0, 'materialized' => 0];
		$cursor = 0;
		while (true) {
			$batch = $this->roomTaskMapper->findActiveIdBatchAfter($house->getId(), $cursor, self::JOB_BATCH_SIZE);
			if ($batch === []) {
				break;
			}
			foreach ($batch as $roomTaskId) {
				$cursor = $roomTaskId;
				try {
					$materialized = $this->reconcileRoomTask((int)$house->getId(), $roomTaskId, $now);
				} catch (\Throwable $e) {
					// One task's failure must not block the house pass;
					// the next run re-attempts it from the same frontier.
					$this->appLogger->logException('TimelineJob: room task reconciliation failed', $e, [
						'event' => 'timeline_reconcile_failed',
						'service' => 'OccurrenceReconcileService',
						'houseId' => $house->getId(),
						'roomTaskId' => $roomTaskId,
					]);
					continue;
				}
				$stats['tasks']++;
				$stats['materialized'] += $materialized;
			}
		}
		return $stats;
	}

	/**
	 * Insert missing occurrence rows for the enumerated entries (version
	 * 0; members from the revision snapshot) UNDER LOCK.
	 *
	 * @param list<array{ordinal: int, date: string}> $entries
	 * @return Occurrence[] The rows for every entry (existing or inserted)
	 */
	public function materializeEntriesUnderLock(int $houseId, int $roomTaskId, ScheduleRevision $revision, array $entries, \DateTime $now): array {
		$results = [];
		$existingByKey = [];
		foreach ($this->occurrenceMapper->findByRevision($revision->getId()) as $row) {
			$existingByKey[$row->getOrdinal()] = $row;
		}
		foreach ($entries as $entry) {
			$row = $existingByKey[$entry['ordinal']] ?? null;
			if ($row === null) {
				$row = $this->rowFactory->buildOccurrence($houseId, $roomTaskId, $revision, $entry['ordinal'], $entry['date'], $now);
				$row = $this->occurrenceMapper->insertOrGet($row);
				$this->attachMembersUnderLock($revision, $row);
			}
			$results[] = $row;
		}
		return $results;
	}

	/**
	 * Attach the revision's default member snapshot to an occurrence row.
	 */
	public function attachMembersUnderLock(ScheduleRevision $revision, Occurrence $occurrence): void {
		$members = $revision->getDefaultMembers();
		if (!is_array($members)) {
			return;
		}
		foreach ($members as $member) {
			if (!is_array($member) || !isset($member['walletId'])) {
				continue;
			}
			$this->occMemberMapper->addMember(
				$occurrence->getId(),
				(int)$member['walletId'],
				(string)($member['role'] ?? OccurrenceMember::ROLE_RESPONSIBLE),
				(bool)($member['obligated'] ?? true),
				(int)($member['sharePct'] ?? 100),
			);
		}
	}

	/**
	 * Guarantee exactly one pending occurrence for the interval authority
	 * of a room task UNDER LOCK (creation/reconcile/undo paths; completion
	 * normally inserts the successor itself). The pending check spans ALL
	 * revisions of the room task — an unfulfilled occurrence of a closed
	 * revision remains THE next obligation until completed.
	 */
	public function ensureIntervalPendingUnderLock(int $houseId, RoomTask $roomTask, ScheduleRevision $revision, \DateTime $now): int {
		$open = array_values(array_filter(
			$this->occurrenceMapper->findByRoomTask($roomTask->getId()),
			static fn (Occurrence $row): bool => $row->getCompletionId() === null && $row->getCoveredByCompletionId() === null
		));
		if ($open !== []) {
			return 0;
		}

		$anchorKey = $revision->getAnchorDate()->format('Y-m-d');
		$plannedKey = $this->derivedIntervalPendingDate($roomTask->getId(), $revision, $anchorKey);
		if ($plannedKey === null) {
			return 0;
		}

		$ordinal = $this->maxOrdinalOfRevision($revision->getId()) + 1;
		$pending = $this->rowFactory->buildOccurrence($houseId, $roomTask->getId(), $revision, $ordinal, $plannedKey, $now);
		$this->occurrenceMapper->insertOrGet($pending);
		$this->attachMembersUnderLock($revision, $pending);
		$this->revisionMapper->advanceFrontier($revision, RecurrenceCalculator::dayKeyToDate($plannedKey));
		return 1;
	}

	/**
	 * Derive the pending interval date from remaining valid history: the
	 * latest non-undone completion of this room task anchors the successor
	 * in its saved local basis; without a completion the revision anchor
	 * is the pending date. Never invents successive completions for
	 * missed cron runs.
	 */
	public function derivedIntervalPendingDate(int $roomTaskId, ScheduleRevision $revision, string $fallbackKey): ?string {
		$latest = $this->completedTaskMapper->findNewestRevertibleByRoomTask($roomTaskId);
		if ($latest !== null && $latest->getWorkDate() !== null) {
			return $this->calculator->nextIntervalDate(
				$latest->getWorkDate()->format('Y-m-d'),
				$revision->getCadenceUnit(),
				$revision->getCadenceInterval()
			);
		}
		if ($latest !== null && $latest->getOccurrenceDue() !== null) {
			return $this->calculator->nextIntervalDate(
				$latest->getOccurrenceDue()->format('Y-m-d'),
				$revision->getCadenceUnit(),
				$revision->getCadenceInterval()
			);
		}
		return $fallbackKey;
	}

	public function maxOrdinalOfRevision(int $revisionId): int {
		$max = 0;
		foreach ($this->occurrenceMapper->findByRevision($revisionId) as $row) {
			$max = max($max, $row->getOrdinal());
		}
		return $max;
	}

	/**
	 * End-of-life deactivation: a once rule whose occurrence is fulfilled,
	 * or a bounded/once rule with active bounds that can produce no
	 * further obligation, deactivates the room task (planning surface
	 * only — occurrence/completion evidence stays).
	 */
	public function applyEndOfLifeUnderLock(RoomTask $roomTask, ScheduleRevision $revision, \DateTime $now): void {
		if (!$roomTask->getIsActive()) {
			return;
		}
		if ($revision->getLifetime() === Task::RECURRENCE_ONCE) {
			$rows = $this->occurrenceMapper->findByRevision($revision->getId());
			$fulfilled = array_filter($rows, static fn (Occurrence $row): bool => $row->getCompletionId() !== null);
			if ($rows !== [] && count($fulfilled) === count($rows)) {
				$roomTask->setIsActive(false);
			}
			return;
		}
		if ($revision->getLifetime() === Task::RECURRENCE_BOUNDED && $revision->getActiveUntil() !== null) {
			$untilKey = $revision->getActiveUntil()->format('Y-m-d');
			$todayKey = $this->calendar->dayKey($now, new \DateTimeZone($revision->getTimezone()));
			if ($todayKey >= $untilKey && !$this->hasOpenOrFutureObligation($roomTask, $now)) {
				$roomTask->setIsActive(false);
			}
		}
	}

	private function hasOpenOrFutureObligation(RoomTask $roomTask, \DateTime $now): bool {
		foreach ($this->occurrenceMapper->findByRoomTask($roomTask->getId()) as $row) {
			if ($row->getCompletionId() === null
				&& $row->getCoveredByCompletionId() === null
				&& $row->getKind() === Occurrence::KIND_SCHEDULED
				&& ($row->getWindowEnd() > $now || $row->getWindowStart() > $now)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Refresh the derived `next_due`/`ideal_due_date` read projections
	 * from the occurrence authority (DTO consumers keep working; no
	 * second schedule remains behind them). RF-072-WP1-09: the projection
	 * follows ONE indexed row query (the earliest open scheduled
	 * occurrence), and a steady-state pass that computes the values the
	 * row already carries performs no write.
	 */
	public function updateNextDueProjectionUnderLock(RoomTask $roomTask, \DateTime $now): void {
		$next = $this->occurrenceMapper->findFirstOpenScheduledByRoomTask($roomTask->getId());
		$nextDue = $next !== null
			? \DateTime::createFromInterface($next->getDayStart())
			: \DateTime::createFromInterface($now);
		$idealDue = $next !== null ? \DateTime::createFromInterface($next->getDayStart()) : null;

		$currentDue = $roomTask->getNextDue();
		$currentIdeal = $roomTask->getIdealDueDate();
		$sameDue = ($currentDue?->getTimestamp() ?? -1) === ($nextDue?->getTimestamp() ?? -1);
		$sameIdeal = ($currentIdeal?->getTimestamp() ?? -1) === ($idealDue?->getTimestamp() ?? -1);
		if ($sameDue && $sameIdeal) {
			return;
		}

		$roomTask->setNextDue($nextDue);
		$roomTask->setIdealDueDate($idealDue);
		$this->roomTaskMapper->update($roomTask);
	}

	/**
	 * The agreement-horizon day key (`now + HORIZON_DAYS` in the given
	 * zone).
	 */
	public function horizonKey(\DateTime $now, \DateTimeZone $tz): string {
		return $this->calendar->dayKey((clone $now)->modify('+' . self::HORIZON_DAYS . ' days'), $tz);
	}
}
