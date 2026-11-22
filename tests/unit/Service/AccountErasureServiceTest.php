<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\AgreementMapper;
use OCA\ChoreStrike\Db\CommandReceiptMapper;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\CycleMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MedalMapper;
use OCA\ChoreStrike\Db\MemberPreferenceMapper;
use OCA\ChoreStrike\Db\MinigameScoreMapper;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\PauseMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\ScheduleRevisionMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Db\Wallet;
use OCA\ChoreStrike\Db\WalletEntryMapper;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Db\WorkShareMapper;
use OCA\ChoreStrike\Service\AccountErasureService;
use OCA\ChoreStrike\Service\AppLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the erasure sweep's failure containment (CS-PLAN-071
 * WP0 review finding).
 *
 * `UserDeletedEvent` fires exactly once: a per-house failure must never
 * abort the remaining sweep. Every membership/wallet is attempted,
 * failures are logged, and a summary marks the account for manual
 * follow-up.
 */
class AccountErasureServiceTest extends TestCase {
	private HouseUserMapper $houseUserMapper;
	private WalletMapper $walletMapper;
	private MinigameScoreMapper $minigameScoreMapper;
	private MedalMapper $medalMapper;
	private WalletEntryMapper $walletEntryMapper;
	private WorkShareMapper $workShareMapper;
	private MemberPreferenceMapper $memberPreferenceMapper;
	private TransactionExecutor $transactions;
	private AppLogger $appLogger;
	private \OCA\ChoreStrike\Service\RewardRequestService&\PHPUnit\Framework\MockObject\MockObject $rewardRequestService;
	private \OCA\ChoreStrike\Service\RewardService&\PHPUnit\Framework\MockObject\MockObject $rewardService;
	private \OCA\ChoreStrike\Service\ArcadeStartPointStore&\PHPUnit\Framework\MockObject\MockObject $arcadeStartPoints;
	private \OCA\ChoreStrike\Service\ColorSchemeService&\PHPUnit\Framework\MockObject\MockObject $colorSchemes;
	private CompletedTaskMapper&MockObject $completedTaskMapper;
	private OccurrenceMemberMapper&MockObject $occMemberMapper;
	private CycleMapper&MockObject $cycleMapper;
	private ScheduleRevisionMapper&MockObject $scheduleRevisionMapper;
	private AgreementMapper&MockObject $agreementMapper;
	private PauseMapper&MockObject $pauseMapper;
	private TaskAssigneeMapper&MockObject $taskAssigneeMapper;
	private TaskMapper&MockObject $taskMapper;
	private RoomTaskMapper&MockObject $roomTaskMapper;

	protected function setUp(): void {
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->walletMapper = $this->createMock(WalletMapper::class);
		$this->minigameScoreMapper = $this->createMock(MinigameScoreMapper::class);
		$this->medalMapper = $this->createMock(MedalMapper::class);
		$this->walletEntryMapper = $this->createMock(WalletEntryMapper::class);
		$this->workShareMapper = $this->createMock(WorkShareMapper::class);
		$this->memberPreferenceMapper = $this->createMock(MemberPreferenceMapper::class);
		$this->transactions = $this->createMock(TransactionExecutor::class);
		$this->appLogger = $this->createMock(AppLogger::class);
		$this->rewardRequestService = $this->createMock(\OCA\ChoreStrike\Service\RewardRequestService::class);
		$this->rewardService = $this->createMock(\OCA\ChoreStrike\Service\RewardService::class);
		$this->arcadeStartPoints = $this->createMock(\OCA\ChoreStrike\Service\ArcadeStartPointStore::class);
		$this->colorSchemes = $this->createMock(\OCA\ChoreStrike\Service\ColorSchemeService::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->occMemberMapper = $this->createMock(OccurrenceMemberMapper::class);
		$this->cycleMapper = $this->createMock(CycleMapper::class);
		$this->scheduleRevisionMapper = $this->createMock(ScheduleRevisionMapper::class);
		$this->agreementMapper = $this->createMock(AgreementMapper::class);
		$this->pauseMapper = $this->createMock(PauseMapper::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
	}

	private function membership(int $id, int $houseId): HouseUser {
		$membership = new HouseUser();
		$membership->setId($id);
		$membership->setHouseId($houseId);
		$membership->setNextcloudUserId('erased_user');
		return $membership;
	}

	private function wallet(int $id, int $houseId): Wallet {
		$wallet = new Wallet();
		$wallet->setId($id);
		$wallet->setHouseId($houseId);
		$wallet->setNextcloudUserId('erased_user');
		return $wallet;
	}

	private function service(): AccountErasureService {
		return new AccountErasureService(
			$this->createMock(HouseMapper::class),
			$this->houseUserMapper,
			$this->taskAssigneeMapper,
			$this->medalMapper,
			$this->minigameScoreMapper,
			$this->walletMapper,
			$this->walletEntryMapper,
			$this->createMock(CommandReceiptMapper::class),
			$this->completedTaskMapper,
			$this->occMemberMapper,
			$this->cycleMapper,
			$this->createMock(\OCA\ChoreStrike\Db\CycleProgressMapper::class),
			$this->createMock(\OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper::class),
			$this->scheduleRevisionMapper,
			$this->agreementMapper,
			$this->pauseMapper,
			$this->memberPreferenceMapper,
			$this->createMock(\OCA\ChoreStrike\Db\DigestChangeMapper::class),
			$this->createMock(\OCA\ChoreStrike\Db\DigestSlotMapper::class),
			$this->createMock(\OCA\ChoreStrike\Db\CompletionRevisionMapper::class),
			$this->workShareMapper,
			$this->rewardRequestService,
			$this->rewardService,
			$this->transactions,
			$this->appLogger,
			$this->arcadeStartPoints,
			$this->colorSchemes,
			$this->taskMapper,
			$this->roomTaskMapper,
		);
	}

	/**
	 * FirstMembershipFails_RemainingSweepStillRuns
	 */
	public function testFirstMembershipFails_RemainingSweepStillRuns(): void {
		$failing = $this->membership(1, 10);
		$surviving = $this->membership(2, 20);

		$this->houseUserMapper->method('findAllByNextcloudUserId')
			->with('erased_user')
			->willReturn([$failing, $surviving]);

		// First transaction (house 10) fails; the second (house 20)
		// executes its erasure aggregate.
		$runCount = 0;
		$this->transactions->method('run')
			->willReturnCallback(function (callable $fn) use (&$runCount): mixed {
				$runCount++;
				if ($runCount === 1) {
					throw new \RuntimeException('lock wait timeout');
				}
				return $fn();
			});

		// House 20's aggregate runs to completion.
		$this->medalMapper->expects($this->once())->method('deleteByUserIds')->with([2]);
		$this->walletMapper->method('findByHouseAndUser')->willReturn(null);
		$this->walletMapper->method('findAllByUserId')->willReturn([]);
		$this->houseUserMapper->expects($this->once())->method('delete')->with($surviving);

		// Per-failure logging + the terminal summary; no success info log.
		$this->appLogger->expects($this->once())->method('logException');
		$this->appLogger->expects($this->once())->method('error');
		$this->appLogger->expects($this->never())->method('info');

		$this->service()->eraseAccount('erased_user');

		self::assertSame(2, $runCount, 'the failing house must not abort the surviving house');
	}

	/**
	 * DepartedOnlyAccount_StillSweepsUIDKeyedScores
	 */
	public function testDepartedOnlyAccount_StillSweepsUIDKeyedScores(): void {
		$this->houseUserMapper->method('findAllByNextcloudUserId')->willReturn([]);
		$this->walletMapper->method('findAllByUserId')->willReturn([]);
		$this->minigameScoreMapper->expects($this->once())
			->method('deleteByUserIds')
			->with(['erased_user']);

		$this->appLogger->expects($this->once())->method('info');

		$this->service()->eraseAccount('erased_user');
	}

	/**
	 * Erasure_DeletesTheArcadeStartPoint (CS-PLAN-090 D-090-64): the
	 * per-user setting goes with the account, also for a departed-only
	 * account.
	 */
	public function testErasure_DeletesTheArcadeStartPoint(): void {
		$this->houseUserMapper->method('findAllByNextcloudUserId')->willReturn([]);
		$this->walletMapper->method('findAllByUserId')->willReturn([]);
		$this->arcadeStartPoints->expects($this->once())->method('forget')->with('erased_user');
		// CS-PLAN-090 WP-K: so does the colour scheme.
		$this->colorSchemes->expects($this->once())->method('forget')->with('erased_user');

		$this->service()->eraseAccount('erased_user');
	}

	public function testBlankUid_TouchesNoArcadeStartPoint(): void {
		$this->arcadeStartPoints->expects($this->never())->method('forget');
		$this->colorSchemes->expects($this->never())->method('forget');

		$this->service()->eraseAccount('  ');
	}

	/**
	 * CurrentMemberErasure_AnonymizesActorInSurvivingWalletLedgers
	 */
	public function testCurrentMemberErasure_AnonymizesActorInSurvivingWalletLedgers(): void {
		$membership = $this->membership(12, 10);
		$wallet = $this->wallet(42, 10);
		$this->houseUserMapper->method('findAllByNextcloudUserId')->willReturn([$membership]);
		$this->walletMapper->method('findByHouseAndUser')->with(10, 'erased_user')->willReturn($wallet);
		$this->walletMapper->method('findAllByUserId')->willReturn([]);
		$this->transactions->method('run')->willReturnCallback(static fn (callable $fn) => $fn());
		$this->walletEntryMapper->expects($this->once())->method('deleteByWalletIds')->with([42]);
		$this->walletEntryMapper->expects($this->once())->method('anonymizeActor')->with(12);
		$this->walletEntryMapper->expects($this->once())->method('anonymizeActorWallet')->with(42);
		$this->workShareMapper->expects($this->once())->method('anonymizeWallet')->with(42);
		$this->houseUserMapper->expects($this->once())->method('delete')->with($membership);

		$this->service()->eraseAccount('erased_user');
	}

	/**
	 * CurrentMemberErasure_PurgesRankingConsentByMembershipId
	 *
	 * CS-PLAN-070 WP4 (Q-075-01 A / Q-070-02): the ranking-consent row is
	 * personal data — purged with the erased membership, keyed by the
	 * MEMBERSHIP id (never the Nextcloud UID). A recreated same-UID
	 * account gets a fresh membership id, so the deleted consent can
	 * never rebind to it.
	 */
	public function testCurrentMemberErasure_PurgesRankingConsentByMembershipId(): void {
		$membership = $this->membership(12, 10);
		$this->houseUserMapper->method('findAllByNextcloudUserId')->willReturn([$membership]);
		$this->walletMapper->method('findByHouseAndUser')->willReturn(null);
		$this->walletMapper->method('findAllByUserId')->willReturn([]);
		$this->transactions->method('run')->willReturnCallback(static fn (callable $fn) => $fn());
		$this->houseUserMapper->expects($this->once())->method('delete')->with($membership);

		$this->memberPreferenceMapper->expects($this->once())
			->method('deleteByHouseAndUser')
			->with(10, 12);

		$this->service()->eraseAccount('erased_user');
	}

	/**
	 * DepartedWalletErasure_AnonymizesShareBeforeRemovingWallet
	 */
	public function testDepartedWalletErasure_AnonymizesShareBeforeRemovingWallet(): void {
		$wallet = $this->wallet(42, 10);
		$this->houseUserMapper->method('findAllByNextcloudUserId')->willReturn([]);
		$this->walletMapper->method('findAllByUserId')->willReturn([$wallet]);
		$this->transactions->method('run')->willReturnCallback(static fn (callable $fn) => $fn());
		$this->workShareMapper->expects($this->once())->method('anonymizeWallet')->with(42);
		$this->walletEntryMapper->expects($this->once())->method('deleteByWalletIds')->with([42]);
		$this->walletEntryMapper->expects($this->once())->method('anonymizeActorWallet')->with(42);
		$this->walletMapper->expects($this->once())->method('delete')->with($wallet);

		$this->service()->eraseAccount('erased_user');
	}

	/**
	 * ActiveMemberErasure_PurgesRewardDataBeforeTheWalletDies
	 *
	 * CS-PLAN-070 WP7 (CS-PLAN-078 §3.4, Q-070-02): holds are released,
	 * requests/events/wishes purged and queued references removed from
	 * ALL recipients BEFORE the wallet row dies — inside the same
	 * per-house transaction.
	 */
	public function testActiveMemberErasure_PurgesRewardDataBeforeTheWalletDies(): void {
		$membership = $this->membership(12, 10);
		$wallet = $this->wallet(42, 10);
		$this->houseUserMapper->method('findAllByNextcloudUserId')->willReturn([$membership]);
		$this->walletMapper->method('findByHouseAndUser')->with(10, 'erased_user')->willReturn($wallet);
		$this->walletMapper->method('findAllByUserId')->willReturn([]);
		$this->transactions->method('run')->willReturnCallback(static fn (callable $fn) => $fn());

		$order = [];
		$this->rewardRequestService->expects($this->once())
			->method('eraseActorUnderLock')
			->with(10, 12, 42)
			->willReturnCallback(function () use (&$order): void {
				$order[] = 'reward';
			});
		$this->rewardService->expects($this->once())
			->method('eraseActorItemsUnderLock')
			->with(12, 42)
			->willReturnCallback(function () use (&$order): void {
				$order[] = 'wishes';
			});
		$this->walletEntryMapper->expects($this->once())->method('deleteByWalletIds')->willReturnCallback(function () use (&$order): void {
			$order[] = 'wallet';
		});
		$this->houseUserMapper->expects($this->once())->method('delete')->with($membership);

		$this->service()->eraseAccount('erased_user');

		self::assertSame(['reward', 'wishes', 'wallet'], $order, 'reward data goes before the wallet row');
	}

	/**
	 * DepartedWalletErasure_PurgesRewardDataByWalletProvenance
	 */
	public function testDepartedWalletErasure_PurgesRewardDataByWalletProvenance(): void {
		$wallet = $this->wallet(42, 10);
		$this->houseUserMapper->method('findAllByNextcloudUserId')->willReturn([]);
		$this->walletMapper->method('findAllByUserId')->willReturn([$wallet]);
		$this->transactions->method('run')->willReturnCallback(static fn (callable $fn) => $fn());

		// The departed actor has no membership row: the wallet id is the
		// provenance that still finds their reward data.
		$this->rewardRequestService->expects($this->once())
			->method('eraseActorUnderLock')
			->with(10, null, 42);
		$this->rewardService->expects($this->once())
			->method('eraseActorItemsUnderLock')
			->with(null, 42);

		$this->service()->eraseAccount('erased_user');
	}

	/**
	 * Review regression (BUG-SEC-003, D-091-164 to D-091-166): the wallet of
	 * an already-departed account loses every link a current member's wallet
	 * loses, in its own house — the appointment members, the rule-default and
	 * agreement snapshots (with the plain user id) and the personal absences
	 * included — and both paths remove the raw user id from definitions,
	 * takeovers and assignments of that house.
	 */
	public function testDepartedWalletErasure_RemovesEveryLinkAMembershipRemoves(): void {
		$current = $this->wallet(42, 10);
		$departed = $this->wallet(43, 20);
		$this->houseUserMapper->method('findAllByNextcloudUserId')->willReturn([$this->membership(12, 10)]);
		$this->walletMapper->method('findByHouseAndUser')->willReturn($current);
		$this->walletMapper->method('findAllByUserId')->willReturn([$departed]);
		$this->transactions->method('run')->willReturnCallback(static fn (callable $fn) => $fn());

		$calls = [42 => [], 43 => []];
		$record = static function (MockObject $mock, string $method) use (&$calls): void {
			$mock->method($method)->willReturnCallback(static function (...$args) use (&$calls, $method): void {
				$walletId = (array)array_pop($args);
				$calls[$walletId[0]][] = $args === [] ? $method : $method . '@' . $args[0];
			});
		};
		$record($this->occMemberMapper, 'deleteByWalletId');
		$record($this->scheduleRevisionMapper, 'anonymizeDefaultMembers');
		$this->agreementMapper->method('anonymizeMemberSnapshots')->willReturnCallback(static function (int $houseId, int $walletId, string $userId) use (&$calls): void {
			$calls[$walletId][] = 'anonymizeMemberSnapshots@' . $houseId . ':' . $userId;
		});
		$userCalls = [10 => [], 20 => []];
		$byHouse = static function (MockObject $mock, string $method, int $housePosition) use (&$userCalls): void {
			$mock->method($method)->willReturnCallback(static function (...$args) use (&$userCalls, $method, $housePosition): void {
				$userCalls[$args[$housePosition]][] = $method . ':' . $args[1 - $housePosition];
			});
		};
		$byHouse($this->taskMapper, 'forgetOwnerInHouse', 0);
		$byHouse($this->roomTaskMapper, 'forgetTakeoverUserInHouse', 0);
		$byHouse($this->taskAssigneeMapper, 'forgetAssignerInHouse', 1);
		$record($this->cycleMapper, 'forgetWallet');
		$record($this->completedTaskMapper, 'forgetCoinFactorWallet');
		$record($this->pauseMapper, 'deletePersonalByWalletId');
		$record($this->workShareMapper, 'anonymizeWallet');
		$record($this->walletEntryMapper, 'deleteByWalletIds');
		$record($this->walletEntryMapper, 'anonymizeActorWallet');

		$this->service()->eraseAccount('erased_user');

		// The set of links matters, not the order inside the step.
		$expected = static function (int $houseId): array {
			$links = ['deleteByWalletIds', 'anonymizeActorWallet', 'deleteByWalletId',
				'anonymizeDefaultMembers@' . $houseId, 'anonymizeMemberSnapshots@' . $houseId . ':erased_user',
				'forgetWallet@' . $houseId, 'forgetCoinFactorWallet@' . $houseId,
				'deletePersonalByWalletId', 'anonymizeWallet'];
			sort($links);
			return $links;
		};
		sort($calls[42]);
		sort($calls[43]);
		self::assertSame($expected(10), $calls[42], 'a current member\'s wallet');
		self::assertSame($expected(20), $calls[43], 'a departed account\'s wallet, in its own house');
		$userIdStep = ['forgetOwnerInHouse:erased_user', 'forgetTakeoverUserInHouse:erased_user', 'forgetAssignerInHouse:erased_user'];
		self::assertSame([10 => $userIdStep, 20 => $userIdStep], $userCalls, 'the raw user id goes in both houses');
	}
}
