<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\ScheduleRevision;
use OCA\ChoreStrike\Db\ScheduleRevisionMapper;

/**
 * Write-free selection and read derivation of the occurrence timeline
 * (CS-PLAN-072 WP1; parent CS-PLAN-070 §3.2 rule 3, §3.3; RF-072-WP1-18
 * split of the former OccurrenceService monolith).
 *
 * Derives the agreed occurrence entries (materialized rows plus the
 * virtual keys the frontier already covers — cron gaps never change the
 * answer), the canonical completion selection, the future projections
 * beyond the frontier, and the shared occurrence DTO block / selection
 * token. Merely reading a preview agrees nothing: no method here
 * writes.
 *
 * WP2 (CS-PLAN-073): the derivation stamps the effective pause inputs
 * (neutral suspension, agreed work appointment) onto every entry through
 * the lower {@see PauseProjectionService} — materialized and virtual rows
 * converge — and the selection token fingerprints them, so a
 * pause/consent change invalidates stale tokens for virtual targets too
 * (plan §3.5). A suspended obligation is neutral: it never blocks
 * selection and never appears as the catch-up target.
 */
class OccurrenceSelectionService {
	/**
	 * Bounded lookback of the selection derivation (RF-072-WP1-10): the
	 * read/selection path enumerates virtual agreed candidates only within
	 * the recent window; materialized backlog rows of any age still come
	 * from the indexed open-rows query.
	 */
	public const SELECTION_LOOKBACK_DAYS = 31;

	private ScheduleRevisionMapper $revisionMapper;
	private OccurrenceMapper $occurrenceMapper;
	private RecurrenceCalculator $calculator;
	private CalendarService $calendar;
	private OccurrenceRowFactory $rowFactory;
	private OccurrenceReconcileService $reconcile;
	private PauseProjectionService $pauseProjection;

	public function __construct(
		ScheduleRevisionMapper $revisionMapper,
		OccurrenceMapper $occurrenceMapper,
		RecurrenceCalculator $calculator,
		CalendarService $calendar,
		OccurrenceRowFactory $rowFactory,
		OccurrenceReconcileService $reconcile,
		PauseProjectionService $pauseProjection,
	) {
		$this->revisionMapper = $revisionMapper;
		$this->occurrenceMapper = $occurrenceMapper;
		$this->calculator = $calculator;
		$this->calendar = $calendar;
		$this->rowFactory = $rowFactory;
		$this->reconcile = $reconcile;
		$this->pauseProjection = $pauseProjection;
	}

	/**
	 * The canonical completion target of a room task (C1c/C1d): among
	 * agreed, unfulfilled, uncovered occurrences with an OPEN window
	 * ([start, end) contains now) choose the smallest window end, then
	 * the earliest planned date, then the canonical key. Without an open
	 * window select the latest missed occurrence (catch-up); never a
	 * future projection. Voluntary kinds never block scheduled selection.
	 *
	 * Bounded derivation (RF-072-WP1-10): batched indexed queries instead
	 * of a full-history derivation — selection cost no longer grows with
	 * schedule age. The same builder serves the write-side canonical
	 * selection check, so a shown read target always resolves.
	 *
	 * @return array{occurrence: Occurrence, revision: ScheduleRevision, classification: string}|null
	 */
	public function selectionFor(RoomTask $roomTask, \DateTime $now, int $houseId = 0): ?array {
		$entries = $this->selectionEntriesForTasks([$roomTask], $now, $houseId)[$roomTask->getId()] ?? [];
		return $this->selectFromAgreed($entries, $now);
	}

	/**
	 * Stamp the WP2 projection facts of ONE entry in memory (effective
	 * suspension) and return its derived work appointment — the
	 * write-free twin of the batched derivation, used by the completion
	 * re-validation so the token comparison sees the same material the
	 * read path stamped.
	 *
	 * @return array{date: ?string, zone: string}
	 */
	public function stampProjection(int $houseId, Occurrence $occurrence, ScheduleRevision $revision): array {
		$appointments = $this->pauseProjection->stampSuspensionOnEntries($houseId, [
			['occurrence' => $occurrence, 'revision' => $revision],
		]);
		return $appointments[$occurrence->toPublicKey()]
			?? PauseProjectionService::defaultAppointment($occurrence);
	}

	/**
	 * @param list<array{occurrence: Occurrence, revision: ScheduleRevision}> $agreed
	 */
	public function selectFromAgreed(array $agreed, \DateTime $now): ?array {
		$best = null;
		foreach ($agreed as $entry) {
			$occ = $entry['occurrence'];
			if ($occ->getCompletionId() !== null || $occ->getCoveredByCompletionId() !== null) {
				continue;
			}
			if ($occ->getKind() === Occurrence::KIND_VOLUNTARY) {
				continue;
			}
			// WP2: a neutrally suspended obligation has no open duty —
			// it neither wins the open-window selection nor the catch-up
			// target (plan §3.4; voluntary work stays recordable).
			if ($occ->getSuspensionState() === Occurrence::SUSPENSION_SUSPENDED) {
				continue;
			}
			$windowEnd = $occ->getWindowEnd();
			$windowStart = $occ->getWindowStart();
			if ($windowStart <= $now && $now < $windowEnd) {
				$candidate = ['entry' => $entry, 'mode' => 'open'];
				if ($best === null
					|| $windowEnd < $best['entry']['occurrence']->getWindowEnd()
					|| ($windowEnd == $best['entry']['occurrence']->getWindowEnd()
						&& $this->occurrenceSortKey($occ) < $this->occurrenceSortKey($best['entry']['occurrence']))) {
					$best = $candidate;
				}
			}
		}
		if ($best !== null) {
			return ['occurrence' => $best['entry']['occurrence'], 'revision' => $best['entry']['revision'], 'classification' => 'agreed', 'workAppointment' => $best['entry']['workAppointment'] ?? null];
		}

		// Catch-up: the latest missed, uncovered occurrence (late work).
		$latestMiss = null;
		foreach ($agreed as $entry) {
			$occ = $entry['occurrence'];
			if ($this->isMissed($occ, $now)
				&& ($latestMiss === null || $this->occurrenceSortKey($occ) > $this->occurrenceSortKey($latestMiss['occurrence']))) {
				$latestMiss = $entry;
			}
		}
		if ($latestMiss !== null) {
			return ['occurrence' => $latestMiss['occurrence'], 'revision' => $latestMiss['revision'], 'classification' => 'late', 'workAppointment' => $latestMiss['workAppointment'] ?? null];
		}

		// Voluntary actionable work stays recordable (server-selected kind);
		// a covered voluntary row is no longer recordable (RF-072-WP1-14).
		foreach ($agreed as $entry) {
			$occ = $entry['occurrence'];
			if ($occ->getCompletionId() === null
				&& $occ->getCoveredByCompletionId() === null
				&& $occ->getKind() === Occurrence::KIND_VOLUNTARY) {
				return ['occurrence' => $occ, 'revision' => $entry['revision'], 'classification' => 'voluntary', 'workAppointment' => $entry['workAppointment'] ?? null];
			}
		}

		return null;
	}

	/**
	 * Whether an agreed occurrence is missed work (`late`): a scheduled,
	 * unsuspended obligation that is neither fulfilled nor covered and whose
	 * saved window has ended. The catch-up selection picks the latest such
	 * occurrence; the calendar marks each one (CR-084-01).
	 */
	public function isMissed(Occurrence $occurrence, \DateTime $now): bool {
		return $occurrence->getCompletionId() === null
			&& $occurrence->getCoveredByCompletionId() === null
			&& $occurrence->getKind() !== Occurrence::KIND_VOLUNTARY
			&& $occurrence->getSuspensionState() !== Occurrence::SUSPENSION_SUSPENDED
			&& $occurrence->getWindowEnd() <= $now;
	}

	/**
	 * Bounded batched selection derivation (RF-072-WP1-10): the open
	 * (unfulfilled, uncovered) occurrence rows of any age from ONE indexed
	 * query — the live backlog, misses included — plus the virtual agreed
	 * candidates of the CURRENT fixed revision enumerated only within the
	 * recent lookback window and resolved against one bounded in-window
	 * row query (a fulfilled planned day never resurrects as a virtual
	 * twin). Closed revisions were fully materialized at close time, so
	 * virtual candidates exist only under the current revision.
	 *
	 * @param RoomTask[] $roomTasks
	 * @return array<int, list<array{occurrence: Occurrence, revision: ScheduleRevision}>> Map room task id => selection entries
	 */
	private function selectionEntriesForTasks(array $roomTasks, \DateTime $now, int $houseId = 0): array {
		if ($roomTasks === []) {
			return [];
		}
		$taskIds = array_map(static fn (RoomTask $roomTask): int => $roomTask->getId(), $roomTasks);

		// Revisions per task (ascending revision order): the current
		// (unclosed) revision drives the virtual candidates; every row
		// keeps its OWN revision.
		$currentByTask = [];
		$revisionsById = [];
		foreach ($this->revisionMapper->findByRoomTasks($taskIds) as $revision) {
			$revisionsById[$revision->getId()] = $revision;
			if ($revision->getClosedAt() === null) {
				$currentByTask[$revision->getRoomTaskId()] = $revision;
			}
		}

		// Bounded virtual candidates per current fixed revision.
		$candidatesByTask = [];
		$windowFrom = null;
		$windowTo = null;
		foreach ($currentByTask as $taskKey => $revision) {
			if ($revision->getCadenceMode() !== ScheduleRevision::CADENCE_FIXED) {
				continue;
			}
			$tz = new \DateTimeZone($revision->getTimezone());
			$todayKey = $this->calendar->dayKey($now, $tz);
			$frontierKey = $revision->getAgreedThrough()->format('Y-m-d');
			$lower = max(
				$this->reconcile->fixedLowerBoundKey($revision),
				RecurrenceCalculator::offsetDays($todayKey, -self::SELECTION_LOOKBACK_DAYS),
				RecurrenceCalculator::dayAfter($frontierKey)
			);
			if ($frontierKey < $lower) {
				continue;
			}
			$candidatesByTask[$taskKey] = $this->calculator->enumerate($revision, $lower, $frontierKey);
			$windowFrom = $windowFrom === null ? $lower : min($windowFrom, $lower);
			$windowTo = $windowTo === null ? $frontierKey : max($windowTo, $frontierKey);
		}

		// Rows: the open backlog (any age) plus one bounded in-window query
		// resolving candidate keys against persisted state.
		$entriesByTask = [];
		foreach ($this->occurrenceMapper->findOpenByRoomTasks($taskIds) as $row) {
			$revision = $revisionsById[$row->getScheduleRevId()] ?? null;
			if ($revision === null) {
				continue;
			}
			$entriesByTask[$row->getRoomTaskId()][$row->toPublicKey()] = [
				'occurrence' => $row,
				'revision' => $revision,
			];
		}
		if ($candidatesByTask !== []) {
			$inWindow = [];
			foreach ($this->occurrenceMapper->findByRoomTasksPlannedBetween($taskIds, $windowFrom, $windowTo) as $row) {
				$inWindow[$row->toPublicKey()] = $row;
			}
			foreach ($candidatesByTask as $taskKey => $candidates) {
				$revision = $currentByTask[$taskKey];
				foreach ($candidates as $candidate) {
					$key = $revision->getId() . ':' . $candidate['ordinal'];
					if (isset($entriesByTask[$taskKey][$key])) {
						continue;
					}
					$row = $inWindow[$key] ?? null;
					$entriesByTask[$taskKey][$key] = [
						'occurrence' => $row ?? $this->rowFactory->virtualOccurrence($revision, $candidate['ordinal'], $candidate['date']),
						'revision' => $revision,
					];
				}
			}
		}

		// Deterministic canonical order (revision, ordinal) — the voluntary
		// fallback and sort-key tie-breaks depend on it.
		$result = [];
		foreach ($entriesByTask as $taskKey => $entries) {
			usort($entries, static fn (array $a, array $b): int
				=> [$a['occurrence']->getScheduleRevId(), $a['occurrence']->getOrdinal()]
				<=> [$b['occurrence']->getScheduleRevId(), $b['occurrence']->getOrdinal()]);
			$result[$taskKey] = array_values($entries);
		}

		// WP2: stamp the effective pause inputs (suspension, agreed work
		// appointment) on every entry — persisted and virtual rows converge
		// (write-free; a stopped cron never changes the read answer).
		if ($houseId > 0) {
			$flat = [];
			foreach ($result as $entries) {
				array_push($flat, ...$entries);
			}
			$appointments = $this->pauseProjection->stampSuspensionOnEntries($houseId, $flat);
			foreach ($result as $taskKey => $entries) {
				foreach ($entries as $index => $entry) {
					$result[$taskKey][$index]['workAppointment']
						= $appointments[$entry['occurrence']->toPublicKey()] ?? null;
				}
			}
		}

		return $result;
	}

	/**
	 * Batched house-wide variant of {@see agreedOccurrencesForTask} for
	 * the read paths (daily/overdue/calendar/derivation): two queries for
	 * the whole house instead of two per task. RF-072-WP1-10: a windowed
	 * call loads only the rows of the window plus the open backlog —
	 * never the house's entire occurrence history. The open rows also
	 * keep the interval pending guard correct outside the window.
	 *
	 * @param RoomTask[] $roomTasks Tasks to derive (subset of the house)
	 * @param string|null $fromKey Optional inclusive planned-date window
	 * @param string|null $toKey Optional inclusive planned-date window
	 * @return array<int, list<array{occurrence: Occurrence, revision: ScheduleRevision}>> Map room task id => agreed entries
	 */
	public function agreedOccurrencesForHouseTasks(int $houseId, array $roomTasks, ?string $fromKey, ?string $toKey): array {
		$taskIds = array_map(static fn (RoomTask $roomTask): int => $roomTask->getId(), $roomTasks);
		if ($taskIds === []) {
			return [];
		}

		$revisionsByTask = [];
		foreach ($this->revisionMapper->findByHouseId($houseId) as $revision) {
			$revisionsByTask[$revision->getRoomTaskId()][] = $revision;
		}
		$rowsByKey = [];
		if ($fromKey !== null && $toKey !== null) {
			foreach ($this->occurrenceMapper->findByRoomTasksPlannedBetween($taskIds, $fromKey, $toKey) as $row) {
				$rowsByKey[$row->getScheduleRevId() . ':' . $row->getOrdinal()] = $row;
			}
			foreach ($this->occurrenceMapper->findOpenByRoomTasks($taskIds) as $row) {
				$rowsByKey[$row->getScheduleRevId() . ':' . $row->getOrdinal()] = $row;
			}
		} else {
			foreach ($this->occurrenceMapper->findByHouseId($houseId) as $row) {
				$rowsByKey[$row->getScheduleRevId() . ':' . $row->getOrdinal()] = $row;
			}
		}

		$result = [];
		foreach ($roomTasks as $roomTask) {
			$result[$roomTask->getId()] = $this->deriveAgreedEntries(
				$revisionsByTask[$roomTask->getId()] ?? [],
				$rowsByKey,
				$roomTask->getId(),
				$fromKey,
				$toKey
			);
		}

		// WP2: stamp the effective pause inputs on the windowed entries
		// (write-free derivation; materialized and virtual rows agree).
		$flat = [];
		foreach ($result as $entries) {
			array_push($flat, ...$entries);
		}
		$appointments = $this->pauseProjection->stampSuspensionOnEntries($houseId, $flat);
		foreach ($result as $taskKey => $entries) {
			foreach ($entries as $index => $entry) {
				$result[$taskKey][$index]['workAppointment']
					= $appointments[$entry['occurrence']->toPublicKey()] ?? null;
			}
		}

		return $result;
	}

	/**
	 * Batched canonical selection per room task (write-free DTO
	 * enrichment for list/detail/daily/overdue reads). RF-072-WP1-10:
	 * bounded derivation — no full-history load, no anchor enumeration.
	 *
	 * @param RoomTask[] $roomTasks
	 * @return array<int, array{occurrence: Occurrence, revision: ScheduleRevision, classification: string}|null>
	 */
	public function selectionsForHouseTasks(int $houseId, array $roomTasks, \DateTime $now): array {
		$entriesByTask = $this->selectionEntriesForTasks($roomTasks, $now, $houseId);
		$selections = [];
		foreach ($roomTasks as $roomTask) {
			$selections[$roomTask->getId()] = $this->selectFromAgreed($entriesByTask[$roomTask->getId()] ?? [], $now);
		}
		return $selections;
	}

	/**
	 * The occurrence DTO block of one entry (shared by every read path):
	 * public key, version, classification, planned/work dates and the
	 * saved window/zone plus the WP2 neutral-suspension facts and the
	 * agreed work appointment (plan §3.5: exact capacity/agreement
	 * exposure on the daily/detail/range/overdue reads).
	 *
	 * @param array{date: ?string, zone: string}|null $workAppointment The derived work appointment of the stamped entry
	 * @return array<string, mixed>
	 */
	public function occurrenceBlock(Occurrence $occurrence, ScheduleRevision $revision, string $classification, int $houseId = 0, ?string $pauseFingerprint = null, ?array $workAppointment = null): array {
		$appointment = $workAppointment;
		$suspension = $occurrence->suspensionFacts();
		return [
			'occurrenceId' => $occurrence->toPublicKey(),
			'expectedVersion' => $occurrence->getVersion(),
			'selectionToken' => $this->selectionToken($occurrence, $revision, $classification, $houseId, $pauseFingerprint, $appointment),
			'classification' => $classification,
			'kind' => $occurrence->getKind(),
			'plannedDate' => $occurrence->getPlannedDate()?->format('Y-m-d'),
			'workDate' => $appointment['date'] ?? $occurrence->getPlannedDate()?->format('Y-m-d'),
			'workZone' => $appointment['zone'] ?? $occurrence->getTimezone(),
			'suspended' => $suspension['suspended'],
			'suspensionEvidence' => $suspension['evidence'],
			'windowStart' => $occurrence->getWindowStart()?->format('c'),
			'windowEnd' => $occurrence->getWindowEnd()?->format('c'),
			'timezone' => $occurrence->getTimezone(),
			'fulfilled' => $occurrence->getCompletionId() !== null,
			'covered' => $occurrence->getCoveredByCompletionId() !== null,
			'energy' => $occurrence->getEnergy(),
		];
	}

	/**
	 * @return list<array{occurrence: Occurrence, revision: ScheduleRevision}>
	 */
	private function deriveAgreedEntries(array $revisions, array $rowsByKey, int $roomTaskId, ?string $fromKey, ?string $toKey): array {
		$agreed = [];
		foreach ($revisions as $index => $revision) {
			$frontierKey = $revision->getAgreedThrough()->format('Y-m-d');
			$anchorKey = $revision->getAnchorDate()->format('Y-m-d');

			if ($revision->getCadenceMode() === ScheduleRevision::CADENCE_FIXED) {
				// A revision owns only dates beyond its predecessor's frozen
				// frontier (RF-072-WP1-02): a keep-anchor revision keeps the
				// original anchor for ordinal stability but must never
				// re-derive the closed predecessor's agreed history as
				// completable virtual twins.
				$lower = $anchorKey;
				if ($index > 0) {
					$lower = max($lower, RecurrenceCalculator::dayAfter($revisions[$index - 1]->getAgreedThrough()->format('Y-m-d')));
				}
				$upper = $frontierKey;
				if ($toKey !== null) {
					$upper = min($upper, $toKey);
				}
				if ($upper < $lower) {
					continue;
				}
				$from = $fromKey !== null ? max($fromKey, $lower) : $lower;
				foreach ($this->calculator->enumerate($revision, $from, $upper) as $entry) {
					$key = $revision->getId() . ':' . $entry['ordinal'];
					$agreed[] = [
						'occurrence' => $rowsByKey[$key] ?? $this->rowFactory->virtualOccurrence($revision, $entry['ordinal'], $entry['date']),
						'revision' => $revision,
					];
				}
				continue;
			}

			// Interval: rows are authoritative; add a derived pending when
			// NO row of the revision exists (defensive — completion inserts
			// successors atomically). A participant amendment appends a new
			// interval revision while the OLD revision's unfulfilled pending
			// survives as the task's sole open obligation (reviseUnderLock
			// never duplicates it); fabricating a virtual pending for the
			// row-less new revision would double-count that survivor in the
			// calendar/range/selection reads, so the defensive virtual is
			// only derived when NO open occurrence row survives anywhere for
			// this room task.
			$revisionRows = [];
			foreach ($rowsByKey as $key => $row) {
				if ($row->getScheduleRevId() === $revision->getId() && $row->getRoomTaskId() === $roomTaskId) {
					$revisionRows[$key] = $row;
				}
			}
			if ($revisionRows !== []) {
				foreach ($revisionRows as $row) {
					$plannedKey = $row->getPlannedDate()->format('Y-m-d');
					if (($fromKey === null || $plannedKey >= $fromKey) && ($toKey === null || $plannedKey <= $toKey)) {
						$agreed[] = ['occurrence' => $row, 'revision' => $revision];
					}
				}
			} else {
				$hasOpenRow = false;
				foreach ($rowsByKey as $row) {
					if ($row->getRoomTaskId() === $roomTaskId
						&& $row->getCompletionId() === null
						&& $row->getCoveredByCompletionId() === null) {
						$hasOpenRow = true;
						break;
					}
				}
				if (!$hasOpenRow) {
					$planned = $this->reconcile->derivedIntervalPendingDate($roomTaskId, $revision, $anchorKey);
					if ($planned !== null && ($fromKey === null || $planned >= $fromKey) && ($toKey === null || $planned <= $toKey)) {
						$agreed[] = [
							'occurrence' => $this->rowFactory->virtualOccurrence($revision, 1, $planned),
							'revision' => $revision,
						];
					}
				}
			}
		}
		return $agreed;
	}

	/**
	 * All AGREED occurrences of a room task (materialized rows plus the
	 * virtual keys the frontier already covers — cron gaps never change the
	 * answer), optionally filtered to a planned-date window. Write-free.
	 *
	 * @return list<array{occurrence: Occurrence, revision: ScheduleRevision}> Ascending by (revision id, ordinal)
	 */
	public function agreedOccurrencesForTask(RoomTask $roomTask, ?string $fromKey, ?string $toKey): array {
		$revisions = $this->revisionMapper->findByRoomTask($roomTask->getId());
		if ($revisions === []) {
			return [];
		}
		$rowsByKey = [];
		foreach ($this->occurrenceMapper->findByRoomTask($roomTask->getId()) as $row) {
			$rowsByKey[$row->getScheduleRevId() . ':' . $row->getOrdinal()] = $row;
		}

		return $this->deriveAgreedEntries($revisions, $rowsByKey, $roomTask->getId(), $fromKey, $toKey);
	}

	/**
	 * FUTURE projection dates of a room task beyond the frontier
	 * (calendar reads; classification 'projection', no agreement).
	 *
	 * @return list<array{date: string, key: string}> Ascending by date
	 */
	public function projectionsForTask(RoomTask $roomTask, string $fromKey, string $toKey): array {
		try {
			$revision = $this->revisionMapper->findCurrentByRoomTask($roomTask->getId());
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return [];
		}
		if ($revision->getCadenceMode() !== ScheduleRevision::CADENCE_FIXED) {
			return [];
		}

		$frontierKey = $revision->getAgreedThrough()->format('Y-m-d');
		$anchorKey = $revision->getAnchorDate()->format('Y-m-d');
		$from = max($fromKey, $anchorKey, RecurrenceCalculator::dayAfter($frontierKey));
		if ($from > $toKey) {
			return [];
		}

		$projections = [];
		foreach ($this->calculator->enumerate($revision, $from, $toKey) as $entry) {
			$projections[] = ['date' => $entry['date'], 'key' => $revision->getId() . ':' . $entry['ordinal']];
		}
		return $projections;
	}

	/**
	 * Deterministic selection token (parent §3.4): fingerprints the shown
	 * target identity, version, classification, saved window, the
	 * relevant rule/participant inputs, the effective pause inputs and
	 * the house pause-revision fingerprint — never clock seconds. A
	 * window boundary, a changed candidate or a pause/consent change
	 * invalidates an old token (plan §3.5: a row version bump alone
	 * cannot cover a read-only virtual target).
	 */
	public function selectionToken(Occurrence $occurrence, ScheduleRevision $revision, string $classification, int $houseId = 0, ?string $pauseFingerprint = null, ?array $workAppointment = null): string {
		$fingerprint = $pauseFingerprint
			?? ($houseId > 0 ? $this->pauseProjection->housePauseFingerprint($houseId) : '');
		$material = [
			'key' => $occurrence->toPublicKey(),
			'version' => $occurrence->getVersion(),
			'classification' => $classification,
			'plannedDate' => $occurrence->getPlannedDate()?->format('Y-m-d'),
			'windowStart' => $occurrence->getWindowStart()?->format('c'),
			'windowEnd' => $occurrence->getWindowEnd()?->format('c'),
			'revision' => $revision->getRevision(),
			'members' => $revision->getDefaultMembers(),
			'energy' => $occurrence->getEnergy(),
			'suspension' => $occurrence->getSuspensionState(),
			'workAppointment' => $workAppointment,
			'pauseFingerprint' => $fingerprint,
		];
		// NOTE: the revision frontier is deliberately NOT fingerprinted —
		// completing a virtual agreed key MATERIALIZES it and advances the
		// frontier, so a frontier-bound token would always self-conflict
		// at the materialization boundary. Frontier drift is covered by
		// the occurrence version/staleness gates.
		$json = (string)json_encode($material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return sha1($json);
	}

	private function occurrenceSortKey(Occurrence $occurrence): string {
		return $occurrence->getWindowEnd()->format('c') . '|' . $occurrence->getPlannedDate()?->format('Y-m-d') . '|' . $occurrence->toPublicKey();
	}
}
