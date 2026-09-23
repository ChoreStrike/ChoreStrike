<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\ScheduleRevision;
use OCA\ChoreStrike\Db\Task;

/**
 * Pure recurrence date enumeration (CS-PLAN-072 WP1; parent CS-PLAN-070
 * §3.3 rule 1, C1a/C1b).
 *
 * Enumerates a schedule revision's anchored sequence as LOCAL calendar
 * dates — never elapsed 86,400-second arithmetic. Rules:
 *
 *  - Fixed daily: anchor + k · interval calendar days.
 *  - Fixed weekly on named weekdays: cadence weeks anchored on the Monday
 *    of the anchor's ISO week (fortnightly = interval 2 from the CHOSEN
 *    first anchor, never odd/even ISO weeks); each cadence week emits the
 *    configured weekdays on/after the anchor.
 *  - Fixed monthly: the originally selected day, clamped to the month's
 *    last day when it does not exist (Jan 31 → Feb 28/29 → Mar 31; same
 *    for 29/30). The anchor day itself is never rewritten.
 *  - Interval rules are NOT fixed sequences: they own one pending
 *    occurrence at a time and advance from a real completion's local work
 *    date ({@see nextIntervalDate()}); missed cron runs never invent
 *    successive interval completions.
 *
 * Ordinals are positions in the revision's complete anchored sequence
 * (slot k = k+1 for day/month rules; running weekday count for weekly
 * rules), independent of any query window, active bounds or row
 * existence. Enumeration is bounded; non-advancing guards protect against
 * degenerate inputs.
 */
class RecurrenceCalculator {
	/** Hard iteration guard for one enumeration pass (~100 years daily). */
	private const MAX_SLOTS = 36525;

	private const WEEKDAY_ISO = [
		'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4,
		'friday' => 5, 'saturday' => 6, 'sunday' => 7,
	];

	/**
	 * Enumerate a fixed rule's occurrences with planned dates in
	 * [$fromKey, $toKey] (inclusive, `Y-m-d` local dates), also honoring
	 * the revision's inclusive active bounds. Interval revisions yield
	 * nothing here — their pending occurrence is derived from the latest
	 * completion by the caller.
	 *
	 * @param string $fromKey Inclusive `Y-m-d` window start
	 * @param string $toKey Inclusive `Y-m-d` window end
	 * @return list<array{ordinal: int, date: string}> Ascending by date
	 */
	public function enumerate(ScheduleRevision $revision, string $fromKey, string $toKey): array {
		if ($revision->getCadenceMode() !== ScheduleRevision::CADENCE_FIXED) {
			return [];
		}
		if ($fromKey > $toKey) {
			return [];
		}

		$anchor = $revision->getAnchorDate()->format('Y-m-d');
		if ($revision->getLifetime() === Task::RECURRENCE_ONCE) {
			return $this->inBounds($anchor, $revision) && $anchor >= $fromKey && $anchor <= $toKey
				? [['ordinal' => 1, 'date' => $anchor]]
				: [];
		}

		$interval = max(1, $revision->getCadenceInterval());
		if ($revision->getCadenceUnit() === ScheduleRevision::UNIT_MONTH) {
			return $this->enumerateMonthly($anchor, $interval, $revision, $fromKey, $toKey);
		}

		$weekdays = $revision->weekdayList();
		if ($revision->getCadenceUnit() === ScheduleRevision::UNIT_WEEK && $weekdays !== []) {
			return $this->enumerateWeeklyWeekdays($anchor, $interval, $weekdays, $revision, $fromKey, $toKey);
		}

		// Day unit; weekly-without-weekdays degenerates to whole-week steps
		// (the legacy static fallback cadence).
		$stepDays = $revision->getCadenceUnit() === ScheduleRevision::UNIT_WEEK
			? $interval * 7
			: $interval;
		return $this->enumerateDaily($anchor, $stepDays, $revision, $fromKey, $toKey);
	}

	/**
	 * The successor local date of a completion-relative interval rule:
	 * calendar-date arithmetic in the saved local basis (C1: local dates,
	 * never assumed 24-hour days — DST days resolve via the calendar
	 * service once the date becomes an instant).
	 *
	 * @param string $anchorKey Local work date of the anchoring completion
	 */
	public function nextIntervalDate(string $anchorKey, string $unit, int $interval): string {
		$interval = max(1, $interval);
		$year = (int)substr($anchorKey, 0, 4);
		$month = (int)substr($anchorKey, 5, 2);
		$day = (int)substr($anchorKey, 8, 2);

		switch ($unit) {
			case ScheduleRevision::UNIT_MONTH:
				$total = ($year * 12 + ($month - 1)) + $interval;
				$newYear = intdiv($total, 12);
				$newMonth = ($total % 12) + 1;
				return sprintf('%04d-%02d-%02d', $newYear, $newMonth, min($day, self::daysInMonth($newYear, $newMonth)));
			case ScheduleRevision::UNIT_WEEK:
				return self::offsetDays($anchorKey, $interval * 7);
			case ScheduleRevision::UNIT_DAY:
			default:
				return self::offsetDays($anchorKey, $interval);
		}
	}

	/**
	 * Monday of the given local date's ISO week (`Y-m-d`).
	 */
	public static function mondayOf(string $dateKey): string {
		$dow = (int)\DateTime::createFromFormat('!Y-m-d', $dateKey)->format('N'); // 1=Mon..7=Sun
		return self::offsetDays($dateKey, -($dow - 1));
	}

	/**
	 * The local day key after the given one (`Y-m-d`).
	 */
	public static function dayAfter(string $dayKey): string {
		return self::offsetDays($dayKey, 1);
	}

	/**
	 * The local day key before the given one (`Y-m-d`).
	 */
	public static function dayBefore(string $dayKey): string {
		return self::offsetDays($dayKey, -1);
	}

	/**
	 * Midnight `\DateTime` of a local `Y-m-d` day key (the `!` prefix
	 * resets the clock; the host timezone never leaks in).
	 */
	public static function dayKeyToDate(string $dayKey): \DateTime {
		return \DateTime::createFromFormat('!Y-m-d', $dayKey);
	}

	/**
	 * @return list<array{ordinal: int, date: string}>
	 */
	private function enumerateDaily(string $anchor, int $stepDays, ScheduleRevision $revision, string $fromKey, string $toKey): array {
		$results = [];
		$cursor = $anchor;
		$slot = 0;
		while ($slot < self::MAX_SLOTS) {
			if ($cursor > $toKey) {
				break;
			}
			if ($cursor >= $fromKey && $this->inBounds($cursor, $revision)) {
				$results[] = ['ordinal' => $slot + 1, 'date' => $cursor];
			}
			$next = self::offsetDays($cursor, $stepDays);
			if ($next <= $cursor) {
				break; // non-advancing guard
			}
			$cursor = $next;
			$slot++;
		}
		return $results;
	}

	/**
	 * @param list<string> $weekdays Lowercase weekday names
	 * @return list<array{ordinal: int, date: string}>
	 */
	private function enumerateWeeklyWeekdays(string $anchor, int $interval, array $weekdays, ScheduleRevision $revision, string $fromKey, string $toKey): array {
		$targets = [];
		foreach ($weekdays as $name) {
			if (isset(self::WEEKDAY_ISO[$name])) {
				$targets[self::WEEKDAY_ISO[$name]] = true;
			}
		}
		if ($targets === []) {
			return [];
		}
		$targetDays = array_keys($targets);
		sort($targetDays);

		$anchorDow = (int)\DateTime::createFromFormat('!Y-m-d', $anchor)->format('N');
		// Week 0 emits only weekdays on/after the anchor itself.
		$firstIdx = 0;
		while ($firstIdx < count($targetDays) && $targetDays[$firstIdx] < $anchorDow) {
			$firstIdx++;
		}

		$weekMonday = self::mondayOf($anchor);
		$results = [];
		$week = 0;
		$ordinal = 0;
		while ($week < self::MAX_SLOTS) {
			$emittedBeforeAnchorCut = $week === 0 ? $firstIdx : 0;
			for ($i = $emittedBeforeAnchorCut; $i < count($targetDays); $i++) {
				$date = self::offsetDays($weekMonday, $week * $interval * 7 + ($targetDays[$i] - 1));
				$ordinal++;
				if ($date > $toKey) {
					return $results;
				}
				if ($date >= $fromKey && $this->inBounds($date, $revision)) {
					$results[] = ['ordinal' => $ordinal, 'date' => $date];
				}
			}
			$nextMonday = self::offsetDays($weekMonday, ($week + 1) * $interval * 7);
			if ($nextMonday <= $weekMonday) {
				break; // non-advancing guard
			}
			if (self::offsetDays($nextMonday, -1) > $toKey) {
				break; // whole cadence week beyond the window
			}
			$week++;
		}
		return $results;
	}

	/**
	 * @return list<array{ordinal: int, date: string}>
	 */
	private function enumerateMonthly(string $anchor, int $interval, ScheduleRevision $revision, string $fromKey, string $toKey): array {
		$anchorDay = (int)substr($anchor, 8, 2);
		$year = (int)substr($anchor, 0, 4);
		$month = (int)substr($anchor, 5, 2);

		$results = [];
		$slot = 0;
		while ($slot < self::MAX_SLOTS) {
			$total = ($year * 12 + ($month - 1)) + $slot * $interval;
			$y = intdiv($total, 12);
			$m = ($total % 12) + 1;
			$date = sprintf('%04d-%02d-%02d', $y, $m, min($anchorDay, self::daysInMonth($y, $m)));

			if ($date > $toKey) {
				break;
			}
			if ($date >= $fromKey && $this->inBounds($date, $revision)) {
				$results[] = ['ordinal' => $slot + 1, 'date' => $date];
			}
			$slot++;
		}
		return $results;
	}

	/**
	 * Whether a local date is inside the revision's inclusive active bounds.
	 */
	private function inBounds(string $dateKey, ScheduleRevision $revision): bool {
		$from = $revision->getActiveFrom();
		$until = $revision->getActiveUntil();
		if ($from !== null && $dateKey < $from->format('Y-m-d')) {
			return false;
		}
		if ($until !== null && $dateKey > $until->format('Y-m-d')) {
			return false;
		}
		return true;
	}

	private static function daysInMonth(int $year, int $month): int {
		return (int)\DateTime::createFromFormat('!Y-m-d', sprintf('%04d-%02d-01', $year, $month))->format('t');
	}

	/**
	 * Offset a local day key by whole calendar days (pure `Y-m-d`
	 * arithmetic — DST-safe, never elapsed-time arithmetic). Shared by
	 * the timeline collaborators (RF-072-WP1-18).
	 */
	public static function offsetDays(string $dateKey, int $days): string {
		$date = \DateTime::createFromFormat('!Y-m-d', $dateKey);
		$date->modify(($days >= 0 ? '+' : '') . $days . ' days');
		return $date->format('Y-m-d');
	}
}
