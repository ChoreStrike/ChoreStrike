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
 * The rule of a task definition as the window derivation reads it
 * (ADR-021 decision 1). It is built from the definition on every read, so
 * a changed rule, flex or bound applies to open work at once.
 */
final class CycleRule {
	private const WEEKDAY_ISO = [
		'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4,
		'friday' => 5, 'saturday' => 6, 'sunday' => 7,
	];

	/**
	 * @param bool $fixed Fixed days (static) instead of after completion
	 * @param bool $once One-time task: a single cycle on its first date
	 * @param bool $bonus Bonus task: never due, no cycle (decision 8)
	 * @param string $unit ScheduleRevision::UNIT_*
	 * @param list<int> $weekdays ISO weekdays 1–7 of a fixed weekly rule, ascending
	 * @param int|null $monthDay Day 1–31 of a fixed monthly rule
	 * @param string|null $activeFrom Inclusive house-local bound (`Y-m-d`)
	 * @param string|null $activeUntil Inclusive house-local bound (`Y-m-d`)
	 */
	public function __construct(
		public readonly bool $fixed,
		public readonly bool $once,
		public readonly bool $bonus,
		public readonly string $unit,
		public readonly int $interval,
		public readonly array $weekdays,
		public readonly ?int $monthDay,
		public readonly int $flexDays,
		public readonly ?string $activeFrom,
		public readonly ?string $activeUntil,
	) {
	}

	/** The rule of a definition as it stands now; active bounds are calendar days. */
	public static function fromTask(Task $task): self {
		$type = (string)$task->getFrequencyType();
		$unit = match ($type) {
			'daily' => ScheduleRevision::UNIT_DAY,
			'monthly' => ScheduleRevision::UNIT_MONTH,
			default => ScheduleRevision::UNIT_WEEK,
		};
		$fixed = $task->getSchedulingMode() === Task::SCHEDULING_STATIC;
		$weekdays = $fixed && $unit === ScheduleRevision::UNIT_WEEK
			? self::isoWeekdays(FlexDaysPolicy::fixedWeekdays(Task::SCHEDULING_STATIC, $task->getFixedScheduleDays()))
			: [];
		$monthDay = $task->getFixedMonthDay();

		return new self(
			$fixed,
			$task->getRecurrenceMode() === Task::RECURRENCE_ONCE,
			(bool)$task->getIsVoluntary(),
			$unit,
			$type === 'custom' ? 1 : max(1, (int)$task->getFrequencyValue()),
			$weekdays,
			$monthDay !== null ? max(1, min(31, $monthDay)) : null,
			// Validation owns the rule's cap; a stored legacy value is never shrunk here.
			min(FlexDaysPolicy::SANITY_MAX, max(0, (int)$task->getFlexDays())),
			self::dayKey($task->getActiveFrom()),
			self::dayKey($task->getActiveUntil()),
		);
	}

	/**
	 * @param list<string> $names
	 * @return list<int>
	 */
	private static function isoWeekdays(array $names): array {
		$days = [];
		foreach ($names as $name) {
			$iso = self::WEEKDAY_ISO[strtolower(trim($name))] ?? null;
			if ($iso !== null) {
				$days[$iso] = $iso;
			}
		}
		sort($days);
		return $days;
	}

	/**
	 * An active bound is a calendar day stored as its midnight (the
	 * validated `Y-m-d` input), never an instant: it is read as written,
	 * without a zone conversion that would move it a day west of UTC.
	 */
	private static function dayKey(?\DateTimeInterface $bound): ?string {
		return $bound?->format('Y-m-d');
	}
}
