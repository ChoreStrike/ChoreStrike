<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;

/**
 * The inputs of one suggestion read (ADR-022 decision 1), loaded once by
 * {@see SuggestionInputs}: the members with their budgets, the current
 * cycles of the house and what today already took.
 */
final class SuggestionContext {
	/**
	 * @param array<int, HouseUser> $members By house-user id
	 * @param list<SuggestionMember> $plannerMembers
	 * @param array<int, array<string, true>> $absentDays Member id to the days of the horizon they are absent on
	 * @param array<int, int> $doneMinor Member id to the energy minor of today's valid work
	 * @param array<int, RoomTask> $roomTasks The active room tasks of the house, by id
	 * @param array<int, Task> $tasks Their definitions, by task id
	 * @param array<int, list<string>> $assignees Nextcloud user ids by room task
	 * @param array<int, SuggestionJob> $jobs The current cycle of every room task that has one, by room task
	 */
	public function __construct(
		public readonly int $houseId,
		public readonly \DateTimeZone $timeZone,
		public readonly string $todayKey,
		public readonly array $members,
		public readonly array $plannerMembers,
		public readonly array $absentDays,
		public readonly array $doneMinor,
		public readonly array $roomTasks,
		public readonly array $tasks,
		public readonly array $assignees,
		public readonly array $jobs,
	) {
	}

	/** A fresh set of budgets: every run of the planner books on its own. */
	public function ledger(): SuggestionLedger {
		return new SuggestionLedger($this->todayKey, $this->plannerMembers, $this->absentDays, $this->doneMinor, SuggestionPlanner::HORIZON_DAYS);
	}

	/** The definition of a room task of the house; null when it is not read. */
	public function taskOf(int $roomTaskId): ?Task {
		$roomTask = $this->roomTasks[$roomTaskId] ?? null;
		return $roomTask === null ? null : ($this->tasks[$roomTask->getTaskId()] ?? null);
	}

	/**
	 * The Nextcloud user ids of members, in the given order; a member who
	 * left the house is left out.
	 *
	 * @param list<int> $memberIds
	 * @return list<string>
	 */
	public function userIdsOf(array $memberIds): array {
		$userIds = [];
		foreach ($memberIds as $memberId) {
			$userId = ($this->members[$memberId] ?? null)?->getNextcloudUserId();
			if (is_string($userId) && $userId !== '') {
				$userIds[] = $userId;
			}
		}
		return $userIds;
	}
}
