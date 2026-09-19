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
	private WalletService $service;

	protected function setUp(): void {
		$this->walletMapper = $this->createMock(WalletMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->service = new WalletService($this->walletMapper, $this->houseUserMapper);
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
}
