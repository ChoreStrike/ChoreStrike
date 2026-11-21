<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\Cycle;
use OCA\ChoreStrike\Db\CycleMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;

/**
 * The closed-cycle rows (ADR-021 decisions 3 and 5, D-091-17) and the one
 * rule of a miss: a fixed cycle that stopped being current without the
 * work is recorded as `missed`, exactly once. Split off {@see CycleService}
 * (RF-091-10); the completion, the undo, sleep and the timeline job record
 * through it.
 *
 * Methods suffixed `UnderLock` run inside the caller's house-first
 * transaction with the room task row locked, and never begin one.
 */
class CycleMisses {
	public function __construct(
		private WindowService $windows,
		private CycleMapper $cycleMapper,
		private TaskResponsibility $responsibility,
	) {
	}

	/** Only a recurring fixed rule has cycles that can be missed (decision 5). */
	public static function canMiss(Task $task): bool {
		$rule = CycleRule::fromTask($task);
		return $rule->fixed && !$rule->once && !$rule->bonus;
	}

	/**
	 * Record UNDER LOCK the fixed cycles of one room task that stopped
	 * being current without the work (decision 5), exactly once each.
	 *
	 * @return int Rows written
	 */
	public function recordMissedUnderLock(House $house, RoomTask $roomTask, Task $task, ?CycleHistory $history, \DateTimeZone $timeZone, \DateTime $now): int {
		$roomTaskId = (int)$roomTask->getId();
		$recorded = $this->cycleMapper->latestDayKeyedCentres([$roomTaskId])[$roomTaskId] ?? null;
		$written = 0;
		$responsible = null;
		foreach ($this->unrecordedMissed($house, $roomTask, $task, $history, $recorded, $timeZone, $now) as $cycle) {
			$responsible ??= $this->responsibility->walletIdsOf((int)$house->getId(), $roomTaskId);
			$written += $this->insertMissed((int)$house->getId(), $roomTask, $task, $cycle, $timeZone, $now, $responsible) ? 1 : 0;
		}
		return $written;
	}

	/**
	 * Missed cycles the derived model owns (see {@see countsAsMissed()}).
	 *
	 * @return list<CurrentCycle>
	 */
	public function unrecordedMissed(House $house, RoomTask $roomTask, Task $task, ?CycleHistory $history, ?string $recorded, \DateTimeZone $timeZone, \DateTime $now): array {
		return array_values(array_filter(
			$this->windows->missedCycles($roomTask, $task, $history, $timeZone, $now, $recorded),
			fn (CurrentCycle $cycle): bool => $this->countsAsMissed($house, $roomTask, $cycle, $timeZone)
		));
	}

	/**
	 * Whether a fixed cycle that stopped being current is the derived
	 * model's miss: stored occurrences own every window that ended before
	 * the house's `cycles_from`, and no window that ended before the room
	 * task was created counts against anyone (a past first date).
	 */
	public function countsAsMissed(House $house, RoomTask $roomTask, CurrentCycle $cycle, \DateTimeZone $timeZone): bool {
		$from = $house->getCyclesFrom()?->format('Y-m-d') ?? '0000-00-00';
		$created = $roomTask->getCreatedAt() !== null ? WindowService::todayKey($roomTask->getCreatedAt(), $timeZone) : '0000-00-00';
		return $cycle->endOn >= max($from, $created);
	}

	/**
	 * @param list<int>|null $responsible Wallet ids when the caller resolved them
	 * @return bool Whether the row was written (false: the cycle was closed already)
	 */
	public function insertMissed(int $houseId, RoomTask $roomTask, Task $task, CurrentCycle $cycle, \DateTimeZone $timeZone, \DateTime $now, ?array $responsible = null): bool {
		$row = $this->row($houseId, $roomTask, $cycle, $timeZone, $now, $responsible);
		$row->setOutcome(Cycle::OUTCOME_MISSED);
		$row->setEnergy(max(1, (int)$task->getEnergyCost()));
		return $this->cycleMapper->insertIfAbsent($row);
	}

	/**
	 * The closed-cycle row of a cycle, before its outcome.
	 *
	 * @param list<int>|null $responsible Wallet ids when the caller resolved them
	 */
	public function row(int $houseId, RoomTask $roomTask, CurrentCycle $cycle, \DateTimeZone $timeZone, \DateTime $now, ?array $responsible = null): Cycle {
		$row = new Cycle();
		$row->setHouseId($houseId);
		$row->setRoomTaskId((int)$roomTask->getId());
		$row->setCycleKey($cycle->key);
		$row->setWindowStartOn(RecurrenceCalculator::dayKeyToDate($cycle->startOn));
		$row->setWindowEndOn(RecurrenceCalculator::dayKeyToDate($cycle->endOn));
		$row->setWeekStart(RecurrenceCalculator::dayKeyToDate($cycle->weekStart()));
		$row->setTimezone($timeZone->getName());
		$row->setResponsible($responsible ?? $this->responsibility->walletIdsOf($houseId, (int)$roomTask->getId()));
		$row->setCreatedAt($now);
		return $row;
	}
}
