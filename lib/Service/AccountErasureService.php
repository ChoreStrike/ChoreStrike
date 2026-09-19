<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CommandReceiptMapper;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MedalMapper;
use OCA\ChoreStrike\Db\MinigameScoreMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Db\WalletEntryMapper;
use OCA\ChoreStrike\Db\WalletMapper;

/**
 * Account erasure (CS-PLAN-071 WP0, Q-070-02 / RF-070-17 WP0 portion).
 *
 * Fired from the Nextcloud `UserDeletedEvent` (registered in
 * `Application::register`); never exposed through an app API. For every
 * membership of the deleted UID, one house-first transaction per house:
 *
 *  - delete `task_assignees`, `medals`, `minigame_scores` rows,
 *  - delete the wallet with its `wallet_entries` (personal finances
 *    removed; active reservations cannot exist before WP7),
 *  - null the actor on `commands` receipts (the receipts survive as
 *    anonymous facts),
 *  - null `completed_tasks.user_id` / `combo_with_user_id` (the anonymous
 *    shared work facts — time, energy, coins-earned totals, room effects —
 *    are retained),
 *  - delete the `house_users` row (scheduling preferences die with it).
 *
 * Ordinary departure NEVER runs this path (decision D3): removal keeps the
 * wallet as dormant financial identity. Erasure of an already-departed
 * account (wallet without membership) is covered by the final sweep over
 * every wallet still bound to the UID.
 *
 * No rebinding: history references internal bigint IDs only (never raw
 * UIDs), so a recreated same-name account gets a fresh `house_users.id` /
 * `wallets.id` and cannot recover prior data. Anonymized activity rows
 * render through the localized "Former member" placeholder in
 * {@see ActivityService}.
 *
 * Later-package surfaces (agreement snapshots, digest records, reward
 * wishes, audit reasons) extend this service in WP2/WP5/WP7; the complete
 * cross-domain gate is WP9 (parent §4).
 */
class AccountErasureService {
	public function __construct(
		private HouseMapper $houseMapper,
		private HouseUserMapper $houseUserMapper,
		private TaskAssigneeMapper $taskAssigneeMapper,
		private MedalMapper $medalMapper,
		private MinigameScoreMapper $minigameScoreMapper,
		private WalletMapper $walletMapper,
		private WalletEntryMapper $walletEntryMapper,
		private CommandReceiptMapper $commandReceiptMapper,
		private CompletedTaskMapper $completedTaskMapper,
		private TransactionExecutor $transactions,
		private AppLogger $appLogger,
	) {
	}

	/**
	 * Erase every ChoreStrike reference of a deleted Nextcloud account.
	 *
	 * `UserDeletedEvent` fires exactly once, so a per-house failure must
	 * never abort the sweep: every membership and remaining wallet is
	 * attempted, failures are logged with their identifiers, and a final
	 * error-level summary marks the account for manual follow-up (there
	 * is no retry event — the platform account is already gone).
	 *
	 * @param string $nextcloudUserId The deleted account's UID
	 */
	public function eraseAccount(string $nextcloudUserId): void {
		if (trim($nextcloudUserId) === '') {
			return;
		}

		$failedHouseIds = [];
		$memberships = $this->houseUserMapper->findAllByNextcloudUserId($nextcloudUserId);
		foreach ($memberships as $membership) {
			try {
				$this->eraseMembership($membership);
			} catch (\Throwable $e) {
				$failedHouseIds[] = $membership->getHouseId();
				$this->appLogger->logException('AccountErasureService: membership erasure failed', $e, [
					'event' => 'account_erasure_membership_failed',
					'service' => 'AccountErasureService',
					'houseId' => $membership->getHouseId(),
					'houseUserId' => $membership->getId(),
				]);
			}
		}

		// Final sweep: wallets still bound to the UID — covers
		// already-departed accounts (wallet without membership) and any
		// binding the membership loop could not have seen (decision D3).
		$failedWalletIds = [];
		$remainingWallets = $this->walletMapper->findAllByUserId($nextcloudUserId);
		foreach ($remainingWallets as $wallet) {
			try {
				$this->transactions->run(function () use ($wallet): void {
					$this->houseMapper->findForUpdate($wallet->getHouseId());
					$this->walletEntryMapper->deleteByWalletIds([$wallet->getId()]);
					$this->walletMapper->delete($wallet);
				});
			} catch (\Throwable $e) {
				$failedWalletIds[] = $wallet->getId();
				$this->appLogger->logException('AccountErasureService: orphan wallet erasure failed', $e, [
					'event' => 'account_erasure_wallet_failed',
					'service' => 'AccountErasureService',
					'houseId' => $wallet->getHouseId(),
					'walletId' => $wallet->getId(),
				]);
			}
		}

		// Minigame scores carry the RAW UID and can outlive every
		// membership (a departed-only account never entered the loop
		// above) — always sweep them by UID (CS-PLAN-071 purge matrix).
		$this->minigameScoreMapper->deleteByUserIds([$nextcloudUserId]);

		if ($failedHouseIds !== [] || $failedWalletIds !== []) {
			$this->appLogger->error('Account erasure finished with failures; manual follow-up required', [
				'event' => 'account_erasure_incomplete',
				'service' => 'AccountErasureService',
				'failedHouseIds' => $failedHouseIds,
				'failedWalletIds' => $failedWalletIds,
			]);
			return;
		}

		$this->appLogger->info('Account erasure completed', [
			'event' => 'account_erasure_completed',
			'service' => 'AccountErasureService',
			'memberships' => count($memberships),
		]);
	}

	/**
	 * Erase one membership of the deleted account as one house-first
	 * aggregate (house → membership deletes → wallet delete → receipts/
	 * completions anonymization).
	 */
	private function eraseMembership(HouseUser $membership): void {
		$houseId = $membership->getHouseId();
		$houseUserId = $membership->getId();
		$ncUserId = $membership->getNextcloudUserId();

		$this->transactions->run(function () use ($membership, $houseId, $houseUserId, $ncUserId): void {
			// FIRST lock of the documented order: the house row.
			$this->houseMapper->findForUpdate($houseId);

			$this->taskAssigneeMapper->deleteByUserForHouse($ncUserId, $houseId);
			$this->medalMapper->deleteByUserIds([$houseUserId]);
			$this->minigameScoreMapper->deleteByUserIds([$ncUserId]);

			// Personal finances removed (no active reservations before WP7).
			$wallet = $this->walletMapper->findByHouseAndUser($houseId, $ncUserId);
			if ($wallet !== null) {
				$this->walletEntryMapper->deleteByWalletIds([$wallet->getId()]);
				$this->walletMapper->delete($wallet);
			}

			// The receipts survive as anonymous facts.
			$this->commandReceiptMapper->anonymizeActor($houseId, $houseUserId);

			// Retain the anonymous shared work facts, lose the actor.
			$this->completedTaskMapper->anonymizeActor($houseUserId);

			// The membership row (and its scheduling preferences) dies last.
			$this->houseUserMapper->delete($membership);
		});
	}
}
