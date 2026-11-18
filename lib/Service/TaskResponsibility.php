<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;

/**
 * Who a room task counts for (ADR-021 decision 5): its assignees who are
 * members of the house. The one resolution behind the closed-cycle rows,
 * the recognition facts and the recognition recompute of a correction.
 */
class TaskResponsibility {
	public function __construct(
		private TaskAssigneeMapper $taskAssigneeMapper,
		private HouseUserMapper $houseUserMapper,
	) {
	}

	/**
	 * @param int[] $roomTaskIds
	 * @return array<int, list<HouseUser>> Room task id to its assigned members; unassigned room tasks are absent
	 */
	public function membersByRoomTask(int $houseId, array $roomTaskIds): array {
		$assignees = $roomTaskIds === [] ? [] : array_filter($this->taskAssigneeMapper->findUserIdsByRoomTasks($roomTaskIds));
		if ($assignees === []) {
			return [];
		}
		$byUserId = [];
		foreach ($this->houseUserMapper->findByHouseId($houseId) as $member) {
			$byUserId[$member->getNextcloudUserId()] = $member;
		}
		$members = [];
		foreach ($assignees as $roomTaskId => $userIds) {
			$found = array_values(array_filter(array_map(static fn (string $userId): ?HouseUser => $byUserId[$userId] ?? null, $userIds)));
			if ($found !== []) {
				$members[(int)$roomTaskId] = $found;
			}
		}
		return $members;
	}

	/**
	 * @param int[] $roomTaskIds
	 * @return array<int, list<int>> Room task id to the wallet ids of its assignees, ascending
	 */
	public function walletIdsByRoomTask(int $houseId, array $roomTaskIds): array {
		$wallets = [];
		foreach ($this->membersByRoomTask($houseId, $roomTaskIds) as $roomTaskId => $members) {
			$ids = [];
			foreach ($members as $member) {
				if ($member->getWalletId() !== null) {
					$ids[] = (int)$member->getWalletId();
				}
			}
			sort($ids);
			$wallets[$roomTaskId] = $ids;
		}
		return $wallets;
	}

	/** @return list<HouseUser> The assigned members of one room task */
	public function membersOf(int $houseId, int $roomTaskId): array {
		return $this->membersByRoomTask($houseId, [$roomTaskId])[$roomTaskId] ?? [];
	}

	/** @return list<int> The wallet ids of one room task's assignees, ascending */
	public function walletIdsOf(int $houseId, int $roomTaskId): array {
		return $this->walletIdsByRoomTask($houseId, [$roomTaskId])[$roomTaskId] ?? [];
	}
}
