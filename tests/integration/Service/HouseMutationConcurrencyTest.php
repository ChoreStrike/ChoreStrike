<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\HouseSetupService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * CS-PLAN-071 WP0 T0: the house-first lock order on the REAL database
 * (parent CS-PLAN-070 §3.5, plan §3.5) plus the D5 SQLite write-acquisition
 * mechanism on an isolated disposable SQLite environment.
 *
 * The DB races follow the repo's two-connection `proc_open` helper pattern
 * and are MySQL/MariaDB-only (SQLite has no row-level concurrency to prove
 * — its mechanism is proven in the dedicated D5 test below). Deterministic
 * barrier: the T1 helper holds the HOUSE row lock (the FIRST lock of the
 * documented order) and commits a competing fact while T2's aggregate sits
 * blocked between its pre-transaction probe and that lock.
 */
class HouseMutationConcurrencyTest extends IntegrationTestCase {
	/** @var int[] Room tasks whose completed_tasks rows need cleanup */
	private array $cleanupRoomTaskIds = [];

	protected function tearDown(): void {
		foreach ($this->cleanupRoomTaskIds as $roomTaskId) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_completed_tasks')
				->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		parent::tearDown();
	}

	/**
	 * CompleteVsMemberRemoval_MemberRemovedBehindHouseLock_Yields403ZeroWrites
	 *
	 * T1 holds the house lock and removes the completer's membership; T2's
	 * completion was already past its pre-transaction probe and must hit the
	 * under-lock membership re-read → 403 with ZERO writes (no ledger row,
	 * no coin delta).
	 */
	public function testCompleteVsMemberRemoval_MemberRemovedBehindHouseLock_Yields403ZeroWrites(): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_MYSQL) {
			self::markTestSkipped('The two-connection race scenario requires MySQL/MariaDB (the Docker test DB).');
		}

		$houseId = $this->createHouse('hmc_owner', 'HMC Removal House');
		$this->addHouseMember($houseId, 'hmc_completer', 'HMC Completer', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'HMC task');
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'hmc_completer');
		$this->cleanupRoomTaskIds[] = $roomTaskId;

		$completerHouseUserId = $this->findHouseUserId($houseId, 'hmc_completer');

		$signalFile = sys_get_temp_dir() . '/cs-hmc-house-locked-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-hmc-remover-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildMemberRemovalHolderScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, (string)$houseId, (string)$completerHouseUserId, $signalFile],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the removal holder process');

		try {
			$deadline = microtime(true) + 30.0;
			while (!file_exists($signalFile)) {
				if (microtime(true) > $deadline) {
					self::fail('The removal holder did not acquire the house lock in time');
				}
				usleep(50000);
			}

			// T2: the completion blocks at the HOUSE lock until T1 commits
			// the membership removal, then the under-lock re-read denies.
			$completionService = \OC::$server->get(CompletionService::class);
			try {
				$this->completeOccurrence($completionService, $houseId, 'hmc_completer', $roomTaskId);
				self::fail('a completion whose membership vanished behind the house lock must be denied');
			} catch (ForbiddenException $e) {
				self::assertStringContainsString('member', $e->getMessage());
			}
		} finally {
			$this->terminateHelper($process, $pipes);
			@unlink($signalFile);
			@unlink($helperScript);
		}

		// Zero writes: no ledger row, no work-share/wallet credit.
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ledgerCount = (int)$result->fetchOne();
		$result->closeCursor();
		self::assertSame(0, $ledgerCount, 'the denied completion must not write a ledger row');

		$contributionQb = $this->db->getQueryBuilder();
		$contributionQb->select('contribution_minor')
			->from('chorestrike_house_users')
			->where($contributionQb->expr()->eq('id', $contributionQb->createNamedParameter($completerHouseUserId, IQueryBuilder::PARAM_INT)));
		$completerContribution = (int)$this->fetchScalar($contributionQb);
		self::assertSame(0, $completerContribution, 'the denied completion must not credit work');
	}

	/**
	 * CompleteVsComplete_BehindHouseLock_CommittedWinnerYieldsOccurrenceConflict409
	 *
	 * T1 holds the house lock and commits the winner's completion of the
	 * SAME occurrence target; T2's completion (already past its
	 * pre-transaction probe, carrying the pre-commit receipt) must hit the
	 * under-lock occurrence re-check → 409 (CS-PLAN-072 WP1: occurrence
	 * validity replaces the day-duplicate 403) and write no second ledger
	 * row.
	 */
	public function testCompleteVsComplete_BehindHouseLock_CommittedWinnerYieldsOccurrenceConflict409(): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_MYSQL) {
			self::markTestSkipped('The two-connection race scenario requires MySQL/MariaDB (the Docker test DB).');
		}

		$houseId = $this->createHouse('hmc_dup_owner', 'HMC Duplicate House');
		$this->addHouseMember($houseId, 'hmc_dup_completer', 'HMC Dup Completer', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'HMC duplicate task');
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'hmc_dup_completer');
		$this->cleanupRoomTaskIds[] = $roomTaskId;
		$completerHouseUserId = $this->findHouseUserId($houseId, 'hmc_dup_completer');

		// T2's receipt is resolved BEFORE the winner commits — the shown
		// target both racers aim at.
		$occurrenceService = \OC::$server->get(\OCA\ChoreStrike\Service\OccurrenceService::class);
		$selection = $occurrenceService->selectionFor(
			\OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->find($roomTaskId),
			new \DateTime()
		);
		self::assertNotNull($selection, 'the fixture must expose a completable occurrence');
		$target = $selection['occurrence'];

		$signalFile = sys_get_temp_dir() . '/cs-hmc-dup-locked-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-hmc-dup-winner-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildDuplicateWinnerHolderScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, (string)$roomTaskId, (string)$completerHouseUserId, (string)$target->getId(), $signalFile],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the duplicate-winner holder process');

		try {
			$deadline = microtime(true) + 30.0;
			while (!file_exists($signalFile)) {
				if (microtime(true) > $deadline) {
					self::fail('The duplicate-winner holder did not acquire the house lock in time');
				}
				usleep(50000);
			}

			// T2: the receipt probe passed pre-transaction, the completion
			// blocks at the house lock, and the under-lock re-check finds
			// T1's committed fulfillment of the SAME target → 409.
			$completionService = \OC::$server->get(CompletionService::class);
			try {
				$completionService->complete(
					$houseId,
					'hmc_dup_completer',
					$roomTaskId,
					sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand()),
					$target->toPublicKey(),
					$target->getVersion(),
					$occurrenceService->selectionToken($target, $selection['revision'], $selection['classification'])
				);
				self::fail('a second completion of the same occurrence must be rejected with 409');
			} catch (ConflictException $e) {
				self::assertSame('This occurrence is already completed', $e->getMessage());
			}
		} finally {
			$this->terminateHelper($process, $pipes);
			@unlink($signalFile);
			@unlink($helperScript);
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		self::assertSame(1, (int)$result->fetchOne(), 'exactly one ledger row survives the duplicate race');
		$result->closeCursor();
	}

	/**
	 * DeleteRoomTaskVsCompletion_RoomTaskTombstonedBehindHouseLock_Yields409ZeroWrites
	 *
	 * T1 holds the house lock and tombstones the room task; T2's completion
	 * (past its pre-transaction probe) re-reads the row under the locks and
	 * must answer 409 with zero writes — the evidence stays untouched.
	 */
	public function testDeleteRoomTaskVsCompletion_RoomTaskTombstonedBehindHouseLock_Yields409ZeroWrites(): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_MYSQL) {
			self::markTestSkipped('The two-connection race scenario requires MySQL/MariaDB (the Docker test DB).');
		}

		$houseId = $this->createHouse('hmc_tomb_owner', 'HMC Tombstone House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'HMC tombstone task');
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'hmc_tomb_owner');
		$this->cleanupRoomTaskIds[] = $roomTaskId;

		$signalFile = sys_get_temp_dir() . '/cs-hmc-tomb-locked-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-hmc-tombstoner-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildTombstoneHolderScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, (string)$roomTaskId, $signalFile],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the tombstone holder process');

		try {
			$deadline = microtime(true) + 30.0;
			while (!file_exists($signalFile)) {
				if (microtime(true) > $deadline) {
					self::fail('The tombstone holder did not acquire the house lock in time');
				}
				usleep(50000);
			}

			$completionService = \OC::$server->get(CompletionService::class);
			try {
				$this->completeOccurrence($completionService, $houseId, 'hmc_tomb_owner', $roomTaskId);
				self::fail('a completion of a room task tombstoned behind the house lock must answer 409');
			} catch (ConflictException $e) {
				self::assertSame('Task is not active', $e->getMessage());
			}
		} finally {
			$this->terminateHelper($process, $pipes);
			@unlink($signalFile);
			@unlink($helperScript);
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		self::assertSame(0, (int)$result->fetchOne(), 'the rejected completion must not write a ledger row');
		$result->closeCursor();
	}

	/**
	 * ConcurrentMembershipCreation_MembershipCreatedBehindHouseLock_OneWalletExactly
	 *
	 * T1 holds the house lock and creates the target's membership + wallet;
	 * T2's inviteMember (blocked at the house lock, past its account
	 * resolution) must find BOTH rows under the lock and create NO
	 * duplicates — exactly one membership and one wallet identity exist
	 * (uniq_wallet_house_user + the house-first serialization).
	 */
	public function testConcurrentMembershipCreation_MembershipCreatedBehindHouseLock_OneWalletExactly(): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_MYSQL) {
			self::markTestSkipped('The two-connection race scenario requires MySQL/MariaDB (the Docker test DB).');
		}

		$houseId = $this->createBareHouse('HMC Wallet House');
		$this->ensureUserWithEmail('hmc_wallet_target', 'hmc_wallet_target@example.com');

		$signalFile = sys_get_temp_dir() . '/cs-hmc-wallet-locked-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-hmc-winner-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildWalletWinnerHolderScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, (string)$houseId, $signalFile],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the wallet-winner holder process');

		try {
			$deadline = microtime(true) + 30.0;
			while (!file_exists($signalFile)) {
				if (microtime(true) > $deadline) {
					self::fail('The wallet-winner holder did not acquire the house lock in time');
				}
				usleep(50000);
			}

			// T2: blocked at the house lock while T1 commits the membership
			// + wallet; afterwards the invite finds both under the lock and
			// creates no duplicates (createOrGet idempotence).
			$this->actingAs('admin');
			$houseService = \OC::$server->get(\OCA\ChoreStrike\Service\HouseService::class);
			$membership = $houseService->inviteMember($houseId, 'hmc_wallet_target@example.com', 'member');

			self::assertNotNull($membership->getWalletId(), 'the membership carries the winner\'s wallet binding');
		} finally {
			$this->terminateHelper($process, $pipes);
			@unlink($signalFile);
			@unlink($helperScript);
		}

		$membershipCount = (int)$this->fetchScalar(
			$this->countQuery('chorestrike_house_users', 'house_id', $houseId)
		);
		self::assertSame(1, $membershipCount, 'exactly one membership exists');

		$walletCount = (int)$this->fetchScalar(
			$this->countQuery('chorestrike_wallets', 'house_id', $houseId)
		);
		self::assertSame(1, $walletCount, 'exactly one wallet identity exists');

		// Local cleanup (wallets are not covered by the base teardown).
		$this->deleteByHouseId('chorestrike_wallets', $houseId);
	}

	private function ensureUserWithEmail(string $userId, string $email): void {
		$this->actingAs($userId);
		$userManager = \OC::$server->get(\OCP\IUserManager::class);
		$user = $userManager->get($userId);
		if ($user === null) {
			$user = $userManager->createUser($userId, 'test-password-' . $userId);
		}
		$user->setEMailAddress($email);
	}

	/**
	 * FK-free schema: delete rows of a house-scoped table during teardown.
	 */
	private function deleteByHouseId(string $table, int $houseId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * SetupApplyFailureAfterWalletWrite_RollsBackTheWholeAggregate
	 *
	 * All-or-nothing (plan §3.5): applySetup creates the member wallets
	 * inside its house-first transaction; a failure AFTER that write (here:
	 * a sabotaged assignee writer) must roll the wallets back — an identity
	 * row never survives a failed aggregate (the receipt-without-effects
	 * class the lock order and single-transaction discipline exist for).
	 */
	public function testSetupApplyFailureAfterWalletWrite_RollsBackTheWholeAggregate(): void {
		$houseId = $this->createHouse('hmc_setup_owner', 'HMC Setup House');
		$this->addHouseMember($houseId, 'hmc_setup_kid', 'HMC Setup Kid', 'child');
		// Bind the owner session AFTER the fixtures (the session is
		// process-global; requireHouseOwner needs the membership in place).
		$this->actingAs('hmc_setup_owner');

		// WP3 membership invariant: fixtures carry their (legitimate)
		// bindings — the rollback claim is about the WIZARD's own writes.
		$walletsBefore = (int)$this->fetchScalar(
			$this->countQuery('chorestrike_wallets', 'house_id', $houseId)
		);
		self::assertSame(2, $walletsBefore, 'both fixture members are bound');

		$setupService = \OC::$server->get(HouseSetupService::class);

		// Sabotage the assignee writer — it runs AFTER ensureWallets in the
		// same transaction. Same newInstanceWithoutConstructor precedent as
		// CompletionConcurrencyTest's sabotaged post-commit boundary.
		$saboteur = (new \ReflectionClass(SabotagedAssigneeMapper::class))
			->newInstanceWithoutConstructor();
		$prop = new \ReflectionProperty(HouseSetupService::class, 'taskAssigneeMapper');
		$prop->setValue($setupService, $saboteur);

		$payload = [
			'houseName' => 'HMC Setup House',
			'intensityLevel' => 'putzmuffel',
			'options' => ['createHouseholdRoom' => false, 'createHallways' => false],
			'floors' => [
				[
					'name' => 'Ground Floor',
					'rooms' => [
						[
							'name' => 'Kids Bathroom',
							'roomType' => 'bathroom',
							'assigneeUserId' => 'hmc_setup_kid',
						],
					],
				],
			],
		];

		try {
			$setupService->applySetup($houseId, $payload);
			self::fail('applySetup must fail via the sabotaged assignee writer');
		} catch (\RuntimeException $e) {
			self::assertSame('sabotaged assignee write', $e->getMessage());
		}

		// The wizard's own wallet writes rolled back: exactly the fixture
		// identities remain, no additional row survived the failure.
		$walletsAfter = (int)$this->fetchScalar(
			$this->countQuery('chorestrike_wallets', 'house_id', $houseId)
		);
		self::assertSame($walletsBefore, $walletsAfter, 'the failed wizard must not leave wallet identities behind');

		// And exactly the two PRE-EXISTING fixture bindings remain.
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('wallet_id'));
		$boundCount = (int)$this->fetchScalar($qb);
		self::assertSame(2, $boundCount, 'only the fixture members keep their bindings');

		// The structural writes rolled back too.
		$floorCount = (int)$this->fetchScalar(
			$this->countQuery('chorestrike_floors', 'house_id', $houseId)
		);
		self::assertSame(0, $floorCount, 'the failed wizard must not leave floors behind');
	}

	/**
	 * SQLiteHouseFirstWriteAcquisition_SecondWriterWaitsNotFails (D5)
	 *
	 * The isolated disposable-SQLite proof of the D5 mechanism: connection A
	 * begins a transaction whose FIRST statement is the house-lock-shaped
	 * no-op self-assignment UPDATE (acquiring the database-wide write lock);
	 * connection B — with the bounded busy_timeout the TransactionExecutor
	 * applies — runs the same first statement and must WAIT until A commits
	 * (never an immediate SQLITE_BUSY failure). SQLite has exactly one write
	 * lock, so the contractually-first house-lock statement IS the
	 * immediate-mode acquisition at the application level.
	 */
	public function testSQLiteHouseFirstWriteAcquisition_SecondWriterWaitsNotFails(): void {
		$dbFile = tempnam(sys_get_temp_dir(), 'cs-hmc-sqlite-') . '.sqlite3';
		$signalFile = sys_get_temp_dir() . '/cs-hmc-sqlite-locked-' . uniqid();

		$pdoA = new \PDO('sqlite:' . $dbFile);
		$pdoA->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$pdoA->exec('CREATE TABLE chorestrike_houses (id INTEGER PRIMARY KEY AUTOINCREMENT, state_version INTEGER NOT NULL DEFAULT 0)');
		$pdoA->exec('INSERT INTO chorestrike_houses (state_version) VALUES (0)');

		$helperScript = sys_get_temp_dir() . '/cs-hmc-sqlite-holder-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildSqliteHolderScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, $dbFile, $signalFile],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the sqlite lock holder process');

		try {
			$deadline = microtime(true) + 30.0;
			while (!file_exists($signalFile)) {
				if (microtime(true) > $deadline) {
					self::fail('The sqlite holder did not acquire the write lock in time');
				}
				usleep(50000);
			}

			// Connection B: bounded busy timeout (what TransactionExecutor
			// applies), deferred BEGIN, FIRST statement = the house-lock
			// write — must block (>= the holder's 1s hold), not fail.
			$pdoB = new \PDO('sqlite:' . $dbFile);
			$pdoB->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
			$pdoB->exec('PRAGMA busy_timeout = 5000');

			$startedAt = microtime(true);
			$pdoB->beginTransaction();
			$pdoB->exec('UPDATE chorestrike_houses SET state_version = state_version');
			$waitedMs = (microtime(true) - $startedAt) * 1000.0;
			$pdoB->commit();

			self::assertGreaterThanOrEqual(900.0, $waitedMs, 'the second writer must WAIT on the SQLite write lock (bounded busy timeout), not fail fast');
		} finally {
			$this->terminateHelper($process, $pipes);
			@unlink($signalFile);
			@unlink($helperScript);
			@unlink($dbFile);
		}
	}

	// ========== Helpers ==========

	private function findHouseUserId(int $houseId, string $ncUserId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($ncUserId)));
		$result = $qb->executeQuery();
		$id = (int)$result->fetchOne();
		$result->closeCursor();
		self::assertGreaterThan(0, $id, "house user row for {$ncUserId} must exist");
		return $id;
	}

	private function countQuery(string $table, string $column, int $value): \OCP\DB\QueryBuilder\IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)));
		return $qb;
	}

	/**
	 * @return mixed
	 */
	private function fetchScalar(\OCP\DB\QueryBuilder\IQueryBuilder $qb) {
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();
		return $value;
	}

	private function terminateHelper($process, array $pipes): void {
		$status = proc_get_status($process);
		if ($status !== false && $status['running']) {
			proc_terminate($process);
		}
		foreach ([1, 2] as $pipe) {
			if (isset($pipes[$pipe]) && is_resource($pipes[$pipe])) {
				fclose($pipes[$pipe]);
			}
		}
		proc_close($process);
	}

	private function buildMemberRemovalHolderScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

$houseId = (int)$argv[1];
$houseUserId = (int)$argv[2];
$signalFile = $argv[3];

$db = \OC::$server->get(\OCP\IDBConnection::class);

$db->beginTransaction();
$result = $db->executeQuery(
    'SELECT id FROM *PREFIX*chorestrike_houses WHERE id = :id FOR UPDATE',
    ['id' => $houseId],
    ['id' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
);
$result->closeCursor();

file_put_contents($signalFile, 'locked');
usleep(2000000);

$db->executeStatement(
    'DELETE FROM *PREFIX*chorestrike_task_assignees WHERE user_id = (SELECT nextcloud_user_id FROM *PREFIX*chorestrike_house_users WHERE id = :hid) AND 1 = 1',
    ['hid' => $houseUserId],
    ['hid' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
);
$db->executeStatement(
    'DELETE FROM *PREFIX*chorestrike_house_users WHERE id = :id',
    ['id' => $houseUserId],
    ['id' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
);
$db->commit();
PHP;
	}

	private function buildDuplicateWinnerHolderScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

$roomTaskId = (int)$argv[1];
$houseUserId = (int)$argv[2];
$occurrenceId = (int)$argv[3];
$signalFile = $argv[4];

$db = \OC::$server->get(\OCP\IDBConnection::class);

$db->beginTransaction();
$result = $db->executeQuery(
    'SELECT id FROM *PREFIX*chorestrike_houses WHERE id = (SELECT f.house_id FROM *PREFIX*chorestrike_floors f INNER JOIN *PREFIX*chorestrike_rooms r ON r.floor_id = f.id INNER JOIN *PREFIX*chorestrike_room_tasks rt ON rt.room_id = r.id WHERE rt.id = :rtid) FOR UPDATE',
    ['rtid' => $roomTaskId],
    ['rtid' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
);
$result->closeCursor();

file_put_contents($signalFile, 'locked');
usleep(2000000);

$db->executeStatement(
    'INSERT INTO *PREFIX*chorestrike_completed_tasks (room_task_id, user_id, completed_at, total_energy, rate_minor, completion_version, allocation_revision, occurrence_due, work_date, work_zone, completion_class) VALUES (:rtid, :uid, NOW(), 3, 1000, 0, 1, CURDATE(), CURDATE(), \'UTC\', \'agreed\')',
    ['rtid' => $roomTaskId, 'uid' => $houseUserId],
    ['rtid' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT, 'uid' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
);
// CS-PLAN-072 WP1: the winner FULFILLS the shared occurrence target — the
// loser's under-lock re-check reads this committed fulfillment (occurrence
// validity replaces the day-duplicate guard).
$ledgerId = (int)$db->lastInsertId('*PREFIX*chorestrike_completed_tasks');
$db->executeStatement(
    'UPDATE *PREFIX*chorestrike_occurrences SET completion_id = :cid, version = version + 1 WHERE id = :oid',
    ['cid' => $ledgerId, 'oid' => $occurrenceId],
    ['cid' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT, 'oid' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
);
$db->commit();
PHP;
	}

	private function buildTombstoneHolderScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

$roomTaskId = (int)$argv[1];
$signalFile = $argv[2];

$db = \OC::$server->get(\OCP\IDBConnection::class);

$db->beginTransaction();
$result = $db->executeQuery(
    'SELECT id FROM *PREFIX*chorestrike_houses WHERE id = (SELECT f.house_id FROM *PREFIX*chorestrike_floors f INNER JOIN *PREFIX*chorestrike_rooms r ON r.floor_id = f.id INNER JOIN *PREFIX*chorestrike_room_tasks rt ON rt.room_id = r.id WHERE rt.id = :rtid) FOR UPDATE',
    ['rtid' => $roomTaskId],
    ['rtid' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
);
$result->closeCursor();

file_put_contents($signalFile, 'locked');
usleep(2000000);

$db->executeStatement(
    'UPDATE *PREFIX*chorestrike_room_tasks SET deleted_at = NOW(), deleted_token = id, is_active = 0 WHERE id = :rtid',
    ['rtid' => $roomTaskId],
    ['rtid' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
);
$db->commit();
PHP;
	}

	private function buildWalletWinnerHolderScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

$houseId = (int)$argv[1];
$signalFile = $argv[2];

$tx = \OC::$server->get(\OCA\ChoreStrike\Db\TransactionExecutor::class);
$walletMapper = \OC::$server->get(\OCA\ChoreStrike\Db\WalletMapper::class);
$db = \OC::$server->get(\OCP\IDBConnection::class);

// T1: hold the house lock, then create the target's membership + wallet
// INSIDE the held transaction (house-first), signal, hold, commit.
$tx->begin();
$result = $db->executeQuery(
    'SELECT id FROM *PREFIX*chorestrike_houses WHERE id = :id FOR UPDATE',
    ['id' => $houseId],
    ['id' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
);
$result->closeCursor();

try {
    $now = date('Y-m-d H:i:s');
    $db->executeStatement(
        'INSERT INTO *PREFIX*chorestrike_house_users (house_id, nextcloud_user_id, display_name, role, contribution_minor, current_streak, longest_streak, unlocked_titles, joined_at) VALUES (:hid, :uid, :dn, :role, 0, 0, 0, :titles, :now)',
        ['hid' => $houseId, 'uid' => 'hmc_wallet_target', 'dn' => 'HMC Wallet Target', 'role' => 'member', 'titles' => '[]', 'now' => $now],
        ['hid' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT, 'uid' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR, 'dn' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR, 'role' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR, 'titles' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR, 'now' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR]
    );
    $houseUserId = (int)$db->lastInsertId('chorestrike_house_users');
    $wallet = $walletMapper->createOrGet($houseId, 'hmc_wallet_target');
    $db->executeStatement(
        'UPDATE *PREFIX*chorestrike_house_users SET wallet_id = :wid WHERE id = :hid2',
        ['wid' => $wallet->getId(), 'hid2' => $houseUserId],
        ['wid' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT, 'hid2' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
    );

    file_put_contents($signalFile, 'locked');
    usleep(2000000);
    $tx->commit();
} catch (Throwable $e) {
    $tx->rollBack();
    file_put_contents($signalFile, 'locked');
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
PHP;
	}

	private function buildSqliteHolderScript(): string {
		return <<<'PHP'
<?php

$dbFile = $argv[1];
$signalFile = $argv[2];

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA busy_timeout = 5000');

$pdo->beginTransaction();
// FIRST statement = the house-lock-shaped no-op self-assignment UPDATE:
// on SQLite this acquires the database-wide write lock immediately.
$pdo->exec('UPDATE chorestrike_houses SET state_version = state_version');

file_put_contents($signalFile, 'locked');
usleep(1000000);

$pdo->commit();
PHP;
	}
}

/**
 * Test-local TaskAssigneeMapper whose assignee write always fails — the
 * injected failure AFTER ensureWallets inside the applySetup aggregate.
 * Instantiated via `newInstanceWithoutConstructor` (the overridden method
 * throws before any dependency would be touched).
 */
class SabotagedAssigneeMapper extends \OCA\ChoreStrike\Db\TaskAssigneeMapper {
	public function addAssignee(int $roomTaskId, string $userId, ?string $assignedBy = null): \OCA\ChoreStrike\Db\TaskAssignee {
		throw new \RuntimeException('sabotaged assignee write');
	}
}
