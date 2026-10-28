<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\Task;

/**
 * The flex-window cap of a task rule (FEAT-UX-039, CS-PLAN-088 D-088-12):
 * at most half the recurrence period, rounded up. The period is
 * frequencyValue × unit days (day 1, week 7, month 30; the legacy `custom`
 * type is one week); a fixed weekly rule on several weekdays uses the
 * smallest gap between them. A one-time task has no period and keeps the
 * former bound. `src/utils/flexDays.js` mirrors this rule for the form.
 */
final class FlexDaysPolicy {
	/** Hard sanity bound of any flex value (days); never shrinks a stored value below its cap. */
	public const SANITY_MAX = 366;

	/** Bound of a one-time task (the former [0, 7] range). */
	public const ONE_TIME_MAX = 7;

	private const UNIT_DAYS = ['daily' => 1, 'weekly' => 7, 'monthly' => 30];

	/**
	 * The largest flex value for the given rule inputs.
	 *
	 * @param list<string> $weekdays The weekdays of a fixed weekly rule (empty otherwise)
	 */
	public static function maxFlexDays(string $frequencyType, int $frequencyValue, string $recurrenceMode, array $weekdays = []): int {
		if ($recurrenceMode === Task::RECURRENCE_ONCE) {
			return self::ONE_TIME_MAX;
		}
		$period = self::periodDays($frequencyType, $frequencyValue, $weekdays);
		return min(self::SANITY_MAX, (int)ceil($period / 2));
	}

	/**
	 * The cap of a task definition's current rule inputs.
	 */
	public static function maxForTask(Task $task): int {
		return self::maxFlexDays(
			(string)$task->getFrequencyType(),
			(int)$task->getFrequencyValue(),
			(string)$task->getRecurrenceMode(),
			self::fixedWeekdays((string)$task->getSchedulingMode(), $task->getFixedScheduleDays()),
		);
	}

	/**
	 * The recurrence period in days.
	 *
	 * @param list<string> $weekdays The weekdays of a fixed weekly rule (empty otherwise)
	 */
	public static function periodDays(string $frequencyType, int $frequencyValue, array $weekdays = []): int {
		if ($frequencyType === 'custom') {
			return 7;
		}
		// From 2 × SANITY_MAX on, every cap is SANITY_MAX: clamping there
		// changes no result and keeps the product an int (no overflow).
		$period = (self::UNIT_DAYS[$frequencyType] ?? 7) * max(1, min($frequencyValue, 2 * self::SANITY_MAX));
		if ($frequencyType !== 'weekly') {
			return $period;
		}
		return self::smallestWeekdayGap($weekdays, $period);
	}

	/**
	 * The weekdays that shape the period: only a fixed (static) schedule
	 * runs on its weekdays; a completion-relative one ignores them.
	 *
	 * @param string|list<string>|null $fixedScheduleDays JSON list or weekday list
	 * @return list<string>
	 */
	public static function fixedWeekdays(string $schedulingMode, $fixedScheduleDays): array {
		if ($schedulingMode !== Task::SCHEDULING_STATIC) {
			return [];
		}
		$days = is_string($fixedScheduleDays) ? json_decode($fixedScheduleDays, true) : $fixedScheduleDays;
		return is_array($days) ? array_values(array_filter($days, 'is_string')) : [];
	}

	/**
	 * The smallest circular gap between the weekdays within one cycle of
	 * `$cycleDays` (a single weekday repeats after the whole cycle).
	 *
	 * @param list<string> $weekdays
	 */
	private static function smallestWeekdayGap(array $weekdays, int $cycleDays): int {
		$offsets = [];
		foreach ($weekdays as $day) {
			$offset = array_search(strtolower(trim($day)), TaskService::WEEKDAYS, true);
			if ($offset !== false) {
				$offsets[$offset] = $offset;
			}
		}
		sort($offsets);
		if (count($offsets) < 2) {
			return $cycleDays;
		}
		$gap = $offsets[0] + $cycleDays - $offsets[count($offsets) - 1];
		for ($i = 1, $n = count($offsets); $i < $n; $i++) {
			$gap = min($gap, $offsets[$i] - $offsets[$i - 1]);
		}
		return $gap;
	}
}
