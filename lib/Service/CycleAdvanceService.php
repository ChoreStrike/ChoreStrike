<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CycleMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;

/**
 * The timeline job's pass over the cycles (ADR-021 decisions 3, 5 and 7):
 * what the passing of time changes without any command. A fixed-day cycle
 * whose window ended without the work is recorded as missed, exactly once,
 * a sleeping task wakes on its wake date, and the `next_due` projection
 * follows the cycle that is current now. {@see CycleService} stays the
 * only writer of cycle rows.
 */
class CycleAdvanceService {
	/** Cursor batch size of the pass. */
	public const BATCH_SIZE = 200;

	public function __construct(
		private CycleService $cycles,
		private WindowService $windows,
		private CycleMapper $cycleMapper,
		private RoomTaskMapper $roomTaskMapper,
		private TaskMapper $taskMapper,
		private HouseMapper $houseMapper,
		private CalendarService $calendar,
		private TransactionExecutor $transactions,
		private AppLogger $appLogger,
	) {
	}

	/**
	 * The pass over the one household of the instance: see {@see advanceHouse}.
	 *
	 * @return array{houses: int, tasks: int, missed: int}
	 */
	public function advanceForJobs(?\DateTime $now = null): array {
		$house = $this->houseMapper->findOneForJobs();
		if ($house === null) {
			return ['houses' => 0, 'tasks' => 0, 'missed' => 0];
		}
		return ['houses' => 1] + $this->advanceHouse($house, $now ?? new \DateTime());
	}

	/**
	 * One house: write-free for a room task that needs nothing; every
	 * other one in its own house-first transaction.
	 *
	 * @return array{tasks: int, missed: int}
	 */
	public function advanceHouse(House $house, \DateTime $now): array {
		$stats = ['tasks' => 0, 'missed' => 0];
		$cursor = 0;
		while (true) {
			$batch = $this->roomTaskMapper->findActiveIdBatchAfter((int)$house->getId(), $cursor, self::BATCH_SIZE);
			if ($batch === []) {
				break;
			}
			$cursor = (int)end($batch);
			foreach ($this->roomTasksNeedingWork($house, $batch, $now) as $roomTaskId) {
				try {
					$stats['missed'] += $this->transactions->run(fn (): int => $this->advanceRoomTask($house, $roomTaskId, $now));
					$stats['tasks']++;
				} catch (\Throwable $e) {
					// One task's failure must not block the pass; the next run repeats it.
					$this->appLogger->logException('TimelineJob: cycle advance failed', $e, [
						'event' => 'timeline_cycle_advance_failed',
						'service' => 'CycleAdvanceService',
						'houseId' => $house->getId(),
						'roomTaskId' => $roomTaskId,
					]);
				}
			}
		}
		return $stats;
	}

	/**
	 * @param int[] $roomTaskIds
	 * @return int[] The ones with an unrecorded missed cycle, a wake date that has come or a drifted projection
	 */
	private function roomTasksNeedingWork(House $house, array $roomTaskIds, \DateTime $now): array {
		$timeZone = $this->calendar->getTimeZone($house);
		$roomTasks = $this->roomTaskMapper->findByIdsScopedToHouse($roomTaskIds, (int)$house->getId());
		$tasks = $this->taskMapper->findByIds(array_map(static fn (RoomTask $roomTask): int => $roomTask->getTaskId(), $roomTasks));
		$histories = $this->windows->historiesFor($roomTaskIds);
		$recorded = $this->cycleMapper->latestDayKeyedCentres($roomTaskIds);
		$today = WindowService::todayKey($now, $timeZone);

		$needing = [];
		foreach ($roomTasks as $roomTask) {
			$id = (int)$roomTask->getId();
			$task = $tasks[$roomTask->getTaskId()] ?? null;
			if ($task === null) {
				continue;
			}
			$history = $histories[$id] ?? null;
			$missed = $this->cycles->unrecordedMissed($house, $roomTask, $task, $history, $recorded[$id] ?? null, $timeZone, $now);
			$cycle = $this->windows->currentCycle($roomTask, $task, $history, $timeZone, $now);
			if ($missed !== [] || CycleService::wakesBy($roomTask, $today) || $this->cycles->projectionDrifted($roomTask, $cycle, $timeZone)) {
				$needing[] = $id;
			}
		}
		return $needing;
	}

	/** One room task of the pass inside its own house-first transaction. */
	private function advanceRoomTask(House $house, int $roomTaskId, \DateTime $now): int {
		$house = $this->houseMapper->findForUpdate((int)$house->getId());
		$roomTask = $this->roomTaskMapper->lockForUpdate($roomTaskId);
		$task = $this->taskMapper->find($roomTask->getTaskId());
		$timeZone = $this->calendar->getTimeZone($house);
		if (CycleService::wakesBy($roomTask, WindowService::todayKey($now, $timeZone))) {
			// The fresh cycle hangs on the wake date, also when the job runs late.
			$this->cycles->wakeUnderLock($roomTask, $task, $roomTask->getWakeOn()->format('Y-m-d'), $timeZone, $now);
			return 0;
		}
		$history = $this->windows->historiesFor([$roomTaskId])[$roomTaskId] ?? null;

		$written = $this->cycles->recordMissedUnderLock($house, $roomTask, $task, $history, $timeZone, $now);
		$this->cycles->reprojectUnderLock($roomTask, $this->windows->currentCycle($roomTask, $task, $history, $timeZone, $now), $timeZone);
		return $written;
	}
}
