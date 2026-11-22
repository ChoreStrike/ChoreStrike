<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\SuggestionDay;
use OCA\ChoreStrike\Db\SuggestionDayMapper;
use OCP\IL10N;

/**
 * The live suggestion (CS-PLAN-091 WP-C, ADR-022): computed on read from
 * the open windows, the members' energy and preferred days and the
 * absences. Nothing is applied and nothing becomes an agreement.
 *
 * Today's list is stable (decision 3): the first read of a house-local
 * day stores what it placed on that day. Later reads keep those entries
 * while their cycle is the one that was placed, let work created since
 * join, and move everything else from tomorrow on. The stored row is a
 * cache without history value; {@see deleteOldDaysForJobs()} removes it.
 * The read is the one write on a GET this app allows (security.md, *No
 * Mutations on GET*): member-gated, idempotent, never client content.
 */
class SuggestionService {
	/** Snapshots older than this many days are deleted by the timeline job. */
	public const KEEP_DAYS = 7;

	public function __construct(
		private SuggestionInputs $inputs,
		private SuggestionPlanner $planner,
		private SuggestionDayMapper $dayMapper,
		private WindowService $windows,
		private TaskAccessPolicy $accessPolicy,
		private RoomMapper $roomMapper,
		private HouseMapper $houseMapper,
		private CalendarService $calendar,
		private IL10N $l10n,
		private AppLogger $logger,
	) {
	}

	/**
	 * The suggestion of a house as it stands now, with today's list kept.
	 * The day's first read places with today still open and stores what it
	 * put on today; every read, that one included, then places from the
	 * stored list, so the same inputs always yield the same plan.
	 */
	public function view(House $house, \DateTimeInterface $now): SuggestionView {
		$context = $this->inputs->read($house, $now);
		$successor = $this->successors($context);

		$stored = $this->dayMapper->findDay($context->houseId, $context->todayKey);
		if ($stored === null) {
			$stored = $this->storeDay($context, $successor, $now);
		}
		$placements = $stored->getPlacements();
		[$pinned, $joiners] = $this->todaysList($context, is_array($placements) ? $placements : [], $stored->getCreatedAt());
		$plan = $this->planner->plan($context->ledger(), array_values($context->jobs), $pinned, $joiners, $successor);
		if ($plan->truncated) {
			$this->logger->warning('Suggestion left cycles unplaced at its step bound', ['event' => 'suggestion_plan_truncated', 'service' => 'SuggestionService', 'houseId' => $context->houseId, 'jobs' => count($context->jobs)]);
		}
		return new SuggestionView($context, $plan);
	}

	/**
	 * The suggestion for a range of days as a viewer may see it (API
	 * reference, Scheduling): per day the visible entries, the overdue
	 * work that counts against today, and the load of the viewer — of
	 * every member for the owner, who manages the budgets.
	 *
	 * The range is cut to the days from today to the end of the horizon
	 * before anything is read, so a client whose day ran over midnight
	 * still gets the days that remain; a range wholly outside yields no
	 * days and stores nothing.
	 *
	 * @param string $from First house-local day (`Y-m-d`)
	 * @param string $to Last house-local day
	 * @return array{days: list<array<string, mixed>>, overdue: list<array<string, mixed>>, members: list<array<string, mixed>>, horizonDays: int, horizonEndOn: string}
	 * @throws \InvalidArgumentException When the last day lies before the first
	 */
	public function range(House $house, HouseUser $viewer, string $from, string $to, ?\DateTimeInterface $now = null): array {
		$now ??= new \DateTime();
		[$from, $to, $horizonEndOn] = $this->insideHorizon($house, $from, $to, $now);
		// The client learns the horizon from every answer instead of copying it.
		$horizon = ['horizonDays' => SuggestionPlanner::HORIZON_DAYS, 'horizonEndOn' => $horizonEndOn];
		if ($from > $to) {
			return ['days' => [], 'overdue' => [], 'members' => []] + $horizon;
		}
		$view = $this->view($house, $now);
		$visible = $this->visibleRoomTasks($view->context, $viewer);
		$rooms = $this->roomMapper->findByIds(array_values(array_unique(array_map(
			static fn (RoomTask $roomTask): int => $roomTask->getRoomId(),
			$visible
		))));

		return $view->range($viewer, $visible, $rooms, $from, $to) + $horizon;
	}

	/**
	 * The room tasks of a suggestion read that a viewer may see (ADR-010),
	 * by id: one visibility decision per room task from the definitions and
	 * assignees the read already holds, whatever the number of placements.
	 * {@see RoomTaskReadService::visibleRoomTasks()} is the same policy for
	 * a batch whose definitions are not read yet.
	 *
	 * @return array<int, RoomTask>
	 */
	public function visibleRoomTasks(SuggestionContext $context, HouseUser $viewer): array {
		$visible = [];
		foreach ($this->accessPolicy->filterVisibleRoomTasks(array_values($context->roomTasks), $context->tasks, $viewer, $context->assignees) as $roomTask) {
			$visible[(int)$roomTask->getId()] = $roomTask;
		}
		return $visible;
	}

	/**
	 * A requested range cut to the days from the house's today to the end
	 * of the horizon; empty when the first day then lies after the last.
	 *
	 * @return array{string, string, string} The first day, the last day and the last day of the horizon
	 * @throws \InvalidArgumentException When the last day lies before the first
	 */
	private function insideHorizon(House $house, string $from, string $to, \DateTimeInterface $now): array {
		if ($to < $from) {
			throw new \InvalidArgumentException($this->l10n->t('The last day must not lie before the first day'));
		}
		$today = WindowService::todayKey($now, $this->calendar->getTimeZone($house));
		$horizonEndOn = RecurrenceCalculator::offsetDays($today, SuggestionPlanner::HORIZON_DAYS - 1);
		return [max($from, $today), min($to, $horizonEndOn), $horizonEndOn];
	}

	/**
	 * The timeline job's pass: the snapshots of the instance's household
	 * that are older than a week go.
	 *
	 * @return int Deleted rows
	 */
	public function deleteOldDaysForJobs(\DateTimeInterface $now): int {
		$house = $this->houseMapper->findOneForJobs();
		return $house === null ? 0 : $this->deleteOldDays($house, $now);
	}

	/**
	 * Delete the snapshots of one house whose day lies more than a week
	 * back. The rows of today and of the days just past stay.
	 *
	 * @return int Deleted rows
	 */
	public function deleteOldDays(House $house, \DateTimeInterface $now): int {
		$today = WindowService::todayKey($now, $this->calendar->getTimeZone($house));
		return $this->dayMapper->deleteBefore((int)$house->getId(), RecurrenceCalculator::offsetDays($today, -self::KEEP_DAYS));
	}

	/**
	 * The day's first read: place with today still open and store what the
	 * plan put on today. A read that lost the race to another first read
	 * gets that read's list, which is the one everyone sees.
	 *
	 * @param callable(SuggestionJob, string): ?CurrentCycle $successor
	 */
	private function storeDay(SuggestionContext $context, callable $successor, \DateTimeInterface $now): SuggestionDay {
		$first = $this->planner->plan($context->ledger(), array_values($context->jobs), null, [], $successor);
		$snapshot = self::snapshotOf($first, $context->todayKey);
		$stored = $this->dayMapper->storeOnce($context->houseId, $context->todayKey, $snapshot, $now);
		// Loose: MySQL's JSON type hands the keys of each entry back in its own order.
		if ($stored->getPlacements() != $snapshot) {
			$this->logger->warning('Suggestion day stored by another read', ['event' => 'suggestion_day_race_lost', 'service' => 'SuggestionService', 'houseId' => $context->houseId]);
		}
		return $stored;
	}

	/**
	 * The cycle that follows a job done on a day, for the planner. The
	 * rule and the anchor of a room task are read once, however many of
	 * its cycles a run places.
	 *
	 * @return \Closure(SuggestionJob, string): ?CurrentCycle
	 */
	private function successors(SuggestionContext $context): \Closure {
		$byRoomTask = [];
		return function (SuggestionJob $job, string $doneOn) use (&$byRoomTask, $context): ?CurrentCycle {
			$id = $job->roomTaskId;
			$task = $context->taskOf($id);
			if ($task === null) {
				return null;
			}
			$byRoomTask[$id] ??= $this->windows->successorOf($context->roomTasks[$id], $task, $context->timeZone);
			return $byRoomTask[$id]($job->cycle, $doneOn);
		};
	}

	/**
	 * What a plan placed on a day, as the snapshot stores it.
	 *
	 * @return list<array{r: int, k: string, u: list<int>, p: int, o: bool}>
	 */
	private static function snapshotOf(SuggestionPlan $plan, string $day): array {
		$entries = [];
		foreach ($plan->on($day) as $placement) {
			if ($placement->current) {
				$entries[] = [
					'r' => $placement->roomTaskId,
					'k' => (string)$placement->cycleKey,
					'u' => $placement->memberIds,
					'p' => $placement->postponeCount,
					'o' => $placement->overload,
				];
			}
		}
		return $entries;
	}

	/**
	 * Today's stored list as it stands now (decision 3). An entry stays
	 * while the cycle it placed is still current, unmoved and open today
	 * and the member who carries it can still be responsible; done,
	 * postponed, sleeping and deleted work leaves, and so does work that
	 * was handed to someone else or whose window an edit moved off today.
	 * A room task created after the day was stored joins when its window
	 * contains today, and so does work the list does not hold whose window
	 * ends today: it has no later day.
	 *
	 * @param array<mixed> $stored The stored placements; anything that is no entry is skipped
	 * @return array{array<int, array{memberIds: list<int>, overload: bool}>, array<int, true>}
	 */
	private function todaysList(SuggestionContext $context, array $stored, \DateTimeInterface $storedAt): array {
		$pinned = [];
		foreach ($stored as $entry) {
			$kept = is_array($entry) ? self::keptEntry($context, $entry) : null;
			if ($kept !== null) {
				$pinned[$kept[0]] = $kept[1];
			}
		}
		$today = $context->todayKey;
		$joiners = [];
		foreach ($context->jobs as $roomTaskId => $job) {
			if (isset($pinned[$roomTaskId]) || $job->cycle->startOn > $today || $job->cycle->endOn < $today) {
				continue;
			}
			$createdAt = $context->roomTasks[$roomTaskId]->getCreatedAt();
			// Work whose window ends today has no later day to move to.
			if ($job->cycle->endOn === $today || ($createdAt !== null && $createdAt > $storedAt)) {
				$joiners[$roomTaskId] = true;
			}
		}
		return [$pinned, $joiners];
	}

	/**
	 * One stored entry as it stands now: its room task and who keeps it,
	 * or null when it leaves today's list.
	 *
	 * @param array<mixed> $entry A stored `{r, k, u, p, o}` placement
	 * @return array{int, array{memberIds: list<int>, overload: bool}}|null
	 */
	private static function keptEntry(SuggestionContext $context, array $entry): ?array {
		$job = $context->jobs[(int)($entry['r'] ?? 0)] ?? null;
		if ($job === null || $job->cycle->key !== ($entry['k'] ?? null) || $job->cycle->postponeCount !== (int)($entry['p'] ?? 0)
			|| $job->cycle->startOn > $context->todayKey || $job->cycle->endOn < $context->todayKey) {
			return null;
		}
		$memberIds = array_values(array_map('intval', (array)($entry['u'] ?? [])));
		if ($memberIds === [] || !in_array($memberIds[0], $job->candidates, true)) {
			return null;
		}
		return [$job->roomTaskId, [
			'memberIds' => array_values(array_filter($memberIds, static fn (int $id): bool => in_array($id, $job->candidates, true))),
			'overload' => (bool)($entry['o'] ?? false),
		]];
	}
}
