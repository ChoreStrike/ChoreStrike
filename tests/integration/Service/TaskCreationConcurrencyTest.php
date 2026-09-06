<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Service\TaskCreationService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Real-DB concurrency tests for the creation aggregate (CS-PLAN-063 §4).
 *
 * The races are reproduced deterministically with two real connections (the
 * `proc_open` helper pattern from BootstrapConcurrencyTest, refined by
 * SetupApplyConcurrencyTest): the helper process plays the concurrent
 * winner (holds the house-row lock inside an uncommitted transaction), the
 * test process plays the loser whose aggregate blocks at the lock and then
 * either fails the under-lock duplicate recheck (mount race) or the
 * under-lock membership revalidation (removed-member race) — both with 400
 * and zero persisted writes.
 *
 * MySQL/MariaDB only — SQLite has no row-level concurrency to prove; the
 * tolerant code paths are exercised there as straight-line behavior.
 */
class TaskCreationConcurrencyTest extends IntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
	}

	/**
	 * Two concurrent mounts of the same definition: exactly one room-task
	 * row survives and the loser gets 400 — the duplicate guard is rechecked
	 * under the house lock, so the loser sees the winner's committed row.
	 */
	public function testConcurrentMountRace_ExactlyOneRow_LoserGets400(): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_MYSQL) {
			self::markTestSkipped('The two-connection race scenario requires MySQL/MariaDB (the Docker test DB).');
		}

		$ownerUserId = 'tcc-mount-owner-' . uniqid();
		$houseId = $this->createHouse($ownerUserId);
		$floorId = $this->createFloor($houseId, 'Race Floor');
		$roomId = $this->createRoom($houseId, $floorId, 'Race Room');
		$taskId = $this->createTask($houseId, 'Race Mount Source');

		// T1 (helper): lock the house row, insert the winning room task
		// (uncommitted), signal, hold, commit.
		$signalFile = sys_get_temp_dir() . '/cs-create-mount-race-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-create-mount-holder-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildRoomTaskInsertHolderScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, (string)$houseId, $signalFile, (string)$roomId, (string)$taskId],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the mount-race holder process');

		try {
			$deadline = microtime(true) + 30.0;
			while (!file_exists($signalFile)) {
				if (microtime(true) > $deadline) {
					self::fail('The mount-race holder did not acquire the house lock in time');
				}
				usleep(50000);
			}

			// T2 (this process): the aggregate blocks at findForUpdate until
			// T1 commits, then the under-lock duplicate recheck must reject.
			$service = \OC::$server->get(TaskCreationService::class);
			$actor = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
				->findByNextcloudUserId($houseId, $ownerUserId);

			try {
				$service->create($houseId, $actor, [
					'roomId' => $roomId,
					'taskId' => $taskId,
				]);
				self::fail('The racing loser mount must be rejected with 400');
			} catch (\InvalidArgumentException $e) {
				self::assertStringContainsString('already exists in room', $e->getMessage());
			}
		} finally {
			$this->terminateHelper($process, $pipes);
			@unlink($signalFile);
			@unlink($helperScript);
		}

		// Exactly one room-task row survived the race.
		self::assertSame(1, $this->countRoomTasksFor($roomId, $taskId), 'exactly one mount may survive the race');
	}

	/**
	 * Membership removed while the creator waits on the house lock: the
	 * under-lock revalidation fails with 400 and zero definition, room-task,
	 * and assignee rows persist (the DB-level orphan-definition proof).
	 */
	public function testRemovedMemberDuringCreate_Yields400_AndZeroWrites(): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_MYSQL) {
			self::markTestSkipped('The two-connection race scenario requires MySQL/MariaDB (the Docker test DB).');
		}

		$ownerUserId = 'tcc-del-owner-' . uniqid();
		$assigneeUserId = 'tcc-del-assignee-' . uniqid();
		$houseId = $this->createHouse($ownerUserId);
		$this->addHouseMember($houseId, $assigneeUserId, 'Race Assignee', 'member');
		$floorId = $this->createFloor($houseId, 'Race Floor');
		$roomId = $this->createRoom($houseId, $floorId, 'Race Room');

		// T1 (helper): lock the house row, signal, hold, DELETE the assignee's
		// membership, commit — the removal lands while T2 waits on the lock.
		$signalFile = sys_get_temp_dir() . '/cs-create-del-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-create-del-holder-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildMembershipDeleteHolderScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, (string)$houseId, $signalFile, $assigneeUserId],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the membership-delete holder process');

		try {
			$deadline = microtime(true) + 30.0;
			while (!file_exists($signalFile)) {
				if (microtime(true) > $deadline) {
					self::fail('The membership-delete holder did not acquire the house lock in time');
				}
				usleep(50000);
			}

			$service = \OC::$server->get(TaskCreationService::class);
			$actor = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
				->findByNextcloudUserId($houseId, $ownerUserId);

			try {
				$service->create($houseId, $actor, [
					'roomId' => $roomId,
					'task' => [
						'name' => 'Race Orphan Proof',
						'energyCost' => 3,
						'priority' => 2,
						'frequencyType' => 'weekly',
						'frequencyValue' => 1,
						'estimatedMinutes' => 15,
						'category' => 'cleaning',
					],
					'assigneeUserIds' => [$assigneeUserId],
				]);
				self::fail('The removed-member assignee must fail the under-lock revalidation');
			} catch (\InvalidArgumentException $e) {
				self::assertStringContainsString('not a member', $e->getMessage());
			}
		} finally {
			$this->terminateHelper($process, $pipes);
			@unlink($signalFile);
			@unlink($helperScript);
		}

		// Zero writes survived the rollback.
		self::assertSame(0, $this->countRowsByJoin('chorestrike_tasks', 'name', 'Race Orphan Proof'), 'zero definition rows');
		self::assertSame(0, $this->countRoomTasksFor($roomId, 0), 'zero room-task rows in the race room');
		self::assertSame(0, $this->countAssigneeRowsFor($assigneeUserId), 'zero assignee rows');
	}

	// ========== Helpers ==========

	private function countRoomTasksFor(int $roomId, int $taskId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_room_tasks')
			->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function countRowsByJoin(string $table, string $col, string $value): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($table)
			->where($qb->expr()->eq($col, $qb->createNamedParameter($value)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function countAssigneeRowsFor(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_task_assignees')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
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
	 * The T1 helper: boots Nextcloud, opens a transaction, locks the house
	 * row (first of the documented lock order), inserts the winning room
	 * task (uncommitted), signals the parent, holds briefly, then commits.
	 */
	private function buildRoomTaskInsertHolderScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

$houseId = (int)$argv[1];
$signalFile = $argv[2];
$roomId = (int)$argv[3];
$taskId = (int)$argv[4];

$db = \OC::$server->get(\OCP\IDBConnection::class);

$db->beginTransaction();
$result = $db->executeQuery(
    'SELECT id FROM *PREFIX*chorestrike_houses WHERE id = :id FOR UPDATE',
    ['id' => $houseId],
    ['id' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
);
$result->closeCursor();

$qb = $db->getQueryBuilder();
$qb->insert('chorestrike_room_tasks')
    ->values([
        'room_id' => $qb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        'task_id' => $qb->createNamedParameter($taskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        'next_due' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
        'is_active' => $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
    ]);
$qb->executeStatement();

file_put_contents($signalFile, 'locked');
usleep(2000000);
$db->commit();
PHP;
	}

	/**
	 * The T1 helper: boots Nextcloud, opens a transaction, locks the house
	 * row, signals the parent, holds briefly, deletes the assignee's
	 * membership, then commits — the removal lands while the loser's
	 * aggregate waits on the house lock.
	 */
	private function buildMembershipDeleteHolderScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

$houseId = (int)$argv[1];
$signalFile = $argv[2];
$assigneeUserId = $argv[3];

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

$qb = $db->getQueryBuilder();
$qb->delete('chorestrike_house_users')
    ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
    ->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($assigneeUserId)));
$qb->executeStatement();

$db->commit();
PHP;
	}
}
