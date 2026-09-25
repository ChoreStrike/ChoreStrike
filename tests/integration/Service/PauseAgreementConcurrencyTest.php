<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\Pause;
use OCA\ChoreStrike\Service\PauseService;
use OCA\ChoreStrike\Service\SchedulePlanService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Real two-connection race proofs for the WP2 pause/agreement surfaces
 * (CS-PLAN-073 §4 "Real races / rollback", RF-073-WP2-09). Every scenario
 * runs a SECOND Nextcloud-bootstrapped process against the same MariaDB —
 * sequential calls are never passed off as concurrency evidence:
 *
 *  1. plan apply vs an acknowledged pause: the house-first lock order
 *     serializes both aggregates into ONE consistent final state;
 *  2. duplicate restarts of one suspended predecessor: exactly one
 *     successor, the loser answers a clean conflict;
 *  3. two shortenings against the same pause revision: exactly one
 *     appends revision 2, the other gets the stale-revision conflict;
 *  4. the SAME receipt UUID fired from two connections: one commit, the
 *     other a pure replay — a single effect, identical refs;
 *  5. an injected failure after the pause-row insertion rolls back the
 *     pause, its effects AND its receipt (nothing survives half-done).
 *
 * MySQL/MariaDB-only (SQLite has no row-level concurrency to prove).
 *
 * @group DB
 */
class PauseAgreementConcurrencyTest extends IntegrationTestCase {
	private string $todayKey;

	/** @var list<int> */
	private array $cleanupHouseIds = [];

	protected function setUp(): void {
		parent::setUp();
		$this->todayKey = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
	}

	protected function tearDown(): void {
		// House-scoped wipe of the WP2 fact tables (FK-free schema); the
		// base cleanup removes houses/members/tasks/rooms.
		foreach ($this->cleanupHouseIds as $houseId) {
			$this->db->executeStatement(
				'DELETE FROM *PREFIX*chorestrike_agreements WHERE occurrence_id IN'
				. ' (SELECT id FROM *PREFIX*chorestrike_occurrences WHERE house_id = ?)',
				[$houseId]
			);
			$this->db->executeStatement(
				'DELETE FROM *PREFIX*chorestrike_occ_members WHERE occurrence_id IN'
				. ' (SELECT id FROM *PREFIX*chorestrike_occurrences WHERE house_id = ?)',
				[$houseId]
			);
			$this->db->executeStatement(
				'DELETE FROM *PREFIX*chorestrike_pauses WHERE house_id = ?',
				[$houseId]
			);
			$this->db->executeStatement(
				'DELETE FROM *PREFIX*chorestrike_commands WHERE house_id = ?',
				[$houseId]
			);
		}
		$this->cleanupHouseIds = [];
		parent::tearDown();
	}

	private function skipUnlessMariaDb(): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_MYSQL) {
			self::markTestSkipped('The two-connection race scenario requires MySQL/MariaDB (the Docker test DB).');
		}
	}

	private function uuidPac(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}

	/**
	 * A race house: owner + adult member (wallets bound) and one low-
	 * energy task; `$assignedTo` null leaves it unassigned for the planner.
	 *
	 * @return array{houseId: int, roomTaskId: int, owner: string, member: string}
	 */
	private function raceHouse(string $suffix, ?string $assignedTo = '', string $taskMode = 'dynamic'): array {
		$owner = 'pac_owner' . $suffix;
		$member = 'pac_mom' . $suffix;
		if ($assignedTo === '') {
			$assignedTo = $owner;
		}
		$houseId = $this->createHouse($owner, 'Pause Race House');
		$this->cleanupHouseIds[] = $houseId;
		$this->addHouseMember($houseId, $member, 'Mom', 'member');

		$houseUserMapper = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class);
		$walletService = \OC::$server->get(\OCA\ChoreStrike\Service\WalletService::class);
		foreach ([$owner, $member] as $userId) {
			$walletService->ensureBinding($houseId, $houseUserMapper->findByNextcloudUserId($houseId, $userId));
		}

		// Wallets are bound BEFORE the assignment so the revision defaults
		// and the occurrence member rows carry them (the suspension
		// derivation resolves per wallet).
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Race dishes', '', 5, 2);
		if ($taskMode === 'static') {
			$qb = $this->db->getQueryBuilder();
			$qb->update('chorestrike_tasks')
				->set('scheduling_mode', $qb->createNamedParameter('static'))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, $assignedTo);

		return ['houseId' => $houseId, 'roomTaskId' => $roomTaskId, 'owner' => $owner, 'member' => $member];
	}

	/**
	 * Run the helper process against a barrier: start it, wait for its
	 * ready signal, run `$mainAction` while the helper waits for the go
	 * file, then collect the helper's outcome.
	 *
	 * @param list<string> $argv Extra script arguments
	 * @return array{main: mixed, helper: string}
	 */
	private function raceWithHelper(string $script, array $argv, callable $mainAction): array {
		$dir = sys_get_temp_dir();
		$readyFile = $dir . '/cs-pac-ready-' . uniqid();
		$goFile = $dir . '/cs-pac-go-' . uniqid();
		$resultFile = $dir . '/cs-pac-result-' . uniqid();
		$helperScript = $dir . '/cs-pac-helper-' . uniqid() . '.php';
		file_put_contents($helperScript, $script);

		$process = proc_open(
			array_merge([PHP_BINARY, $helperScript], $argv, [$readyFile, $goFile, $resultFile]),
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the race helper process');

		try {
			$deadline = microtime(true) + 60.0;
			while (!file_exists($readyFile)) {
				if (microtime(true) > $deadline) {
					self::fail('the helper did not reach the barrier in time: ' . stream_get_contents($pipes[2]));
				}
				usleep(20000);
			}

			// Fire both sides as close together as the barrier allows.
			file_put_contents($goFile, 'go');
			$main = $mainAction();

			$deadline = microtime(true) + 60.0;
			while (!file_exists($resultFile)) {
				if (microtime(true) > $deadline) {
					self::fail('the helper did not finish in time: ' . stream_get_contents($pipes[2]));
				}
				usleep(20000);
			}
			$helper = trim((string)file_get_contents($resultFile));
			return ['main' => $main, 'helper' => $helper];
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
			foreach ([$readyFile, $goFile, $resultFile, $helperScript] as $file) {
				@unlink($file);
			}
		}
	}

	// ------------------------------------------------------------------
	// 1. Plan apply vs acknowledged pause
	// ------------------------------------------------------------------

	public function testPlanApplyVsPause_SerializesIntoOneConsistentState(): void {
		$this->skipUnlessMariaDb();
		$f = $this->raceHouse('P', null);

		$planService = \OC::$server->get(SchedulePlanService::class);
		$ownerActor = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
			->findByNextcloudUserId($f['houseId'], $f['owner']);
		$preview = $planService->preview($f['houseId'], $ownerActor, $this->todayKey, [$f['roomTaskId']], []);
		self::assertCount(1, $preview['changes']);

		$outcome = $this->raceWithHelper(
			$this->buildPauseHelperScript(),
			[(string)$f['houseId'], $f['member'], $this->todayKey, $this->uuidPac()],
			function () use ($planService, $preview, $f, $ownerActor): string {
				try {
					$planService->apply(
						$f['houseId'],
						$ownerActor,
						$this->todayKey,
						[$f['roomTaskId']],
						[],
						$preview['previewVersion'],
						$this->uuidPac(),
						true
					);
					return 'ok';
				} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
					return 'conflict';
				}
			}
		);

		self::assertSame('ok', $outcome['helper'], 'the pause aggregate always commits (it owns no stale preview)');
		self::assertContains($outcome['main'], ['ok', 'conflict'], 'the apply either wins the lock or cleanly conflicts');

		// The pause fact exists in BOTH serializations.
		self::assertGreaterThanOrEqual(1, $this->countPauses($f['houseId']));

		$assigneeRows = $this->fetchAssigneeRows($f['roomTaskId']);
		if ($outcome['main'] === 'conflict') {
			self::assertSame([], $assigneeRows, 'a conflicted apply wrote nothing');
		} else {
			$proposed = $preview['changes'][0]['proposed']['assignees'];
			$applied = array_map(static fn (array $row): string => (string)$row['user_id'], $assigneeRows);
			sort($applied);
			self::assertSame($proposed, $applied, 'the winning apply committed its assignment exactly once');
			if (in_array($f['member'], $proposed, true)) {
				// The acknowledged pause covers today: an obligation assigned
				// to the paused member ends up neutrally suspended — the
				// final state is consistent regardless of commit order.
				$occurrences = \OC::$server->get(\OCA\ChoreStrike\Db\OccurrenceMapper::class)
					->findByRoomTask($f['roomTaskId']);
				$open = array_values(array_filter($occurrences, static fn ($o): bool => $o->getCompletionId() === null));
				self::assertNotEmpty($open);
				$suspended = array_filter($open, static fn ($o): bool => $o->getSuspensionState() === 'suspended');
				self::assertNotEmpty($suspended, 'the pause effect reached the applied obligation');
			}
		}
	}

	// ------------------------------------------------------------------
	// 2. Duplicate restarts of one suspended predecessor
	// ------------------------------------------------------------------

	public function testDuplicateRestarts_ExactlyOneSuccessor(): void {
		$this->skipUnlessMariaDb();
		$f = $this->raceHouse('R');
		$this->suspendPending($f['houseId'], $f['owner']);

		$workDate = (new \DateTime($this->todayKey))->modify('+5 days')->format('Y-m-d');
		$outcome = $this->raceWithHelper(
			$this->buildRestartHelperScript(),
			[(string)$f['houseId'], (string)$f['roomTaskId'], $f['owner'], $workDate, $this->uuidPac()],
			function () use ($f, $workDate): string {
				return $this->fireRestart($f['houseId'], $f['roomTaskId'], $f['owner'], $workDate, $this->uuidPac());
			}
		);

		$outcomes = [$outcome['main'], $outcome['helper']];
		sort($outcomes);
		self::assertSame(['conflict', 'ok'], $outcomes, 'exactly one restart commits, the loser answers a clean conflict');

		$rows = \OC::$server->get(\OCA\ChoreStrike\Db\OccurrenceMapper::class)->findByRoomTask($f['roomTaskId']);
		$successors = array_values(array_filter($rows, static fn ($o): bool => $o->getRestartOfId() !== null));
		self::assertCount(1, $successors, 'the race produced exactly ONE linked restart successor');
	}

	// ------------------------------------------------------------------
	// 3./4. Pause revision races: stale expectedRevision vs replayed UUID
	// ------------------------------------------------------------------

	public function testConcurrentShortenings_SameExpectedRevision_OneStaleConflict(): void {
		$this->skipUnlessMariaDb();
		$f = $this->raceHouse('S');
		$pauseService = \OC::$server->get(PauseService::class);
		$created = $pauseService->requestForSelf($f['houseId'], $f['member'], [
			'commandId' => $this->uuidPac(),
			'startDate' => $this->todayKey,
			'endDate' => (new \DateTime($this->todayKey))->modify('+4 days')->format('Y-m-d'),
			'reasonCode' => Pause::REASON_TRAVEL,
		]);

		$outcome = $this->raceWithHelper(
			$this->buildShortenHelperScript(),
			[
				(string)$f['houseId'], $f['member'], (string)$created['pauseGroupId'],
				(string)$created['revision'], $this->todayKey, $this->uuidPac(),
			],
			function () use ($pauseService, $f, $created): string {
				try {
					$pauseService->shorten($f['houseId'], $f['member'], $created['pauseGroupId'], [
						'commandId' => $this->uuidPac(),
						'expectedRevision' => $created['revision'],
						'endDate' => $this->todayKey,
					]);
					return 'ok';
				} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
					return 'conflict';
				}
			}
		);

		$outcomes = [$outcome['main'], $outcome['helper']];
		sort($outcomes);
		self::assertSame(['conflict', 'ok'], $outcomes, 'exactly one shortening appends revision 2, the other sees the stale revision');
		self::assertSame(2, $this->currentRevision($created['pauseGroupId']));
	}

	public function testReplayedReceiptUuid_TwoConnections_SingleEffect(): void {
		$this->skipUnlessMariaDb();
		$f = $this->raceHouse('Y');
		$pauseService = \OC::$server->get(PauseService::class);
		$created = $pauseService->requestForSelf($f['houseId'], $f['member'], [
			'commandId' => $this->uuidPac(),
			'startDate' => $this->todayKey,
			'endDate' => (new \DateTime($this->todayKey))->modify('+4 days')->format('Y-m-d'),
			'reasonCode' => Pause::REASON_TRAVEL,
		]);

		// BOTH sides carry the SAME command UUID: one commits, the other is
		// a pure receipt replay — never a second revision, never a conflict.
		$sharedUuid = $this->uuidPac();
		$outcome = $this->raceWithHelper(
			$this->buildShortenHelperScript(),
			[
				(string)$f['houseId'], $f['member'], (string)$created['pauseGroupId'],
				(string)$created['revision'], $this->todayKey, $sharedUuid,
			],
			function () use ($pauseService, $f, $created, $sharedUuid): string {
				try {
					$pauseService->shorten($f['houseId'], $f['member'], $created['pauseGroupId'], [
						'commandId' => $sharedUuid,
						'expectedRevision' => $created['revision'],
						'endDate' => $this->todayKey,
					]);
					return 'ok';
				} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
					return 'conflict';
				}
			}
		);

		self::assertSame(['ok', 'ok'], [$outcome['main'], $outcome['helper']], 'the replayed receipt returns the original result on both connections');
		self::assertSame(2, $this->currentRevision($created['pauseGroupId']), 'the shortening took effect exactly once');
		self::assertSame(1, $this->countReceipts($f['houseId'], $sharedUuid));
	}

	// ------------------------------------------------------------------
	// 5. Injected failure after the pause insertion rolls back everything
	// ------------------------------------------------------------------

	public function testInjectedFailureAfterPauseInsert_RollsBackEffectsAndReceipt(): void {
		$f = $this->raceHouse('X');
		$commandId = $this->uuidPac();

		$saboteur = new class(\OC::$server->get(\OCP\IDBConnection::class)) extends \OCA\ChoreStrike\Db\PauseMapper {
			private int $inserts = 0;

			public function insert(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity {
				$this->inserts++;
				$inserted = parent::insert($entity);
				if ($this->inserts === 1) {
					// The failure lands AFTER the pause row exists inside
					// the open transaction — everything must roll back.
					throw new \RuntimeException('injected failure after pause insertion');
				}
				return $inserted;
			}
		};

		$pauseService = new PauseService(
			\OC::$server->get(\OCA\ChoreStrike\Service\CommandService::class),
			$saboteur,
			\OC::$server->get(\OCA\ChoreStrike\Service\PauseProjectionService::class),
			\OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class),
			\OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class),
			\OC::$server->get(\OCA\ChoreStrike\Db\WalletMapper::class),
			\OC::$server->get(\OCA\ChoreStrike\Db\OccurrenceMapper::class),
			\OC::$server->get(\OCA\ChoreStrike\Db\OccurrenceMemberMapper::class),
			\OC::$server->get(\OCA\ChoreStrike\Db\AgreementMapper::class),
			\OC::$server->get(\OCA\ChoreStrike\Db\TransactionExecutor::class),
			\OC::$server->get(\OCA\ChoreStrike\Service\CalendarService::class),
			\OC::$server->get(\OCP\L10N\IFactory::class)->get('chorestrike'),
		);

		try {
			$pauseService->requestForSelf($f['houseId'], $f['member'], [
				'commandId' => $commandId,
				'startDate' => $this->todayKey,
				'endDate' => (new \DateTime($this->todayKey))->modify('+4 days')->format('Y-m-d'),
				'reasonCode' => Pause::REASON_TRAVEL,
			]);
			self::fail('the injected failure must surface');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('injected failure', $e->getMessage());
		}

		self::assertSame(0, $this->countPauses($f['houseId']), 'the inserted pause row rolled back');
		self::assertSame(0, $this->countReceipts($f['houseId'], $commandId), 'the receipt rolled back with the failed transaction');
		$occurrences = \OC::$server->get(\OCA\ChoreStrike\Db\OccurrenceMapper::class)->findByRoomTask($f['roomTaskId']);
		foreach ($occurrences as $occurrence) {
			self::assertNotSame('suspended', $occurrence->getSuspensionState(), 'no half-applied pause effect survived');
		}
		self::assertSame(0, $this->countAgreements($f['houseId']), 'no evidence row survived the rollback');
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Acknowledge an owner pause covering the pending occurrence so the
	 * restart race has a suspended interval predecessor.
	 */
	private function suspendPending(int $houseId, string $owner): void {
		$pauseService = \OC::$server->get(PauseService::class);
		$pauseService->requestForSelf($houseId, $owner, [
			'commandId' => $this->uuidPac(),
			'startDate' => $this->todayKey,
			'endDate' => (new \DateTime($this->todayKey))->modify('+4 days')->format('Y-m-d'),
			'reasonCode' => Pause::REASON_TRAVEL,
		]);
		$occurrenceMapper = \OC::$server->get(\OCA\ChoreStrike\Db\OccurrenceMapper::class);
		$suspended = 0;
		foreach ($occurrenceMapper->findOpenByHouseId($houseId) as $row) {
			if ($row->getSuspensionState() === 'suspended') {
				$suspended++;
			}
		}
		self::assertGreaterThanOrEqual(1, $suspended, 'the fixture pause suspended the pending obligation');
	}

	private function fireRestart(int $houseId, int $roomTaskId, string $owner, string $workDate, string $commandId): string {
		$target = $this->suspendedTarget($houseId);
		try {
			\OC::$server->get(\OCA\ChoreStrike\Service\AgreementService::class)->restart($houseId, $owner, $roomTaskId, [
				'commandId' => $commandId,
				'occurrenceId' => $target['key'],
				'expectedVersion' => $target['version'],
				'consent' => true,
				'workDate' => $workDate,
			]);
			return 'ok';
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			return 'conflict';
		}
	}

	/**
	 * @return array{key: string, version: int}
	 */
	private function suspendedTarget(int $houseId): array {
		foreach (\OC::$server->get(\OCA\ChoreStrike\Db\OccurrenceMapper::class)->findOpenByHouseId($houseId) as $row) {
			if ($row->getSuspensionState() === 'suspended' && $row->getRestartOfId() === null) {
				return ['key' => $row->toPublicKey(), 'version' => $row->getVersion()];
			}
		}
		self::fail('the fixture must own a suspended restart target');
	}

	private function buildPauseHelperScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

[$houseId] = [(int)$argv[1]];
$member = $argv[2];
$todayKey = $argv[3];
$commandId = $argv[4];
$readyFile = $argv[5];
$goFile = $argv[6];
$resultFile = $argv[7];

try {
    file_put_contents($readyFile, 'ready');
    $deadline = microtime(true) + 60.0;
    while (!file_exists($goFile)) {
        if (microtime(true) > $deadline) { file_put_contents($resultFile, 'error:timeout'); exit(1); }
        usleep(20000);
    }
    \OC::$server->get(\OCA\ChoreStrike\Service\PauseService::class)->requestForSelf($houseId, $member, [
        'commandId' => $commandId,
        'startDate' => $todayKey,
        'endDate' => date('Y-m-d', strtotime($todayKey . ' +4 days')),
        'reasonCode' => \OCA\ChoreStrike\Db\Pause::REASON_TRAVEL,
    ]);
    file_put_contents($resultFile, 'ok');
} catch (Throwable $e) {
    file_put_contents($resultFile, 'error:' . get_class($e) . ':' . $e->getMessage());
    exit(1);
}
PHP;
	}

	private function buildRestartHelperScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

$houseId = (int)$argv[1];
$roomTaskId = (int)$argv[2];
$owner = $argv[3];
$workDate = $argv[4];
$commandId = $argv[5];
$readyFile = $argv[6];
$goFile = $argv[7];
$resultFile = $argv[8];

try {
    $target = null;
    foreach (\OC::$server->get(\OCA\ChoreStrike\Db\OccurrenceMapper::class)->findOpenByHouseId($houseId) as $row) {
        if ($row->getSuspensionState() === 'suspended' && $row->getRestartOfId() === null) {
            $target = $row;
        }
    }
    if ($target === null) { file_put_contents($resultFile, 'error:no-target'); exit(1); }

    file_put_contents($readyFile, 'ready');
    $deadline = microtime(true) + 60.0;
    while (!file_exists($goFile)) {
        if (microtime(true) > $deadline) { file_put_contents($resultFile, 'error:timeout'); exit(1); }
        usleep(20000);
    }
    try {
        \OC::$server->get(\OCA\ChoreStrike\Service\AgreementService::class)->restart($houseId, $owner, $roomTaskId, [
            'commandId' => $commandId,
            'occurrenceId' => $target->toPublicKey(),
            'expectedVersion' => $target->getVersion(),
            'consent' => true,
            'workDate' => $workDate,
        ]);
        file_put_contents($resultFile, 'ok');
    } catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
        file_put_contents($resultFile, 'conflict');
    }
} catch (Throwable $e) {
    file_put_contents($resultFile, 'error:' . get_class($e) . ':' . $e->getMessage());
    exit(1);
}
PHP;
	}

	private function buildShortenHelperScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

$houseId = (int)$argv[1];
$member = $argv[2];
$pauseGroupId = (int)$argv[3];
$expectedRevision = (int)$argv[4];
$endDate = $argv[5];
$commandId = $argv[6];
$readyFile = $argv[7];
$goFile = $argv[8];
$resultFile = $argv[9];

try {
    file_put_contents($readyFile, 'ready');
    $deadline = microtime(true) + 60.0;
    while (!file_exists($goFile)) {
        if (microtime(true) > $deadline) { file_put_contents($resultFile, 'error:timeout'); exit(1); }
        usleep(20000);
    }
    try {
        \OC::$server->get(\OCA\ChoreStrike\Service\PauseService::class)->shorten($houseId, $member, $pauseGroupId, [
            'commandId' => $commandId,
            'expectedRevision' => $expectedRevision,
            'endDate' => $endDate,
        ]);
        file_put_contents($resultFile, 'ok');
    } catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
        file_put_contents($resultFile, 'conflict');
    }
} catch (Throwable $e) {
    file_put_contents($resultFile, 'error:' . get_class($e) . ':' . $e->getMessage());
    exit(1);
}
PHP;
	}

	private function countPauses(int $houseId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from('chorestrike_pauses')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function countReceipts(int $houseId, string $commandId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from('chorestrike_commands')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('command_uuid', $qb->createNamedParameter($commandId)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function countAgreements(int $houseId): int {
		$result = $this->db->executeQuery(
			'SELECT COUNT(*) FROM *PREFIX*chorestrike_agreements WHERE occurrence_id IN'
			. ' (SELECT id FROM *PREFIX*chorestrike_occurrences WHERE house_id = ?)',
			[$houseId]
		);
		return (int)$result->fetchOne();
	}

	private function currentRevision(int $pauseGroupId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('revision'))->from('chorestrike_pauses')
			->where($qb->expr()->eq('pause_group_id', $qb->createNamedParameter($pauseGroupId, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function fetchAssigneeRows(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('chorestrike_task_assignees')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}
}
