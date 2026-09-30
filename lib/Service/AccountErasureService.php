<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\AgreementMapper;
use OCA\ChoreStrike\Db\CommandReceiptMapper;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\CompletionRevisionMapper;
use OCA\ChoreStrike\Db\DigestChangeMapper;
use OCA\ChoreStrike\Db\DigestSlotMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MedalMapper;
use OCA\ChoreStrike\Db\MemberPreferenceMapper;
use OCA\ChoreStrike\Db\MinigameScoreMapper;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\PauseMapper;
use OCA\ChoreStrike\Db\ScheduleRevisionMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Db\WalletEntryMapper;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Db\WorkShareMapper;

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
 *  - CS-PLAN-072 WP1: delete the account's `occ_members` links (the
 *    occurrences themselves stay as shared facts), null the account
 *    binding inside `schedule_revs` default-member snapshots (the numeric
 *    wallet fact is non-reidentifiable) and null the actor on
 *    `agreements` evidence rows,
 *  - CS-PLAN-070 WP4 (Q-075-01 A): delete the account's
 *    `member_prefs` consent row (ranking consent is purged with the
 *    membership; a recreated same-UID account starts unconsented),
 *  - CS-PLAN-070 WP5 (CS-PLAN-076 §3.7): purge the account's digest
 *    rows (`digest_changes` queued history + `digest_slots` delivery
 *    history) — membership-keyed, never rebound,
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
 * Later-package surfaces extend this service in WP5/WP7 (digest records,
 * reward wishes, audit reasons); WP2's pause/consent surfaces are covered
 * above, the complete cross-domain gate is WP9 (parent §4).
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
		private OccurrenceMemberMapper $occMemberMapper,
		private ScheduleRevisionMapper $scheduleRevisionMapper,
		private AgreementMapper $agreementMapper,
		private PauseMapper $pauseMapper,
		private MemberPreferenceMapper $memberPreferenceMapper,
		private DigestChangeMapper $digestChangeMapper,
		private DigestSlotMapper $digestSlotMapper,
		private CompletionRevisionMapper $completionRevisionMapper,
		private WorkShareMapper $workShareMapper,
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
					$this->walletEntryMapper->anonymizeActorWallet((int)$wallet->getId());
					$this->workShareMapper->anonymizeWallet((int)$wallet->getId());
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
				$this->walletEntryMapper->anonymizeActorWallet((int)$wallet->getId());
				$this->walletMapper->delete($wallet);

				// CS-PLAN-072 WP1 (T0): purge the account's occurrence
				// member links; the shared occurrence facts stay (only the
				// person's links go), and rule-default snapshots keep the
				// numeric wallet fact while losing the account binding.
				$this->occMemberMapper->deleteByWalletId((int)$wallet->getId());
				$this->scheduleRevisionMapper->anonymizeDefaultMembers($houseId, (int)$wallet->getId());
				$this->agreementMapper->anonymizeMemberSnapshots($houseId, (int)$wallet->getId());
				// CS-PLAN-073 WP2 (§3.6): personal pause rows of the wallet
				// go; shared pause facts keep their non-reidentifiable
				// content and lose the account references below.
				$this->pauseMapper->deletePersonalByWalletId((int)$wallet->getId());
			}
			$this->pauseMapper->anonymizeActor($houseUserId);

			// CS-PLAN-070 WP4 (Q-075-01 A): the ranking consent row is
			// personal data — purged with the membership, never rebound.
			$this->memberPreferenceMapper->deleteByHouseAndUser($houseId, $houseUserId);

			// CS-PLAN-070 WP5 (CS-PLAN-076 §3.7): the digest queue and
			// slot history are membership-keyed personal data — purged
			// with the membership, never rebound.
			$this->digestChangeMapper->deleteByHouseAndUser($houseId, $houseUserId);
			$this->digestSlotMapper->deleteByHouseAndUser($houseId, $houseUserId);

			// The receipts survive as anonymous facts.
			$this->commandReceiptMapper->anonymizeActor($houseId, $houseUserId);
			$this->walletEntryMapper->anonymizeActor($houseUserId);

			// Timeline agreement evidence survives, the actor does not.
			$this->agreementMapper->anonymizeActor($houseUserId);

			// Retain the anonymous shared work facts, lose the actor.
			$this->completedTaskMapper->anonymizeActor($houseUserId);

			// CS-PLAN-070 WP3 (§3.6): the new evidence tables anonymize
			// their actor references too — correction audit rows lose the
			// actor (bounded facts stay), work shares lose the wallet
			// binding (only non-identifying shared work remains). The room
			// journal carries no actor references.
			$this->completionRevisionMapper->anonymizeActor($houseUserId);
			if ($wallet !== null) {
				$this->workShareMapper->anonymizeWallet((int)$wallet->getId());
			}

			// The membership row (and its scheduling preferences) dies last.
			$this->houseUserMapper->delete($membership);
		});
	}
}
