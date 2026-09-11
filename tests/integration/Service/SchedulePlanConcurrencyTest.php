<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Service\SchedulePlanService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Two-process race proofs for the scheduling apply aggregate
 * (CS-PLAN-065 §4.1 "Real concurrency"):
 *
 *  1. Two applies of the SAME preview in opposite input order serialize on
 *     the house-first lock order: exactly one commits, the loser answers
 *     the clean 409, and the final state carries the proposed assignments
 *     exactly once (no duplicates, no lost update, no deadlock).
 *  2. Apply vs a concurrent due-date edit: the apply blocked on the
 *     room-task row lock recomputes from the freshly committed state and
 *     conflicts — never a stale overwrite.
 *
 * The races follow the repo's `proc_open` helper pattern and are
 * MySQL/MariaDB-only (SQLite has no row-level concurrency to prove).
 */
class SchedulePlanConcurrencyTest extends IntegrationTestCase {
	private string $todayKey;

	protected function setUp(): void {
		parent::setUp();
		$this->todayKey = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
	}

	/**
	 * TwoAppliesOppositeInputOrder_SerializeWithOneCleanConflict
	 */
	public function testTwoAppliesOppositeInputOrder_SerializeWithOneCleanConflict(): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_MYSQL) {
			self::markTestSkipped('The two-connection race scenario requires MySQL/MariaDB (the Docker test DB).');
		}

		$houseId = $this->createHouse('spc_owner', 'Race House');
		$this->addHouseMember($houseId, 'spc_mom', 'Mom', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$roomTaskA = $this->assignTaskToRoom($this->createTask($houseId, 'Race A', '', 5, 2), $roomId, null);
		$roomTaskB = $this->assignTaskToRoom($this->createTask($houseId, 'Race B', '', 5, 2), $roomId, null);

		$previewFile = sys_get_temp_dir() . '/cs-spc-preview-' . uniqid();
		$goFile = sys_get_temp_dir() . '/cs-spc-go-' . uniqid();
		$resultFile = sys_get_temp_dir() . '/cs-spc-result-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-spc-helper-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildHelperScript());

		// The helper applies in DESCENDING input order, this process in
		// ascending order — the same content version either way (D2).
		$process = proc_open(
			[PHP_BINARY, $helperScript, (string)$houseId, (string)$roomTaskB, (string)$roomTaskA, $this->todayKey, $previewFile, $goFile, $resultFile],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the helper apply process');

		try {
			$deadline = microtime(true) + 60.0;
			while (!file_exists($previewFile)) {
				if (microtime(true) > $deadline) {
					self::fail('the helper did not produce its preview in time: ' . stream_get_contents($pipes[2]));
				}
				usleep(50000);
			}
			$helperVersion = trim((string)file_get_contents($previewFile));

			$service = \OC::$server->get(SchedulePlanService::class);
			$owner = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)->findByNextcloudUserId($houseId, 'spc_owner');
			$preview = $service->preview($houseId, $owner, $this->todayKey, [$roomTaskA, $roomTaskB], []);
			self::assertSame(
				$helperVersion,
				$preview['previewVersion'],
				'the content version must be identical across processes and input orders'
			);

			file_put_contents($goFile, 'go');
			$mainConflict = false;
			try {
				$service->apply($houseId, $owner, $this->todayKey, [$roomTaskA, $roomTaskB], [], $preview['previewVersion']);
			} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
				$mainConflict = true;
			}

			// Wait for the helper outcome.
			$deadline = microtime(true) + 60.0;
			while (!file_exists($resultFile)) {
				if (microtime(true) > $deadline) {
					self::fail('the helper apply did not finish in time');
				}
				usleep(50000);
			}
			$helperOutcome = trim((string)file_get_contents($resultFile));

			// Exactly one side commits; the loser answers the clean conflict.
			$outcomes = [$helperOutcome, $mainConflict ? 'conflict' : 'ok'];
			sort($outcomes);
			self::assertSame(
				['conflict', 'ok'],
				$outcomes,
				'two applies of one preview must serialize into one commit and one clean conflict'
			);

			// No mixed state: each proposed row carries its assignee exactly
			// once and the proposed due day.
			foreach ($preview['changes'] as $change) {
				$row = $this->fetchRoomTask((int)$change['roomTaskId']);
				self::assertSame(
					$change['proposed']['dueDate'],
					substr((string)$row['next_due'], 0, 10),
					'the committed due day must be the proposed one'
				);
				$assignees = $this->fetchAssignees((int)$change['roomTaskId']);
				sort($assignees);
				self::assertSame(
					$change['proposed']['assignees'],
					$assignees,
					'no duplicate or missing assignee rows may result from the race'
				);
			}
		} finally {
			$status = proc_get_status($process);
			if ($status !== false && $status['running']) {
				proc_terminate($process);
			}
			foreach ([1, 2] as $pipe) {
				if (is_resource($pipes[$pipe])) {
					fclose($pipes[$pipe]);
				}
			}
			proc_close($process);
			foreach ([$previewFile, $goFile, $resultFile, $helperScript] as $file) {
				@unlink($file);
			}
		}
	}

	/**
	 * ApplyVsConcurrentDueDateEdit_BlockedOnRowLock_RecomputesAndConflicts
	 */
	public function testApplyVsConcurrentDueDateEdit_BlockedOnRowLock_RecomputesAndConflicts(): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_MYSQL) {
			self::markTestSkipped('The two-connection race scenario requires MySQL/MariaDB (the Docker test DB).');
		}

		$houseId = $this->createHouse('spc2_owner', 'Race House 2');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$roomTaskId = $this->assignTaskToRoom($this->createTask($houseId, 'Race C', '', 5, 2), $roomId, null);

		$service = \OC::$server->get(SchedulePlanService::class);
		$owner = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)->findByNextcloudUserId($houseId, 'spc2_owner');
		$preview = $service->preview($houseId, $owner, $this->todayKey, [$roomTaskId], []);
		self::assertCount(1, $preview['changes']);

		$signalFile = sys_get_temp_dir() . '/cs-spc2-locked-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-spc2-holder-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildDueDateHolderScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, (string)$roomTaskId, $signalFile],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the due-date holder process');

		try {
			$deadline = microtime(true) + 30.0;
			while (!file_exists($signalFile)) {
				if (microtime(true) > $deadline) {
					self::fail('the due-date holder did not acquire the room-task lock in time');
				}
				usleep(50000);
			}

			// The apply blocks at the room-task row lock, the holder commits
			// a moved due date, and the recomputation must answer the clean
			// stale conflict instead of overwriting the winner.
			try {
				$service->apply($houseId, $owner, $this->todayKey, [$roomTaskId], [], $preview['previewVersion']);
				self::fail('the apply must conflict against the concurrently committed due-date change');
			} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
				self::assertSame('The schedule preview is no longer current. Generate a new preview.', $e->getMessage());
			}
		} finally {
			$status = proc_get_status($process);
			if ($status !== false && $status['running']) {
				proc_terminate($process);
			}
			foreach ([1, 2] as $pipe) {
				if (is_resource($pipes[$pipe])) {
					fclose($pipes[$pipe]);
				}
			}
			proc_close($process);
			@unlink($signalFile);
			@unlink($helperScript);
		}

		self::assertSame(
			$this->todayKey,
			substr((string)$this->fetchRoomTask($roomTaskId)['next_due'], 0, 10),
			'the holder\'s committed due date must survive (no stale overwrite)'
		);
		self::assertSame([], $this->fetchAssignees($roomTaskId), 'the conflicted apply must not write assignees');
	}

	// ========== Helpers ==========

	/**
	 * The T1 helper for the apply-vs-apply race: boot Nextcloud, preview,
	 * signal the version, wait for the parent's go, then apply.
	 */
	private function buildHelperScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

[$houseId, $firstId, $secondId] = [(int)$argv[1], (int)$argv[2], (int)$argv[3]];
$todayKey = $argv[4];
$previewFile = $argv[5];
$goFile = $argv[6];
$resultFile = $argv[7];

$service = \OC::$server->get(\OCA\ChoreStrike\Service\SchedulePlanService::class);
$owner = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)->findByNextcloudUserId($houseId, 'spc_owner');

try {
    $preview = $service->preview($houseId, $owner, $todayKey, [$firstId, $secondId], []);
    file_put_contents($previewFile, $preview['previewVersion']);

    $deadline = microtime(true) + 60.0;
    while (!file_exists($goFile)) {
        if (microtime(true) > $deadline) {
            file_put_contents($resultFile, 'error');
            exit(1);
        }
        usleep(50000);
    }

    try {
        $service->apply($houseId, $owner, $todayKey, [$firstId, $secondId], [], $preview['previewVersion']);
        file_put_contents($resultFile, 'ok');
    } catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
        file_put_contents($resultFile, 'conflict');
    }
} catch (Throwable $e) {
    file_put_contents($resultFile, 'error:' . get_class($e));
    exit(1);
}
PHP;
	}

	/**
	 * The T1 lock holder: opens a transaction, locks the room-task row,
	 * signals, then commits a moved due date while the parent's apply waits.
	 */
	private function buildDueDateHolderScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

$roomTaskId = (int)$argv[1];
$signalFile = $argv[2];

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
    'UPDATE *PREFIX*chorestrike_room_tasks SET next_due = :due WHERE id = :id',
    ['due' => date('Y-m-d') . ' 00:00:00', 'id' => $roomTaskId],
    ['due' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR, 'id' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
);
$db->commit();
PHP;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fetchRoomTask(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('chorestrike_room_tasks')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		self::assertIsArray($row);
		return $row;
	}

	/**
	 * @return list<string>
	 */
	private function fetchAssignees(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')->from('chorestrike_task_assignees')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (string)$row['user_id'];
		}
		$result->closeCursor();
		return $ids;
	}
}
