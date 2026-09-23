<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CommandReceiptMapper;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MedalMapper;
use OCA\ChoreStrike\Db\MinigameScoreMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Db\WalletEntryMapper;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Service\AccountErasureService;
use OCA\ChoreStrike\Service\AppLogger;
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
	private TransactionExecutor $transactions;
	private AppLogger $appLogger;

	protected function setUp(): void {
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->walletMapper = $this->createMock(WalletMapper::class);
		$this->minigameScoreMapper = $this->createMock(MinigameScoreMapper::class);
		$this->medalMapper = $this->createMock(MedalMapper::class);
		$this->transactions = $this->createMock(TransactionExecutor::class);
		$this->appLogger = $this->createMock(AppLogger::class);
	}

	private function membership(int $id, int $houseId): HouseUser {
		$membership = new HouseUser();
		$membership->setId($id);
		$membership->setHouseId($houseId);
		$membership->setNextcloudUserId('erased_user');
		return $membership;
	}

	private function service(): AccountErasureService {
		return new AccountErasureService(
			$this->createMock(HouseMapper::class),
			$this->houseUserMapper,
			$this->createMock(TaskAssigneeMapper::class),
			$this->medalMapper,
			$this->minigameScoreMapper,
			$this->walletMapper,
			$this->createMock(WalletEntryMapper::class),
			$this->createMock(CommandReceiptMapper::class),
			$this->createMock(CompletedTaskMapper::class),
			$this->createMock(\OCA\ChoreStrike\Db\OccurrenceMemberMapper::class),
			$this->createMock(\OCA\ChoreStrike\Db\ScheduleRevisionMapper::class),
			$this->createMock(\OCA\ChoreStrike\Db\AgreementMapper::class),
			$this->transactions,
			$this->appLogger
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
}
