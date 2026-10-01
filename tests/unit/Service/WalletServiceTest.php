<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Wallet;
use OCA\ChoreStrike\Db\WalletEntry;
use OCA\ChoreStrike\Db\WalletEntryMapper;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Service\WalletService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the shared wallet-binding helper (CS-PLAN-071 WP0) and
 * the WP7 reservation/settlement/refund primitives (CS-PLAN-070 WP7 T6,
 * C3.10-11): exact reserve/release/settle/refund arithmetic and the
 * aggregate funding hold.
 */
class WalletServiceTest extends TestCase {
	private WalletMapper $walletMapper;
	private HouseUserMapper $houseUserMapper;
	private WalletEntryMapper $walletEntryMapper;
	private WalletService $service;

	protected function setUp(): void {
		$this->walletMapper = $this->createMock(WalletMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->walletEntryMapper = $this->createMock(WalletEntryMapper::class);
		// postEntries' actor validation: a bound membership of this house.
		$actor = new HouseUser();
		$actor->setId(12);
		$actor->setHouseId(1);
		$actor->setNextcloudUserId('actor');
		$actor->setWalletId(99);
		$this->houseUserMapper->method('find')->willReturn($actor);
		// Identity l10n: message assertions match the wrapped source text.
		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		// WP3: the ledger collaborators (entries, work shares, allocation
		// arithmetic) are irrelevant to the binding tests — auto-mocked.
		$this->service = new WalletService(
			$this->walletMapper,
			$this->walletEntryMapper,
			$this->createMock(\OCA\ChoreStrike\Db\WorkShareMapper::class),
			$this->houseUserMapper,
			new \OCA\ChoreStrike\Service\EffortAllocationService(),
			$l10n,
		);
	}

	private function wallet(int $booked, int $reserved): Wallet {
		$wallet = new Wallet();
		$wallet->setId(55);
		$wallet->setHouseId(1);
		$wallet->setBookedMinor($booked);
		$wallet->setReservedMinor($reserved);
		$wallet->setVersion(3);
		return $wallet;
	}

	private function member(int $id, ?int $walletId): HouseUser {
		$member = new HouseUser();
		$member->setId($id);
		$member->setHouseId(1);
		$member->setNextcloudUserId('wallet_user');
		if ($walletId !== null) {
			$member->setWalletId($walletId);
		}
		return $member;
	}

	/**
	 * EnsureBinding_AbsentWallet_CreatesAndBinds
	 */
	public function testEnsureBinding_AbsentWallet_CreatesAndBinds(): void {
		$member = $this->member(11, null);
		$wallet = new Wallet();
		$wallet->setId(55);

		$this->walletMapper->expects($this->once())
			->method('createOrGet')
			->with(1, 'wallet_user')
			->willReturn($wallet);
		$this->houseUserMapper->expects($this->once())
			->method('update')
			->willReturnCallback(static fn (HouseUser $m) => $m);

		$result = $this->service->ensureBinding(1, $member);

		$this->assertSame(55, $result->getWalletId(), 'the membership row carries the new wallet id');
	}

	/**
	 * EnsureBinding_ExistingBinding_IsIdempotent
	 */
	public function testEnsureBinding_ExistingBinding_IsIdempotent(): void {
		$member = $this->member(11, 55);
		$wallet = new Wallet();
		$wallet->setId(55);

		$this->walletMapper->method('createOrGet')->willReturn($wallet);
		$this->houseUserMapper->expects($this->never())->method('update');

		$result = $this->service->ensureBinding(1, $member);

		$this->assertSame($member, $result, 'no redundant membership write when the binding already matches');
	}

	/**
	 * EnsureBinding_Reattachment_RebindsThePreservedWallet
	 */
	public function testEnsureBinding_Reattendance_RebindsThePreservedWallet(): void {
		$member = $this->member(11, null);
		$wallet = new Wallet();
		$wallet->setId(77);

		$this->walletMapper->method('createOrGet')->willReturn($wallet);
		$this->houseUserMapper->expects($this->once())
			->method('update')
			->willReturnCallback(static fn (HouseUser $m) => $m);

		$result = $this->service->ensureBinding(1, $member);

		$this->assertSame(77, $result->getWalletId(), 'a re-invited account reattaches the preserved wallet');
	}

	/**
	 * PostEntries_RecordsActorWalletProvenanceOnceForEveryRecipient
	 */
	public function testPostEntries_RecordsActorWalletProvenanceOnceForEveryRecipient(): void {
		$actor = $this->member(12, 99);
		$recipientA = new Wallet();
		$recipientA->setId(77);
		$recipientA->setBookedMinor(0);
		$recipientA->setVersion(0);
		$recipientB = new Wallet();
		$recipientB->setId(88);
		$recipientB->setBookedMinor(0);
		$recipientB->setVersion(0);
		$this->houseUserMapper->expects($this->once())->method('find')->with(12)->willReturn($actor);
		$entries = [];
		$this->walletEntryMapper->expects($this->exactly(2))->method('insert')->willReturnCallback(
			static function (WalletEntry $entry) use (&$entries): WalletEntry {
				$entries[] = $entry;
				return $entry;
			}
		);
		$this->walletMapper->method('findForUpdate')->willReturnCallback(
			static fn (int $id): Wallet => $id === 77 ? $recipientA : $recipientB
		);
		$this->walletMapper->method('update')->willReturnArgument(0);

		$this->service->postEntries(1, WalletService::SOURCE_COMPLETION, 42, 1, [77 => 250, 88 => 350], WalletService::REASON_COMPLETION, 12);

		self::assertSame([77, 88], array_map(static fn (WalletEntry $entry): int => $entry->getWalletId(), $entries));
		self::assertSame([12, 12], array_map(static fn (WalletEntry $entry): int => $entry->getActorHouseUserId(), $entries));
		self::assertSame([99, 99], array_map(static fn (WalletEntry $entry): int => $entry->getActorWalletId(), $entries));
		self::assertSame([250, 350], array_map(static fn (WalletEntry $entry): int => $entry->getDeltaMinor(), $entries));
	}

	/**
	 * ReserveReward_MovesReservedOnlyWithinCoverage (T6)
	 */
	public function testReserveReward_MovesReservedOnlyWithinCoverage(): void {
		$wallet = $this->wallet(4000, 0);
		$this->walletMapper->method('findForUpdate')->willReturn($wallet);
		$this->walletMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$result = $this->service->reserveReward(55, 1250);

		self::assertSame(1250, $result->getReservedMinor(), 'R += P');
		self::assertSame(4000, $result->getBookedMinor(), 'B is unchanged by a reservation');
		self::assertSame(4, $result->getVersion(), 'the cache write bumps the optimistic version');
	}

	/**
	 * ReserveReward_InsufficientAvailable_Conflicts (T6)
	 */
	public function testReserveReward_InsufficientAvailable_Conflicts(): void {
		$wallet = $this->wallet(1000, 0);
		$this->walletMapper->method('findForUpdate')->willReturn($wallet);
		$this->walletMapper->expects($this->never())->method('update');

		$this->expectException(ConflictException::class);
		$this->service->reserveReward(55, 1250);
	}

	/**
	 * ReserveReward_UnderFundedHold_BlocksNewRequests (C3.10: B=40, R=60
	 * minor-units x100 — A=-2000 denies a new positive-price request).
	 */
	public function testReserveReward_UnderFundedHold_BlocksNewRequests(): void {
		$wallet = $this->wallet(4000, 6000);
		$this->walletMapper->method('findForUpdate')->willReturn($wallet);
		$this->walletMapper->expects($this->never())->method('update');

		$this->expectException(ConflictException::class);
		$this->service->reserveReward(55, 100);
	}

	/**
	 * ReleaseReward_ReducesReservedExactly (T6)
	 */
	public function testReleaseReward_ReducesReservedExactly(): void {
		$wallet = $this->wallet(4000, 6000);
		$this->walletMapper->method('findForUpdate')->willReturn($wallet);
		$this->walletMapper->method('update')->willReturnArgument(0);

		$result = $this->service->releaseReward(55, 1250);

		self::assertSame(4750, $result->getReservedMinor(), 'R -= P works while underfunded (cancellation)');
		self::assertSame(4000, $result->getBookedMinor(), 'a release never mints booked value');
	}

	/**
	 * SettleReward_DebitsAndReleasesTogetherOnce (C3.11 / T6)
	 */
	public function testSettleReward_DebitsAndReleasesTogetherOnce(): void {
		$wallet = $this->wallet(4000, 1250);
		$posted = null;
		$this->walletMapper->method('findForUpdate')->willReturn($wallet);
		$this->walletEntryMapper->expects($this->once())->method('insert')->willReturnCallback(
			function (WalletEntry $candidate) use (&$posted): WalletEntry {
				$posted = $candidate;
				$candidate->setId(9001);
				return $candidate;
			}
		);
		$this->walletMapper->method('update')->willReturnArgument(0);

		$entryId = $this->service->settleReward(1, 77, 4, 55, 1250, 12);

		self::assertSame(9001, $entryId, 'the stored settlement reference is the ledger entry id');
		self::assertSame(WalletService::SOURCE_REWARD_SETTLEMENT, $posted->getSourceKind());
		self::assertSame(77, $posted->getSourceId(), 'the ledger identity is keyed by the request id');
		self::assertSame(4, $posted->getSourceRevision(), 'and the effect revision');
		self::assertSame(-1250, $posted->getDeltaMinor(), 'B -= P through the ledger');
		self::assertSame(0, $wallet->getReservedMinor(), 'R -= P happens in the same aggregate');
	}

	/**
	 * SettleReward_FundingHold_ConflictsWithoutAnyWrite (T6)
	 */
	public function testSettleReward_FundingHold_ConflictsWithoutAnyWrite(): void {
		$wallet = $this->wallet(4000, 6000);
		$this->walletMapper->method('findForUpdate')->willReturn($wallet);
		$this->walletEntryMapper->expects($this->never())->method('insert');
		$this->walletMapper->expects($this->never())->method('update');

		$this->expectException(ConflictException::class);
		$this->service->settleReward(1, 77, 4, 55, 1250, 12);
	}

	/**
	 * RefundReward_CreditsBookedExactlyOnce (T6)
	 */
	public function testRefundReward_CreditsBookedExactlyOnce(): void {
		$wallet = $this->wallet(2750, 0);
		$entry = new WalletEntry();
		$entry->setId(9002);
		$this->walletMapper->method('findForUpdate')->willReturn($wallet);
		$this->walletEntryMapper->expects($this->once())->method('insert')->willReturnCallback(
			function (WalletEntry $posted) use ($entry): WalletEntry {
				$entry->setWalletId($posted->getWalletId());
				$entry->setSourceKind($posted->getSourceKind());
				$entry->setSourceId($posted->getSourceId());
				$entry->setSourceRevision($posted->getSourceRevision());
				$entry->setDeltaMinor($posted->getDeltaMinor());
				return $entry;
			}
		);
		$this->walletMapper->method('update')->willReturnArgument(0);

		$entryId = $this->service->refundReward(1, 77, 5, 55, 1250, 12);

		self::assertSame(9002, $entryId);
		self::assertSame(WalletService::SOURCE_REWARD_REFUND, $entry->getSourceKind());
		self::assertSame(1250, $entry->getDeltaMinor(), 'the ORIGINAL settlement debit is credited back');
		self::assertSame(0, $wallet->getReservedMinor(), 'a refund never recreates a reservation');
	}

	/**
	 * SerializeOwnWallet_DerivesAvailableAndFundingHold
	 */
	public function testSerializeOwnWallet_DerivesAvailableAndFundingHold(): void {
		$covered = $this->service->serializeOwnWallet($this->wallet(4000, 1250));
		self::assertSame(['bookedMinor' => 4000, 'reservedMinor' => 1250, 'availableMinor' => 2750, 'fundingHold' => false], $covered);

		$held = $this->service->serializeOwnWallet($this->wallet(4000, 6000));
		self::assertSame(['bookedMinor' => 4000, 'reservedMinor' => 6000, 'availableMinor' => -2000, 'fundingHold' => true], $held);
	}
}
