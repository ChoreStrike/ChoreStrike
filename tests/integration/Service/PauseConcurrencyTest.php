<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\Pause;
use OCA\ChoreStrike\Service\PauseService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Real two-connection race proofs for the absence commands (CS-PLAN-073 §4
 * "Real races / rollback", RF-073-WP2-09). The pause-only scenarios of the
 * retired `PauseAgreementConcurrencyTest` (CS-PLAN-091 WP-B1 interim review):
 * its plan-apply and restart races went with those commands, these three
 * cover the retained `PauseService`. Every scenario runs a SECOND
 * Nextcloud-bootstrapped process against the same installed DB:
 *
 *  1. two shortenings against the same absence revision: exactly one
 *     appends revision 2, the other gets the stale-revision conflict;
 *  2. the SAME receipt UUID fired from two connections: one commit, the
 *     other a pure replay — a single effect;
 *  3. an injected failure after the pause-row insertion rolls back the
 *     absence AND its receipt (nothing survives half-done).
 *
 * MariaDB/PostgreSQL use row locks; SQLite serializes database writers.
 *
 * @group DB
 */
class PauseConcurrencyTest extends IntegrationTestCase {
	private string $todayKey;

	protected function setUp(): void {
		parent::setUp();
		$this->todayKey = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
	}

	private function uuid(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}

	/**
	 * A race house: owner + adult member, both with a bound wallet.
	 *
	 * @return array{houseId: int, owner: string, member: string}
	 */
	private function raceHouse(string $suffix): array {
		$owner = 'pc_owner' . $suffix;
		$member = 'pc_mom' . $suffix;
		$houseId = $this->createHouse($owner, 'Pause Race House');
		$this->addHouseMember($houseId, $member, 'Mom', 'member');

		$houseUserMapper = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class);
		$walletService = \OC::$server->get(\OCA\ChoreStrike\Service\WalletService::class);
		foreach ([$owner, $member] as $userId) {
			$walletService->ensureBinding($houseId, $houseUserMapper->findByNextcloudUserId($houseId, $userId));
		}
		return ['houseId' => $houseId, 'owner' => $owner, 'member' => $member];
	}

	/** A five-day absence of the member from today on. */
	private function absence(PauseService $pauseService, array $f, ?string $commandId = null): array {
		return $pauseService->requestForSelf($f['houseId'], $f['member'], [
			'commandId' => $commandId ?? $this->uuid(),
			'startDate' => $this->todayKey,
			'endDate' => (new \DateTime($this->todayKey))->modify('+4 days')->format('Y-m-d'),
			'reasonCode' => Pause::REASON_TRAVEL,
		]);
	}

	/** The main side of a shortening race: `ok` or `conflict`. */
	private function shortenOutcome(PauseService $pauseService, array $f, array $created, string $commandId): string {
		try {
			$pauseService->shorten($f['houseId'], $f['member'], $created['pauseGroupId'], [
				'commandId' => $commandId,
				'expectedRevision' => $created['revision'],
				'endDate' => $this->todayKey,
			]);
			return 'ok';
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			return 'conflict';
		}
	}

	public function testConcurrentShortenings_SameExpectedRevision_OneStaleConflict(): void {
		$f = $this->raceHouse('S');
		$pauseService = \OC::$server->get(PauseService::class);
		$created = $this->absence($pauseService, $f);

		$outcome = $this->raceWithHelper(
			$this->buildShortenHelperScript(),
			[
				(string)$f['houseId'], $f['member'], (string)$created['pauseGroupId'],
				(string)$created['revision'], $this->todayKey, $this->uuid(),
			],
			fn (): string => $this->shortenOutcome($pauseService, $f, $created, $this->uuid())
		);

		$outcomes = [$outcome['main'], $outcome['helper']];
		sort($outcomes);
		self::assertSame(['conflict', 'ok'], $outcomes, 'exactly one shortening appends revision 2, the other sees the stale revision');
		self::assertSame(2, $this->currentRevision($created['pauseGroupId']));
	}

	public function testReplayedReceiptUuid_TwoConnections_SingleEffect(): void {
		$f = $this->raceHouse('Y');
		$pauseService = \OC::$server->get(PauseService::class);
		$created = $this->absence($pauseService, $f);

		// BOTH sides carry the SAME command UUID: one commits, the other is
		// a pure receipt replay — never a second revision, never a conflict.
		$sharedUuid = $this->uuid();
		$outcome = $this->raceWithHelper(
			$this->buildShortenHelperScript(),
			[
				(string)$f['houseId'], $f['member'], (string)$created['pauseGroupId'],
				(string)$created['revision'], $this->todayKey, $sharedUuid,
			],
			fn (): string => $this->shortenOutcome($pauseService, $f, $created, $sharedUuid)
		);

		self::assertSame(['ok', 'ok'], [$outcome['main'], $outcome['helper']], 'the replayed receipt returns the original result on both connections');
		self::assertSame(2, $this->currentRevision($created['pauseGroupId']), 'the shortening took effect exactly once');
		self::assertSame(1, $this->countReceipts($f['houseId'], $sharedUuid));
	}

	public function testInjectedFailureAfterPauseInsert_RollsBackEffectsAndReceipt(): void {
		$f = $this->raceHouse('X');
		$commandId = $this->uuid();

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
			\OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class),
			\OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class),
			\OC::$server->get(\OCA\ChoreStrike\Db\WalletMapper::class),
			\OC::$server->get(\OCA\ChoreStrike\Db\TransactionExecutor::class),
			\OC::$server->get(\OCA\ChoreStrike\Service\CalendarService::class),
			\OC::$server->get(\OCA\ChoreStrike\Db\DigestChangeMapper::class),
			\OC::$server->get(\OCA\ChoreStrike\Service\DigestScheduleService::class),
			\OC::$server->get(\OCP\L10N\IFactory::class)->get('chorestrike'),
		);

		try {
			$this->absence($pauseService, $f, $commandId);
			self::fail('the injected failure must surface');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('injected failure', $e->getMessage());
		}

		self::assertSame(0, $this->countPauses($f['houseId']), 'the inserted pause row rolled back');
		self::assertSame(0, $this->countReceipts($f['houseId'], $commandId), 'the receipt rolled back with the failed transaction');
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
		$readyFile = $dir . '/cs-pc-ready-' . uniqid();
		$goFile = $dir . '/cs-pc-go-' . uniqid();
		$resultFile = $dir . '/cs-pc-result-' . uniqid();
		$helperScript = $dir . '/cs-pc-helper-' . uniqid() . '.php';
		file_put_contents($helperScript, $script);

		$process = proc_open(
			array_merge([PHP_BINARY, $helperScript], $argv, [$readyFile, $goFile, $resultFile]),
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the race helper process');

		try {
			$this->waitForFile($readyFile, 'the helper did not reach the barrier in time: ', $pipes[2]);
			// Fire both sides as close together as the barrier allows.
			file_put_contents($goFile, 'go');
			$main = $mainAction();
			$this->waitForFile($resultFile, 'the helper did not finish in time: ', $pipes[2]);
			return ['main' => $main, 'helper' => trim((string)file_get_contents($resultFile))];
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

	/** @param resource $stderr */
	private function waitForFile(string $file, string $failure, $stderr): void {
		$deadline = microtime(true) + 60.0;
		while (!file_exists($file)) {
			if (microtime(true) > $deadline) {
				self::fail($failure . stream_get_contents($stderr));
			}
			usleep(20000);
		}
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

	private function currentRevision(int $pauseGroupId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('revision'))->from('chorestrike_pauses')
			->where($qb->expr()->eq('pause_group_id', $qb->createNamedParameter($pauseGroupId, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
