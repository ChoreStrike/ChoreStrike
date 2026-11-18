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
use OCA\ChoreStrike\Db\WorkShareMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCP\IL10N;

/**
 * Who may be credited for work (ADR-021 decisions 8 and 10). The
 * completion and the owner's change of people decide through this one
 * class.
 */
class CreditPolicy {
	public function __construct(
		private TaskAccessPolicy $taskAccessPolicy,
		private WorkShareMapper $workShareMapper,
		private HouseUserMapper $houseUserMapper,
		private IL10N $l10n,
	) {
	}

	/**
	 * Only a member who may see a task can be credited on it (D-091-20):
	 * a private task credits its creator and its assignees, an admin-only
	 * task the owner.
	 *
	 * @param string[] $assigneeUserIds
	 * @throws ForbiddenException
	 */
	public function assertVisibleTo(Task $task, HouseUser $member, array $assigneeUserIds): void {
		if (!$this->taskAccessPolicy->canView($task, $member, $assigneeUserIds)) {
			throw new ForbiddenException($this->l10n->t('Only members who can see this task can be credited for it'));
		}
	}

	/**
	 * A bonus task pays each person at most once per house-local day
	 * (decision 8). Call with the wallets locked.
	 *
	 * @param string $dayKey The work day (`Y-m-d`)
	 * @param int[] $walletIds The people to credit
	 * @param int|null $exceptCompletionId The entry whose people are being changed
	 * @throws ConflictException `BONUS_ALREADY_TODAY`
	 */
	public function assertBonusOncePerDay(int $roomTaskId, string $dayKey, array $walletIds, ?int $exceptCompletionId = null): void {
		if ($walletIds === []) {
			// Nobody new to credit (an owner's re-split of the same people).
			return;
		}
		$credited = $this->workShareMapper->findWalletIdsCreditedOn($roomTaskId, $dayKey, $exceptCompletionId);
		if (array_intersect($walletIds, $credited) !== []) {
			throw new ConflictException(
				$this->l10n->t('This bonus task was already counted for this person today'),
				ConflictException::REASON_BONUS_ALREADY_TODAY
			);
		}
	}

	/**
	 * Whom each of the given bonus room tasks already paid on a day, as
	 * the members they are now: what a row tells the client before anyone
	 * presses.
	 *
	 * @param int[] $roomTaskIds
	 * @param string $dayKey House-local day (`Y-m-d`)
	 * @return array<int, list<string>> Room task id to Nextcloud user ids; room tasks that paid nobody are absent
	 */
	public function bonusCreditedOn(int $houseId, array $roomTaskIds, string $dayKey): array {
		$walletsByRoomTask = $this->workShareMapper->findWalletIdsCreditedOnByRoomTasks($roomTaskIds, $dayKey);
		if ($walletsByRoomTask === []) {
			return [];
		}
		$userIdByWallet = [];
		foreach ($this->houseUserMapper->findByHouseId($houseId) as $member) {
			if ($member->getWalletId() !== null) {
				$userIdByWallet[(int)$member->getWalletId()] = $member->getNextcloudUserId();
			}
		}
		$credited = [];
		foreach ($walletsByRoomTask as $roomTaskId => $walletIds) {
			$userIds = array_values(array_filter(array_map(static fn (int $walletId): ?string => $userIdByWallet[$walletId] ?? null, $walletIds)));
			sort($userIds);
			$credited[$roomTaskId] = $userIds;
		}
		return $credited;
	}
}
