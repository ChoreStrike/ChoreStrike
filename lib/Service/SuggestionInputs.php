<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\WorkShareMapper;

/**
 * Reads what the live suggestion places from (ADR-022 decision 1): the
 * current cycles of the house (ADR-021; a sleeping or bonus task has
 * none), each member's energy, availability and preferred days, the
 * absences and today's valid work. A bounded batch per source, whatever
 * the number of tasks; write-free.
 */
class SuggestionInputs {
	public function __construct(
		private RoomTaskMapper $roomTaskMapper,
		private TaskMapper $taskMapper,
		private TaskAssigneeMapper $assigneeMapper,
		private HouseUserMapper $houseUserMapper,
		private WorkShareMapper $workShareMapper,
		private WindowService $windows,
		private PauseProjectionService $pauses,
		private TaskAccessPolicy $accessPolicy,
		private SchedulingService $scheduling,
		private CalendarService $calendar,
	) {
	}

	/** Everything one suggestion read places from, in a bounded batch per source. */
	public function read(House $house, \DateTimeInterface $now): SuggestionContext {
		$houseId = (int)$house->getId();
		$timeZone = $this->calendar->getTimeZone($house);
		$today = WindowService::todayKey($now, $timeZone);

		$members = self::byId($this->houseUserMapper->findByHouseId($houseId));
		ksort($members);
		$roomTasks = self::byId($this->roomTaskMapper->findByHouse($houseId, true));
		$tasks = $this->taskMapper->findByIds(array_values(array_unique(array_map(
			static fn (RoomTask $roomTask): int => $roomTask->getTaskId(),
			$roomTasks
		))));
		$assignees = $this->assigneeMapper->findUserIdsByRoomTasks(array_keys($roomTasks));

		return new SuggestionContext(
			$houseId,
			$timeZone,
			$today,
			$members,
			array_values(array_map(fn (HouseUser $member): SuggestionMember => $this->plannerMember($member), $members)),
			$this->absentDays($houseId, $members, $today),
			$this->doneMinor($members, $today),
			$roomTasks,
			$tasks,
			$assignees,
			$this->jobs($roomTasks, $tasks, $assignees, $members, $timeZone, $now, $today),
		);
	}

	/**
	 * @template T of HouseUser|RoomTask
	 * @param T[] $entities
	 * @return array<int, T> By id, in the order of the read
	 */
	private static function byId(array $entities): array {
		$byId = [];
		foreach ($entities as $entity) {
			$byId[(int)$entity->getId()] = $entity;
		}
		return $byId;
	}

	private function plannerMember(HouseUser $member): SuggestionMember {
		$preferences = $this->scheduling->preferencesOf($member);
		return new SuggestionMember(
			(int)$member->getId(),
			(string)$member->getNextcloudUserId(),
			$preferences['energyBudget'],
			$preferences['weekdayAvailability'],
			$preferences['preferredWeekdays'],
			// The daily limit is a child's second budget (decision 2, rule 5).
			$member->isChild() ? $preferences['maxDailyTasks'] : null,
		);
	}

	/**
	 * The current cycle of every room task that has one, with the members
	 * who can carry it: the assignees who may see the task, or everyone
	 * who may see an unassigned one (ADR-010). A task with several
	 * assignees is a team task: one of them carries it and all of them see
	 * it on its day.
	 *
	 * @param array<int, RoomTask> $roomTasks
	 * @param array<int, Task> $tasks
	 * @param array<int, list<string>> $assignees
	 * @param array<int, HouseUser> $members Ascending by id
	 * @return array<int, SuggestionJob>
	 */
	private function jobs(array $roomTasks, array $tasks, array $assignees, array $members, \DateTimeZone $timeZone, \DateTimeInterface $now, string $today): array {
		$withCycles = array_filter($roomTasks, static fn (RoomTask $roomTask): bool => isset($tasks[$roomTask->getTaskId()])
			&& CycleProjection::hasCycles($roomTask, $tasks[$roomTask->getTaskId()]));
		$histories = $this->windows->historiesFor(array_keys($withCycles));

		$jobs = [];
		foreach ($withCycles as $id => $roomTask) {
			$task = $tasks[$roomTask->getTaskId()];
			$history = $histories[$id] ?? null;
			$cycle = $this->windows->currentCycle($roomTask, $task, $history, $timeZone, $now);
			if ($cycle === null) {
				continue;
			}
			$assigned = $assignees[$id] ?? [];
			$candidates = $this->candidates($task, $assigned, $members);
			$jobs[$id] = new SuggestionJob(
				$id,
				$task->energy(),
				$candidates,
				$assigned !== [],
				$assigned !== [] && count($candidates) > 1,
				$cycle,
				// A task is done at most once a local day (ADR-021 decision 4).
				$history?->latestWorkDay === $today ? RecurrenceCalculator::dayAfter($today) : null,
			);
		}
		return $jobs;
	}

	/**
	 * Who can carry a task: its assignees who may see it, or everyone who
	 * may see an unassigned one (ADR-010).
	 *
	 * @param list<string> $assigned Nextcloud user ids
	 * @param array<int, HouseUser> $members Ascending by id
	 * @return list<int> House-user ids, ascending
	 */
	private function candidates(Task $task, array $assigned, array $members): array {
		$candidates = [];
		foreach ($members as $memberId => $member) {
			$responsible = $assigned === [] || in_array($member->getNextcloudUserId(), $assigned, true);
			if ($responsible && $this->accessPolicy->canView($task, $member, $assigned)) {
				$candidates[] = $memberId;
			}
		}
		return $candidates;
	}

	/**
	 * The days of the horizon each member is absent on: a personal absence
	 * or one of the whole household (ADR-020).
	 *
	 * @param array<int, HouseUser> $members
	 * @return array<int, array<string, true>> Member id to day keys
	 */
	private function absentDays(int $houseId, array $members, string $today): array {
		$recognized = $this->pauses->recognizedForHouse($houseId);
		if ($recognized === [] || $members === []) {
			return [];
		}
		// A member without a wallet has no personal absence; a negative key lets a household absence cover them.
		$memberByWallet = [];
		foreach ($members as $memberId => $member) {
			$memberByWallet[$member->getWalletId() !== null ? (int)$member->getWalletId() : -$memberId] = $memberId;
		}
		$wallets = array_fill_keys(array_keys($memberByWallet), true);
		$absent = [];
		for ($offset = 0; $offset < SuggestionPlanner::HORIZON_DAYS; $offset++) {
			$day = RecurrenceCalculator::offsetDays($today, $offset);
			foreach (array_keys(PauseProjectionService::walletsAbsentThroughout($recognized, $day, $day, $wallets)) as $walletId) {
				$absent[$memberByWallet[$walletId]][$day] = true;
			}
		}
		return $absent;
	}

	/**
	 * @param array<int, HouseUser> $members
	 * @return array<int, int> Member id to the energy minor of today's valid work
	 */
	private function doneMinor(array $members, string $today): array {
		$memberByWallet = [];
		foreach ($members as $memberId => $member) {
			if ($member->getWalletId() !== null) {
				$memberByWallet[(int)$member->getWalletId()] = $memberId;
			}
		}
		$done = [];
		foreach ($this->workShareMapper->sumEnergyMinorByWalletOn(array_keys($memberByWallet), $today) as $walletId => $minor) {
			$done[$memberByWallet[$walletId]] = $minor;
		}
		return $done;
	}
}
