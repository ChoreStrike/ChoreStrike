<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;

/**
 * The sleep and wake rules of a room task (ADR-021 decision 7, D-091-66,
 * D-091-67, D-091-82): a sleeping task has no cycle — no window, never
 * overdue, nothing to suggest — and wakes into a fresh cycle. Split off
 * {@see CycleService} (RF-091-10); the sleep command, the one form, the
 * completion of a sleeping task and the timeline job use it.
 *
 * Methods suffixed `UnderLock` run inside the caller's house-first
 * transaction with the room task row locked, and never begin one.
 */
class CycleSleep {
	public function __construct(
		private WindowService $windows,
		private CycleProjection $projection,
		private CycleMisses $misses,
	) {
	}

	/** Whether a sleeping room task is due to wake: its wake date has come (decision 7). */
	public static function wakesBy(RoomTask $roomTask, string $todayKey): bool {
		return $roomTask->getSleepingSince() !== null && $roomTask->getWakeOn() !== null
			&& $roomTask->getWakeOn()->format('Y-m-d') <= $todayKey;
	}

	/**
	 * Put a room task to sleep UNDER LOCK: a fixed cycle that had already
	 * stopped being current is recorded as missed first; the one that is
	 * current goes without a trace. Called again for a sleeping task, it
	 * only moves the wake date.
	 *
	 * @param string|null $wakeOn The house-local day the timeline job wakes it (`Y-m-d`)
	 * @param array<int, CycleHistory>|null $histories Completion inputs the caller read already
	 */
	public function sleepUnderLock(House $house, RoomTask $roomTask, Task $task, ?string $wakeOn, \DateTimeZone $timeZone, \DateTime $now, ?array $histories = null): void {
		if ($roomTask->getSleepingSince() === null) {
			if (CycleMisses::canMiss($task)) {
				$roomTaskId = (int)$roomTask->getId();
				$histories ??= $this->windows->historiesFor([$roomTaskId]);
				$this->misses->recordMissedUnderLock($house, $roomTask, $task, $histories[$roomTaskId] ?? null, $timeZone, $now);
			}
			// The current cycle ends without an outcome, and its postponement
			// with it: the fresh cycle of the wake day starts unmoved, also
			// when it gets the same key (D-091-82).
			$roomTask->setCycleKey(null);
			$roomTask->setPostponeCount(0);
			$roomTask->setPostponeDays(0);
			$roomTask->setSleepingSince($now);
		}
		$roomTask->setWakeOn($wakeOn === null ? null : RecurrenceCalculator::dayKeyToDate($wakeOn));
		$this->projection->changedUnderLock($roomTask, $task, $timeZone, $now);
	}

	/**
	 * Wake a sleeping room task UNDER LOCK: it starts a fresh cycle from
	 * the wake day ({@see WindowService::wakeAnchor()}). The time it slept
	 * leaves no missed cycle, because no rule day before the new anchor
	 * counts.
	 *
	 * @param string $wakeDayKey The house-local day it wakes on (`Y-m-d`)
	 * @param array<int, CycleHistory>|null $histories Completion inputs the caller read already
	 */
	public function wakeUnderLock(RoomTask $roomTask, Task $task, string $wakeDayKey, \DateTimeZone $timeZone, \DateTime $now, ?array $histories = null): ?CurrentCycle {
		$anchor = $this->windows->wakeAnchor($roomTask, $task, $wakeDayKey, $timeZone);
		if ($anchor !== null) {
			$roomTask->setAnchorOn(RecurrenceCalculator::dayKeyToDate($anchor));
		}
		$roomTask->setSleepingSince(null);
		$roomTask->setWakeOn(null);
		return $this->projection->changedUnderLock($roomTask, $task, $timeZone, $now, $histories);
	}
}
