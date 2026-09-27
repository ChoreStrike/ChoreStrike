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
use OCA\ChoreStrike\Service\WalletService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the shared wallet-binding helper (CS-PLAN-071 WP0).
 *
 * One implementation of the membership-to-wallet reattachment invariant
 * for every membership-creation path (bootstrap, invite, setup).
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
		// WP3: the ledger collaborators (entries, work shares, allocation
		// arithmetic) are irrelevant to the binding tests — auto-mocked.
		$this->service = new WalletService(
			$this->walletMapper,
			$this->walletEntryMapper,
			$this->createMock(\OCA\ChoreStrike\Db\WorkShareMapper::class),
			$this->houseUserMapper,
			$this->createMock(\OCA\ChoreStrike\Service\EffortAllocationService::class),
		);
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
}
