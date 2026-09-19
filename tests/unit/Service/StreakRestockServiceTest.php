<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCA\ChoreStrike\Service\StreakRestockService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StreakRestockService (CS-PLAN-023).
 *
 * The restock pass is time-independent — it takes no date and sets each
 * user's freezes to the configured weekly value capped at the configured
 * maximum.
 *
 * CS-PLAN-071 WP0: each restock write runs in one house-first transaction
 * (house row lock → membership row lock) against the fresh locked entity.
 */
class StreakRestockServiceTest extends TestCase {
	private HouseUserMapper&MockObject $houseUserMapper;
	private RewardConfigService&MockObject $configService;
	private AppLogger&MockObject $appLogger;
	private HouseMapper&MockObject $houseMapper;
	private StreakRestockService $service;
	/** Registry for the under-lock re-read (CS-PLAN-071 findForUpdate). */
	private array $usersById = [];

	protected function setUp(): void {
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->configService = $this->createMock(RewardConfigService::class);
		$this->appLogger = $this->createMock(AppLogger::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);

		$this->configService->method('getStreakFreezesPerWeek')->willReturn(1);
		$this->configService->method('getStreakFreezeCap')->willReturn(2);

		$utcHouse = new House();
		$utcHouse->setTimezone('UTC');
		$this->houseMapper->method('findForUpdate')->willReturn($utcHouse);
		$this->usersById = [];
		$this->houseUserMapper->method('findForUpdate')->willReturnCallback(
			fn (int $id): HouseUser => $this->usersById[$id]
		);

		$this->service = new StreakRestockService(
			$this->houseUserMapper,
			$this->configService,
			$this->appLogger,
			$this->houseMapper,
			new \OCA\ChoreStrike\Db\TransactionExecutor($this->createMock(\OCP\IDBConnection::class)),
		);
	}

	/**
	 * Build a membership with the house id the house-first pass needs.
	 */
	private function buildUser(int $id, int $freezes): HouseUser {
		$user = new HouseUser();
		$user->setId($id);
		$user->setHouseId(10);
		$user->setStreakFreezes($freezes);
		$this->usersById[$id] = $user;
		return $user;
	}

	/**
	 * Process_RestocksAllUsers
	 */
	public function testProcess_RestocksAllUsers(): void {
		$user1 = $this->buildUser(1, 0);
		$user2 = $this->buildUser(2, 2);

		$this->houseUserMapper->method('findAllForJobs')->willReturn([$user1, $user2]);
		$this->houseUserMapper->expects($this->exactly(2))->method('update');

		$stats = $this->service->process();

		$this->assertSame(2, $stats['processed']);
	}

	/**
	 * Process_RespectsConfiguredValue
	 */
	public function testProcess_RespectsConfiguredValue(): void {
		$user = $this->buildUser(1, 0);

		$this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
		$this->houseUserMapper->expects($this->once())->method('update')
			->with($this->callback(function (HouseUser $u) {
				return $u->getStreakFreezes() === 1;
			}));

		$this->service->process();
	}

	/**
	 * Process_ReturnsStatsWithConfigValues
	 */
	public function testProcess_ReturnsStatsWithConfigValues(): void {
		$this->houseUserMapper->method('findAllForJobs')->willReturn([]);

		$stats = $this->service->process();

		$this->assertSame([
			'processed' => 0,
			'freezesPerWeek' => 1,
			'cap' => 2,
			'failed' => 0,
		], $stats);
	}

	/**
	 * Process_OneFailingUser_DoesNotAbortTheLoop
	 *
	 * CR-SVC-30 regression: the try/catch lives inside the foreach, so the
	 * second user is still restocked after the first user's update fails.
	 */
	public function testProcess_OneFailingUser_DoesNotAbortTheLoop(): void {
		$failing = $this->buildUser(1, 0);
		$healthy = $this->buildUser(2, 0);

		$this->houseUserMapper->method('findAllForJobs')->willReturn([$failing, $healthy]);
		$this->houseUserMapper->method('update')
			->willReturnCallback(function (HouseUser $user) {
				if ($user->getId() === 1) {
					throw new \RuntimeException('db gone');
				}
				return $user;
			});
		$this->appLogger->expects($this->once())->method('logException');

		$stats = $this->service->process();

		$this->assertSame(2, $stats['processed']);
		$this->assertSame(1, $stats['failed']);
		// The healthy user was restocked despite the first user's failure.
		$this->assertSame(1, $healthy->getStreakFreezes());
	}
}
