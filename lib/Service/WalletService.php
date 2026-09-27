<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Wallet;
use OCA\ChoreStrike\Db\WalletEntry;
use OCA\ChoreStrike\Db\WalletEntryMapper;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Db\WorkShareMapper;

/**
 * The sole monetary authority (CS-PLAN-070 WP3 §3.3; CS-PLAN-071 WP0
 * binding retained).
 *
 * Every monetary movement is an append-only `wallet_entries` row with the
 * unique (wallet, source-kind, source-ID, source-revision) identity; the
 * wallet's `booked_minor` cache is updated in the SAME transaction and
 * must always equal the entry sum. Negative booked/available balances are
 * valid correction results; `reserved_minor >= 0` and
 * `fundingHold = bookedMinor < reservedMinor` (no reservation lifecycle in
 * this slice). Lower service — it never calls the completion coordinator
 * back and runs inside the caller's house-first transaction.
 */
class WalletService {
	public const SOURCE_COMPLETION = 'completion';
	public const SOURCE_COMPLETION_UNDO = 'completion_undo';

	public const REASON_COMPLETION = 'completion.credit';
	public const REASON_ATTRIBUTION = 'completion.attribution';
	public const REASON_UNDO = 'completion.undo';

	public function __construct(
		private WalletMapper $walletMapper,
		private WalletEntryMapper $walletEntryMapper,
		private WorkShareMapper $workShareMapper,
		private HouseUserMapper $houseUserMapper,
		private EffortAllocationService $allocation,
	) {
	}

	/**
	 * Persistent wallet identity helper (CS-PLAN-071 WP0, decision D1).
	 *
	 * The single implementation of the membership-to-wallet binding so every
	 * membership-creation path (bootstrap, invite, setup) enforces the same
	 * reattachment invariant: create the wallet when absent, bind its id on
	 * the house_users row when it differs. Idempotent; runs inside the
	 * caller's house-first transaction. Financial identity grants no access
	 * (authorization stays solely the active house_users membership).
	 */
	public function ensureBinding(int $houseId, HouseUser $member): HouseUser {
		$wallet = $this->walletMapper->createOrGet($houseId, $member->getNextcloudUserId());
		if ($member->getWalletId() !== $wallet->getId()) {
			$member->setWalletId($wallet->getId());
			return $this->houseUserMapper->update($member);
		}
		return $member;
	}

	/**
	 * Post ONE additive ledger entry per (wallet, source, revision) and
	 * move the booked cache by the same amount — the caller passes the
	 * exact delta map (walletId => signed delta minor). A repeated call
	 * with an already-written identity would violate the unique entry key
	 * and roll the transaction back: the completion aggregate writes each
	 * revision exactly once.
	 *
	 * @param array<int, int> $deltasByWallet walletId => signed delta minor
	 */
	public function postEntries(int $houseId, string $sourceKind, int $sourceId, int $sourceRevision, array $deltasByWallet, string $reasonCode, ?int $actorHouseUserId): void {
		$actor = $actorHouseUserId !== null ? $this->houseUserMapper->find($actorHouseUserId) : null;
		if ($actor !== null && ($actor->getHouseId() !== $houseId || $actor->getWalletId() === null)) {
			throw new \LogicException('Wallet entry actor must have a bound membership in this house');
		}
		foreach ($deltasByWallet as $walletId => $deltaMinor) {
			$walletId = (int)$walletId;
			$deltaMinor = (int)$deltaMinor;
			if ($deltaMinor === 0) {
				// A zero delta (attribution keeping a share identical) still
				// pins the identity: write the entry so the ledger records
				// the revision uniformly.
			}
			$this->allocation->assertSafeAmount($deltaMinor, 'wallet delta');

			$entry = new WalletEntry();
			$entry->setWalletId($walletId);
			$entry->setSourceKind($sourceKind);
			$entry->setSourceId($sourceId);
			$entry->setSourceRevision($sourceRevision);
			$entry->setDeltaMinor($deltaMinor);
			$entry->setReasonCode($reasonCode);
			$entry->setActorHouseUserId($actorHouseUserId);
			$entry->setActorWalletId($actor?->getWalletId());
			$entry->setCreatedAt(new \DateTime());
			$this->walletEntryMapper->insert($entry);

			$wallet = $this->walletMapper->findForUpdate($walletId);
			$booked = $wallet->getBookedMinor() + $deltaMinor;
			$this->allocation->assertSafeAmount($booked, 'wallet balance');
			$wallet->setBookedMinor($booked);
			$wallet->setVersion($wallet->getVersion() + 1);
			$wallet->setUpdatedAt(new \DateTime());
			$this->walletMapper->update($wallet);
		}
	}

	/**
	 * Re-derive the house_users.contribution_minor cache for the given
	 * memberships from the valid SELECTED work shares (WP3 §3.1: derived,
	 * never an independent authority). Runs under the caller's lock; each
	 * membership row must already be locked by the caller.
	 *
	 * @param int[] $walletIds
	 */
	public function recalcContributionCache(array $walletIds): void {
		// Callers may pass id-keyed OR user-keyed arrays — only the values
		// (wallet ids) matter; keys are discarded before the batch read.
		$walletIds = array_values(array_unique(array_filter(array_map('intval', array_values($walletIds)))));
		if ($walletIds === []) {
			return;
		}

		$sums = $this->workShareMapper->sumValidSelectedByWallet($walletIds);
		foreach ($walletIds as $walletId) {
			$member = $this->houseUserMapper->findByWalletId($walletId);
			if ($member === null) {
				continue;
			}
			$minor = $sums[$walletId] ?? 0;
			$this->allocation->assertSafeAmount($minor, 'contribution');
			if ($member->getContributionMinor() !== $minor) {
				$member->setContributionMinor($minor);
				$this->houseUserMapper->update($member);
			}
		}
	}

	/**
	 * The caller-safe own-wallet DTO (WP3 §3.6): minor units only, never
	 * whole Coins, plus the funding hold derived from
	 * `bookedMinor < reservedMinor`.
	 *
	 * @return array{bookedMinor: int, reservedMinor: int, availableMinor: int, fundingHold: bool}
	 */
	public function serializeOwnWallet(Wallet $wallet): array {
		$booked = $wallet->getBookedMinor();
		$reserved = $wallet->getReservedMinor();
		return [
			'bookedMinor' => $booked,
			'reservedMinor' => $reserved,
			'availableMinor' => $booked - $reserved,
			'fundingHold' => $booked < $reserved,
		];
	}
}
