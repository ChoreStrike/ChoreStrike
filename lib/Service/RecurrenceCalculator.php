<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\DayKey;

/**
 * Pure recurrence date arithmetic (CS-PLAN-072 WP1, C1a/C1b; ADR-021).
 *
 * Works on LOCAL calendar dates — never elapsed 86,400-second arithmetic.
 * The fixed sequences it locates a day in:
 *
 *  - Fixed daily: anchor + k · interval calendar days.
 *  - Fixed weekly on named weekdays: cadence weeks anchored on the Monday
 *    of the anchor's ISO week (fortnightly = interval 2 from the CHOSEN
 *    first anchor, never odd/even ISO weeks); each cadence week holds the
 *    configured weekdays on/after the anchor.
 *  - Fixed monthly: the originally selected day, clamped to the month's
 *    last day when it does not exist (Jan 31 → Feb 28/29 → Mar 31; same
 *    for 29/30). The anchor day itself is never rewritten.
 *  - Interval rules are NOT fixed sequences: they advance from a real
 *    completion's local work date ({@see nextIntervalDate()}).
 *
 * The enumeration of a stored revision's sequence went with the stored
 * occurrences (CS-PLAN-091 WP-B7); windows are derived per cycle.
 */
class RecurrenceCalculator {
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
			case CycleRule::UNIT_MONTH:
				$total = ($year * 12 + ($month - 1)) + $interval;
				$newYear = intdiv($total, 12);
				$newMonth = ($total % 12) + 1;
				return sprintf('%04d-%02d-%02d', $newYear, $newMonth, min($day, self::daysInMonth($newYear, $newMonth)));
			case CycleRule::UNIT_WEEK:
				return self::offsetDays($anchorKey, $interval * 7);
			case CycleRule::UNIT_DAY:
			default:
				return self::offsetDays($anchorKey, $interval);
		}
	}

	/**
	 * The first rule day of a fixed rule on or after `$fromKey` (ADR-021
	 * decision 1), computed without walking the sequence from its anchor.
	 * The sequence is the one of the class description: day and whole-week
	 * steps from the anchor, named weekdays in cadence weeks counted from
	 * the anchor's Monday, and the fixed day of the month clamped to the
	 * month's last day. No rule day lies before the anchor or outside the
	 * rule's bounds.
	 *
	 * @param string $anchorKey The parity anchor (`Y-m-d`)
	 * @return string|null Null when the rule has no day left inside its bounds
	 */
	public function firstFixedDayOnOrAfter(CycleRule $rule, string $anchorKey, string $fromKey): ?string {
		$from = max($fromKey, $anchorKey, $rule->activeFrom ?? $anchorKey);
		if ($rule->unit === CycleRule::UNIT_MONTH) {
			$day = $this->monthDayOnOrAfter($anchorKey, $rule->interval, $rule->monthDay ?? (int)substr($anchorKey, 8, 2), $from);
		} elseif ($rule->unit === CycleRule::UNIT_WEEK && $rule->weekdays !== []) {
			$day = $this->weekdayOnOrAfter($anchorKey, $rule->interval, $rule->weekdays, $from);
		} else {
			$step = $rule->unit === CycleRule::UNIT_WEEK ? $rule->interval * 7 : $rule->interval;
			$day = self::offsetDays($anchorKey, intdiv(self::daysBetween($anchorKey, $from) + $step - 1, $step) * $step);
		}
		return $rule->activeUntil !== null && $day > $rule->activeUntil ? null : $day;
	}

	/**
	 * @param list<int> $weekdays ISO weekdays, ascending
	 */
	private function weekdayOnOrAfter(string $anchorKey, int $interval, array $weekdays, string $from): string {
		$firstMonday = self::mondayOf($anchorKey);
		$period = $interval * 7;
		$week = intdiv(self::daysBetween($firstMonday, $from), $period);
		foreach ([$week, $week + 1] as $cadenceWeek) {
			foreach ($weekdays as $weekday) {
				$day = self::offsetDays($firstMonday, $cadenceWeek * $period + $weekday - 1);
				if ($day >= $from) {
					return $day;
				}
			}
		}
		// Unreachable: the following cadence week always holds a day on or after $from.
		return self::offsetDays($firstMonday, ($week + 1) * $period + $weekdays[0] - 1);
	}

	private function monthDayOnOrAfter(string $anchorKey, int $interval, int $monthDay, string $from): string {
		$anchorMonth = (int)substr($anchorKey, 0, 4) * 12 + (int)substr($anchorKey, 5, 2) - 1;
		$fromMonth = (int)substr($from, 0, 4) * 12 + (int)substr($from, 5, 2) - 1;
		$slot = intdiv($fromMonth - $anchorMonth + $interval - 1, $interval);
		do {
			$total = $anchorMonth + $slot * $interval;
			$year = intdiv($total, 12);
			$month = $total % 12 + 1;
			$day = sprintf('%04d-%02d-%02d', $year, $month, min($monthDay, self::daysInMonth($year, $month)));
			$slot++;
		} while ($day < $from);
		return $day;
	}

	/** Whole calendar days from one local day key to a later one. */
	public static function daysBetween(string $fromKey, string $toKey): int {
		$utc = new \DateTimeZone('UTC');
		return intdiv((new \DateTimeImmutable($toKey, $utc))->getTimestamp() - (new \DateTimeImmutable($fromKey, $utc))->getTimestamp(), 86400);
	}

	/**
	 * Monday of the given local date's ISO week (`Y-m-d`).
	 */
	public static function mondayOf(string $dateKey): string {
		$dow = (int)\DateTime::createFromFormat('!Y-m-d', $dateKey)->format('N'); // 1=Mon..7=Sun
		return self::offsetDays($dateKey, -($dow - 1));
	}

	/**
	 * The local day key after the given one (`Y-m-d`); the one helper is
	 * {@see DayKey::after()}, which the mappers use too.
	 */
	public static function dayAfter(string $dayKey): string {
		return DayKey::after($dayKey);
	}

	/**
	 * Midnight `\DateTime` of a local `Y-m-d` day key (the `!` prefix
	 * resets the clock; the host timezone never leaks in).
	 */
	public static function dayKeyToDate(string $dayKey): \DateTime {
		return \DateTime::createFromFormat('!Y-m-d', $dayKey);
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
