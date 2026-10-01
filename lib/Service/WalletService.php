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
use OCA\ChoreStrike\Exception\ConflictException;
use OCP\IL10N;

/**
 * The sole monetary authority (CS-PLAN-070 WP3 §3.3; CS-PLAN-071 WP0
 * binding retained).
 *
 * Every monetary movement is an append-only `wallet_entries` row with the
 * unique (wallet, source-kind, source-ID, source-revision) identity; the
 * wallet's `booked_minor` cache is updated in the SAME transaction and
 * must always equal the entry sum. Negative booked/available balances are
 * valid correction results; `reserved_minor >= 0` and
 * `fundingHold = bookedMinor < reservedMinor` (WP7: reservations live in
 * the reward aggregate — reserve at request creation, settle only at
 * confirmed delivery, refund only an owner-attested false fulfillment).
 * B and R are the ONLY balance caches; neither is ever repaired at read
 * time. Lower service — it never calls the completion coordinator back
 * and runs inside the caller's house-first transaction (lock order:
 * wallets by ID before the reward rows).
 */
class WalletService {
	public const SOURCE_COMPLETION = 'completion';
	public const SOURCE_COMPLETION_UNDO = 'completion_undo';
	/** WP7 §3.2: keyed by request ID + effect revision (event revision). */
	public const SOURCE_REWARD_SETTLEMENT = 'reward_settlement';
	public const SOURCE_REWARD_REFUND = 'reward_refund';

	public const REASON_COMPLETION = 'completion.credit';
	public const REASON_ATTRIBUTION = 'completion.attribution';
	public const REASON_UNDO = 'completion.undo';
	public const REASON_REWARD_SETTLEMENT = 'reward.settlement';
	public const REASON_REWARD_REFUND = 'reward.refund';

	public function __construct(
		private WalletMapper $walletMapper,
		private WalletEntryMapper $walletEntryMapper,
		private WorkShareMapper $workShareMapper,
		private HouseUserMapper $houseUserMapper,
		private EffortAllocationService $allocation,
		private IL10N $l10n,
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
	 * @return array<int, int> walletId => written ledger entry id (the WP7
	 *                         settlement/refund references are stored on the request row)
	 */
	public function postEntries(int $houseId, string $sourceKind, int $sourceId, int $sourceRevision, array $deltasByWallet, string $reasonCode, ?int $actorHouseUserId): array {
		$actor = $actorHouseUserId !== null ? $this->houseUserMapper->find($actorHouseUserId) : null;
		if ($actor !== null && ($actor->getHouseId() !== $houseId || $actor->getWalletId() === null)) {
			throw new \LogicException('Wallet entry actor must have a bound membership in this house');
		}
		$entryIds = [];
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
			$inserted = $this->walletEntryMapper->insert($entry);

			$wallet = $this->walletMapper->findForUpdate($walletId);
			$booked = $wallet->getBookedMinor() + $deltaMinor;
			$this->allocation->assertSafeAmount($booked, 'wallet balance');
			$wallet->setBookedMinor($booked);
			$wallet->setVersion($wallet->getVersion() + 1);
			$wallet->setUpdatedAt(new \DateTime());
			$this->walletMapper->update($wallet);
			$entryIds[$walletId] = (int)$inserted->getId();
		}
		return $entryIds;
	}

	/**
	 * WP7 under-lock reservation (§3.2): `R += P` with the create-time
	 * coverage requirement `A = B - R >= P`. B is unchanged; no booked
	 * ledger entry exists for a reservation. The wallet row must be
	 * writable under the caller's house-first transaction (wallet lock
	 * taken here, after the house row per the documented order).
	 *
	 * @throws ConflictException on insufficient available coins (409)
	 */
	public function reserveReward(int $walletId, int $amountMinor): Wallet {
		if ($amountMinor <= 0) {
			throw new \InvalidArgumentException('A reservation requires a positive amount');
		}
		$wallet = $this->walletMapper->findForUpdate($walletId);
		$available = $wallet->getBookedMinor() - $wallet->getReservedMinor();
		if ($available < $amountMinor) {
			throw new ConflictException($this->l10n->t('Not enough available Coins for this request'));
		}
		$reserved = $wallet->getReservedMinor() + $amountMinor;
		$this->allocation->assertSafeAmount($reserved, 'wallet reservation');
		$wallet->setReservedMinor($reserved);
		$wallet->setVersion($wallet->getVersion() + 1);
		$wallet->setUpdatedAt(new \DateTime());
		return $this->walletMapper->update($wallet);
	}

	/**
	 * WP7 under-lock release (§3.2): `R -= P` for a rejection/cancellation
	 * — also valid while the request is underfunded (coverage is the
	 * aggregate check, never a per-request lock). No booked ledger entry.
	 */
	public function releaseReward(int $walletId, int $amountMinor): Wallet {
		if ($amountMinor <= 0) {
			throw new \InvalidArgumentException('A release requires a positive amount');
		}
		$wallet = $this->walletMapper->findForUpdate($walletId);
		$reserved = $wallet->getReservedMinor() - $amountMinor;
		if ($reserved < 0) {
			throw new \LogicException('Reservation release would undershoot zero');
		}
		$wallet->setReservedMinor($reserved);
		$wallet->setVersion($wallet->getVersion() + 1);
		$wallet->setUpdatedAt(new \DateTime());
		return $this->walletMapper->update($wallet);
	}

	/**
	 * WP7 settlement (§3.2): debit the booked balance through the ledger
	 * (`reward_settlement`, keyed by request ID + effect revision) and
	 * release the reservation in the SAME aggregate — `B -= P` and
	 * `R -= P` together, exactly once per identity. The aggregate
	 * coverage check `B >= R` is enforced here against the locked row.
	 *
	 * @return int The stored settlement ledger entry id
	 * @throws ConflictException on a funding hold (409)
	 */
	public function settleReward(int $houseId, int $requestId, int $effectRevision, int $walletId, int $amountMinor, ?int $actorHouseUserId): int {
		if ($amountMinor <= 0) {
			throw new \InvalidArgumentException('A settlement requires a positive amount');
		}
		$wallet = $this->walletMapper->findForUpdate($walletId);
		if ($wallet->getBookedMinor() < $wallet->getReservedMinor()) {
			throw new ConflictException($this->l10n->t('This request cannot be fulfilled while the wallet is underfunded'));
		}
		$entryIds = $this->postEntries(
			$houseId,
			self::SOURCE_REWARD_SETTLEMENT,
			$requestId,
			$effectRevision,
			[$walletId => -$amountMinor],
			self::REASON_REWARD_SETTLEMENT,
			$actorHouseUserId,
		);
		// The same locked wallet row: release the reservation together
		// with the debit (one aggregate, no intermediate visible state).
		$wallet = $this->walletMapper->findForUpdate($walletId);
		$reserved = $wallet->getReservedMinor() - $amountMinor;
		if ($reserved < 0) {
			throw new \LogicException('Settlement would undershoot the reservation');
		}
		$wallet->setReservedMinor($reserved);
		$wallet->setVersion($wallet->getVersion() + 1);
		$wallet->setUpdatedAt(new \DateTime());
		$this->walletMapper->update($wallet);
		return $entryIds[$walletId];
	}

	/**
	 * WP7 refund (§3.2): credit back an owner-attested false fulfillment
	 * exactly once through the ledger (`reward_refund`, keyed by request
	 * ID + effect revision). No reservation is created; the amount is the
	 * ORIGINAL settlement debit, never the current item price.
	 *
	 * @return int The stored refund ledger entry id
	 */
	public function refundReward(int $houseId, int $requestId, int $effectRevision, int $walletId, int $amountMinor, ?int $actorHouseUserId): int {
		if ($amountMinor <= 0) {
			throw new \InvalidArgumentException('A refund requires a positive amount');
		}
		$entryIds = $this->postEntries(
			$houseId,
			self::SOURCE_REWARD_REFUND,
			$requestId,
			$effectRevision,
			[$walletId => $amountMinor],
			self::REASON_REWARD_REFUND,
			$actorHouseUserId,
		);
		return $entryIds[$walletId];
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
