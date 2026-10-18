<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\WorkShareMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\GameLockedException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\ArcadeAccessService;
use OCA\ChoreStrike\Service\ArcadeCatalog;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * CS-PLAN-086 §3.2 (D-086-01/02/06): arcade access is a pure derivation of
 * the finished-chore count and the owner gate — nothing is stored, so the
 * state follows the ledger on every read. The ledger semantics (helpers
 * counted, undone and superseded shares ignored) are proven against real
 * rows in WorkShareCountTest.
 */
class ArcadeAccessServiceTest extends TestCase {
	private const WALLET_ID = 7;

	private WorkShareMapper&MockObject $workShares;
	private RewardConfigService&MockObject $config;
	private AuthorizationService&MockObject $auth;
	private AppLogger&MockObject $appLogger;
	private ArcadeAccessService $service;
	private bool $gateEnabled = true;

	protected function setUp(): void {
		parent::setUp();
		$this->workShares = $this->createMock(WorkShareMapper::class);
		$this->config = $this->createMock(RewardConfigService::class);
		$this->config->method('getArcadeGateEnabled')->willReturnCallback(fn (): bool => $this->gateEnabled);
		$this->auth = $this->createMock(AuthorizationService::class);
		$this->appLogger = $this->createMock(AppLogger::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->service = new ArcadeAccessService($this->workShares, $this->config, $this->auth, $l10n, $this->appLogger);
	}

	private function member(?int $walletId = self::WALLET_ID): HouseUser {
		$member = new HouseUser();
		$member->setId(3);
		$member->setHouseId(1);
		$member->setNextcloudUserId('kid');
		$member->setWalletId($walletId);
		return $member;
	}

	private function finishedChores(int ...$counts): void {
		$this->workShares->method('countValidSelectedByWallet')
			->with([self::WALLET_ID])
			->willReturnOnConsecutiveCalls(...array_map(
				static fn (int $count): array => $count > 0 ? [self::WALLET_ID => $count] : [],
				$counts
			));
	}

	/** @return array<string, array{gameId: string, threshold: int, unlocked: bool}> */
	private static function gamesById(array $catalog): array {
		return array_column($catalog['games'], null, 'gameId');
	}

	public function testGetCatalog_ListsEveryCatalogGameInOrder(): void {
		$this->finishedChores(0);

		$catalog = $this->service->getCatalog($this->member());

		$this->assertSame(['gateEnabled', 'finishedChores', 'games'], array_keys($catalog));
		$this->assertSame(ArcadeCatalog::ids(), array_column($catalog['games'], 'gameId'));
		foreach ($catalog['games'] as $game) {
			$this->assertSame(['gameId', 'threshold', 'unlocked'], array_keys($game));
		}
	}

	public function testGetCatalog_BelowThreshold_LockedWithProgress(): void {
		$this->finishedChores(9);

		$catalog = $this->service->getCatalog($this->member());

		$this->assertTrue($catalog['gateEnabled']);
		$this->assertSame(9, $catalog['finishedChores']);
		$this->assertSame(['gameId' => 'snake', 'threshold' => 10, 'unlocked' => false], self::gamesById($catalog)['snake']);
	}

	public function testGetCatalog_AtThreshold_Unlocked(): void {
		$this->finishedChores(10);

		$this->assertTrue(self::gamesById($this->service->getCatalog($this->member()))['snake']['unlocked']);
	}

	/**
	 * GetCatalog_DerivesOnEveryRead_ReLocksWhenTheCountDrops: an undo that
	 * lowers the count re-locks the game — there is no stored unlock.
	 */
	public function testGetCatalog_DerivesOnEveryRead_ReLocksWhenTheCountDrops(): void {
		$this->finishedChores(10, 9);

		$this->assertTrue(self::gamesById($this->service->getCatalog($this->member()))['snake']['unlocked']);
		$this->assertFalse(self::gamesById($this->service->getCatalog($this->member()))['snake']['unlocked']);
	}

	/**
	 * GetCatalog_SockMatch_OpensWithTheThirdFinishedChore (D-086-06)
	 */
	public function testGetCatalog_SockMatch_OpensWithTheThirdFinishedChore(): void {
		$this->finishedChores(2, 3);

		$this->assertSame(['gameId' => 'socks', 'threshold' => 3, 'unlocked' => false], self::gamesById($this->service->getCatalog($this->member()))['socks']);
		$this->assertTrue(self::gamesById($this->service->getCatalog($this->member()))['socks']['unlocked']);
	}

	/**
	 * GetCatalog_TileBreaker_OpensWithTheTwentyFifthFinishedChore (D-086-06)
	 */
	public function testGetCatalog_TileBreaker_OpensWithTheTwentyFifthFinishedChore(): void {
		$this->finishedChores(24, 25);

		$this->assertSame(['gameId' => 'tiles', 'threshold' => 25, 'unlocked' => false], self::gamesById($this->service->getCatalog($this->member()))['tiles']);
		$this->assertTrue(self::gamesById($this->service->getCatalog($this->member()))['tiles']['unlocked']);
	}

	/**
	 * GetCatalog_DustRunner_OpensWithTheFiftiethFinishedChore (D-086-06)
	 */
	public function testGetCatalog_DustRunner_OpensWithTheFiftiethFinishedChore(): void {
		$this->finishedChores(49, 50);

		$this->assertSame(['gameId' => 'dust', 'threshold' => 50, 'unlocked' => false], self::gamesById($this->service->getCatalog($this->member()))['dust']);
		$this->assertTrue(self::gamesById($this->service->getCatalog($this->member()))['dust']['unlocked']);
	}

	/**
	 * GetCatalog_ThresholdZero_OpenWithoutChores: Spot Sprint is the
	 * always-open entry game, so a new member can play right away.
	 */
	public function testGetCatalog_ThresholdZero_OpenWithoutChores(): void {
		$this->finishedChores(0);

		$spots = self::gamesById($this->service->getCatalog($this->member()))['spots'];

		$this->assertSame(['gameId' => 'spots', 'threshold' => 0, 'unlocked' => true], $spots);
	}

	public function testAssertUnlocked_ThresholdZero_SkipsTheCount(): void {
		$this->workShares->expects($this->never())->method('countValidSelectedByWallet');
		$this->appLogger->expects($this->never())->method('warning');

		$this->service->assertUnlocked($this->member(), 'spots');
		$this->addToAssertionCount(1);
	}

	public function testGetCatalog_GateOff_OpensEveryGame(): void {
		$this->gateEnabled = false;
		$this->finishedChores(0);

		$catalog = $this->service->getCatalog($this->member());

		$this->assertFalse($catalog['gateEnabled']);
		foreach ($catalog['games'] as $game) {
			$this->assertTrue($game['unlocked'], $game['gameId']);
		}
	}

	public function testGetCatalog_NoWallet_CountsZeroWithoutQuery(): void {
		$this->workShares->expects($this->never())->method('countValidSelectedByWallet');

		$catalog = $this->service->getCatalog($this->member(null));

		$this->assertSame(0, $catalog['finishedChores']);
		$this->assertFalse(self::gamesById($catalog)['snake']['unlocked']);
	}

	public function testAssertUnlocked_LockedGame_ThrowsAndLogs(): void {
		$this->finishedChores(9);
		$this->appLogger->expects($this->once())
			->method('warning')
			->with('Minigame score save rejected: game locked', $this->callback(
				static fn (array $context): bool => $context['event'] === 'minigame_locked' && $context['gameId'] === 'snake'
			));

		$this->expectException(GameLockedException::class);
		$this->expectExceptionMessage('This game is still locked.');
		$this->service->assertUnlocked($this->member(), 'snake');
	}

	public function testAssertUnlocked_ThresholdReached_Passes(): void {
		$this->finishedChores(12);
		$this->appLogger->expects($this->never())->method('warning');

		$this->service->assertUnlocked($this->member(), 'snake');
		$this->addToAssertionCount(1);
	}

	public function testAssertUnlocked_GateOff_SkipsTheCount(): void {
		$this->gateEnabled = false;
		$this->workShares->expects($this->never())->method('countValidSelectedByWallet');

		$this->service->assertUnlocked($this->member(), 'snake');
		$this->addToAssertionCount(1);
	}

	public function testSetGateEnabled_Owner_WritesAndAudits(): void {
		$this->auth->expects($this->once())->method('requireHouseOwner')->with(1)->willReturn($this->member());
		$this->config->expects($this->once())->method('setArcadeGateEnabled')->with(false);
		$this->appLogger->expects($this->once())
			->method('audit')
			->with('Arcade gate update completed', $this->callback(
				static fn (array $context): bool => $context['event'] === 'arcade.gate.updated'
					&& $context['actorId'] === 3
					&& $context['gateEnabled'] === false
					&& $context['outcome'] === 'changed'
			));

		$this->service->setGateEnabled(1, false);
	}

	public function testSetGateEnabled_SameValue_IsIdempotent(): void {
		$this->auth->method('requireHouseOwner')->willReturn($this->member());
		$this->config->expects($this->never())->method('setArcadeGateEnabled');
		$this->appLogger->expects($this->once())
			->method('audit')
			->with($this->anything(), $this->callback(static fn (array $context): bool => $context['outcome'] === 'no-change'));

		$this->service->setGateEnabled(1, true);
	}

	public function testSetGateEnabled_NonOwner_DeniedWithoutWrite(): void {
		$this->auth->method('requireHouseOwner')->willThrowException(new ForbiddenException('Owner role required'));
		$this->config->expects($this->never())->method('setArcadeGateEnabled');

		$this->expectException(ForbiddenException::class);
		$this->service->setGateEnabled(1, false);
	}
}
