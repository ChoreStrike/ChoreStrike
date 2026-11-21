<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;

/**
 * The current cycle of a room task and what the room-task row keeps of it
 * (CS-PLAN-091 WP-B1, ADR-021 decision 3): the cycle version every change
 * of the current cycle bumps, and the `next_due` projection the list
 * queries filter by. Split off {@see CycleService} (RF-091-10); the cycle
 * writers, {@see CycleMisses} and {@see CycleSleep} build on it.
 *
 * Methods suffixed `UnderLock` run inside the caller's house-first
 * transaction with the room task row locked, and never begin one.
 */
class CycleProjection {
	public function __construct(
		private WindowService $windows,
		private RoomTaskMapper $roomTaskMapper,
		private TaskMapper $taskMapper,
		private CalendarService $calendar,
	) {
	}

	/** Whether the room task has cycles at all: neither a bonus task (decision 8) nor asleep (decision 7). */
	public static function hasCycles(RoomTask $roomTask, Task $task): bool {
		return !$task->getIsVoluntary() && $roomTask->getSleepingSince() === null;
	}

	/** An awake bonus task: no cycle, so its completions never move its version (D-091-84). */
	public static function isAwakeBonus(RoomTask $roomTask, Task $task): bool {
		return (bool)$task->getIsVoluntary() && $roomTask->getSleepingSince() === null;
	}

	/**
	 * The current cycle of one room task, read with its completions. A
	 * task without cycles (asleep, a bonus task) or one that ended needs no
	 * completion read (RF-091-10).
	 *
	 * @param array<int, CycleHistory>|null $histories Completion inputs a caller read for several room tasks at once
	 */
	public function currentFor(RoomTask $roomTask, Task $task, \DateTimeZone $timeZone, \DateTimeInterface $now, ?array $histories = null): ?CurrentCycle {
		if (!$roomTask->getIsActive() || !self::hasCycles($roomTask, $task)) {
			return null;
		}
		$histories ??= $this->windows->historiesFor([(int)$roomTask->getId()]);
		return $this->windows->currentCycle($roomTask, $task, $histories[(int)$roomTask->getId()] ?? null, $timeZone, $now);
	}

	/**
	 * The current cycle changed UNDER LOCK (a completion, an undo, an edit
	 * of the rule, flex, energy, assignees or first date, sleep and wake):
	 * bump the cycle version, end a task that has no cycle left and refresh
	 * the `next_due` projection. Always persists the room task.
	 *
	 * @param array<int, CycleHistory>|null $histories {@see WindowService::historiesFor()}
	 */
	public function changedUnderLock(RoomTask $roomTask, Task $task, \DateTimeZone $timeZone, \DateTime $now, ?array $histories = null): ?CurrentCycle {
		$roomTask->bumpCycleVersion();
		$cycle = $this->currentFor($roomTask, $task, $timeZone, $now, $histories);
		if ($cycle === null && self::hasCycles($roomTask, $task) && $roomTask->getIsActive()) {
			// A one-time task that is done, or a bounded rule after its last cycle.
			$roomTask->setIsActive(false);
		}
		$this->project($roomTask, $cycle, $timeZone);
		$this->roomTaskMapper->update($roomTask);
		return $cycle;
	}

	/**
	 * The house time zone changed UNDER LOCK: windows are house-local
	 * days, so every `next_due` projection moves to the new zone's days.
	 */
	public function reprojectHouseUnderLock(int $houseId, \DateTimeZone $timeZone, \DateTime $now): void {
		$roomTasks = $this->roomTaskMapper->findByHouse($houseId, true);
		$tasks = $this->taskMapper->findByIds(array_map(static fn (RoomTask $roomTask): int => $roomTask->getTaskId(), $roomTasks));
		$histories = $this->windows->historiesFor(array_map(static fn (RoomTask $roomTask): int => (int)$roomTask->getId(), $roomTasks));
		foreach ($roomTasks as $roomTask) {
			$task = $tasks[$roomTask->getTaskId()] ?? null;
			if ($task === null) {
				continue;
			}
			$this->reprojectUnderLock($roomTask, $this->windows->currentCycle($roomTask, $task, $histories[(int)$roomTask->getId()] ?? null, $timeZone, $now), $timeZone);
		}
	}

	/** Whether the passing of time moved the current window away from the stored projection. */
	public function projectionDrifted(RoomTask $roomTask, ?CurrentCycle $cycle, \DateTimeZone $timeZone): bool {
		$projection = $this->projection($cycle, $timeZone);
		return $projection !== null && (
			$roomTask->getNextDue()?->getTimestamp() !== $projection[0]->getTimestamp()
			|| $roomTask->getIdealDueDate()?->getTimestamp() !== $projection[1]->getTimestamp()
		);
	}

	/** Repair a drifted projection UNDER LOCK without touching the cycle version. */
	public function reprojectUnderLock(RoomTask $roomTask, ?CurrentCycle $cycle, \DateTimeZone $timeZone): void {
		if ($this->projectionDrifted($roomTask, $cycle, $timeZone)) {
			$this->project($roomTask, $cycle, $timeZone);
			$this->roomTaskMapper->update($roomTask);
		}
	}

	/**
	 * `next_due` is the start instant of the current window's last day and
	 * `ideal_due_date` that of its (postponed) centre; a task without a
	 * cycle keeps what it had.
	 *
	 * @return array{0: \DateTime, 1: \DateTime}|null
	 */
	private function projection(?CurrentCycle $cycle, \DateTimeZone $timeZone): ?array {
		if ($cycle === null) {
			return null;
		}
		return [
			$this->calendar->dayStartInstant($cycle->endOn, $timeZone),
			$this->calendar->dayStartInstant($cycle->plannedOn(), $timeZone),
		];
	}

	private function project(RoomTask $roomTask, ?CurrentCycle $cycle, \DateTimeZone $timeZone): void {
		$projection = $this->projection($cycle, $timeZone);
		if ($projection !== null) {
			$roomTask->setNextDue($projection[0]);
			$roomTask->setIdealDueDate($projection[1]);
		}
	}
}
