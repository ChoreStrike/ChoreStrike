<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\Wallet;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCP\IL10N;

/**
 * The membership and wallet locks of the completion commands, each
 * category in stable id order (WP3 §3.3): memberships after the room row,
 * wallets after the memberships and before any completion write. Never
 * acquire a room lock after these.
 */
class CompletionLocks {
	public function __construct(
		private HouseUserMapper $houseUserMapper,
		private WalletMapper $walletMapper,
		private WalletService $walletService,
		private CreditPolicy $creditPolicy,
		private IL10N $l10n,
	) {
	}

	/**
	 * Lock the people credited AND the responsible assignees in ascending
	 * member id order. Only the people credited are checked for task
	 * visibility (D-091-20).
	 *
	 * @param array<string, int> $allocation userId => sharePct
	 * @param string[] $assigneeUserIds
	 * @param HouseUser[] $all The members of the house
	 * @return HouseUser[]
	 * @throws ConflictException a credited person who is no member
	 * @throws ForbiddenException a credited member who may not see the task
	 */
	public function participants(array $allocation, Task $task, array $assigneeUserIds, array $all): array {
		$membersByUserId = [];
		foreach ($all as $member) {
			$membersByUserId[$member->getNextcloudUserId()] = $member;
		}

		$ids = [];
		foreach (array_keys($allocation) as $uid) {
			$member = $membersByUserId[$uid] ?? null;
			if ($member === null) {
				throw new ConflictException($this->l10n->t('Participants must be house members'));
			}
			$ids[(int)$member->getId()] = true;
		}
		foreach ($assigneeUserIds as $uid) {
			if (isset($membersByUserId[$uid])) {
				$ids[(int)$membersByUserId[$uid]->getId()] = true;
			}
		}
		ksort($ids, SORT_NUMERIC);
		$locked = [];
		foreach (array_keys($ids) as $id) {
			$member = $this->houseUserMapper->findForUpdate($id);
			if (isset($allocation[$member->getNextcloudUserId()])) {
				$this->creditPolicy->assertVisibleTo($task, $member, $assigneeUserIds);
			}
			$locked[] = $member;
		}
		return $locked;
	}

	/**
	 * Lock the live members behind the given wallets, plus further members,
	 * by member id (a departed wallet has no membership to lock).
	 *
	 * @param int[] $walletIds
	 * @param HouseUser[] $additionalMembers
	 * @return HouseUser[]
	 */
	public function membersOfWallets(int $houseId, array $walletIds, array $additionalMembers = []): array {
		$walletIds = array_fill_keys($walletIds, true);
		$ids = [];
		foreach ($this->houseUserMapper->findByHouseId($houseId) as $member) {
			if ($member->getWalletId() !== null && isset($walletIds[(int)$member->getWalletId()])) {
				$ids[(int)$member->getId()] = true;
			}
		}
		foreach ($additionalMembers as $member) {
			$ids[(int)$member->getId()] = true;
		}
		ksort($ids, SORT_NUMERIC);
		$members = [];
		foreach (array_keys($ids) as $id) {
			$members[] = $this->houseUserMapper->findForUpdate($id);
		}
		return $members;
	}

	/**
	 * Lock the wallets of the given users in stable id order. A user
	 * without a binding (a raw fixture row, a membership from before
	 * wallets) gets the wallet created and bound here: taking part is the
	 * trigger, so a missing wallet never reaches the share writer.
	 *
	 * @param string[] $userIds
	 * @param HouseUser[]|null $members The members of the house when the caller read them under the same lock
	 * @return array<string, Wallet> By user id
	 */
	public function wallets(int $houseId, array $userIds, ?array $members = null): array {
		$walletIdByUser = [];
		foreach ($members ?? $this->houseUserMapper->findByHouseId($houseId) as $member) {
			if (in_array($member->getNextcloudUserId(), $userIds, true)) {
				$bound = $member->getWalletId() !== null
					? $member
					: $this->walletService->ensureBinding($houseId, $member);
				$walletIdByUser[$member->getNextcloudUserId()] = (int)$bound->getWalletId();
			}
		}
		$locked = $this->walletsById(array_values($walletIdByUser));
		$byUser = [];
		foreach ($walletIdByUser as $uid => $walletId) {
			$byUser[$uid] = $locked[$walletId];
		}
		return $byUser;
	}

	/**
	 * One sorted lock pass over wallet ids; a departed member's retained
	 * wallet is locked by id like any other.
	 *
	 * @param int[] $walletIds
	 * @return array<int, Wallet> By wallet id
	 */
	public function walletsById(array $walletIds): array {
		$ids = array_values(array_unique($walletIds));
		sort($ids);
		$wallets = [];
		foreach ($ids as $id) {
			$wallets[$id] = $this->walletMapper->findForUpdate($id);
		}
		return $wallets;
	}
}
