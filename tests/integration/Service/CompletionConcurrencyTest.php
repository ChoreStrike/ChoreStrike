<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\NotificationService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Real-DB concurrency and failure-isolation proofs for the completion/undo
 * aggregates (CS-PLAN-064 §4, WP5 exit gate):
 *
 *  1. Same-room freshness: a completion blocked on the documented SECOND
 *     lock (the room row) must build on the concurrent winner's committed
 *     cleanliness — the deterministic interleaving that clobbered room
 *     state under the old unlocked read-modify-write.
 *  2. Retry-after-post-commit-failure: a sabotaged weekly-goal read must
 *     not turn the committed completion into an ambiguous failure (D7);
 *     the retried completion hits the day-duplicate 403 with no second
 *     ledger row.
 *  3. Concurrent undo: the loser of the under-lock revertible re-check is
 *     the idempotent zero-write no-op (D3).
 *  4. Inactive occurrence: 409 with zero writes (D4).
 *
 * The races follow the repo's two-connection `proc_open` helper pattern and
 * are MySQL/MariaDB-only (SQLite has no row-level concurrency to prove).
 */
class CompletionConcurrencyTest extends IntegrationTestCase {
	/** @var int[] Room task ids whose ledger/checklist rows need cleanup */
	private array $cleanupRoomTaskIds = [];

	protected function tearDown(): void {
		foreach ($this->cleanupRoomTaskIds as $roomTaskId) {
			foreach (['chorestrike_completed_tasks', 'chorestrike_chk_progress'] as $table) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)
					->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
					->executeStatement();
			}
		}

		parent::tearDown();
	}

	/**
	 * SameRoomCompletion_BlockedOnRoomLock_BuildsOnCommittedWinnerCleanliness
	 *
	 * T1 (helper) holds the ROOM row lock and commits the concurrent winner
	 * B's cleanliness delta while T2 (this process) waits at the second lock
	 * of the documented order. T2's completion must then read the FRESH
	 * committed cleanliness and add its own delta on top — final state is
	 * the sum of both deltas. Fails on the old unlocked RMW, which read 50
	 * pre-lock and wrote the clobbering 50 + deltaA.
	 */
	public function testSameRoomCompletion_BlockedOnRoomLock_BuildsOnCommittedWinnerCleanliness(): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_MYSQL) {
			self::markTestSkipped('The two-connection race scenario requires MySQL/MariaDB (the Docker test DB).');
		}

		$houseId = $this->createHouse('cct_owner', 'Concurrency House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskA = $this->createTask($houseId, 'CCT dishes', '', 5, 3);
		$taskB = $this->createTask($houseId, 'CCT trash', '', 5, 3);
		$roomTaskA = $this->assignTaskToRoom($taskA, $roomId, 'cct_owner');
		$roomTaskB = $this->assignTaskToRoom($taskB, $roomId, 'cct_owner');
		$this->cleanupRoomTaskIds = [$roomTaskA, $roomTaskB];

		// Start low: updateCleanliness clamps at 100, so the deterministic
		// sum (10 + 30 winner + 30 own) must stay below it.
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_rooms')
			->set('cleanliness', $qb->createNamedParameter(10, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$initialCleanliness = 10;

		$signalFile = sys_get_temp_dir() . '/cs-cct-room-locked-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-cct-room-holder-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildRoomLockHolderScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, (string)$roomId, $signalFile],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the room-lock holder process');

		try {
			$deadline = microtime(true) + 30.0;
			while (!file_exists($signalFile)) {
				if (microtime(true) > $deadline) {
					self::fail('The room-lock holder did not acquire the room lock in time');
				}
				usleep(50000);
			}

			// T2: completes task A — blocks at the room lock until T1 commits
			// B's +30, then must add A's delta on the committed 80.
			$completionService = \OC::$server->get(CompletionService::class);
			$result = $completionService->complete($houseId, 'cct_owner', $roomTaskA);

			$row = $this->fetchRow('chorestrike_rooms', 'id', $roomId);
			self::assertSame(
				$initialCleanliness + 30 + $result['rewards']['cleanlinessChange'],
				(int)$row['cleanliness'],
				'the blocked completion must build on the winner\'s committed cleanliness (sum of both deltas), not clobber it'
			);

			$ledger = $this->ledgerRows($roomTaskA);
			self::assertCount(1, $ledger);
			$deltas = json_decode((string)$ledger[0]['deltas'], true, 512, JSON_THROW_ON_ERROR);
			self::assertSame($result['rewards']['cleanlinessChange'], $deltas['cleanlinessChange'], 'the applied delta is effect-record-visible');
		} finally {
			$this->terminateHelper($process, $pipes);
			@unlink($signalFile);
			@unlink($helperScript);
		}
	}

	/**
	 * PostCommitWeeklyGoalFailure_CommittedStillSucceeds_AndRetryIsDayDuplicate
	 *
	 * Decision D7 on the real DB: the sabotaged weekly-goal read must not
	 * throw past the post-commit boundary — the committed completion answers
	 * success with `rewards.degraded = true`; the retry then hits the
	 * day-duplicate 403 with NO second ledger row.
	 */
	public function testPostCommitWeeklyGoalFailure_CommittedStillSucceeds_AndRetryIsDayDuplicate(): void {
		$houseId = $this->createHouse('cct_retry_owner', 'Retry House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'CCT retry', '', 5, 3);
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'cct_retry_owner');
		$this->cleanupRoomTaskIds[] = $roomTaskId;

		$completionService = $this->sabotagedWeeklyGoalCompletionService();

		// First completion: commits fine, the post-commit read degrades.
		$result = $completionService->complete($houseId, 'cct_retry_owner', $roomTaskId);
		self::assertTrue($result['rewards']['degraded'], 'the failed post-commit effect must flag the payload degraded');
		self::assertNull($result['rewards']['weeklyGoal']);
		self::assertCount(1, $this->ledgerRows($roomTaskId));

		// Retry on the same day: day-duplicate 403, no second ledger row.
		try {
			$completionService->complete($houseId, 'cct_retry_owner', $roomTaskId);
			self::fail('the retried completion must hit the day-duplicate 403');
		} catch (ForbiddenException $e) {
			self::assertSame('You already completed this task today', $e->getMessage());
		}

		self::assertCount(1, $this->ledgerRows($roomTaskId), 'the failed retry must not write a second ledger row');
	}

	/**
	 * ConcurrentUndo_LoserOfRevertibleRace_IsIdempotentZeroWriteNoOp
	 *
	 * T1 (helper) holds the room-task lock and commits the winner's
	 * `undone_at` mark while T2 (this process) sits between its pre-transaction
	 * probe and the lock. T2's under-lock re-check must make it the no-op:
	 * `undone: false` and ZERO domain writes.
	 */
	public function testConcurrentUndo_LoserOfRevertibleRace_IsIdempotentZeroWriteNoOp(): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_MYSQL) {
			self::markTestSkipped('The two-connection race scenario requires MySQL/MariaDB (the Docker test DB).');
		}

		$houseId = $this->createHouse('cct_undo_owner', 'Undo Race House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'CCT undo', '', 5, 3);
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'cct_undo_owner');
		$this->cleanupRoomTaskIds[] = $roomTaskId;

		$completionService = \OC::$server->get(CompletionService::class);
		$actor = \OC::$server->get(HouseUserMapper::class)
			->findByNextcloudUserId($houseId, 'cct_undo_owner');

		$completionService->complete($houseId, 'cct_undo_owner', $roomTaskId);

		$roomAfterCompletion = (int)$this->fetchRow('chorestrike_rooms', 'id', $roomId)['cleanliness'];
		$userAfterCompletion = \OC::$server->get(HouseUserMapper::class)
			->findByNextcloudUserId($houseId, 'cct_undo_owner');
		$ledgerRow = $this->ledgerRows($roomTaskId)[0];
		self::assertNull($ledgerRow['undone_at']);

		$signalFile = sys_get_temp_dir() . '/cs-cct-undo-locked-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-cct-undo-holder-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildUndoWinnerHolderScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, (string)$roomTaskId, $ledgerRow['id'], $signalFile],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the undo-winner holder process');

		try {
			$deadline = microtime(true) + 30.0;
			while (!file_exists($signalFile)) {
				if (microtime(true) > $deadline) {
					self::fail('The undo-winner holder did not acquire the room-task lock in time');
				}
				usleep(50000);
			}

			// T2: the probe still sees the revertible row, the transaction
			// blocks at the room-task lock, and the under-lock re-check then
			// finds T1's committed `undone_at` — the no-op.
			$result = $completionService->undo($houseId, $actor, $roomTaskId);

			self::assertFalse($result['undone'], 'the race loser must answer the idempotent no-op');
			self::assertNull($result['rewards'], 'the no-op must not fabricate reverted rewards');
		} finally {
			$this->terminateHelper($process, $pipes);
			@unlink($signalFile);
			@unlink($helperScript);
		}

		// Zero writes: the loser must not have restored anything.
		self::assertSame(
			$roomAfterCompletion,
			(int)$this->fetchRow('chorestrike_rooms', 'id', $roomId)['cleanliness'],
			'the no-op loser must not touch the room'
		);
		$userAfterRace = \OC::$server->get(HouseUserMapper::class)
			->findByNextcloudUserId($houseId, 'cct_undo_owner');
		self::assertSame($userAfterCompletion->getCoins(), $userAfterRace->getCoins(), 'the no-op loser must not touch the reward row');
		self::assertCount(1, $this->ledgerRows($roomTaskId), 'the no-op loser must not add or remove ledger rows');
	}

	/**
	 * InactiveOccurrence_Rejected409_ZeroWrites
	 *
	 * Decision D4 on the real DB: a deactivated room task must not be
	 * completed — 409 before any mutation, no ledger row, no cleanliness
	 * delta, no reward, and the checklist progress stays untouched.
	 */
	public function testInactiveOccurrence_Rejected409_ZeroWrites(): void {
		$houseId = $this->createHouse('cct_inactive_owner', 'Inactive House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'CCT inactive', '', 5, 3);
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'cct_inactive_owner');
		$this->cleanupRoomTaskIds[] = $roomTaskId;

		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_room_tasks')
			->set('is_active', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		// A checked progress row must survive untouched (no reset, no leak).
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_chk_progress')
			->values([
				'room_task_id' => $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT),
				'checklist_item_id' => $qb->createNamedParameter(4242, IQueryBuilder::PARAM_INT),
				'is_checked' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
			])
			->executeStatement();

		$completionService = \OC::$server->get(CompletionService::class);

		try {
			$completionService->complete($houseId, 'cct_inactive_owner', $roomTaskId);
			self::fail('an inactive occurrence must be rejected with 409');
		} catch (ConflictException $e) {
			self::assertSame('Task is not active', $e->getMessage());
		}

		self::assertSame([], $this->ledgerRows($roomTaskId), 'no ledger row for a rejected occurrence');
		self::assertSame(50, (int)$this->fetchRow('chorestrike_rooms', 'id', $roomId)['cleanliness'], 'no cleanliness write');
		$user = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, 'cct_inactive_owner');
		self::assertSame(0, $user->getCoins(), 'no reward write');
		$progress = $this->fetchRowsBy('chorestrike_chk_progress', 'room_task_id', $roomTaskId);
		self::assertCount(1, $progress);
		self::assertSame(1, (int)$progress[0]['is_checked'], 'the checked row must stay checked');
		self::assertSame(0, (int)$this->fetchRow('chorestrike_room_tasks', 'id', $roomTaskId)['is_active']);
	}

	// ========== Helpers ==========

	/**
	 * A CompletionService whose post-commit weekly-goal read always throws —
	 * the D7 boundary is the subject, so the sabotage must be deterministic.
	 * The subclass instance skips the constructor: the overridden method only
	 * throws and never touches an injected dependency.
	 */
	private function sabotagedWeeklyGoalCompletionService(): CompletionService {
		$s = \OC::$server;
		$houseService = (new \ReflectionClass(SabotagedWeeklyGoalHouseService::class))
			->newInstanceWithoutConstructor();

		return new CompletionService(
			$s->get(TransactionExecutor::class),
			$s->get(RoomTaskMapper::class),
			$s->get(TaskMapper::class),
			$s->get(RoomMapper::class),
			$s->get(HouseMapper::class),
			$s->get(HouseUserMapper::class),
			$s->get(TaskAssigneeMapper::class),
			$s->get(CompletedTaskMapper::class),
			$s->get(RoomTaskChecklistProgressMapper::class),
			$s->get(\OCA\ChoreStrike\Service\GameLogicService::class),
			$houseService,
			$s->get(CalendarService::class),
			$s->get(TaskAccessPolicy::class),
			$s->get(NotificationService::class),
			$s->get(\OCP\L10N\IFactory::class)->get('chorestrike'),
			$s->get(AppLogger::class),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fetchRow(string $table, string $column, int $id): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		self::assertIsArray($row, "$table row #$id must exist");
		return $row;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchRowsBy(string $table, string $column, int $value): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function ledgerRows(int $roomTaskId): array {
		return $this->fetchRowsBy('chorestrike_completed_tasks', 'room_task_id', $roomTaskId);
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

	/**
	 * The T1 helper: boots Nextcloud, opens a transaction, locks the ROOM
	 * row (second of the documented lock order), signals the parent, holds
	 * briefly, then commits the concurrent winner B's cleanliness delta.
	 */
	private function buildRoomLockHolderScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

$roomId = (int)$argv[1];
$signalFile = $argv[2];

$db = \OC::$server->get(\OCP\IDBConnection::class);

$db->beginTransaction();
$result = $db->executeQuery(
    'SELECT id FROM *PREFIX*chorestrike_rooms WHERE id = :id FOR UPDATE',
    ['id' => $roomId],
    ['id' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
);
$result->closeCursor();

file_put_contents($signalFile, 'locked');
usleep(2000000);

$db->executeStatement(
    'UPDATE *PREFIX*chorestrike_rooms SET cleanliness = cleanliness + 30 WHERE id = :id',
    ['id' => $roomId],
    ['id' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
);
$db->commit();
PHP;
	}

	/**
	 * The T1 helper: boots Nextcloud, opens a transaction, locks the room
	 * task row (first of the documented lock order), signals the parent,
	 * holds briefly, then commits the concurrent undo winner's `undone_at`
	 * mark on the ledger row.
	 */
	private function buildUndoWinnerHolderScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

$roomTaskId = (int)$argv[1];
$ledgerRowId = (int)$argv[2];
$signalFile = $argv[3];

$db = \OC::$server->get(\OCP\IDBConnection::class);

$db->beginTransaction();
$result = $db->executeQuery(
    'SELECT id FROM *PREFIX*chorestrike_room_tasks WHERE id = :id FOR UPDATE',
    ['id' => $roomTaskId],
    ['id' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
);
$result->closeCursor();

file_put_contents($signalFile, 'locked');
usleep(2000000);

$db->executeStatement(
    'UPDATE *PREFIX*chorestrike_completed_tasks SET undone_at = NOW() WHERE id = :id',
    ['id' => $ledgerRowId],
    ['id' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
);
$db->commit();
PHP;
	}
}

/**
 * Test-local HouseService whose weekly-goal read always fails (D7 sabotage).
 * Instantiated via `newInstanceWithoutConstructor` — the overridden method
 * throws before any injected dependency would be touched.
 */
class SabotagedWeeklyGoalHouseService extends HouseService {
	public function getWeeklyGoalProgress(int $houseId): array {
		throw new \RuntimeException('sabotaged post-commit weekly-goal read');
	}
}
