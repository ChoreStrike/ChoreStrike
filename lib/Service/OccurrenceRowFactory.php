<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\ScheduleRevision;

/**
 * Builder of occurrence row drafts (CS-PLAN-072 WP1; parent
 * CS-PLAN-070 §3.1; RF-072-WP1-18 split of the former OccurrenceService
 * monolith).
 *
 * Pure construction — nothing here touches the database. The
 * materialization pass ({@see OccurrenceReconcileService}) and the
 * write-free read derivation ({@see OccurrenceSelectionService}) share
 * this builder, so the persisted and the virtual representation of one
 * occurrence key are identical (parent §3.1: persisted and virtual
 * forms must produce the same key and boundaries).
 */
class OccurrenceRowFactory {
	private CalendarService $calendar;

	public function __construct(CalendarService $calendar) {
		$this->calendar = $calendar;
	}

	/**
	 * Build (not insert) an occurrence row with resolved saved-zone
	 * boundaries. DST-correct: each boundary is resolved as a local
	 * wall-clock instant of its own date (23/25-hour days preserved).
	 */
	public function buildOccurrence(int $houseId, int $roomTaskId, ScheduleRevision $revision, int $ordinal, string $plannedKey, \DateTime $now): Occurrence {
		$occurrence = new Occurrence();
		$occurrence->setHouseId($houseId);
		$occurrence->setRoomTaskId($roomTaskId);
		$occurrence->setScheduleRevId($revision->getId());
		$occurrence->setOrdinal($ordinal);
		$occurrence->setKind($revision->getIsVoluntary() ? Occurrence::KIND_VOLUNTARY : Occurrence::KIND_SCHEDULED);
		$this->applyWindow($occurrence, $revision, $plannedKey);
		$occurrence->setEnergy($revision->getEnergy());
		$occurrence->setIsPublic($revision->getIsPublic());
		$occurrence->setVersion(0);
		$occurrence->setCreatedAt($now);
		return $occurrence;
	}

	/**
	 * Resolve the occurrence's saved-zone date fields and half-open UTC
	 * window boundaries for a planned local date.
	 */
	public function applyWindow(Occurrence $occurrence, ScheduleRevision $revision, string $plannedKey): void {
		$tz = new \DateTimeZone($revision->getTimezone());
		$window = $this->windowBounds($plannedKey, $revision->getEarlyWindowDays(), $revision->getLateWindowDays(), $tz);

		$occurrence->setPlannedDate(RecurrenceCalculator::dayKeyToDate($plannedKey));
		$occurrence->setWeekStart(RecurrenceCalculator::dayKeyToDate(RecurrenceCalculator::mondayOf($plannedKey)));
		$occurrence->setTimezone($revision->getTimezone());
		$occurrence->setDayStart($this->calendar->dayStartInstant($plannedKey, $tz));
		$occurrence->setWindowStart($window['start']);
		$occurrence->setWindowEnd($window['end']);
	}

	/**
	 * The half-open UTC window `[start, end)` of a planned local day with
	 * `$early`/`$late` flex days in the given zone.
	 *
	 * @return array{start: \DateTime, end: \DateTime}
	 */
	public function windowBounds(string $plannedKey, int $early, int $late, \DateTimeZone $tz): array {
		return [
			'start' => $this->calendar->dayStartInstant(RecurrenceCalculator::offsetDays($plannedKey, -max(0, $early)), $tz),
			'end' => $this->calendar->dayStartInstant(RecurrenceCalculator::offsetDays($plannedKey, max(0, $late) + 1), $tz),
		];
	}

	/**
	 * A read-only virtual twin of an agreed occurrence key: identical to
	 * the row materialization would insert, but never persisted (a GET
	 * writes nothing; materialization alone keeps version 0).
	 */
	public function virtualOccurrence(ScheduleRevision $revision, int $ordinal, string $plannedKey): Occurrence {
		return $this->buildOccurrence(0, $revision->getRoomTaskId(), $revision, $ordinal, $plannedKey, new \DateTime());
	}
}
