<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Service\MinigameService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Integration tests for the minigame coin mutations under concurrency
 * (CR-SVC-54 saveScore, CR-SVC-23 purchaseBoost).
 *
 * Both defects are the same lost-update class CR-SVC-04/53 fixed for task
 * completion: the score insert + coin award (and the boost check-deduct)
 * ran without a transaction or row lock, so two concurrent same-user calls
 * clobbered each other's coin balance. The races are reproduced
 * deterministically with two real connections, exactly like CS-028's
 * ConcurrentCompletionTest: a helper process (T1) locks the house_user row,
 * holds the lock, then commits its effect; the shared connection (T2) runs
 * the real service method, whose findForUpdate must block until T1 commits
 * and then build on T1's committed state.
 *
 * Both tests fail on the pre-fix (lock-less) code.
 */
class MinigameConcurrencyTest extends IntegrationTestCase {
	/** @var int[] House ids whose minigame_score rows need cleanup */
	private array $scoreHouseIds = [];
	/** @var resource|null The T1 helper process handle */
	private $helperProcess = null;
	/** @var array<int, resource> The T1 helper pipes */
	private array $helperPipes = [];
	private string $helperSignalFile = '';
	private string $helperScriptFile = '';

	protected function tearDown(): void {
		$this->stopHelperProcess();

		// The base cleanup does not track minigame_scores rows; remove the
		// rows this test created so the shared Docker DB stays clean.
		if (!empty($this->scoreHouseIds)) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_minigame_scores')
				->where($qb->expr()->in(
					'house_id',
					$qb->createNamedParameter($this->scoreHouseIds, IQueryBuilder::PARAM_INT_ARRAY)
				));
			$qb->executeStatement();
		}

		parent::tearDown();
	}

	/**
	 * CR-SVC-54: two interleaved saveScore calls must both credit their
	 * coins — the locked award builds on the concurrent winner's committed
	 * increment instead of clobbering it.
	 *
	 *   T1 (helper process): locks the house_user row, holds the lock,
	 *                        then commits coins +10
	 *   T2 (this test):      saveScore(score=120) — the pre-lock read sees
	 *                        the stale balance 0; findForUpdate blocks
	 *                        until T1 commits and must award 12 on top of
	 *                        T1's 10
	 *   Final:               coins 22 — both increments landed. The
	 *                        lock-less pre-fix code wrote 0 + 12 = 12 and
	 *                        lost T1's 10.
	 */
	public function testSaveScore_ConcurrentCoinAward_NoLostUpdate(): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_MYSQL) {
			self::markTestSkipped('The two-connection race scenario requires MySQL/MariaDB (the Docker test DB).');
		}

		$houseId = $this->createHouse('mg_race_owner', 'Minigame Race House');
		$this->scoreHouseIds = [$houseId];
		$houseUserId = $this->findHouseUserId($houseId, 'mg_race_owner');
		self::assertSame(0, $this->fetchCoins($houseUserId), 'fixture: owner starts with 0 coins');

		$this->actingAs('mg_race_owner');
		$service = \OC::$server->get(MinigameService::class);
		$this->startHelperProcess($this->buildCoinIncrementScript(), $houseUserId);

		try {
			// T2: the real service path. Score 120 → 12 coins, awarded on
			// the locked (fresh) balance 10, not the stale pre-lock balance
			// 0. findForUpdate blocks until T1 commits (~1.5s).
			$result = $service->saveScore($houseId, 'mg_race_owner', 'snake', 120);
		} finally {
			$this->stopHelperProcess();
		}

		self::assertSame(12, $result['coinsEarned'], 'the service reports the coins it actually awarded');
		self::assertSame(22, $this->fetchCoins($houseUserId), 'no lost update: T1\'s 10 and T2\'s 12 both landed');
	}

	/**
	 * CR-SVC-23: two interleaved purchaseBoost calls with a single-boost
	 * balance must result in exactly one purchase — the sufficiency check
	 * runs on the locked row, so the second call sees the deducted balance.
	 *
	 *   T1 (helper process): locks the house_user row (balance 10), holds
	 *                        the lock, then commits its purchase deduction
	 *                        (10 → 0)
	 *   T2 (this test):      purchaseBoost('slow_motion', cost 10) — the
	 *                        pre-lock read still sees the committed
	 *                        balance 10; findForUpdate blocks until T1
	 *                        commits and must then reject on the locked
	 *                        balance 0
	 *   Final:               exactly one purchase; T2 throws
	 *                        "Insufficient coins". The lock-less pre-fix
	 *                        code let both purchases pass the stale check.
	 */
	public function testPurchaseBoost_ConcurrentSingleBoostBalance_ExactlyOneSucceeds(): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_MYSQL) {
			self::markTestSkipped('The two-connection race scenario requires MySQL/MariaDB (the Docker test DB).');
		}

		$houseId = $this->createHouse('mg_boost_owner', 'Boost Race House');
		$this->scoreHouseIds = [$houseId];
		$houseUserId = $this->findHouseUserId($houseId, 'mg_boost_owner');

		// Seed a single-boost balance (slow_motion costs 10).
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_house_users')
			->set('coins', $qb->createNamedParameter(10, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		$this->actingAs('mg_boost_owner');
		$service = \OC::$server->get(MinigameService::class);
		$this->startHelperProcess($this->buildBoostPurchaseScript(), $houseUserId);

		try {
			// T2: the real service path — must be rejected on the locked
			// balance (findForUpdate blocks until T1 commits, then sees 0).
			try {
				$service->purchaseBoost($houseId, 'mg_boost_owner', 'slow_motion');
				self::fail('the second concurrent purchase must be rejected on the locked balance');
			} catch (\InvalidArgumentException $e) {
				self::assertStringContainsString('Insufficient coins', $e->getMessage());
			}
		} finally {
			$this->stopHelperProcess();
		}

		self::assertSame(0, $this->fetchCoins($houseUserId), 'exactly one purchase was deducted');
	}

	private function findHouseUserId(int $houseId, string $ncUserId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($ncUserId)));
		$result = $qb->executeQuery();
		$id = (int)$result->fetchOne();
		$result->closeCursor();

		self::assertGreaterThan(0, $id, 'house user row must exist');
		return $id;
	}

	private function fetchCoins(int $houseUserId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('coins')
			->from('chorestrike_house_users')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$coins = (int)$result->fetchOne();
		$result->closeCursor();

		return $coins;
	}

	/**
	 * Start the T1 helper and wait until it holds the row lock (mirrors
	 * ConcurrentCompletionTest's structure). The helper stays running —
	 * its committed effect lands while T2 (the test) blocks on the lock.
	 *
	 * @param string $script The helper script source
	 */
	private function startHelperProcess(string $script, int $houseUserId): void {
		$this->helperSignalFile = sys_get_temp_dir() . '/cs-mg-race-locked-' . uniqid();
		$this->helperScriptFile = sys_get_temp_dir() . '/cs-mg-race-holder-' . uniqid() . '.php';
		file_put_contents($this->helperScriptFile, $script);

		$this->helperProcess = proc_open(
			[PHP_BINARY, $this->helperScriptFile, (string)$houseUserId, $this->helperSignalFile],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$this->helperPipes
		);
		self::assertIsResource($this->helperProcess, 'failed to start the lock-holder process');

		// Wait until T1 holds the row lock (it commits ~1.5s later — T2's
		// lock acquisition inside the service call below will block on it).
		$deadline = microtime(true) + 30.0;
		while (!file_exists($this->helperSignalFile)) {
			if (microtime(true) > $deadline) {
				self::fail('The lock-holder process did not acquire the row lock in time');
			}
			usleep(50000);
		}
	}

	/**
	 * Wait for the T1 helper to finish (its transaction committed), then
	 * close the process and clean up the temp files.
	 */
	private function stopHelperProcess(): void {
		if ($this->helperProcess !== null && is_resource($this->helperProcess)) {
			$deadline = microtime(true) + 30.0;
			while (true) {
				$status = proc_get_status($this->helperProcess);
				if ($status === false || !$status['running']) {
					break;
				}
				if (microtime(true) > $deadline) {
					proc_terminate($this->helperProcess);
					self::fail('The lock-holder process did not finish in time');
				}
				usleep(50000);
			}

			foreach ([1, 2] as $pipe) {
				if (isset($this->helperPipes[$pipe]) && is_resource($this->helperPipes[$pipe])) {
					fclose($this->helperPipes[$pipe]);
				}
			}
			proc_close($this->helperProcess);
		}

		$this->helperProcess = null;
		$this->helperPipes = [];
		if ($this->helperSignalFile !== '') {
			@unlink($this->helperSignalFile);
			$this->helperSignalFile = '';
		}
		if ($this->helperScriptFile !== '') {
			@unlink($this->helperScriptFile);
			$this->helperScriptFile = '';
		}
	}

	/**
	 * T1 for the saveScore race: lock the house_user row, hold it, then
	 * commit a concurrent coin increment of +10.
	 */
	private function buildCoinIncrementScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

$houseUserId = (int)$argv[1];
$signalFile = $argv[2];

$db = \OC::$server->get(\OCP\IDBConnection::class);
$db->beginTransaction();
$mapper = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class);
$mapper->findForUpdate($houseUserId);

file_put_contents($signalFile, 'locked');
usleep(1500000);

$db->executeStatement(
    'UPDATE *PREFIX*chorestrike_house_users SET coins = coins + 10 WHERE id = :id',
    ['id' => $houseUserId],
    ['id' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
);
$db->commit();
PHP;
	}

	/**
	 * T1 for the purchaseBoost race: lock the house_user row (balance 10),
	 * hold it, then commit the concurrent purchase's deduction (10 → 0).
	 */
	private function buildBoostPurchaseScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

$houseUserId = (int)$argv[1];
$signalFile = $argv[2];

$db = \OC::$server->get(\OCP\IDBConnection::class);
$db->beginTransaction();
$mapper = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class);
$locked = $mapper->findForUpdate($houseUserId);

file_put_contents($signalFile, 'locked');
usleep(1500000);

$locked->setCoins($locked->getCoins() - 10);
$locked->touch();
$mapper->update($locked);
$db->commit();
PHP;
	}
}
