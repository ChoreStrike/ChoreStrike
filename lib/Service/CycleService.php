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
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\StaleTargetException;
use OCP\IL10N;

/**
 * The writer of cycles (CS-PLAN-091 WP-B1, ADR-021 decisions 3 to 5).
 *
 * {@see WindowService} derives the current cycle; this service is the
 * only place that closes one, with {@see CycleMisses} for its rows and
 * {@see CycleProjection} for the cycle version and `next_due`. A completion closes its cycle with a `done`
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
		private CycleProjection $projection,
		private CycleMisses $misses,
		private CycleSleep $sleep,
		private CycleMapper $cycleMapper,
		private RoomTaskMapper $roomTaskMapper,
		private HouseMapper $houseMapper,
		private CompletedTaskMapper $completedTaskMapper,
		private IL10N $l10n,
	) {
	}

	/**
	 * Resolve the cycle a completion names UNDER LOCK (decision 4): it
	 * must be the current one at the version the client showed. A task
	 * can be completed before, inside or after its window, but not on the
	 * house-local day of its latest valid completion.
	 *
	 * A bonus task and a sleeping task have no cycle to name: the client
	 * sends the version alone. A bonus task has no day rule here; whom it
	 * may still pay today is the completion's question (decision 8). A
	 * sleeping task wakes with the completion, which fulfils the fresh
	 * cycle (D-091-22).
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
		$named = CycleProjection::hasCycles($roomTask, $task);
		if (CycleProjection::isAwakeBonus($roomTask, $task)) {
			$this->assertTarget(null, $roomTask, $cycleKey, $expectedVersion);
			return null;
		}
		// One completion read, also for the wake that comes first.
		$histories = $this->windows->historiesFor([$roomTaskId]);
		if (!$named) {
			$this->assertTarget(null, $roomTask, $cycleKey, $expectedVersion);
			$this->sleep->wakeUnderLock($roomTask, $task, WindowService::todayKey($now, $timeZone), $timeZone, $now, $histories);
			if ($task->getIsVoluntary()) {
				return null;
			}
		} elseif ($cycleKey !== null && $this->cycleMapper->findByKey($roomTaskId, $cycleKey)?->getOutcome() === Cycle::OUTCOME_DONE) {
			throw new ConflictException($this->l10n->t('This task is already completed'), ConflictException::REASON_ALREADY_COMPLETED);
		}

		$history = $histories[$roomTaskId] ?? null;
		$cycle = $this->windows->currentCycle($roomTask, $task, $history, $timeZone, $now);
		if ($named) {
			$this->assertTarget($cycle, $roomTask, $cycleKey, $expectedVersion);
		} elseif ($cycle === null) {
			// The woken rule has no cycle left: a bounded rule ended while the
			// task slept. Waking it closes it; nothing is left to complete.
			throw new ConflictException($this->l10n->t('This task has ended. Wake it up to close it.'), ConflictException::REASON_TASK_ENDED);
		}
		$this->assertNotDoneToday($history, $timeZone, $now);
		if (CycleMisses::canMiss($task)) {
			$this->misses->recordMissedUnderLock($this->houseMapper->find($houseId), $roomTask, $task, $history, $timeZone, $now);
		}
		return $cycle;
	}

	/**
	 * The one stale-target check (decision 2): the key the client showed is
	 * the current cycle's — none for a task without a cycle to name — at
	 * the cycle version it showed. A command that names the room task alone
	 * (sleep, the one form's save) passes no cycle and no key.
	 *
	 * @throws StaleTargetException
	 */
	public function assertTarget(?CurrentCycle $cycle, RoomTask $roomTask, ?string $cycleKey, int $expectedVersion): void {
		if ($cycle?->key !== $cycleKey || $roomTask->getCycleVersion() !== $expectedVersion) {
			throw new StaleTargetException($this->l10n->t('The task changed since it was displayed. Reload and try again.'));
		}
	}

	/**
	 * The completions of a bonus task moved UNDER LOCK (a completion, an
	 * undo, a date correction): it has no cycle, so nothing derives and its
	 * version stays — several people complete it on one day (decision 8,
	 * D-091-84). Only its last-completed mark follows.
	 */
	public function bonusDoneUnderLock(RoomTask $roomTask, ?\DateTime $lastCompleted): void {
		$roomTask->setLastCompleted($lastCompleted);
		$this->roomTaskMapper->update($roomTask);
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
		$row = $this->misses->row($houseId, $roomTask, $cycle, $timeZone, $now);
		$row->setOutcome(Cycle::OUTCOME_DONE);
		$row->setCompletionId((int)$completion->getId());
		$row->setCompletionClass($completion->getCompletionClass());
		$row->setEnergy($completion->getTotalEnergy());
		if (!$this->cycleMapper->insertIfAbsent($row)) {
			throw new ConflictException($this->l10n->t('This task is already completed'), ConflictException::REASON_ALREADY_COMPLETED);
		}

		$roomTask->setLastCompleted($now);
		$this->projection->changedUnderLock($roomTask, $task, $timeZone, $now);
	}

	/**
	 * Undo seam UNDER LOCK: the done row of the completion goes, so its
	 * cycle reopens and the current cycle derives again. A completion from
	 * before the derived model has no such row; its stored occurrence is
	 * history and stays as it is — readers count valid completions only.
	 * A task that ended with this completion becomes active again. A fixed
	 * cycle that does not become current again (a later one is done) and
	 * whose window has ended counts as missed (D-091-02); one that becomes
	 * current again — the later entry was undone too — is open work, so a
	 * miss an earlier undo recorded for it goes (final review of
	 * CS-PLAN-091).
	 */
	public function reopenUnderLock(int $houseId, RoomTask $roomTask, Task $task, CompletedTask $completion, \DateTimeZone $timeZone, \DateTime $now): void {
		$roomTaskId = (int)$roomTask->getId();
		$closed = CycleMisses::canMiss($task) ? $this->cycleMapper->findByCompletion($roomTaskId, (int)$completion->getId()) : null;
		$this->cycleMapper->deleteByCompletionId($roomTaskId, (int)$completion->getId());
		$latest = $this->completedTaskMapper->findNewestRevertibleByRoomTask($roomTaskId);
		$roomTask->setLastCompleted($latest?->getCompletedAt());
		if (CycleProjection::isAwakeBonus($roomTask, $task)) {
			$this->roomTaskMapper->update($roomTask);
			return;
		}
		if (!$roomTask->getIsActive() && $roomTask->getDeletedAt() === null) {
			$roomTask->setIsActive(true);
		}
		$current = $this->projection->changedUnderLock($roomTask, $task, $timeZone, $now);
		if ($current !== null && CycleMisses::canMiss($task)) {
			$this->cycleMapper->deleteMissed($roomTaskId, $current->key);
		}

		$day = $closed === null ? null : CurrentCycle::dayOfKey($closed->getCycleKey());
		if ($day === null || $current?->key === $closed->getCycleKey()) {
			return;
		}
		$reopened = new CurrentCycle(
			$closed->getCycleKey(),
			$day,
			$closed->getWindowStartOn()->format('Y-m-d'),
			$closed->getWindowEndOn()->format('Y-m-d'),
		);
		$house = $this->houseMapper->find($houseId);
		if ($reopened->isOverdue(WindowService::todayKey($now, $timeZone)) && $this->misses->countsAsMissed($house, $roomTask, $reopened, $timeZone)) {
			$this->misses->insertMissed($houseId, $roomTask, $task, $reopened, $timeZone, $now);
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
		// A postponement moves no completion: one read serves both derivations.
		$histories = $this->windows->historiesFor([(int)$roomTask->getId()]);
		$cycle = $this->projection->currentFor($roomTask, $task, $timeZone, $now, $histories);
		if ($cycle === null) {
			throw new StaleTargetException($this->l10n->t('The task changed since it was displayed. Reload and try again.'));
		}
		$this->assertTarget($cycle, $roomTask, $cycleKey, $expectedVersion);
		$refusal = WindowService::postponeRefusal($task, $cycle, WindowService::todayKey($now, $timeZone));
		if ($refusal !== null) {
			throw new ConflictException($refusal === ConflictException::REASON_WINDOW_ENDED
				? $this->l10n->t('An overdue task cannot be postponed')
				: $this->l10n->t('This task cannot be postponed any further'), $refusal);
		}
		$roomTask->setCycleKey($cycle->key);
		$roomTask->setPostponeCount($cycle->postponeCount + 1);
		$roomTask->setPostponeDays($cycle->postponeDays + WindowService::postponeStep((int)$task->getFlexDays()));
		return $this->projection->changedUnderLock($roomTask, $task, $timeZone, $now, $histories);
	}

	/**
	 * A date correction UNDER LOCK (decision 9), after the completion side
	 * saved the entry with its new day and classification and moved the
	 * frozen window of the entry that followed it under an after-completion
	 * rule. The closed cycles project those frozen facts again, and the
	 * current cycle derives anew.
	 *
	 * @param CompletedTask|null $follower The entry whose window moved with the corrected day
	 */
	public function redatedUnderLock(RoomTask $roomTask, Task $task, CompletedTask $completion, ?CompletedTask $follower, \DateTimeZone $timeZone, \DateTime $now): ?CurrentCycle {
		$roomTaskId = (int)$roomTask->getId();
		foreach (array_filter([$completion, $follower]) as $entry) {
			$this->projectClosed($roomTaskId, $entry);
		}

		$roomTask->setLastCompleted($this->completedTaskMapper->findNewestRevertibleByRoomTask($roomTaskId)?->getCompletedAt());
		if (CycleProjection::isAwakeBonus($roomTask, $task)) {
			$this->roomTaskMapper->update($roomTask);
			return null;
		}
		// As the undo: a bounded task that ended may have a cycle again on
		// the corrected day; the derivation below ends it again if not.
		if (!$roomTask->getIsActive() && $roomTask->getDeletedAt() === null) {
			$roomTask->setIsActive(true);
		}
		return $this->projection->changedUnderLock($roomTask, $task, $timeZone, $now);
	}

	/**
	 * The done row of a completion only projects the cycle facts frozen on
	 * it: bring the row in line after a correction changed them. An entry
	 * from before the derived model has no row.
	 */
	private function projectClosed(int $roomTaskId, CompletedTask $completion): void {
		$row = $this->cycleMapper->findByCompletion($roomTaskId, (int)$completion->getId());
		if ($row === null || $completion->getWindowEndOn() === null) {
			return;
		}
		$startOn = $completion->getWindowStartOn()->format('Y-m-d');
		$endOn = $completion->getWindowEndOn()->format('Y-m-d');
		if ($row->getWindowStartOn()->format('Y-m-d') === $startOn && $row->getWindowEndOn()->format('Y-m-d') === $endOn
			&& $row->getCompletionClass() === $completion->getCompletionClass()) {
			return;
		}
		$row->setWindowStartOn(RecurrenceCalculator::dayKeyToDate($startOn));
		$row->setWindowEndOn(RecurrenceCalculator::dayKeyToDate($endOn));
		$row->setWeekStart(RecurrenceCalculator::dayKeyToDate(RecurrenceCalculator::mondayOf($endOn)));
		$row->setCompletionClass($completion->getCompletionClass());
		$this->cycleMapper->update($row);
	}

	/**
	 * A first date UNDER LOCK: it is the centre of the first window and,
	 * for a fixed rule, the anchor its days count from.
	 *
	 * @param array<int, CycleHistory>|null $histories Completion inputs the caller read already; `[]` for a room
	 *                                                 task this transaction created, which has none
	 */
	public function startUnderLock(RoomTask $roomTask, Task $task, string $firstDayKey, \DateTimeZone $timeZone, \DateTime $now, ?array $histories = null): ?CurrentCycle {
		$roomTask->setAnchorOn(RecurrenceCalculator::dayKeyToDate($firstDayKey));
		return $this->projection->changedUnderLock($roomTask, $task, $timeZone, $now, $histories);
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
		if ($before->getIsVoluntary() && !$after->getIsVoluntary()) {
			// A bonus task starts producing cycles on the edit day, never from
			// an anchor its bonus time left behind: those days count no miss
			// (D-091-81).
			$roomTask->setAnchorOn(RecurrenceCalculator::dayKeyToDate(WindowService::todayKey($now, $timeZone)));
		} elseif ($becomesFixed && self::ruleShape($before) !== self::ruleShape($after)) {
			$previous = CycleRule::fromTask($before);
			$replaced = $previous->fixed && !$previous->once ? $this->projection->currentFor($roomTask, $before, $timeZone, $now, $histories) : null;
			$roomTask->setAnchorOn(RecurrenceCalculator::dayKeyToDate($replaced?->centreOn ?? WindowService::todayKey($now, $timeZone)));
		}
		return $this->projection->changedUnderLock($roomTask, $after, $timeZone, $now, $histories);
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

}
