<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Db\WorkShareMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\GameLockedException;
use OCA\ChoreStrike\Exception\ValidationException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\ArcadeAccessService;
use OCA\ChoreStrike\Service\ArcadeCatalog;
use OCA\ChoreStrike\Service\ArcadeStartPointStore;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * CS-PLAN-086 §3.2 (D-086-01/02/06): arcade access is a pure derivation of
 * the finished-chore count and the owner gate — no unlock state is stored,
 * so the state follows the ledger on every read. The ledger semantics
 * (helpers counted, undone and superseded shares ignored, the per-wallet
 * lower bound) are proven against real rows in WorkShareCountTest.
 *
 * CS-PLAN-090 WP-J (D-090-63/66): owner thresholds over the catalog
 * defaults, the reset of one member and the owner's progress list.
 */
class ArcadeAccessServiceTest extends TestCase {
	private const WALLET_ID = 7;

	private WorkShareMapper&MockObject $workShares;
	private CompletedTaskMapper&MockObject $completedTasks;
	private HouseMapper&MockObject $houseMapper;
	private HouseUserMapper&MockObject $houseUsers;
	private TransactionExecutor&MockObject $transactions;
	private ArcadeStartPointStore&MockObject $startPoints;
	private RewardConfigService&MockObject $config;
	private AuthorizationService&MockObject $auth;
	private AppLogger&MockObject $appLogger;
	private ArcadeAccessService $service;
	private bool $gateEnabled = true;
	/** @var array<string, mixed> */
	private array $storedThresholds = [];

	protected function setUp(): void {
		parent::setUp();
		$this->workShares = $this->createMock(WorkShareMapper::class);
		$this->completedTasks = $this->createMock(CompletedTaskMapper::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->houseUsers = $this->createMock(HouseUserMapper::class);
		$this->transactions = $this->createMock(TransactionExecutor::class);
		$this->transactions->method('run')->willReturnCallback(static fn (callable $fn): mixed => $fn());
		$this->startPoints = $this->createMock(ArcadeStartPointStore::class);
		$this->config = $this->createMock(RewardConfigService::class);
		$this->config->method('getArcadeGateEnabled')->willReturnCallback(fn (): bool => $this->gateEnabled);
		$this->config->method('getArcadeThresholds')->willReturnCallback(fn (): array => $this->storedThresholds);
		$this->auth = $this->createMock(AuthorizationService::class);
		$this->appLogger = $this->createMock(AppLogger::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->service = new ArcadeAccessService(
			$this->workShares,
			$this->completedTasks,
			$this->houseMapper,
			$this->houseUsers,
			$this->transactions,
			$this->startPoints,
			$this->config,
			$this->auth,
			$l10n,
			$this->appLogger,
		);
	}

	private function member(?int $walletId = self::WALLET_ID, string $userId = 'kid', string $name = 'Kid', int $id = 3): HouseUser {
		$member = new HouseUser();
		$member->setId($id);
		$member->setHouseId(1);
		$member->setNextcloudUserId($userId);
		$member->setDisplayName($name);
		$member->setWalletId($walletId);
		return $member;
	}

	/**
	 * @param array<int, int> $after The lower bounds the count must receive
	 */
	private function finishedChores(array $after, int ...$counts): void {
		$this->workShares->method('countValidSelectedByWallet')
			->with([self::WALLET_ID], $after)
			->willReturnOnConsecutiveCalls(...array_map(
				static fn (int $count): array => $count > 0 ? [self::WALLET_ID => $count] : [],
				$counts
			));
	}

	/** @return array<string, array{gameId: string, threshold: int, unlocked: bool}> */
	private static function gamesById(array $catalog): array {
		return array_column($catalog['games'], null, 'gameId');
	}

	/** @return array<string, int> */
	private static function defaults(): array {
		return array_map(static fn (array $rules): int => $rules['unlockThreshold'], ArcadeCatalog::GAMES);
	}

	public function testGetCatalog_ListsEveryCatalogGameInOrder(): void {
		$this->finishedChores([], 0);

		$catalog = $this->service->getCatalog($this->member());

		$this->assertSame(['gateEnabled', 'finishedChores', 'games'], array_keys($catalog));
		$this->assertSame(ArcadeCatalog::ids(), array_column($catalog['games'], 'gameId'));
		foreach ($catalog['games'] as $game) {
			$this->assertSame(['gameId', 'threshold', 'unlocked'], array_keys($game));
		}
	}

	public function testGetCatalog_BelowThreshold_LockedWithProgress(): void {
		$this->finishedChores([], 9);

		$catalog = $this->service->getCatalog($this->member());

		$this->assertTrue($catalog['gateEnabled']);
		$this->assertSame(9, $catalog['finishedChores']);
		$this->assertSame(['gameId' => 'snake', 'threshold' => 10, 'unlocked' => false], self::gamesById($catalog)['snake']);
	}

	public function testGetCatalog_AtThreshold_Unlocked(): void {
		$this->finishedChores([], 10);

		$this->assertTrue(self::gamesById($this->service->getCatalog($this->member()))['snake']['unlocked']);
	}

	/**
	 * GetCatalog_DerivesOnEveryRead_ReLocksWhenTheCountDrops: an undo that
	 * lowers the count re-locks the game — there is no stored unlock.
	 */
	public function testGetCatalog_DerivesOnEveryRead_ReLocksWhenTheCountDrops(): void {
		$this->finishedChores([], 10, 9);

		$this->assertTrue(self::gamesById($this->service->getCatalog($this->member()))['snake']['unlocked']);
		$this->assertFalse(self::gamesById($this->service->getCatalog($this->member()))['snake']['unlocked']);
	}

	/**
	 * GetCatalog_DefaultThresholds_OpenAt3_10_25_50 (D-086-06): without a
	 * stored setting every game reads its catalog default.
	 */
	public function testGetCatalog_DefaultThresholds_OpenAt3_10_25_50(): void {
		$this->finishedChores([], 0);

		$this->assertSame(
			['spots' => 0, 'socks' => 3, 'snake' => 10, 'tiles' => 25, 'dust' => 50],
			array_column($this->service->getCatalog($this->member())['games'], 'threshold', 'gameId')
		);
	}

	/**
	 * GetCatalog_ThresholdZero_OpenWithoutChores: Spot Sprint is the
	 * always-open entry game, so a new member can play right away.
	 */
	public function testGetCatalog_ThresholdZero_OpenWithoutChores(): void {
		$this->finishedChores([], 0);

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
		$this->finishedChores([], 0);

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
		$this->finishedChores([], 9);
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
		$this->finishedChores([], 12);
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

	// ========== WP-J: thresholds ==========

	/**
	 * Thresholds_StoredValuesOverrideTheDefaults: a stored value in range
	 * wins; a value out of range or of the wrong type reads the default.
	 */
	public function testThresholds_StoredValuesOverrideTheDefaults(): void {
		$this->storedThresholds = ['spots' => 2, 'socks' => 1, 'snake' => 501, 'tiles' => '5', 'dust' => 0, 'gone' => 4];

		$this->assertSame(
			['spots' => 2, 'socks' => 1, 'snake' => 10, 'tiles' => 25, 'dust' => 0],
			$this->service->thresholds()
		);
	}

	public function testGetCatalog_LoweredThreshold_OpensSockMatchWithOneChore(): void {
		$this->storedThresholds = ['socks' => 1];
		$this->finishedChores([], 1);

		$this->assertSame(['gameId' => 'socks', 'threshold' => 1, 'unlocked' => true], self::gamesById($this->service->getCatalog($this->member()))['socks']);
	}

	/**
	 * AssertUnlocked_RaisedThreshold_LocksTheEntryGame: an owner can put
	 * even Spot Sprint behind chores.
	 */
	public function testAssertUnlocked_RaisedThreshold_LocksTheEntryGame(): void {
		$this->storedThresholds = ['spots' => 2];
		$this->finishedChores([], 1);

		$this->expectException(GameLockedException::class);
		$this->service->assertUnlocked($this->member(), 'spots');
	}

	public function testAssertUnlocked_LoweredThreshold_Passes(): void {
		$this->storedThresholds = ['dust' => 4];
		$this->finishedChores([], 4);

		$this->service->assertUnlocked($this->member(), 'dust');
		$this->addToAssertionCount(1);
	}

	public function testSetThresholds_Owner_SavesEveryGameInCatalogOrderAndAudits(): void {
		$owner = $this->member(null, 'owner', 'Owner', 1);
		$this->auth->expects($this->once())->method('requireHouseOwner')->with(1)->willReturn($owner);
		$values = ['dust' => 40, 'tiles' => 20, 'snake' => 8, 'socks' => 1, 'spots' => 0];
		$expected = ['spots' => 0, 'socks' => 1, 'snake' => 8, 'tiles' => 20, 'dust' => 40];
		$this->config->expects($this->once())->method('setArcadeThresholds')
			->with($expected)
			->willReturnCallback(function (array $saved): void {
				$this->storedThresholds = $saved;
			});
		$this->appLogger->expects($this->once())
			->method('audit')
			->with('Arcade thresholds saved', $this->callback(
				static fn (array $context): bool => $context['event'] === 'arcade.thresholds.updated'
					&& $context['actorId'] === 1
					&& $context['thresholds'] === $expected
			));

		$catalog = $this->service->setThresholds(1, $values);

		$this->assertSame($expected, array_column($catalog['games'], 'threshold', 'gameId'), 'the answer is the owner\'s catalog with the new values');
	}

	/**
	 * @return array<string, array{array<mixed>}>
	 */
	public static function invalidThresholdProvider(): array {
		$valid = array_map(static fn (array $rules): int => $rules['unlockThreshold'], ArcadeCatalog::GAMES);
		return [
			'unknown game' => [$valid + ['chess' => 3]],
			'missing game' => [array_diff_key($valid, ['dust' => true])],
			'above the maximum' => [['snake' => 501] + $valid],
			'negative' => [['socks' => -1] + $valid],
			'numeric string' => [['socks' => '3'] + $valid],
			'fraction' => [['socks' => 2.5] + $valid],
			'null' => [['socks' => null] + $valid],
			'empty' => [[]],
		];
	}

	/**
	 * @dataProvider invalidThresholdProvider
	 * @param array<mixed> $thresholds
	 */
	public function testSetThresholds_InvalidPayload_RefusedWithoutWrite(array $thresholds): void {
		$this->auth->method('requireHouseOwner')->willReturn($this->member());
		$this->config->expects($this->never())->method('setArcadeThresholds');
		$this->appLogger->expects($this->never())->method('audit');

		$this->expectException(ValidationException::class);
		$this->service->setThresholds(1, $thresholds);
	}

	public function testSetThresholds_TheMaximumIsAllowed(): void {
		$this->auth->method('requireHouseOwner')->willReturn($this->member());
		$this->config->expects($this->once())->method('setArcadeThresholds')
			->with($this->callback(static fn (array $saved): bool => $saved['dust'] === ArcadeAccessService::THRESHOLD_MAX));

		$this->service->setThresholds(1, ['dust' => 500] + self::defaults());
	}

	public function testSetThresholds_NonOwner_DeniedWithoutWrite(): void {
		$this->auth->method('requireHouseOwner')->willThrowException(new ForbiddenException('Owner role required'));
		$this->config->expects($this->never())->method('setArcadeThresholds');

		$this->expectException(ForbiddenException::class);
		$this->service->setThresholds(1, self::defaults());
	}

	// ========== WP-J: reset and progress ==========

	/**
	 * ResetMember_StoresTheHighestCompletionIdUnderTheHouseLock (D-090-66):
	 * the house row is locked before the start point is read and written.
	 */
	public function testResetMember_StoresTheHighestCompletionIdUnderTheHouseLock(): void {
		$calls = [];
		$this->auth->method('requireHouseOwner')->willReturn($this->member(null, 'owner', 'Owner', 1));
		$this->houseMapper->expects($this->once())->method('findForUpdate')->with(1)
			->willReturnCallback(static function () use (&$calls): House {
				$calls[] = 'lock';
				return new House();
			});
		$this->houseUsers->method('findByHouseAndUser')->with(1, 'kid')->willReturn($this->member());
		$this->completedTasks->method('maxId')->willReturnCallback(static function () use (&$calls): int {
			$calls[] = 'maxId';
			return 42;
		});
		$before = time();
		$this->startPoints->expects($this->once())->method('set')
			->with('kid', 42, $this->callback(static fn (int $at): bool => $at >= $before && $at <= time()))
			->willReturnCallback(static function () use (&$calls): void {
				$calls[] = 'set';
			});

		$this->service->resetMember(1, 'kid');

		$this->assertSame(['lock', 'maxId', 'set'], $calls);
	}

	public function testResetMember_AuditsTheTargetByMemberId(): void {
		$this->auth->method('requireHouseOwner')->willReturn($this->member(null, 'owner', 'Owner', 1));
		$this->houseUsers->method('findByHouseAndUser')->willReturn($this->member());
		$this->appLogger->expects($this->once())
			->method('audit')
			->with('Arcade progress reset', $this->callback(
				static fn (array $context): bool => $context['event'] === 'arcade.progress.reset'
					&& $context['actorId'] === 1
					&& $context['targetMemberId'] === 3
					&& !in_array('kid', $context, true)
			));

		$this->service->resetMember(1, 'kid');
	}

	public function testResetMember_TargetOutsideTheHouse_DeniedWithoutWrite(): void {
		$this->auth->method('requireHouseOwner')->willReturn($this->member());
		$this->houseUsers->method('findByHouseAndUser')->willReturn(null);
		$this->startPoints->expects($this->never())->method('set');
		$this->appLogger->expects($this->never())->method('audit');

		$this->expectException(ForbiddenException::class);
		$this->service->resetMember(1, 'stranger');
	}

	public function testResetMember_NonOwner_DeniedBeforeTheLock(): void {
		$this->auth->method('requireHouseOwner')->willThrowException(new ForbiddenException('Owner role required'));
		$this->houseMapper->expects($this->never())->method('findForUpdate');
		$this->startPoints->expects($this->never())->method('set');

		$this->expectException(ForbiddenException::class);
		$this->service->resetMember(1, 'kid');
	}

	/**
	 * GetCatalog_AfterAReset_CountsOnlyLaterChores: the member's start point
	 * becomes the wallet's lower bound, so earlier work no longer counts
	 * and the game locks again.
	 */
	public function testGetCatalog_AfterAReset_CountsOnlyLaterChores(): void {
		$this->startPoints->method('forUsers')->with(['kid'])->willReturn(['kid' => ['after' => 42, 'at' => 1700000000]]);
		$this->finishedChores([self::WALLET_ID => 42], 2);

		$catalog = $this->service->getCatalog($this->member());

		$this->assertSame(2, $catalog['finishedChores']);
		$this->assertFalse(self::gamesById($catalog)['socks']['unlocked']);
	}

	public function testAssertUnlocked_AfterAReset_LocksUntilNewChores(): void {
		$this->startPoints->method('forUsers')->willReturn(['kid' => ['after' => 42, 'at' => 1700000000]]);
		$this->finishedChores([self::WALLET_ID => 42], 0);

		$this->expectException(GameLockedException::class);
		$this->service->assertUnlocked($this->member(), 'socks');
	}

	/**
	 * GetProgress_OneGroupedCount_InNameOrder (RF-090-26): every wallet
	 * with its own bound in one query; the list is sorted by name, never by
	 * contribution (ADR-019 decision 7).
	 */
	public function testGetProgress_OneGroupedCount_InNameOrder(): void {
		$this->auth->expects($this->once())->method('requireHouseOwner')->with(1);
		$this->houseUsers->method('findByHouseId')->with(1)->willReturn([
			$this->member(9, 'zoe', 'Zoe', 4),
			$this->member(null, 'baby', 'Ben', 5),
			$this->member(7, 'anna', 'anna', 6),
		]);
		$this->startPoints->method('forUsers')->with(['anna', 'baby', 'zoe'])
			->willReturn(['zoe' => ['after' => 10, 'at' => 1700000000]]);
		$this->workShares->expects($this->once())->method('countValidSelectedByWallet')
			->with([7, 9], [9 => 10])
			->willReturn([7 => 4, 9 => 2]);

		$this->assertSame([
			['userId' => 'anna', 'displayName' => 'anna', 'finishedChores' => 4, 'resetAt' => null],
			['userId' => 'baby', 'displayName' => 'Ben', 'finishedChores' => 0, 'resetAt' => null],
			['userId' => 'zoe', 'displayName' => 'Zoe', 'finishedChores' => 2, 'resetAt' => '2023-11-14T22:13:20+00:00'],
		], $this->service->getProgress(1));
	}

	public function testGetProgress_NonOwner_DeniedWithoutRead(): void {
		$this->auth->method('requireHouseOwner')->willThrowException(new ForbiddenException('Owner role required'));
		$this->houseUsers->expects($this->never())->method('findByHouseId');

		$this->expectException(ForbiddenException::class);
		$this->service->getProgress(1);
	}
}
