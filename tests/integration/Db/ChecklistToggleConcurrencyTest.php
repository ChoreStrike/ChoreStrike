<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper correctness minors (CS-PLAN-060 WP1 §3.6): bounded getUserRank and
 * the checklist toggle's locked RMW with unique-violation-tolerant insert.
 *
 * The toggle race is MySQL/MariaDB-only (SQLite has no row-level
 * concurrency to prove).
 */
class ChecklistToggleConcurrencyTest extends IntegrationTestCase {
	private int $checklistItemId = 0;
	private int $roomTaskId = 0;

	protected function setUp(): void {
		parent::setUp();
	}

	protected function tearDown(): void {
		// chk_progress / chk_items rows for the fixture (FK-free schema).
		if ($this->roomTaskId !== 0) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_chk_progress')
				->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($this->roomTaskId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
		}
		if ($this->checklistItemId !== 0) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_chk_items')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($this->checklistItemId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
		}

		parent::tearDown();
	}

	/**
	 * Two concurrent first toggles for the same (room task, item): the loser
	 * hits the unique constraint, re-reads the winner's row and flips it —
	 * exactly one row, deterministic final state, no lost update.
	 */
	public function testToggle_TwoConcurrentFirstToggles_SingleRowFlippedOnce(): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_MYSQL) {
			self::markTestSkipped('The two-connection race scenario requires MySQL/MariaDB (the Docker test DB).');
		}

		$this->seedFixture();

		// T1 (helper): toggle inside an uncommitted transaction (inserts the
		// checked row), signal, hold briefly, commit.
		$signalFile = sys_get_temp_dir() . '/cs-chk-race-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-chk-race-holder-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildToggleHolderScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, (string)$this->roomTaskId, (string)$this->checklistItemId, $signalFile],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the toggle holder process');

		try {
			$deadline = microtime(true) + 30.0;
			while (!file_exists($signalFile)) {
				if (microtime(true) > $deadline) {
					self::fail('The toggle holder did not acquire its insert in time');
				}
				usleep(50000);
			}

			// T2: the insert blocks on uniq_prog_item until T1 commits, then
			// the unique violation is handled by re-reading and flipping.
			$mapper = \OC::$server->get(RoomTaskChecklistProgressMapper::class);
			$progress = $mapper->toggle($this->roomTaskId, $this->checklistItemId, 77);

			self::assertFalse($progress->getIsChecked(), 'the loser flips the winner\'s committed checked row');
		} finally {
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
			@unlink($signalFile);
			@unlink($helperScript);
		}

		// Exactly one row, deterministic final state.
		$qb = $this->db->getQueryBuilder();
		$qb->select('is_checked')
			->from('chorestrike_chk_progress')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($this->roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('checklist_item_id', $qb->createNamedParameter($this->checklistItemId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		self::assertCount(1, $rows, 'no duplicate progress row may exist');
		self::assertSame(0, (int)$rows[0]['is_checked'], 'the final state is the deterministic second flip');
	}

	/**
	 * The T1 helper: boots Nextcloud, toggles the item inside an uncommitted
	 * transaction (inserts the checked row), signals the parent, holds
	 * briefly, then commits.
	 */
	private function buildToggleHolderScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

$roomTaskId = (int)$argv[1];
$checklistItemId = (int)$argv[2];
$signalFile = $argv[3];

$mapper = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper::class);

try {
    $mapper->toggle($roomTaskId, $checklistItemId, 55);
    file_put_contents($signalFile, 'locked');
    usleep(2000000);
} catch (Throwable $e) {
    file_put_contents($signalFile, 'locked');
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
PHP;
	}

	/**
	 * House + floor + room + task + room task + one checklist item.
	 */
	private function seedFixture(): void {
		$houseId = $this->createHouse('chk_owner', 'Checklist House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Chore with checklist');
		$this->roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'chk_owner');

		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_chk_items')
			->values([
				'task_id' => $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT),
				'label' => $qb->createNamedParameter('Step 1'),
				'sort_order' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			]);
		$qb->executeStatement();
		$this->checklistItemId = (int)$this->db->lastInsertId('chorestrike_chk_items');
	}
}
