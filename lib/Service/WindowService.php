<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Exception\ConflictException;

/**
 * Derived due windows (CS-PLAN-091 WP-B1, ADR-021 decisions 1 and 2).
 *
 * A room task has at most one current cycle. It is computed on read from
 * the definition's rule, the room task's stored inputs (anchor,
 * postponement, sleep) and its valid completions, in house-local days
 * (ADR-009). No future cycle is stored, and an open one is never frozen:
 * a changed rule, flex or first date shows at once. Lists, *Today*, the
 * calendar, the completion command and the timeline job read through
 * this one service.
 *
 * The derivation itself is pure; only {@see historiesFor()} reads.
 */
class WindowService {
	/** Upper bound of fixed cycles walked in one derivation. */
	private const MAX_STEPS = 1000;

	/** A room task is postponed at most this often per cycle (decision 6). */
	public const MAX_POSTPONEMENTS = 3;

	/** States of the `cycle` block. */
	public const STATE_UPCOMING = 'upcoming';
	public const STATE_OPEN = 'open';
	public const STATE_OVERDUE = 'overdue';
	public const STATE_ASLEEP = 'asleep';
	public const STATE_BONUS = 'bonus';

	public function __construct(
		private RecurrenceCalculator $calculator,
		private CompletedTaskMapper $completedTaskMapper,
	) {
	}

	/**
	 * The completion inputs of the given room tasks in two queries.
	 *
	 * @param int[] $roomTaskIds
	 * @return array<int, CycleHistory> Room tasks without a valid completion are absent
	 */
	public function historiesFor(array $roomTaskIds): array {
		$histories = [];
		foreach ($this->completedTaskMapper->findCycleAnchorsByRoomTasks($roomTaskIds) as $roomTaskId => $anchor) {
			$histories[$roomTaskId] = new CycleHistory($anchor['id'], $anchor['workDay'], $anchor['cycleKey'], $anchor['fixedCentre']);
		}
		return $histories;
	}

	/**
	 * The current cycle of a room task, or null when it has none: a
	 * sleeping, bonus or inactive task, a one-time task that is done and a
	 * bounded rule after its last cycle.
	 */
	public function currentCycle(RoomTask $roomTask, Task $task, ?CycleHistory $history, \DateTimeZone $timeZone, \DateTimeInterface $now): ?CurrentCycle {
		if (!$roomTask->getIsActive() || $roomTask->getSleepingSince() !== null) {
			return null;
		}
		$rule = CycleRule::fromTask($task);
		if ($rule->bonus) {
			return null;
		}
		$history ??= new CycleHistory();
		$anchor = $this->anchorKey($roomTask, $timeZone);
		if ($rule->once || !$rule->fixed) {
			return $this->anchoredCycle($roomTask, $rule, $history, $anchor);
		}
		$today = self::todayKey($now, $timeZone);
		// Every cycle that ended more than one rule period and the longest
		// postponement ago has a successor that opened: it is not current.
		$reach = $rule->flexDays + self::periodDays($rule) + self::MAX_POSTPONEMENTS * self::postponeStep($rule->flexDays);
		$steps = $this->fixedCycles($roomTask, $rule, $history, $anchor, $today, RecurrenceCalculator::offsetDays($today, -$reach - 1));
		if ($steps === [] && $rule->activeUntil !== null) {
			// A bounded rule past its end: its last cycle stays current until
			// it is done. That cycle lies within one period of the bound, so
			// the walk starts there instead of at the anchor.
			$steps = $this->fixedCycles($roomTask, $rule, $history, $anchor, $today, RecurrenceCalculator::offsetDays($rule->activeUntil, -self::periodDays($rule) - 1));
		}
		return $steps === [] ? null : $steps[count($steps) - 1];
	}

	/**
	 * The fixed cycles that stopped being current without being done
	 * (decision 5): their window ended and the next one opened. Oldest
	 * first; an after-completion or one-time cycle is never missed.
	 *
	 * @param string|null $afterCentre Skip cycles up to this rule day (already recorded)
	 * @return list<CurrentCycle>
	 */
	public function missedCycles(RoomTask $roomTask, Task $task, ?CycleHistory $history, \DateTimeZone $timeZone, \DateTimeInterface $now, ?string $afterCentre = null): array {
		if (!$roomTask->getIsActive() || $roomTask->getSleepingSince() !== null) {
			return [];
		}
		$rule = CycleRule::fromTask($task);
		if ($rule->bonus || $rule->once || !$rule->fixed) {
			return [];
		}
		$steps = $this->fixedCycles($roomTask, $rule, $history ?? new CycleHistory(), $this->anchorKey($roomTask, $timeZone), self::todayKey($now, $timeZone), $afterCentre);
		return array_slice($steps, 0, -1);
	}

	/**
	 * The current cycle and the ones the rule yields after it, for a
	 * calendar range. Cycles after the current one are projections: a
	 * fixed rule's next rule days, an after-completion rule's next centres
	 * as if each cycle were done on its centre day.
	 *
	 * @param string|null $fromKey The first day of the range: projections whose window ended before it are
	 *                             skipped where the rule allows to jump (the current cycle always leads the list)
	 * @return list<CurrentCycle> Ascending; cycles whose window starts after `$throughKey` are left out.
	 *                            A projected after-completion cycle carries a `p<Y-m-d>` key, which is no target.
	 */
	public function cyclesThrough(RoomTask $roomTask, Task $task, ?CycleHistory $history, \DateTimeZone $timeZone, \DateTimeInterface $now, string $throughKey, ?string $fromKey = null): array {
		$current = $this->currentCycle($roomTask, $task, $history, $timeZone, $now);
		if ($current === null || $current->startOn > $throughKey) {
			return [];
		}
		$rule = CycleRule::fromTask($task);
		$anchor = $this->anchorKey($roomTask, $timeZone);
		$cycles = [$current];
		$centre = $current->centreOn;
		// A projection shows from the day its window ends on or after the range start.
		$resume = $fromKey === null ? null : RecurrenceCalculator::offsetDays($fromKey, -$rule->flexDays);
		while (!$rule->once && count($cycles) < self::MAX_STEPS) {
			$centre = $rule->fixed
				? $this->calculator->firstFixedDayOnOrAfter($rule, $anchor, max(RecurrenceCalculator::dayAfter($centre), $resume ?? ''))
				: $this->nextAfterCompletion($rule, $this->skipIntervals($rule, $centre, $resume));
			$resume = null;
			if ($centre === null) {
				break;
			}
			$next = $this->window($rule->fixed ? CurrentCycle::keyOn($centre) : CurrentCycle::keyProjected($centre), $centre, $rule->flexDays, $roomTask);
			if ($next->startOn > $throughKey) {
				break;
			}
			$cycles[] = $next;
		}
		return $cycles;
	}

	/**
	 * The cycles that follow the cycles of a room task — projections for
	 * the live suggestion (ADR-022 decision 2), which asks once per placed
	 * cycle: the rule and the anchor are read once per room task. The
	 * answer takes a cycle and the day it is done on. A fixed rule
	 * continues on its next rule day whatever day the work is done; an
	 * after-completion rule hangs the next window on that day. Null for a
	 * one-time task, a bonus task and a bounded rule that ended.
	 *
	 * @return \Closure(CurrentCycle, string): ?CurrentCycle A projected after-completion cycle carries a
	 *                                                       `p<Y-m-d>` key, which is no target
	 */
	public function successorOf(RoomTask $roomTask, Task $task, \DateTimeZone $timeZone): \Closure {
		$rule = CycleRule::fromTask($task);
		if ($rule->once || $rule->bonus) {
			return static fn (): ?CurrentCycle => null;
		}
		$anchor = $rule->fixed ? $this->anchorKey($roomTask, $timeZone) : '';
		return function (CurrentCycle $cycle, string $doneOn) use ($roomTask, $rule, $anchor): ?CurrentCycle {
			$centre = $rule->fixed
				? $this->calculator->firstFixedDayOnOrAfter($rule, $anchor, RecurrenceCalculator::dayAfter($cycle->centreOn))
				: $this->nextAfterCompletion($rule, $doneOn);
			return $centre === null
				? null
				: $this->window($rule->fixed ? CurrentCycle::keyOn($centre) : CurrentCycle::keyProjected($centre), $centre, $rule->flexDays, $roomTask);
		};
	}

	/**
	 * The `cycle` block of a room-task row (API reference, Room Tasks): the
	 * completion target and what the row shows of its window. Null for a
	 * task without a cycle that is neither asleep nor a bonus task.
	 *
	 * @return array<string, mixed>|null
	 */
	public function block(RoomTask $roomTask, Task $task, ?CurrentCycle $cycle, string $todayKey): ?array {
		if ($roomTask->getSleepingSince() !== null) {
			// The version is the receipt of the wake command and of a completion, which wakes the task.
			return [
				'state' => self::STATE_ASLEEP,
				'expectedVersion' => $roomTask->getCycleVersion(),
				'wakeOn' => $roomTask->getWakeOn()?->format('Y-m-d'),
			];
		}
		if ($task->getIsVoluntary() && $roomTask->getIsActive()) {
			return ['state' => self::STATE_BONUS, 'expectedVersion' => $roomTask->getCycleVersion()];
		}
		if ($cycle === null) {
			return null;
		}
		$state = $cycle->isOverdue($todayKey) ? self::STATE_OVERDUE : ($cycle->startOn <= $todayKey ? self::STATE_OPEN : self::STATE_UPCOMING);
		return [
			'cycleKey' => $cycle->key,
			'expectedVersion' => $roomTask->getCycleVersion(),
			'windowStartOn' => $cycle->startOn,
			'windowEndOn' => $cycle->endOn,
			'centreOn' => $cycle->plannedOn(),
			'state' => $state,
			'postponeCount' => $cycle->postponeCount,
			'postponeLimit' => self::MAX_POSTPONEMENTS,
			'canPostpone' => self::postponeRefusal($task, $cycle, $todayKey) === null,
		];
	}

	/**
	 * Why a cycle cannot be postponed (decision 6), or null when it can:
	 * a red cycle stays a miss, a task without flex days has nothing to
	 * move by, and a cycle moves at most three times. The row's
	 * `canPostpone` and the command decide through this one rule.
	 *
	 * @return string|null `ConflictException::REASON_WINDOW_ENDED` or `REASON_NOT_POSTPONABLE`
	 */
	public static function postponeRefusal(Task $task, CurrentCycle $cycle, string $todayKey): ?string {
		if ($cycle->isOverdue($todayKey)) {
			return ConflictException::REASON_WINDOW_ENDED;
		}
		if (self::postponeStep((int)$task->getFlexDays()) === 0 || $cycle->postponeCount >= self::MAX_POSTPONEMENTS) {
			return ConflictException::REASON_NOT_POSTPONABLE;
		}
		return null;
	}

	/**
	 * The anchor a room task takes when it wakes on a day (decision 7,
	 * D-091-28): an after-completion or one-time task hangs on the wake
	 * day, a fixed rule continues with its next rule day, so its parity
	 * and its day of the month stay. A fixed rule with no day left inside
	 * its bounds takes the wake day and so has no cycle left: the task
	 * ends, and what it slept through is no miss. Null for a bonus task,
	 * which has no anchor.
	 */
	public function wakeAnchor(RoomTask $roomTask, Task $task, string $wakeDayKey, \DateTimeZone $timeZone): ?string {
		$rule = CycleRule::fromTask($task);
		if ($rule->bonus) {
			return null;
		}
		return $rule->fixed && !$rule->once
			? ($this->calculator->firstFixedDayOnOrAfter($rule, $this->anchorKey($roomTask, $timeZone), $wakeDayKey) ?? $wakeDayKey)
			: $wakeDayKey;
	}

	/**
	 * How many days the cycle that follows a completion moves when that
	 * completion's day moves (ADR-021 decision 9). Only an after-completion
	 * rule hangs its next window on the completion.
	 */
	public function shiftAfterCompletion(Task $task, string $fromDay, string $toDay): int {
		$rule = CycleRule::fromTask($task);
		if ($rule->fixed || $rule->once || $rule->bonus) {
			return 0;
		}
		return RecurrenceCalculator::daysBetween(
			$this->calculator->nextIntervalDate($fromDay, $rule->unit, $rule->interval),
			$this->calculator->nextIntervalDate($toDay, $rule->unit, $rule->interval)
		);
	}

	/** The house-local day of an instant. */
	public static function todayKey(\DateTimeInterface $now, \DateTimeZone $timeZone): string {
		return CalendarService::dayKeyOf($now, $timeZone);
	}

	/**
	 * After completion and one-time: the cycle hangs on a day (the first
	 * date or a wake day) until a valid completion fulfils it, afterwards
	 * on the latest valid completion. Any valid completion of the anchor's
	 * cycle uses it up — also one that a later early completion followed —
	 * since `d` keys of these rules come from the anchor alone.
	 */
	private function anchoredCycle(RoomTask $roomTask, CycleRule $rule, CycleHistory $history, string $anchor): ?CurrentCycle {
		$dayKey = CurrentCycle::keyOn($anchor);
		$anchorFulfilled = $history->latestCycleKey === $dayKey
			|| ($history->fixedCentre !== null && $history->fixedCentre >= $anchor);
		$onAnchor = !$history->hasCompletion()
			|| ($anchor > $history->latestWorkDay && !$anchorFulfilled);
		if ($onAnchor) {
			return $this->window($dayKey, $anchor, $rule->flexDays, $roomTask);
		}
		if ($rule->once) {
			return null;
		}
		$centre = $this->nextAfterCompletion($rule, (string)$history->latestWorkDay);
		return $centre === null ? null : $this->window(CurrentCycle::keyAfter((int)$history->latestCompletionId), $centre, $rule->flexDays, $roomTask);
	}

	/**
	 * The centre to continue an after-completion projection from, so that
	 * the next one is the first whose day reaches `$resume`. Day and week
	 * intervals are plain day steps; a month interval is walked.
	 */
	private function skipIntervals(CycleRule $rule, string $centre, ?string $resume): string {
		if ($resume === null || $rule->unit === CycleRule::UNIT_MONTH) {
			return $centre;
		}
		$step = $rule->interval * ($rule->unit === CycleRule::UNIT_WEEK ? 7 : 1);
		$skipped = intdiv(max(0, RecurrenceCalculator::daysBetween($centre, $resume) - 1), $step);
		return RecurrenceCalculator::offsetDays($centre, $skipped * $step);
	}

	private function nextAfterCompletion(CycleRule $rule, string $fromDay): ?string {
		$centre = $this->calculator->nextIntervalDate($fromDay, $rule->unit, $rule->interval);
		return $rule->activeUntil !== null && $centre > $rule->activeUntil ? null : $centre;
	}

	/**
	 * The fixed cycles from the first one that is neither done nor
	 * recorded up to the current one, which is last. A cycle stays current
	 * until it is done, or until its window has ended and the next
	 * cycle's window has opened.
	 *
	 * @return list<CurrentCycle> Empty when the rule has no cycle left
	 */
	private function fixedCycles(RoomTask $roomTask, CycleRule $rule, CycleHistory $history, string $anchor, string $today, ?string $afterCentre = null): array {
		$from = $anchor;
		foreach ([$history->fixedCentre, $afterCentre] as $done) {
			if ($done !== null) {
				$from = max($from, RecurrenceCalculator::dayAfter($done));
			}
		}
		$centre = $this->calculator->firstFixedDayOnOrAfter($rule, $anchor, $from);
		$cycles = [];
		while ($centre !== null && count($cycles) < self::MAX_STEPS) {
			$cycle = $this->window(CurrentCycle::keyOn($centre), $centre, $rule->flexDays, $roomTask);
			$cycles[] = $cycle;
			$next = $this->calculator->firstFixedDayOnOrAfter($rule, $anchor, RecurrenceCalculator::dayAfter($centre));
			$nextOpened = $next !== null && RecurrenceCalculator::offsetDays($next, -$rule->flexDays) <= $today;
			if (!$cycle->isOverdue($today) || !$nextOpened) {
				break;
			}
			$centre = $next;
		}
		return $cycles;
	}

	/** The window of a cycle: its centre plus and minus the flex days, moved by its postponement. */
	private function window(string $key, string $centre, int $flexDays, RoomTask $roomTask): CurrentCycle {
		$postponed = $roomTask->getCycleKey() === $key;
		$shift = $postponed ? max(0, (int)$roomTask->getPostponeDays()) : 0;
		return new CurrentCycle(
			$key,
			$centre,
			RecurrenceCalculator::offsetDays($centre, $shift - $flexDays),
			RecurrenceCalculator::offsetDays($centre, $shift + $flexDays),
			$postponed ? (int)$roomTask->getPostponeCount() : 0,
			$shift,
		);
	}

	/**
	 * The stored anchor; a room task without one (never set by the
	 * cutover because it had a valid completion) falls back to the day it
	 * was created.
	 */
	private function anchorKey(RoomTask $roomTask, \DateTimeZone $timeZone): string {
		$anchor = $roomTask->getAnchorOn();
		if ($anchor !== null) {
			return $anchor->format('Y-m-d');
		}
		return self::todayKey($roomTask->getCreatedAt() ?? new \DateTime('@0'), $timeZone);
	}

	/** Days one postponement moves a window: half the flex days, rounded up (decision 6). */
	public static function postponeStep(int $flexDays): int {
		return intdiv(max(0, $flexDays) + 1, 2);
	}

	/** An upper bound of the days between two rule days of a fixed rule. */
	private static function periodDays(CycleRule $rule): int {
		return $rule->interval * match ($rule->unit) {
			CycleRule::UNIT_DAY => 1,
			CycleRule::UNIT_MONTH => 31,
			default => 7,
		};
	}
}
