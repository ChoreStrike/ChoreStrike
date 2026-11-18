<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\Cycle;
use OCA\ChoreStrike\Db\CycleMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\StaleTargetException;
use OCP\IL10N;

/**
 * The writer of cycles (CS-PLAN-091 WP-B1, ADR-021 decisions 3 to 5).
 *
 * {@see WindowService} derives the current cycle; this service is the
 * only place that closes one. A completion closes its cycle with a `done`
 * row in its own transaction, an undo deletes that row, and the timeline
 * job records fixed cycles that stopped being current as `missed`. Every
 * change of the current cycle bumps `room_tasks.cycle_version` and
 * refreshes the `next_due` projection in the same transaction.
 *
 * Methods suffixed `UnderLock` run inside the caller's house-first
 * transaction with the room task row locked, and never begin one.
 */
class CycleService {
	/** Cursor batch size of the timeline job pass. */
	public const JOB_BATCH_SIZE = 200;

	public function __construct(
		private WindowService $windows,
		private CycleMapper $cycleMapper,
		private RoomTaskMapper $roomTaskMapper,
		private TaskMapper $taskMapper,
		private TaskAssigneeMapper $taskAssigneeMapper,
		private HouseUserMapper $houseUserMapper,
		private HouseMapper $houseMapper,
		private CompletedTaskMapper $completedTaskMapper,
		private CalendarService $calendar,
		private TransactionExecutor $transactions,
		private AppLogger $appLogger,
		private IL10N $l10n,
	) {
	}

	/** The current cycle of one room task, read with its completions. */
	public function currentFor(RoomTask $roomTask, Task $task, \DateTimeZone $timeZone, \DateTimeInterface $now): ?CurrentCycle {
		$history = $this->windows->historiesFor([(int)$roomTask->getId()])[(int)$roomTask->getId()] ?? null;
		return $this->windows->currentCycle($roomTask, $task, $history, $timeZone, $now);
	}

	/**
	 * Resolve the cycle a completion names UNDER LOCK (decision 4): it
	 * must be the current one at the version the client showed. A task
	 * can be completed before, inside or after its window, but not on the
	 * house-local day of its latest valid completion. Until WP-B4 brings
	 * the per-person rule of decision 8, that day rule holds for a bonus
	 * task too.
	 *
	 * The fixed cycles that stopped being current before the target are
	 * recorded as missed first: the timeline job may not have reached them,
	 * and once a later cycle is done they would drop out of every count.
	 *
	 * @return CurrentCycle|null Null for a bonus task, which has no cycle
	 * @throws ConflictException `ALREADY_COMPLETED`, `STALE_TARGET` or `SAME_DAY`
	 */
	public function resolveTargetUnderLock(int $houseId, RoomTask $roomTask, Task $task, ?string $cycleKey, int $expectedVersion, \DateTimeZone $timeZone, \DateTime $now): ?CurrentCycle {
		$roomTaskId = (int)$roomTask->getId();
		$stale = new StaleTargetException($this->l10n->t('The task changed since it was displayed. Reload and try again.'));
		if ($task->getIsVoluntary()) {
			if ($cycleKey !== null || $roomTask->getCycleVersion() !== $expectedVersion) {
				throw $stale;
			}
			$this->assertNotDoneToday($this->windows->historiesFor([$roomTaskId])[$roomTaskId] ?? null, $timeZone, $now);
			return null;
		}
		if ($cycleKey !== null && $this->cycleMapper->findByKey($roomTaskId, $cycleKey)?->getOutcome() === Cycle::OUTCOME_DONE) {
			throw new ConflictException($this->l10n->t('This task is already completed'), ConflictException::REASON_ALREADY_COMPLETED);
		}

		$history = $this->windows->historiesFor([$roomTaskId])[$roomTaskId] ?? null;
		$cycle = $this->windows->currentCycle($roomTask, $task, $history, $timeZone, $now);
		if ($cycle === null || $cycle->key !== $cycleKey || $roomTask->getCycleVersion() !== $expectedVersion) {
			throw $stale;
		}
		$this->assertNotDoneToday($history, $timeZone, $now);
		if (self::canMiss($task)) {
			$this->recordMissedUnderLock($this->houseMapper->find($houseId), $roomTask, $task, $history, $timeZone, $now);
		}
		return $cycle;
	}

	/** Only a recurring fixed rule has cycles that can be missed (decision 5). */
	private static function canMiss(Task $task): bool {
		$rule = CycleRule::fromTask($task);
		return $rule->fixed && !$rule->once && !$rule->bonus;
	}

	/** @throws ConflictException `SAME_DAY` when the latest valid completion is from today */
	private function assertNotDoneToday(?CycleHistory $history, \DateTimeZone $timeZone, \DateTime $now): void {
		if ($history?->latestWorkDay === WindowService::todayKey($now, $timeZone)) {
			throw new ConflictException($this->l10n->t('This task was already completed today'), ConflictException::REASON_SAME_DAY);
		}
	}

	/**
	 * Close the cycle a completion fulfilled UNDER LOCK: write its `done`
	 * row, reset the postponement, end a one-time or bounded task after
	 * its last cycle and move on to the next cycle.
	 *
	 * @throws ConflictException `ALREADY_COMPLETED` when the cycle was closed meanwhile
	 */
	public function closeUnderLock(int $houseId, RoomTask $roomTask, Task $task, CurrentCycle $cycle, CompletedTask $completion, \DateTimeZone $timeZone, \DateTime $now): void {
		$row = $this->row($houseId, $roomTask, $cycle, $timeZone, $now);
		$row->setOutcome(Cycle::OUTCOME_DONE);
		$row->setCompletionId((int)$completion->getId());
		$row->setCompletionClass($completion->getCompletionClass());
		$row->setEnergy($completion->getTotalEnergy());
		if (!$this->cycleMapper->insertIfAbsent($row)) {
			throw new ConflictException($this->l10n->t('This task is already completed'), ConflictException::REASON_ALREADY_COMPLETED);
		}

		$roomTask->setCycleKey(null);
		$roomTask->setPostponeCount(0);
		$roomTask->setPostponeDays(0);
		$roomTask->setLastCompleted($now);
		$this->changedUnderLock($roomTask, $task, $timeZone, $now);
	}

	/**
	 * Undo seam UNDER LOCK: the done row of the completion goes, so its
	 * cycle reopens and the current cycle derives again. A completion from
	 * before the derived model has no such row; its stored occurrence is
	 * history and stays as it is — readers count valid completions only.
	 * A task that ended with this completion becomes active again. A fixed
	 * cycle that does not become current again (a later one is done) and
	 * whose window has ended counts as missed (D-091-02).
	 */
	public function reopenUnderLock(int $houseId, RoomTask $roomTask, Task $task, CompletedTask $completion, \DateTimeZone $timeZone, \DateTime $now): void {
		$roomTaskId = (int)$roomTask->getId();
		$closed = self::canMiss($task) ? $this->cycleMapper->findByCompletion($roomTaskId, (int)$completion->getId()) : null;
		$this->cycleMapper->deleteByCompletionId($roomTaskId, (int)$completion->getId());
		$latest = $this->completedTaskMapper->findNewestRevertibleByRoomTask($roomTaskId);
		$roomTask->setLastCompleted($latest?->getCompletedAt());
		if (!$roomTask->getIsActive() && $roomTask->getDeletedAt() === null) {
			$roomTask->setIsActive(true);
		}
		$current = $this->changedUnderLock($roomTask, $task, $timeZone, $now);

		if ($closed === null || !str_starts_with($closed->getCycleKey(), 'd') || $current?->key === $closed->getCycleKey()) {
			return;
		}
		$reopened = new CurrentCycle(
			$closed->getCycleKey(),
			substr($closed->getCycleKey(), 1),
			$closed->getWindowStartOn()->format('Y-m-d'),
			$closed->getWindowEndOn()->format('Y-m-d'),
		);
		$house = $this->houseMapper->find($houseId);
		if ($reopened->isOverdue(WindowService::todayKey($now, $timeZone)) && $this->countsAsMissed($house, $roomTask, $reopened, $timeZone)) {
			$this->insertMissed($houseId, $roomTask, $task, $reopened, $timeZone, $now);
		}
	}

	/**
	 * A new room task UNDER LOCK: its first date is the centre of the
	 * first window and, for a fixed rule, the anchor its days count from.
	 */
	public function startUnderLock(RoomTask $roomTask, Task $task, string $firstDayKey, \DateTimeZone $timeZone, \DateTime $now): ?CurrentCycle {
		$roomTask->setAnchorOn(RecurrenceCalculator::dayKeyToDate($firstDayKey));
		return $this->changedUnderLock($roomTask, $task, $timeZone, $now);
	}

	/**
	 * A definition edit UNDER LOCK (ADR-021 decision 1, D-091-28): open
	 * work follows the edited rule at once. A rule that becomes fixed, or
	 * a fixed rule whose days change, is anchored anew. The new anchor is a
	 * day of the rule it replaces — the centre of that rule's current
	 * cycle — so a fortnightly or monthly rule keeps its parity and its
	 * day; a rule that was not fixed starts from the edit day. A change
	 * between recurring and bounded alone keeps the anchor.
	 */
	public function ruleChangedUnderLock(RoomTask $roomTask, Task $before, Task $after, \DateTimeZone $timeZone, \DateTime $now): ?CurrentCycle {
		$becomesFixed = $after->getSchedulingMode() === Task::SCHEDULING_STATIC && $after->getRecurrenceMode() !== Task::RECURRENCE_ONCE;
		if ($becomesFixed && self::ruleShape($before) !== self::ruleShape($after)) {
			$previous = CycleRule::fromTask($before);
			$replaced = $previous->fixed && !$previous->once ? $this->currentFor($roomTask, $before, $timeZone, $now) : null;
			$roomTask->setAnchorOn(RecurrenceCalculator::dayKeyToDate($replaced?->centreOn ?? WindowService::todayKey($now, $timeZone)));
		}
		return $this->changedUnderLock($roomTask, $after, $timeZone, $now);
	}

	/** The days a rule yields: cadence, mode, one-time or not, fixed days — not flex, bounds or recurring versus bounded. */
	private static function ruleShape(Task $task): array {
		return [
			$task->getFrequencyType(), $task->getFrequencyValue(), $task->getSchedulingMode(),
			$task->getRecurrenceMode() === Task::RECURRENCE_ONCE,
			FlexDaysPolicy::fixedWeekdays(Task::SCHEDULING_STATIC, $task->getFixedScheduleDays()), $task->getFixedMonthDay(),
		];
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
			$cycle = $this->windows->currentCycle($roomTask, $task, $histories[(int)$roomTask->getId()] ?? null, $timeZone, $now);
			if ($this->projectionDrifted($roomTask, $cycle, $timeZone)) {
				$this->project($roomTask, $cycle, $timeZone);
				$this->roomTaskMapper->update($roomTask);
			}
		}
	}

	/**
	 * The current cycle changed UNDER LOCK (a completion, an undo, an edit
	 * of the rule, flex, energy, assignees or first date): bump the cycle
	 * version, end a task that has no cycle left and refresh the `next_due`
	 * projection. Always persists the room task.
	 */
	public function changedUnderLock(RoomTask $roomTask, Task $task, \DateTimeZone $timeZone, \DateTime $now): ?CurrentCycle {
		$roomTask->bumpCycleVersion();
		$cycle = $this->currentFor($roomTask, $task, $timeZone, $now);
		$hasCycles = !$task->getIsVoluntary() && $roomTask->getSleepingSince() === null;
		if ($cycle === null && $hasCycles && $roomTask->getIsActive()) {
			// A one-time task that is done, or a bounded rule after its last cycle.
			$roomTask->setIsActive(false);
		}
		$this->project($roomTask, $cycle, $timeZone);
		$this->roomTaskMapper->update($roomTask);
		return $cycle;
	}

	/**
	 * The timeline job's pass over the one household of the instance: see
	 * {@see advanceHouse}.
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
	 * What the passing of time changes in one house (ADR-021 decisions 3
	 * and 5): a fixed-day cycle whose window ended without the work is
	 * recorded as missed, exactly once, and the `next_due` projection
	 * follows the cycle that is current now. Write-free for a room task
	 * that needs nothing; every other one in its own house-first
	 * transaction.
	 *
	 * @return array{tasks: int, missed: int}
	 */
	public function advanceHouse(House $house, \DateTime $now): array {
		$stats = ['tasks' => 0, 'missed' => 0];
		$cursor = 0;
		while (true) {
			$batch = $this->roomTaskMapper->findActiveIdBatchAfter((int)$house->getId(), $cursor, self::JOB_BATCH_SIZE);
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
						'service' => 'CycleService',
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
	 * @return int[] The ones with an unrecorded missed cycle or a drifted projection
	 */
	private function roomTasksNeedingWork(House $house, array $roomTaskIds, \DateTime $now): array {
		$houseId = (int)$house->getId();
		$timeZone = $this->calendar->getTimeZone($house);
		$roomTasks = $this->roomTaskMapper->findByIdsScopedToHouse($roomTaskIds, $houseId);
		$tasks = $this->taskMapper->findByIds(array_map(static fn (RoomTask $roomTask): int => $roomTask->getTaskId(), $roomTasks));
		$histories = $this->windows->historiesFor($roomTaskIds);
		$recorded = $this->cycleMapper->latestDayKeyedCentres($roomTaskIds);

		$needing = [];
		foreach ($roomTasks as $roomTask) {
			$id = (int)$roomTask->getId();
			$task = $tasks[$roomTask->getTaskId()] ?? null;
			if ($task === null) {
				continue;
			}
			$history = $histories[$id] ?? null;
			$missed = $this->unrecordedMissed($house, $roomTask, $task, $history, $recorded[$id] ?? null, $timeZone, $now);
			$cycle = $this->windows->currentCycle($roomTask, $task, $history, $timeZone, $now);
			if ($missed !== [] || $this->projectionDrifted($roomTask, $cycle, $timeZone)) {
				$needing[] = $id;
			}
		}
		return $needing;
	}

	/** One room task of the job pass inside its own house-first transaction. */
	private function advanceRoomTask(House $house, int $roomTaskId, \DateTime $now): int {
		$houseId = (int)$house->getId();
		$house = $this->houseMapper->findForUpdate($houseId);
		$roomTask = $this->roomTaskMapper->lockForUpdate($roomTaskId);
		$task = $this->taskMapper->find($roomTask->getTaskId());
		$timeZone = $this->calendar->getTimeZone($house);
		$history = $this->windows->historiesFor([$roomTaskId])[$roomTaskId] ?? null;

		$written = $this->recordMissedUnderLock($house, $roomTask, $task, $history, $timeZone, $now);
		$cycle = $this->windows->currentCycle($roomTask, $task, $history, $timeZone, $now);
		if ($this->projectionDrifted($roomTask, $cycle, $timeZone)) {
			$this->project($roomTask, $cycle, $timeZone);
			$this->roomTaskMapper->update($roomTask);
		}
		return $written;
	}

	/**
	 * Record UNDER LOCK the fixed cycles of one room task that stopped
	 * being current without the work (decision 5), exactly once each.
	 *
	 * @return int Rows written
	 */
	private function recordMissedUnderLock(House $house, RoomTask $roomTask, Task $task, ?CycleHistory $history, \DateTimeZone $timeZone, \DateTime $now): int {
		$roomTaskId = (int)$roomTask->getId();
		$recorded = $this->cycleMapper->latestDayKeyedCentres([$roomTaskId])[$roomTaskId] ?? null;
		$written = 0;
		$responsible = null;
		foreach ($this->unrecordedMissed($house, $roomTask, $task, $history, $recorded, $timeZone, $now) as $cycle) {
			$responsible ??= $this->responsibleWalletIds((int)$house->getId(), $roomTaskId);
			$written += $this->insertMissed((int)$house->getId(), $roomTask, $task, $cycle, $timeZone, $now, $responsible) ? 1 : 0;
		}
		return $written;
	}

	/**
	 * Missed cycles the derived model owns (see {@see countsAsMissed()}).
	 *
	 * @return list<CurrentCycle>
	 */
	private function unrecordedMissed(House $house, RoomTask $roomTask, Task $task, ?CycleHistory $history, ?string $recorded, \DateTimeZone $timeZone, \DateTime $now): array {
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
	private function countsAsMissed(House $house, RoomTask $roomTask, CurrentCycle $cycle, \DateTimeZone $timeZone): bool {
		$from = $house->getCyclesFrom()?->format('Y-m-d') ?? '0000-00-00';
		$created = $roomTask->getCreatedAt() !== null ? WindowService::todayKey($roomTask->getCreatedAt(), $timeZone) : '0000-00-00';
		return $cycle->endOn >= max($from, $created);
	}

	/**
	 * @param list<int>|null $responsible Wallet ids when the caller resolved them
	 * @return bool Whether the row was written (false: the cycle was closed already)
	 */
	private function insertMissed(int $houseId, RoomTask $roomTask, Task $task, CurrentCycle $cycle, \DateTimeZone $timeZone, \DateTime $now, ?array $responsible = null): bool {
		$row = $this->row($houseId, $roomTask, $cycle, $timeZone, $now, $responsible);
		$row->setOutcome(Cycle::OUTCOME_MISSED);
		$row->setEnergy(max(1, (int)$task->getEnergyCost()));
		return $this->cycleMapper->insertIfAbsent($row);
	}

	/** @param list<int>|null $responsible Wallet ids when the caller resolved them */
	private function row(int $houseId, RoomTask $roomTask, CurrentCycle $cycle, \DateTimeZone $timeZone, \DateTime $now, ?array $responsible = null): Cycle {
		$row = new Cycle();
		$row->setHouseId($houseId);
		$row->setRoomTaskId((int)$roomTask->getId());
		$row->setCycleKey($cycle->key);
		$row->setWindowStartOn(RecurrenceCalculator::dayKeyToDate($cycle->startOn));
		$row->setWindowEndOn(RecurrenceCalculator::dayKeyToDate($cycle->endOn));
		$row->setWeekStart(RecurrenceCalculator::dayKeyToDate($cycle->weekStart()));
		$row->setTimezone($timeZone->getName());
		$row->setResponsible($responsible ?? $this->responsibleWalletIds($houseId, (int)$roomTask->getId()));
		$row->setCreatedAt($now);
		return $row;
	}

	/**
	 * A cycle counts for the room task's assignees when it closes.
	 *
	 * @return list<int> Wallet ids, ascending; empty for an unassigned task
	 */
	private function responsibleWalletIds(int $houseId, int $roomTaskId): array {
		$userIds = $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTaskId);
		if ($userIds === []) {
			return [];
		}
		$walletIds = [];
		foreach ($this->houseUserMapper->findByHouseIdAndNextcloudUserIds($houseId, $userIds) as $member) {
			if ($member->getWalletId() !== null) {
				$walletIds[] = (int)$member->getWalletId();
			}
		}
		sort($walletIds);
		return $walletIds;
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

	private function projectionDrifted(RoomTask $roomTask, ?CurrentCycle $cycle, \DateTimeZone $timeZone): bool {
		$projection = $this->projection($cycle, $timeZone);
		return $projection !== null && (
			$roomTask->getNextDue()?->getTimestamp() !== $projection[0]->getTimestamp()
			|| $roomTask->getIdealDueDate()?->getTimestamp() !== $projection[1]->getTimestamp()
		);
	}

	private function project(RoomTask $roomTask, ?CurrentCycle $cycle, \DateTimeZone $timeZone): void {
		$projection = $this->projection($cycle, $timeZone);
		if ($projection !== null) {
			$roomTask->setNextDue($projection[0]);
			$roomTask->setIdealDueDate($projection[1]);
		}
	}
}
