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
		$successor = fn (SuggestionJob $job, string $doneOn): ?CurrentCycle => $this->successorOf($context, $job, $doneOn);

		$stored = $this->dayMapper->findDay($context->houseId, $context->todayKey);
		if ($stored === null) {
			$first = $this->planner->plan($context->ledger(), array_values($context->jobs), null, [], $successor);
			$snapshot = self::snapshotOf($first, $context->todayKey);
			$stored = $this->dayMapper->storeOnce($context->houseId, $context->todayKey, $snapshot, $now);
			// Loose: MySQL's JSON type hands the keys of each entry back in its own order.
			if ($stored->getPlacements() != $snapshot) {
				// Another first read won the day: its list is the one everyone sees.
				$this->logger->warning('Suggestion day stored by another read', ['event' => 'suggestion_day_race_lost', 'service' => 'SuggestionService', 'houseId' => $context->houseId]);
			}
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
	 * @return array{days: list<array<string, mixed>>, overdue: list<array<string, mixed>>, members: list<array<string, mixed>>}
	 * @throws \InvalidArgumentException When the last day lies before the first
	 */
	public function range(House $house, HouseUser $viewer, string $from, string $to, ?\DateTimeInterface $now = null): array {
		$now ??= new \DateTime();
		[$from, $to] = $this->insideHorizon($house, $from, $to, $now);
		if ($from > $to) {
			return ['days' => [], 'overdue' => [], 'members' => []];
		}
		$view = $this->view($house, $now);
		$context = $view->context;
		$days = array_values(array_filter($view->plan->ledger->days, static fn (string $day): bool => $day >= $from && $day <= $to));
		$visible = array_values(array_filter(
			$view->plan->placements,
			fn (array $placement): bool => $placement['day'] >= $from && $placement['day'] <= $to && $this->canSee($context, $viewer, $placement['roomTaskId'])
		));
		$overdue = $from === $context->todayKey
			? array_values(array_filter($view->plan->overdue, fn (array $entry): bool => $this->canSee($context, $viewer, $entry['roomTaskId'])))
			: [];
		$describe = $this->describer($context, [...$visible, ...$overdue]);

		return [
			'days' => array_map(static fn (string $day): array => [
				'day' => $day,
				'entries' => array_map($describe, array_values(array_filter($visible, static fn (array $placement): bool => $placement['day'] === $day))),
			], $days),
			'overdue' => array_map($describe, $overdue),
			'members' => $this->loads($context, $view->plan->ledger, $viewer, $days),
		];
	}

	/**
	 * A requested range cut to the days from the house's today to the end
	 * of the horizon; empty when the first day then lies after the last.
	 *
	 * @return array{string, string}
	 * @throws \InvalidArgumentException When the last day lies before the first
	 */
	private function insideHorizon(House $house, string $from, string $to, \DateTimeInterface $now): array {
		if ($to < $from) {
			throw new \InvalidArgumentException($this->l10n->t('The last day must not lie before the first day'));
		}
		$today = WindowService::todayKey($now, $this->calendar->getTimeZone($house));
		return [max($from, $today), min($to, RecurrenceCalculator::offsetDays($today, SuggestionPlanner::HORIZON_DAYS - 1))];
	}

	/**
	 * Describe entries of the plan with their room, read once for all of them.
	 *
	 * @param list<array<string, mixed>> $entries
	 * @return \Closure(array<string, mixed>): array<string, mixed>
	 */
	private function describer(SuggestionContext $context, array $entries): \Closure {
		$rooms = $this->roomMapper->findByIds(array_values(array_unique(array_map(
			static fn (array $entry): int => $context->roomTasks[$entry['roomTaskId']]->getRoomId(),
			$entries
		))));
		return fn (array $entry): array => $this->describe($context, $rooms, $entry);
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

	private function successorOf(SuggestionContext $context, SuggestionJob $job, string $doneOn): ?CurrentCycle {
		$roomTask = $context->roomTasks[$job->roomTaskId] ?? null;
		$task = $context->taskOf($job->roomTaskId);
		return $roomTask === null || $task === null
			? null
			: $this->windows->cycleAfter($roomTask, $task, $job->cycle, $doneOn, $context->timeZone);
	}

	/**
	 * What a plan placed on a day, as the snapshot stores it.
	 *
	 * @return list<array{r: int, k: string, u: list<int>, p: int, o: bool}>
	 */
	private static function snapshotOf(SuggestionPlan $plan, string $day): array {
		$entries = [];
		foreach ($plan->on($day) as $placement) {
			if ($placement['current']) {
				$entries[] = [
					'r' => $placement['roomTaskId'],
					'k' => (string)$placement['cycleKey'],
					'u' => $placement['memberIds'],
					'p' => $placement['postponeCount'],
					'o' => $placement['overload'],
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

	private function canSee(SuggestionContext $context, HouseUser $viewer, int $roomTaskId): bool {
		$task = $context->taskOf($roomTaskId);
		return $task !== null && $this->accessPolicy->canView($task, $viewer, $context->assignees[$roomTaskId] ?? []);
	}

	/**
	 * One entry of the read contract, with what a calendar row shows.
	 *
	 * @param array<int, \OCA\ChoreStrike\Db\Room> $rooms
	 * @param array<string, mixed> $entry A placement or an overdue entry of the plan
	 * @return array<string, mixed>
	 */
	private function describe(SuggestionContext $context, array $rooms, array $entry): array {
		$roomTask = $context->roomTasks[$entry['roomTaskId']];
		return [
			'roomTaskId' => $entry['roomTaskId'],
			'cycleKey' => $entry['cycleKey'],
			'userIds' => $context->userIdsOf($entry['memberIds']),
			'energy' => $entry['energy'],
			'overload' => $entry['overload'] ?? false,
			'windowStartOn' => $entry['windowStartOn'],
			'windowEndOn' => $entry['windowEndOn'],
			'taskName' => (string)$context->taskOf($entry['roomTaskId'])?->getName(),
			'roomId' => $roomTask->getRoomId(),
			'roomName' => ($rooms[$roomTask->getRoomId()] ?? null)?->getName(),
		];
	}

	/**
	 * The placed energy against the budget, per member and day.
	 *
	 * @param list<string> $days
	 * @return list<array<string, mixed>>
	 */
	private function loads(SuggestionContext $context, SuggestionLedger $ledger, HouseUser $viewer, array $days): array {
		$loads = [];
		foreach ($context->members as $memberId => $member) {
			if ($memberId !== (int)$viewer->getId() && !$viewer->isOwner()) {
				continue;
			}
			foreach ($days as $day) {
				$loads[] = [
					'userId' => (string)$member->getNextcloudUserId(),
					'day' => $day,
					'budget' => $ledger->budget($memberId, $day),
					'placed' => $ledger->placed($memberId, $day),
					'doneMinor' => $day === $context->todayKey ? $ledger->doneMinor($memberId) : 0,
					'preferred' => $ledger->isPreferred($memberId, $day),
					'absent' => $ledger->isAbsent($memberId, $day),
				];
			}
		}
		return $loads;
	}
}
