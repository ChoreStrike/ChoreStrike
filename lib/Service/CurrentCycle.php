<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTask;

/**
 * One derived cycle of a room task with its window in house-local days
 * (ADR-021 decisions 1 and 2). Never stored while it is open.
 */
final class CurrentCycle {
	/**
	 * @param string $key `c<completionId>` or `d<Y-m-d>`
	 * @param string $centreOn The rule day, before any postponement
	 * @param string $startOn First day of the window (inclusive)
	 * @param string $endOn Last day of the window (inclusive)
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $centreOn,
		public readonly string $startOn,
		public readonly string $endOn,
		public readonly int $postponeCount = 0,
		public readonly int $postponeDays = 0,
	) {
	}

	/** Whether a client value has the shape of a cycle key (boundary validation). */
	public static function isKey(mixed $value): bool {
		return is_string($value) && preg_match('/^(c[1-9]\d{0,17}|d\d{4}-\d{2}-\d{2})$/D', $value) === 1;
	}

	/** The window has ended: the task is the one red entry. */
	public function isOverdue(string $todayKey): bool {
		return $this->endOn < $todayKey;
	}

	/**
	 * Decided once, at completion (D-091-02): before or inside the window,
	 * a postponement included, is on time; after it is late.
	 */
	public function classify(string $workDayKey): string {
		return $workDayKey <= $this->endOn ? CompletedTask::CLASS_ON_TIME : CompletedTask::CLASS_LATE;
	}

	/** The day the cycle is planned for: its rule day, moved by a postponement. */
	public function plannedOn(): string {
		return RecurrenceCalculator::offsetDays($this->centreOn, $this->postponeDays);
	}

	/** The house-local Monday of the week the window ends in. */
	public function weekStart(): string {
		return RecurrenceCalculator::mondayOf($this->endOn);
	}
}
