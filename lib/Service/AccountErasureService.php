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
use OCA\ChoreStrike\Db\CycleMapper;
use OCA\ChoreStrike\Db\CycleProgressMapper;
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
use OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\ScheduleRevisionMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Db\Wallet;
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
 *  - delete the account's `task_assignees`, `medals` and `minigame_scores`
 *    rows, its `member_prefs` consent row (CS-PLAN-070 WP4), its digest
 *    queue and slot history (WP5) and last the `house_users` row,
 *  - null the actor on receipts, ledger entries, agreements, completions
 *    (`completed_tasks.user_id`), correction audit rows, sub-task ticks and
 *    shared pauses; the anonymous shared facts stay,
 *  - the user-id step ({@see eraseUserReferencesUnderLock()}): definitions
 *    lose their creator, room tasks their takeover names, assignments
 *    their assigner,
 *  - the wallet step ({@see eraseWalletUnderLock()}): reward holds,
 *    requests, events and wishes (WP7), the wallet with its ledger,
 *    appointment members, the account binding in rule-default, agreement
 *    and consent snapshots, closed-cycle members, frozen coin factors,
 *    personal absences and work-share bindings.
 *
 * Ordinary departure NEVER runs this path (decision D3): removal keeps the
 * wallet as dormant financial identity. The final sweep erases every wallet
 * still bound to the UID (an already-departed account) with the same two
 * steps under that wallet's house lock (BUG-SEC-003). Minigame scores, the
 * arcade start point and the colour scheme are swept by UID for every
 * account (CS-PLAN-090 WP-J, WP-K).
 *
 * No rebinding: history references internal bigint IDs only, so a recreated
 * same-name account gets a fresh `house_users.id` / `wallets.id` and cannot
 * recover prior data. Anonymized activity rows render through the localized
 * "Former member" placeholder in {@see ActivityService}.
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
		private CycleMapper $cycleMapper,
		private CycleProgressMapper $cycleProgressMapper,
		private RoomTaskChecklistProgressMapper $checklistProgressMapper,
		private ScheduleRevisionMapper $scheduleRevisionMapper,
		private AgreementMapper $agreementMapper,
		private PauseMapper $pauseMapper,
		private MemberPreferenceMapper $memberPreferenceMapper,
		private DigestChangeMapper $digestChangeMapper,
		private DigestSlotMapper $digestSlotMapper,
		private CompletionRevisionMapper $completionRevisionMapper,
		private WorkShareMapper $workShareMapper,
		private RewardRequestService $rewardRequestService,
		private RewardService $rewardService,
		private TransactionExecutor $transactions,
		private AppLogger $appLogger,
		private ArcadeStartPointStore $arcadeStartPoints,
		private ColorSchemeService $colorSchemes,
		private TaskMapper $taskMapper,
		private RoomTaskMapper $roomTaskMapper,
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
					// The departed actor has no membership row: the wallet id
					// and its user id are the provenance that still finds their data.
					$this->eraseUserReferencesUnderLock((int)$wallet->getHouseId(), $wallet->getNextcloudUserId());
					$this->eraseWalletUnderLock(null, $wallet);
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
		// CS-PLAN-090 D-090-64: the arcade start point and the colour
		// scheme are per-user settings; Nextcloud deletes them with the
		// account, this makes it explicit.
		$this->arcadeStartPoints->forget($nextcloudUserId);
		$this->colorSchemes->forget($nextcloudUserId);

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
	 * aggregate (house → membership deletes → the wallet step → receipts/
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
			$this->eraseUserReferencesUnderLock($houseId, $ncUserId);
			$this->medalMapper->deleteByUserIds([$houseUserId]);
			$this->minigameScoreMapper->deleteByUserIds([$ncUserId]);

			// Personal finances removed (no active reservations survive:
			// WP7 releases holds and purges the reward data first).
			$wallet = $this->walletMapper->findByHouseAndUser($houseId, $ncUserId);
			if ($wallet !== null) {
				$this->eraseWalletUnderLock($houseUserId, $wallet);
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
			// Sub-task ticks stay; the member who ticked them goes (both the
			// per-cycle rows of ADR-021 and the retained per-occurrence rows).
			$this->cycleProgressMapper->anonymizeChecker($houseUserId);
			$this->checklistProgressMapper->anonymizeChecker($houseUserId);

			// CS-PLAN-070 WP3 (§3.6): correction audit rows lose the actor
			// (bounded facts stay); the work shares lost their wallet binding
			// with the wallet above. The room journal carries no actor
			// references.
			$this->completionRevisionMapper->anonymizeActor($houseUserId);

			// The membership row (and its scheduling preferences) dies last.
			$this->houseUserMapper->delete($membership);
		});
	}

	/**
	 * Remove the erased account's raw Nextcloud user id from the house's
	 * other rows under the held house lock (D-091-165, D-091-166): the
	 * creator of definitions (a private one stays private and visible only
	 * to its remaining assignees), the takeover names of room tasks and the
	 * assigner of assignments.
	 */
	private function eraseUserReferencesUnderLock(int $houseId, string $ncUserId): void {
		$this->taskMapper->forgetOwnerInHouse($houseId, $ncUserId);
		$this->roomTaskMapper->forgetTakeoverUserInHouse($houseId, $ncUserId);
		$this->taskAssigneeMapper->forgetAssignerInHouse($ncUserId, $houseId);
	}

	/**
	 * Erase one wallet of the deleted account under the held house lock.
	 *
	 * The membership loop and the sweep of an already-departed account run
	 * the same steps, so neither leaves a wallet-keyed link behind
	 * (BUG-SEC-003: the sweep used to keep the appointment members, both
	 * snapshot kinds and the personal absences).
	 *
	 * @param int|null $houseUserId The membership, null for a departed account
	 * @param Wallet $wallet The wallet; its house is the one whose lock is held
	 */
	private function eraseWalletUnderLock(?int $houseUserId, Wallet $wallet): void {
		$houseId = (int)$wallet->getHouseId();
		$walletId = (int)$wallet->getId();
		// CS-PLAN-070 WP7 (CS-PLAN-078 §3.4): release/cancel holds, purge the
		// person's requests/events/wishes, remove queued references to them
		// from ALL recipients and anonymize the actor on other retained
		// records, before the wallet row dies (wallet provenance finds them).
		$this->rewardRequestService->eraseActorUnderLock($houseId, $houseUserId, $walletId);
		$this->rewardService->eraseActorItemsUnderLock($houseUserId, $walletId);
		$this->walletEntryMapper->deleteByWalletIds([$walletId]);
		$this->walletEntryMapper->anonymizeActorWallet($walletId);
		// CS-PLAN-072 WP1 (T0): the shared appointment facts stay, the
		// person's member links go; rule-default and agreement snapshots keep
		// the numeric wallet fact and lose the account binding.
		$this->occMemberMapper->deleteByWalletId($walletId);
		$this->scheduleRevisionMapper->anonymizeDefaultMembers($houseId, $walletId);
		$this->agreementMapper->anonymizeMemberSnapshots($houseId, $walletId, $wallet->getNextcloudUserId());
		// ADR-021: closed cycles stay as shared facts and stop naming the
		// wallet; the coin factors frozen on completions are keyed by it too.
		$this->cycleMapper->forgetWallet($houseId, $walletId);
		$this->completedTaskMapper->forgetCoinFactorWallet($houseId, $walletId);
		// CS-PLAN-073 WP2 (§3.6): personal absences of the wallet go; shared
		// pause facts keep their content and lose the account references.
		$this->pauseMapper->deletePersonalByWalletId($walletId);
		// CS-PLAN-070 WP3 (§3.6): work shares lose the wallet binding (only
		// non-identifying shared work remains).
		$this->workShareMapper->anonymizeWallet($walletId);
		$this->walletMapper->delete($wallet);
	}
}
