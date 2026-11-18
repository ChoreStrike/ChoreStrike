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
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\StaleTargetException;
use OCP\IL10N;

/**
 * The writer of cycles (CS-PLAN-091 WP-B1, ADR-021 decisions 3 to 5).
 *
 * {@see WindowService} derives the current cycle; this service is the
 * only place that closes one. A completion closes its cycle with a `done`
 * row in its own transaction, an undo deletes that row, a date correction
 * re-derives it, and fixed cycles that stopped being current are recorded
 * as `missed` (the timeline job's pass is {@see CycleAdvanceService}).
 * Every change of the current cycle bumps `room_tasks.cycle_version` and
 * refreshes the `next_due` projection in the same transaction.
 *
 * Methods suffixed `UnderLock` run inside the caller's house-first
 * transaction with the room task row locked, and never begin one.
 */
class CycleService {
	public function __construct(
		private WindowService $windows,
		private CycleMapper $cycleMapper,
		private RoomTaskMapper $roomTaskMapper,
		private TaskMapper $taskMapper,
		private TaskResponsibility $responsibility,
		private HouseMapper $houseMapper,
		private CompletedTaskMapper $completedTaskMapper,
		private CalendarService $calendar,
		private IL10N $l10n,
	) {
	}

	/**
	 * The current cycle of one room task, read with its completions.
	 *
	 * @param array<int, CycleHistory>|null $histories Completion inputs a caller read for several room tasks at once
	 */
	public function currentFor(RoomTask $roomTask, Task $task, \DateTimeZone $timeZone, \DateTimeInterface $now, ?array $histories = null): ?CurrentCycle {
		$histories ??= $this->windows->historiesFor([(int)$roomTask->getId()]);
		return $this->windows->currentCycle($roomTask, $task, $histories[(int)$roomTask->getId()] ?? null, $timeZone, $now);
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
	 * row, end a one-time or bounded task after its last cycle and move on
	 * to the next cycle. The postponement stays stored with its cycle key:
	 * the next cycle has another key and starts without one, and an undo
	 * reopens this cycle as it was postponed, its count included (D-091-57).
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
	 * Postpone the current cycle UNDER LOCK (decision 6): its window moves
	 * by half the flex days, rounded up, at most three times. The next
	 * cycle is not affected, because the postponement is stored for this
	 * cycle key only.
	 *
	 * @throws ConflictException `STALE_TARGET`, `WINDOW_ENDED` or `NOT_POSTPONABLE`
	 */
	public function postponeUnderLock(RoomTask $roomTask, Task $task, string $cycleKey, int $expectedVersion, \DateTimeZone $timeZone, \DateTime $now): CurrentCycle {
		$cycle = $this->currentFor($roomTask, $task, $timeZone, $now);
		if ($cycle === null || $cycle->key !== $cycleKey || $roomTask->getCycleVersion() !== $expectedVersion) {
			throw new StaleTargetException($this->l10n->t('The task changed since it was displayed. Reload and try again.'));
		}
		if ($cycle->isOverdue(WindowService::todayKey($now, $timeZone))) {
			throw new ConflictException($this->l10n->t('An overdue task cannot be postponed'), ConflictException::REASON_WINDOW_ENDED);
		}
		$step = WindowService::postponeStep((int)$task->getFlexDays());
		if ($step === 0 || $cycle->postponeCount >= WindowService::MAX_POSTPONEMENTS) {
			throw new ConflictException($this->l10n->t('This task cannot be postponed any further'), ConflictException::REASON_NOT_POSTPONABLE);
		}
		$roomTask->setCycleKey($cycle->key);
		$roomTask->setPostponeCount($cycle->postponeCount + 1);
		$roomTask->setPostponeDays($cycle->postponeDays + $step);
		return $this->changedUnderLock($roomTask, $task, $timeZone, $now);
	}

	/**
	 * A date correction UNDER LOCK (decision 9), after the entry itself was
	 * saved with its new day and its re-derived classification. Its cycle
	 * row takes that classification. Under an after-completion rule the
	 * cycle that followed the entry hangs on its day: that cycle's frozen
	 * window moves with the corrected day and decides its classification
	 * again. The current cycle derives anew.
	 *
	 * @param string $previousDay The entry's work day before the correction
	 */
	public function redatedUnderLock(RoomTask $roomTask, Task $task, CompletedTask $completion, string $previousDay, \DateTimeZone $timeZone, \DateTime $now): ?CurrentCycle {
		$roomTaskId = (int)$roomTask->getId();
		$own = $this->cycleMapper->findByCompletion($roomTaskId, (int)$completion->getId());
		if ($own !== null && $own->getCompletionClass() !== $completion->getCompletionClass()) {
			$own->setCompletionClass($completion->getCompletionClass());
			$this->cycleMapper->update($own);
		}

		$follower = $this->completedTaskMapper->findValidByCycleKey($roomTaskId, 'c' . $completion->getId());
		$shift = $this->windows->shiftAfterCompletion($task, $previousDay, $completion->getWorkDate()->format('Y-m-d'));
		if ($follower !== null && $follower->getWindowEndOn() !== null && $shift !== 0) {
			$this->moveClosedWindow($roomTaskId, $follower, $shift);
		}

		$roomTask->setLastCompleted($this->completedTaskMapper->findNewestRevertibleByRoomTask($roomTaskId)?->getCompletedAt());
		// As the undo: a bounded task that ended may have a cycle again on
		// the corrected day; the derivation below ends it again if not.
		if (!$roomTask->getIsActive() && $roomTask->getDeletedAt() === null) {
			$roomTask->setIsActive(true);
		}
		return $this->changedUnderLock($roomTask, $task, $timeZone, $now);
	}

	/** Move the frozen window of a closed cycle and of its completion, and classify the completion against it. */
	private function moveClosedWindow(int $roomTaskId, CompletedTask $completion, int $shiftDays): void {
		$startOn = RecurrenceCalculator::offsetDays($completion->getWindowStartOn()->format('Y-m-d'), $shiftDays);
		$endOn = RecurrenceCalculator::offsetDays($completion->getWindowEndOn()->format('Y-m-d'), $shiftDays);
		$completion->setWindowStartOn(RecurrenceCalculator::dayKeyToDate($startOn));
		$completion->setWindowEndOn(RecurrenceCalculator::dayKeyToDate($endOn));
		$completion->setCompletionClass($completion->classOn($completion->getWorkDate()->format('Y-m-d')));
		$this->completedTaskMapper->update($completion);

		$row = $this->cycleMapper->findByCompletion($roomTaskId, (int)$completion->getId());
		if ($row !== null) {
			$row->setWindowStartOn(RecurrenceCalculator::dayKeyToDate($startOn));
			$row->setWindowEndOn(RecurrenceCalculator::dayKeyToDate($endOn));
			$row->setWeekStart(RecurrenceCalculator::dayKeyToDate(RecurrenceCalculator::mondayOf($endOn)));
			$row->setCompletionClass($completion->getCompletionClass());
			$this->cycleMapper->update($row);
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
	 *
	 * @param array<int, CycleHistory>|null $histories {@see historiesFor()}
	 */
	public function ruleChangedUnderLock(RoomTask $roomTask, Task $before, Task $after, \DateTimeZone $timeZone, \DateTime $now, ?array $histories = null): ?CurrentCycle {
		$becomesFixed = $after->getSchedulingMode() === Task::SCHEDULING_STATIC && $after->getRecurrenceMode() !== Task::RECURRENCE_ONCE;
		if ($becomesFixed && self::ruleShape($before) !== self::ruleShape($after)) {
			$previous = CycleRule::fromTask($before);
			$replaced = $previous->fixed && !$previous->once ? $this->currentFor($roomTask, $before, $timeZone, $now, $histories) : null;
			$roomTask->setAnchorOn(RecurrenceCalculator::dayKeyToDate($replaced?->centreOn ?? WindowService::todayKey($now, $timeZone)));
		}
		return $this->changedUnderLock($roomTask, $after, $timeZone, $now, $histories);
	}

	/**
	 * The completion inputs of several room tasks in one read, for a
	 * command that moves all of them (a definition edit).
	 *
	 * @param int[] $roomTaskIds
	 * @return array<int, CycleHistory>
	 */
	public function historiesFor(array $roomTaskIds): array {
		return $this->windows->historiesFor($roomTaskIds);
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
			$this->reprojectUnderLock($roomTask, $this->windows->currentCycle($roomTask, $task, $histories[(int)$roomTask->getId()] ?? null, $timeZone, $now), $timeZone);
		}
	}

	/**
	 * The current cycle changed UNDER LOCK (a completion, an undo, an edit
	 * of the rule, flex, energy, assignees or first date): bump the cycle
	 * version, end a task that has no cycle left and refresh the `next_due`
	 * projection. Always persists the room task.
	 *
	 * @param array<int, CycleHistory>|null $histories {@see historiesFor()}
	 */
	public function changedUnderLock(RoomTask $roomTask, Task $task, \DateTimeZone $timeZone, \DateTime $now, ?array $histories = null): ?CurrentCycle {
		$roomTask->bumpCycleVersion();
		$cycle = $this->currentFor($roomTask, $task, $timeZone, $now, $histories);
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
		$row->setResponsible($responsible ?? $this->responsibility->walletIdsOf($houseId, (int)$roomTask->getId()));
		$row->setCreatedAt($now);
		return $row;
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

	private function project(RoomTask $roomTask, ?CurrentCycle $cycle, \DateTimeZone $timeZone): void {
		$projection = $this->projection($cycle, $timeZone);
		if ($projection !== null) {
			$roomTask->setNextDue($projection[0]);
			$roomTask->setIdealDueDate($projection[1]);
		}
	}
}
