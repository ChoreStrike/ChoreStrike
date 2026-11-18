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
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\OccurrenceMember;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\WorkShareMapper;

/**
 * The one reader of what the recognitions count (CS-PLAN-091 WP-B1,
 * ADR-021 decisions 5 and 12). Pure reads.
 *
 * Two sources, split at the house's `cycles_from` day so that nothing
 * counts twice and no past week is evaluated differently (D-091-04):
 *
 * - **Stored occurrences** from before the derived model: those whose
 *   window ended before `cycles_from`, and those a completion from before
 *   it fulfilled. They are read as stored and never rewritten; open ones
 *   that reach past the cutover are left to the derived cycle.
 * - **Cycles** from `cycles_from` on: closed rows (`done`, `missed`) and
 *   the open cycle of every active room task. A cycle counts in the week
 *   its final window ends and for the assignees it had when it closed; an
 *   open red cycle counts as missed while it is open.
 *
 * Absence is neutral (D-091-03): a window that lies entirely inside an
 * absence of a responsible member is neither kept nor missed for that
 * member, decided on read ({@see PauseProjectionService}).
 *
 * An evaluation that reads several weeks or members begins a
 * {@see RecognitionPass} and hands it to every read: the open inputs and the
 * absences are then loaded once instead of once per week.
 */
class RecognitionFactsService {
	public function __construct(
		private OccurrenceMapper $occurrenceMapper,
		private OccurrenceMemberMapper $occMemberMapper,
		private CompletedTaskMapper $completedTaskMapper,
		private WorkShareMapper $workShareMapper,
		private CycleMapper $cycleMapper,
		private RoomTaskMapper $roomTaskMapper,
		private TaskMapper $taskMapper,
		private TaskResponsibility $responsibility,
		private PauseProjectionService $pauseProjection,
		private WindowService $windows,
		private CalendarService $calendar,
	) {
	}

	/**
	 * The inputs of one evaluation pass, read once: five queries whatever
	 * the number of weeks and members the pass then evaluates.
	 */
	public function beginPass(House $house): RecognitionPass {
		$houseId = (int)$house->getId();
		$roomTasks = $this->roomTaskMapper->findByHouse($houseId, true);
		$ids = array_map(static fn (RoomTask $roomTask): int => (int)$roomTask->getId(), $roomTasks);
		return new RecognitionPass(
			$this->pauseProjection->recognizedForHouse($houseId),
			$roomTasks,
			$this->taskMapper->findByIds(array_values(array_unique(array_map(static fn (RoomTask $roomTask): int => $roomTask->getTaskId(), $roomTasks)))),
			$this->windows->historiesFor($ids),
			$this->responsibility->walletIdsByRoomTask($houseId, $ids),
		);
	}

	/**
	 * Everything that counts in one house-local week (Monday to Sunday),
	 * from both sources.
	 *
	 * @param string $weekKey The week's Monday (`Y-m-d`)
	 * @return list<RecognitionFact>
	 */
	public function factsForWeek(House $house, string $weekKey, \DateTime $now, ?RecognitionPass $pass = null): array {
		$endKey = RecurrenceCalculator::offsetDays($weekKey, 6);
		$pass ??= $this->beginPass($house);
		return $pass->week($weekKey, fn (): array => [
			...$this->storedFacts($house, $weekKey, $endKey, $now),
			...$this->closedFacts($this->cycleMapper->findByHouseEndingBetween((int)$house->getId(), max($weekKey, $this->cyclesFrom($house)), $endKey), $pass->absences),
			...$this->openFacts($house, $now, $weekKey, $endKey, true, $pass),
		]);
	}

	/**
	 * One page of closed cycles in `(window end, id)` order, for the
	 * rhythm's lifetime scan from `cycles_from` on.
	 *
	 * @param RecognitionPass|null $pass The scan's pass; without one the absences are read per page
	 * @return array{facts: list<RecognitionFact>, lastDay: ?string, lastId: int, count: int}
	 */
	public function closedFactsPage(House $house, ?string $afterDay, int $afterId, int $limit, ?RecognitionPass $pass = null): array {
		$rows = $this->cycleMapper->findClosedPage((int)$house->getId(), $this->cyclesFrom($house), $afterDay, $afterId, $limit);
		$last = $rows === [] ? null : $rows[count($rows) - 1];
		return [
			'facts' => $this->closedFacts($rows, $pass?->absences ?? $this->pauseProjection->recognizedForHouse((int)$house->getId())),
			'lastDay' => $last?->getWindowEndOn()->format('Y-m-d'),
			'lastId' => (int)($last?->getId() ?? $afterId),
			'count' => count($rows),
		];
	}

	/**
	 * The open cycle of every active room task whose window ends inside
	 * the given days (default: any), plus — for a week view — the fixed
	 * cycles its rule yields after it inside the range.
	 *
	 * @param RecognitionPass|null $pass The evaluation's pass; without one the inputs are read here
	 * @return list<RecognitionFact>
	 */
	public function openFacts(House $house, \DateTime $now, ?string $fromKey = null, ?string $toKey = null, bool $withUpcoming = false, ?RecognitionPass $pass = null): array {
		$fromKey = max($fromKey ?? '', $this->cyclesFrom($house));
		$pass ??= $this->beginPass($house);
		$roomTasks = $pass->roomTasks;
		if ($roomTasks === []) {
			return [];
		}
		$absences = $pass->absences;
		$tz = $this->calendar->getTimeZone($house);
		$today = WindowService::todayKey($now, $tz);
		$tasks = $pass->tasks;
		$histories = $pass->histories;
		$responsible = $pass->responsible;

		$facts = [];
		foreach ($roomTasks as $roomTask) {
			$task = $tasks[$roomTask->getTaskId()] ?? null;
			if ($task === null) {
				continue;
			}
			$id = (int)$roomTask->getId();
			$cycles = $withUpcoming && $toKey !== null
				? $this->windows->cyclesThrough($roomTask, $task, $histories[$id] ?? null, $tz, $now, $toKey, $fromKey)
				: array_filter([$this->windows->currentCycle($roomTask, $task, $histories[$id] ?? null, $tz, $now)]);
			foreach (array_values($cycles) as $index => $cycle) {
				if ($cycle->endOn < $fromKey || ($toKey !== null && $cycle->endOn > $toKey) || str_starts_with($cycle->key, 'p')) {
					continue;
				}
				$state = match (true) {
					$index === 0 && $cycle->isOverdue($today) => RecognitionFact::MISSED,
					$index === 0 && $cycle->startOn <= $today => RecognitionFact::OPEN,
					default => RecognitionFact::UPCOMING,
				};
				$facts[] = $this->cycleFact($cycle->startOn, $cycle->endOn, $state, $responsible[$id] ?? [], [], $absences, $id);
			}
		}
		return $facts;
	}

	/**
	 * Which of the given room tasks have a household-public definition
	 * (the ranking counts public work only, ADR-015).
	 *
	 * @param int[] $roomTaskIds
	 * @return array<int, true>
	 */
	public function publicRoomTaskIds(int $houseId, array $roomTaskIds): array {
		$roomTasks = $this->roomTaskMapper->findByIdsScopedToHouse(array_values(array_unique($roomTaskIds)), $houseId);
		$tasks = $this->taskMapper->findByIds(array_values(array_unique(array_map(static fn (RoomTask $roomTask): int => $roomTask->getTaskId(), $roomTasks))));
		$public = [];
		foreach ($roomTasks as $roomTask) {
			if (($tasks[$roomTask->getTaskId()] ?? null)?->getVisibilityType() === Task::VISIBILITY_PUBLIC) {
				$public[(int)$roomTask->getId()] = true;
			}
		}
		return $public;
	}

	/**
	 * @param Cycle[] $rows
	 * @param \OCA\ChoreStrike\Db\Pause[] $absences
	 * @return list<RecognitionFact>
	 */
	private function closedFacts(array $rows, array $absences): array {
		if ($rows === []) {
			return [];
		}
		$shares = $this->workShareMapper->findSelectedByCompletionIds(array_values(array_filter(array_map(
			static fn (Cycle $row): ?int => $row->getCompletionId(),
			$rows
		))));
		$facts = [];
		foreach ($rows as $row) {
			$state = match (true) {
				$row->getOutcome() === Cycle::OUTCOME_MISSED => RecognitionFact::MISSED,
				$row->getCompletionClass() === CompletedTask::CLASS_ON_TIME => RecognitionFact::KEPT,
				default => RecognitionFact::LATE,
			};
			$workers = [];
			foreach ($shares[(int)($row->getCompletionId() ?? 0)] ?? [] as $share) {
				if ($share->getWalletId() !== null) {
					$workers[(int)$share->getWalletId()] = true;
				}
			}
			$facts[] = $this->cycleFact(
				$row->getWindowStartOn()->format('Y-m-d'),
				$row->getWindowEndOn()->format('Y-m-d'),
				$state,
				$row->responsibleWalletIds(),
				$workers,
				$absences,
				$row->getRoomTaskId()
			);
		}
		return $facts;
	}

	/**
	 * @param list<int> $responsible
	 * @param array<int, true> $workers
	 * @param \OCA\ChoreStrike\Db\Pause[] $absences
	 */
	private function cycleFact(string $startOn, string $endOn, string $state, array $responsible, array $workers, array $absences, int $roomTaskId): RecognitionFact {
		$neutral = PauseProjectionService::walletsAbsentThroughout($absences, $startOn, $endOn, array_fill_keys($responsible, true));
		return new RecognitionFact(
			$endOn,
			RecurrenceCalculator::mondayOf($endOn),
			$state,
			$responsible,
			$workers,
			$neutral,
			$responsible !== [] && count($neutral) === count($responsible),
			in_array($state, [RecognitionFact::OPEN, RecognitionFact::UPCOMING], true),
			$roomTaskId,
		);
	}

	/**
	 * Stored occurrences planned in the week that the derived model does
	 * not own: their window ended before `cycles_from`, or a completion
	 * from before it fulfilled them.
	 *
	 * @return list<RecognitionFact>
	 */
	private function storedFacts(House $house, string $weekKey, string $endKey, \DateTime $now): array {
		$cyclesFrom = $house->getCyclesFrom()?->format('Y-m-d');
		// A flex window reaches at most SANITY_MAX days past the cutover.
		if ($cyclesFrom === null || $weekKey > RecurrenceCalculator::offsetDays($cyclesFrom, FlexDaysPolicy::SANITY_MAX)) {
			return [];
		}
		$rows = $this->occurrenceMapper->findByHousePlannedBetween((int)$house->getId(), $weekKey, $endKey);
		if ($rows === []) {
			return [];
		}
		$cutover = $this->calendar->dayStartInstant($cyclesFrom, $this->calendar->getTimeZone($house));
		$completions = $this->completedTaskMapper->findValidByIds(array_values(array_unique(array_filter(array_map(
			static fn (Occurrence $row): ?int => $row->getCompletionId(),
			$rows
		)))));
		$rows = array_values(array_filter($rows, static function (Occurrence $row) use ($cutover, $completions): bool {
			$completion = $completions[(int)($row->getCompletionId() ?? 0)] ?? null;
			return $row->getWindowEnd() <= $cutover || ($completion !== null && $completion->getCycleKey() === null);
		}));
		$members = $this->occMemberMapper->findByOccurrenceIds(array_map(static fn (Occurrence $row): int => (int)$row->getId(), $rows));
		$shares = $this->workShareMapper->findSelectedByCompletionIds(array_keys($completions));

		$facts = [];
		foreach ($rows as $row) {
			$facts[] = $this->storedFact($row, $completions[(int)($row->getCompletionId() ?? 0)] ?? null, $members[(int)$row->getId()] ?? [], $shares, $now);
		}
		return $facts;
	}

	/**
	 * @param OccurrenceMember[] $members
	 * @param array<int, \OCA\ChoreStrike\Db\WorkShare[]> $shares By completion id
	 */
	private function storedFact(Occurrence $row, ?CompletedTask $completion, array $members, array $shares, \DateTime $now): RecognitionFact {
		$provisional = $row->getWindowEnd() !== null && $row->getWindowEnd() > $now;
		$state = match (true) {
			$completion !== null && $completion->isAgreedOnTime($row->getWindowStart(), $row->getWindowEnd()) => RecognitionFact::KEPT,
			$completion !== null => RecognitionFact::LATE,
			$provisional => RecognitionFact::OPEN,
			default => RecognitionFact::MISSED,
		};
		$responsible = [];
		foreach ($members as $member) {
			if ($member->getObligated() && $member->getDisposition() === OccurrenceMember::DISPOSITION_ACTIVE) {
				$responsible[] = (int)$member->getWalletId();
			}
		}
		$workers = [];
		foreach ($completion === null ? [] : ($shares[(int)$completion->getId()] ?? []) as $share) {
			if ($share->getWalletId() !== null) {
				$workers[(int)$share->getWalletId()] = true;
			}
		}
		// The suspension stored on the row is its neutrality, for everyone.
		$suspended = $row->getSuspensionState() === Occurrence::SUSPENSION_SUSPENDED;
		return new RecognitionFact(
			$row->getPlannedDate()->format('Y-m-d'),
			RecurrenceCalculator::mondayOf($row->getPlannedDate()->format('Y-m-d')),
			$state,
			$responsible,
			$workers,
			$suspended ? array_fill_keys($responsible, true) : [],
			$suspended,
			$provisional,
			null,
			$row->getIsPublic() === true,
		);
	}

	/** The first day of derived windows; a house without one never had stored occurrences. */
	private function cyclesFrom(House $house): string {
		return $house->getCyclesFrom()?->format('Y-m-d') ?? '0000-00-00';
	}
}
