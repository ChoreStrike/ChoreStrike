<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Service\TaskCreationService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\QueryBarrier;
use OCA\ChoreStrike\Tests\Integration\Support\TaskEditing;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Real-DB concurrency tests for the one form's two writes (CS-PLAN-063 §4,
 * CS-PLAN-091 WP-B6).
 *
 * The races are reproduced deterministically with two real connections (the
 * `proc_open` helper pattern from BootstrapConcurrencyTest, refined by
 * SetupApplyConcurrencyTest): the helper process plays the concurrent
 * winner (holds the house-row lock inside an uncommitted transaction), the
 * test process plays the loser whose aggregate blocks at the lock and then
 * either fails the under-lock duplicate recheck of a move (409) or the
 * under-lock membership revalidation of a create (400) — both with zero
 * persisted writes.
 *
 * MariaDB/PostgreSQL use row locks; SQLite serializes real database writers.
 */
class TaskCreationConcurrencyTest extends IntegrationTestCase {
	use TaskEditing;

	protected function setUp(): void {
		parent::setUp();
	}

	/**
	 * A room task moves into a room while a concurrent writer mounts the
	 * same definition there: the move is rechecked under the house lock, so
	 * the loser sees the winner's committed row, answers 409 and moves
	 * nothing — the room holds the definition exactly once.
	 */
	public function testConcurrentMoveIntoAMountedRoom_ExactlyOneRow_LoserGets409(): void {
		$ownerUserId = 'tcc-move-owner-' . uniqid();
		$houseId = $this->createHouse($ownerUserId);
		$floorId = $this->createFloor($houseId, 'Race Floor');
		$fromRoomId = $this->createRoom($houseId, $floorId, 'Race Room');
		$toRoomId = $this->createRoom($houseId, $floorId, 'Race Target');
		$taskId = $this->createTask($houseId, 'Race Move Source');
		$roomTaskId = $this->assignTaskToRoom($taskId, $fromRoomId);
		$actor = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
			->findByNextcloudUserId($houseId, $ownerUserId);
		$edit = $this->formEdit($roomTaskId, ['roomId' => $toRoomId]);

		// T1 (helper): lock the house row, mount the definition in the
		// target room (uncommitted), signal, hold, commit.
		$signalFile = sys_get_temp_dir() . '/cs-edit-move-race-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-edit-move-holder-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildRoomTaskInsertHolderScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, (string)$houseId, $signalFile, (string)$toRoomId, (string)$taskId],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the move-race holder process');
		$barrier = QueryBarrier::attach($this->db, $signalFile, '/^(?:SELECT .*chorestrike_houses.*FOR UPDATE|UPDATE .*chorestrike_houses)/i');

		try {
			$deadline = microtime(true) + 30.0;
			while (!file_exists($signalFile)) {
				if (microtime(true) > $deadline) {
					self::fail('The move-race holder did not acquire the house lock in time');
				}
				usleep(50000);
			}

			// T2 (this process): the save blocks at the house lock until T1
			// commits, then the under-lock duplicate recheck must refuse.
			try {
				\OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskEditService::class)->edit($houseId, $actor, $roomTaskId, $edit);
				self::fail('The racing loser move must be refused with 409');
			} catch (ConflictException $e) {
				self::assertStringContainsString('already has this task', $e->getMessage());
			}
			self::assertTrue($barrier->wasReached());
			QueryBarrier::finish($process, $pipes);
		} finally {
			$barrier->detach();
			$this->terminateHelper($process, $pipes);
			@unlink($signalFile);
			@unlink($helperScript);
		}

		self::assertSame(1, $this->countRoomTasksFor($toRoomId, $taskId), 'the target room holds the definition once');
		self::assertSame(1, $this->countRoomTasksFor($fromRoomId, $taskId), 'the loser stayed where it was');
	}

	/**
	 * Membership removed while the creator waits on the house lock: the
	 * under-lock revalidation fails with 400 and zero definition, room-task,
	 * and assignee rows persist (the DB-level orphan-definition proof).
	 */
	public function testRemovedMemberDuringCreate_Yields400_AndZeroWrites(): void {
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
		$barrier = QueryBarrier::attach($this->db, $signalFile, '/^(?:SELECT .*chorestrike_houses.*FOR UPDATE|UPDATE .*chorestrike_houses)/i');

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
			self::assertTrue($barrier->wasReached());
			QueryBarrier::finish($process, $pipes);
		} finally {
			$barrier->detach();
			$this->terminateHelper($process, $pipes);
			@unlink($signalFile);
			@unlink($helperScript);
		}

		// Zero writes survived the rollback.
		self::assertSame(0, $this->countRowsByJoin('chorestrike_tasks', 'name', 'Race Orphan Proof'), 'zero definition rows');
		self::assertSame(0, $this->countRoomTasksFor($roomId), 'zero room-task rows in the race room');
		self::assertSame(0, $this->countAssigneeRowsFor($assigneeUserId), 'zero assignee rows');
	}

	// ========== Helpers ==========

	private function countRoomTasksFor(int $roomId, ?int $taskId = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_room_tasks')
			->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
		if ($taskId !== null) {
			$qb->andWhere($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)));
		}
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
require '/var/www/html/custom_apps/chorestrike/tests/integration/Support/QueryBarrier.php';

$houseId = (int)$argv[1];
$signalFile = $argv[2];
$roomId = (int)$argv[3];
$taskId = (int)$argv[4];

$db = \OC::$server->get(\OCP\IDBConnection::class);

$db->beginTransaction();
\OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->findForUpdate($houseId);

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

\OCA\ChoreStrike\Tests\Integration\Support\QueryBarrier::hold($signalFile);
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
require '/var/www/html/custom_apps/chorestrike/tests/integration/Support/QueryBarrier.php';

$houseId = (int)$argv[1];
$signalFile = $argv[2];
$assigneeUserId = $argv[3];

$db = \OC::$server->get(\OCP\IDBConnection::class);

$db->beginTransaction();
\OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->findForUpdate($houseId);

\OCA\ChoreStrike\Tests\Integration\Support\QueryBarrier::hold($signalFile);

$qb = $db->getQueryBuilder();
$qb->delete('chorestrike_house_users')
    ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
    ->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($assigneeUserId)));
$qb->executeStatement();

$db->commit();
PHP;
	}
}
