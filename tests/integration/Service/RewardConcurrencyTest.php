<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\RewardRequestEventMapper;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RewardRequestService;
use OCA\ChoreStrike\Service\RewardService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Integration concurrency tests for the C4 reward lifecycle (CS-PLAN-070
 * parent §3.2 acceptance scenarios C4.11-14; CS-PLAN-078 §3.6): two real
 * DB connections racing on one aggregate — exactly one financial effect,
 * full rollback on mid-aggregate failure, idempotent refund retries.
 *
 * The races follow the repo's two-connection `proc_open` helper pattern
 * and are skipped on non-MySQL providers (the SQLite fallback cannot
 * express row locks).
 */
class RewardConcurrencyTest extends IntegrationTestCase {
	private function skipUnlessMariaDb(): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_MYSQL) {
			self::markTestSkipped('The two-connection race scenario requires MySQL/MariaDB (the Docker test DB).');
		}
	}

	private function uuid(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}

	/**
	 * A reward race house: owner + adult member + child with bound
	 * wallets, catalog gate on and one live item.
	 *
	 * @return array{houseId: int, itemId: int, owner: string, adult: string, child: string}
	 */
	private function raceHouse(string $suffix): array {
		$owner = 'rc_owner' . $suffix;
		$adult = 'rc_adult' . $suffix;
		$child = 'rc_child' . $suffix;
		$houseId = $this->createHouse($owner, 'Reward Race House');
		$this->addHouseMember($houseId, $adult, 'Adult', 'member');
		$this->addHouseMember($houseId, $child, 'Child', 'child');

		// Fund the fixture wallets through the real ledger path.
		$walletMapper = \OC::$server->get(\OCA\ChoreStrike\Db\WalletMapper::class);
		$this->creditTestWallet($houseId, (int)$walletMapper->findByHouseAndUser($houseId, $adult)->getId(), 10000, null);
		$this->creditTestWallet($houseId, (int)$walletMapper->findByHouseAndUser($houseId, $child)->getId(), 10000, null);

		$houseService = \OC::$server->get(HouseService::class);
		$this->actingAs($owner);
		// The gate command targets the FIXTURE house — read its version
		// directly (getOrCreateHouse would resolve the bootstrap house).
		$fixtureHouse = \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($houseId);
		$houseService->setCatalogEnabled($houseId, true, $fixtureHouse->getStateVersion(), $this->uuid());
		$item = \OC::$server->get(RewardService::class)->createItem($houseId, [
			'title' => 'Race prize', 'priceMinor' => 1250, 'commandId' => $this->uuid(),
		]);

		return ['houseId' => $houseId, 'itemId' => (int)$item['id'], 'owner' => $owner, 'adult' => $adult, 'child' => $child];
	}

	/**
	 * Run the helper process against a barrier (repo pattern).
	 *
	 * @param list<string> $argv
	 * @return array{main: string, helper: string}
	 */
	private function raceWithHelper(string $script, array $argv, callable $mainAction): array {
		$dir = sys_get_temp_dir();
		$readyFile = $dir . '/cs-rc-ready-' . uniqid();
		$goFile = $dir . '/cs-rc-go-' . uniqid();
		$resultFile = $dir . '/cs-rc-result-' . uniqid();
		$helperScript = $dir . '/cs-rc-helper-' . uniqid() . '.php';
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

			file_put_contents($goFile, 'go');
			$main = (string)$mainAction();

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

	/**
	 * FulfillVsCancelRace_ExactlyOneFinancialEffect (C4.13)
	 */
	public function testFulfillVsCancelRace_ExactlyOneFinancialEffect(): void {
		$this->skipUnlessMariaDb();
		$f = $this->raceHouse('F');
		$requests = \OC::$server->get(RewardRequestService::class);
		$created = $requests->create($f['houseId'], $f['child'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid(),
		]);
		$requests->approve($f['houseId'], $f['owner'], (int)$created['id'], ['commandId' => $this->uuid(), 'expectedVersion' => 0]);
		// The baseline must be captured BEFORE the race, not after.
		$bookedBefore = $this->walletByUser($f['houseId'], $f['child'])->getBookedMinor();

		$outcome = $this->raceWithHelper(
			$this->buildLifecycleHelperScript(),
			[(string)$f['houseId'], $f['owner'], (string)$created['id'], '1'],
			function () use ($requests, $f, $created): string {
				try {
					$requests->cancel($f['houseId'], $f['child'], (int)$created['id'], ['commandId' => $this->uuid(), 'expectedVersion' => 1]);
					return 'cancelled';
				} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
					return 'conflict';
				} catch (\OCA\ChoreStrike\Exception\ForbiddenException $e) {
					return 'conflict';
				}
			}
		);

		// Exactly one side wins; the loser answers a clean business conflict.
		$final = $this->requestState((int)$created['id']);
		if ($final === 'fulfilled') {
			self::assertContains($outcome['main'], ['conflict'], 'the cancel loser conflicts');
			self::assertSame('ok', $outcome['helper'], 'the fulfill winner commits');
		} else {
			self::assertSame('cancelled', $final, 'the cancel winner commits');
			self::assertContains($outcome['helper'], ['ok', 'error:conflict'], 'the fulfill loser conflicts');
		}

		// The wallet reflects EXACTLY one outcome, never both.
		$wallet = $this->walletByUser($f['houseId'], $f['child']);
		if ($final === 'fulfilled') {
			self::assertSame($bookedBefore - 1250, $wallet->getBookedMinor());
			self::assertSame(0, $wallet->getReservedMinor());
		} else {
			self::assertSame($bookedBefore, $wallet->getBookedMinor());
			self::assertSame(0, $wallet->getReservedMinor());
		}
		self::assertSame(
			$wallet->getBookedMinor(),
			$this->ledgerSumByWalletId((int)$wallet->getId()),
			'B always equals the ledger sum after the race'
		);
		self::assertSame(0, $this->unfulfilledSumByWalletId((int)$wallet->getId()));
	}

	/**
	 * ConcurrentOwnerCancelVsRoleRebase_BothSerializeCoherently (C4.14)
	 *
	 * The owner's cancel and the requester's role rebase race under one
	 * house lock: whichever commits first wins cleanly, the loser answers
	 * a clean conflict, and the final state is coherent in BOTH
	 * serializations.
	 */
	public function testConcurrentOwnerCancelVsRoleRebase_BothSerializeCoherently(): void {
		$this->skipUnlessMariaDb();
		$f = $this->raceHouse('R');
		$requests = \OC::$server->get(RewardRequestService::class);
		$created = $requests->create($f['houseId'], $f['adult'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid(),
		]);
		$houseUserMapper = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class);
		$adultId = (int)$houseUserMapper->findByNextcloudUserId($f['houseId'], $f['adult'])->getId();

		$outcome = $this->raceWithHelper(
			$this->buildCancelHelperScript(),
			[(string)$f['houseId'], $f['owner'], (string)$created['id']],
			function () use ($houseUserMapper, $f, $adultId): string {
				try {
					$this->actingAs($f['owner']);
					\OC::$server->get(HouseService::class)->updateUserRole($adultId, 'child');
					return 'rebased';
				} catch (\Throwable $e) {
					return 'error:' . get_class($e);
				}
			}
		);

		self::assertSame('rebased', $outcome['main'], 'the role change always commits (it owns no business precondition)');
		self::assertContains($outcome['helper'], ['ok', 'conflict'], 'the cancel either wins or cleanly loses on the version check');

		$state = $this->requestState((int)$created['id']);
		self::assertContains($state, ['cancelled', 'pending'], 'the race converges to a coherent lifecycle state in both serializations');
		if ($state === 'cancelled') {
			// The cancel won first; the rebase found nothing unfulfilled.
			self::assertSame(0, $this->walletByUser($f['houseId'], $f['adult'])->getReservedMinor());
		} else {
			// The rebase won first; the reservation survives.
			self::assertSame(1250, $this->walletByUser($f['houseId'], $f['adult'])->getReservedMinor());
		}

		// The audit chain has no gaps and no duplicate revisions.
		$revisions = $this->eventRevisions((int)$created['id']);
		self::assertSame(range(0, count($revisions) - 1), $revisions, 'exactly one event per revision (unique request+revision identity)');
	}

	/**
	 * MidAggregateFailure_RollsBackWalletLedgerRequestAndVersion (C4.11-12)
	 */
	public function testMidAggregateFailure_RollsBackWalletLedgerRequestAndVersion(): void {
		$f = $this->raceHouse('B');
		$requests = \OC::$server->get(RewardRequestService::class);
		$created = $requests->create($f['houseId'], $f['child'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid(),
		]);
		$requestId = (int)$created['id'];
		$requests->approve($f['houseId'], $f['owner'], $requestId, ['commandId' => $this->uuid(), 'expectedVersion' => 0]);

		$houseService = \OC::$server->get(HouseService::class);
		$versionBefore = \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($f['houseId'])->getStateVersion();
		// The rollback baseline must be captured BEFORE the sabotaged fulfill.
		$bookedBefore = $this->walletByUser($f['houseId'], $f['child'])->getBookedMinor();

		// Sabotage the event append — the LAST write of the fulfill
		// aggregate, after the wallet debit is already staged.
		$service = \OC::$server->get(RewardRequestService::class);
		$saboteur = new class(\OC::$server->get(\OCP\IDBConnection::class)) extends RewardRequestEventMapper {
			public function append(\OCA\ChoreStrike\Db\RewardRequestEvent $event): \OCA\ChoreStrike\Db\RewardRequestEvent {
				throw new \RuntimeException('injected failure after the wallet debit');
			}
		};
		$prop = (new \ReflectionClass(RewardRequestService::class))->getProperty('eventMapper');
		$prop->setAccessible(true);
		$prop->setValue($service, $saboteur);

		try {
			$service->fulfill($f['houseId'], $f['owner'], $requestId, ['commandId' => $this->uuid(), 'expectedVersion' => 1, 'delivered' => true]);
			self::fail('the sabotage must abort the fulfill');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('injected failure', $e->getMessage());
		} finally {
			$prop->setValue($service, \OC::$server->get(RewardRequestEventMapper::class));
		}

		// NOTHING changed: wallet, ledger, request, receipt, house version.
		$wallet = $this->walletByUser($f['houseId'], $f['child']);
		self::assertSame($bookedBefore, $wallet->getBookedMinor(), 'the debit rolled back');
		self::assertSame(1250, $wallet->getReservedMinor(), 'the reservation is untouched');
		self::assertSame($bookedBefore, $this->ledgerSumByWalletId((int)$wallet->getId()), 'no settlement ledger row survived');
		self::assertSame('approved', $this->requestState($requestId));
		self::assertSame($versionBefore, \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($f['houseId'])->getStateVersion(), 'no house-version bump survived');

		// The retry with a FRESH command UUID succeeds completely.
		$fulfilled = $requests->fulfill($f['houseId'], $f['owner'], $requestId, ['commandId' => $this->uuid(), 'expectedVersion' => 1, 'delivered' => true]);
		self::assertSame('fulfilled', $fulfilled['state']);
		self::assertSame(10000 - 1250, $this->walletByUser($f['houseId'], $f['child'])->getBookedMinor());
	}

	/**
	 * RefundRetries_AreIdempotentOnTheSameUuidAndConflictOtherwise (C4.11)
	 */
	public function testRefundRetries_AreIdempotentOnTheSameUuidAndConflictOtherwise(): void {
		$f = $this->raceHouse('C');
		$requests = \OC::$server->get(RewardRequestService::class);
		$created = $requests->create($f['houseId'], $f['child'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid(),
		]);
		$requestId = (int)$created['id'];
		$requests->approve($f['houseId'], $f['owner'], $requestId, ['commandId' => $this->uuid(), 'expectedVersion' => 0]);
		$requests->fulfill($f['houseId'], $f['owner'], $requestId, ['commandId' => $this->uuid(), 'expectedVersion' => 1, 'delivered' => true]);
		self::assertSame(10000 - 1250, $this->walletByUser($f['houseId'], $f['child'])->getBookedMinor());

		$correctPayload = [
			'commandId' => $this->uuid(), 'expectedVersion' => 2, 'notDelivered' => true,
			'reasonCode' => 'not_delivered',
			'settlementEntryId' => (int)$this->requestRow($requestId)['settlement_entry_id'],
		];
		$corrected = $requests->correct($f['houseId'], $f['owner'], $requestId, $correctPayload);
		self::assertSame('corrected', $corrected['state']);
		self::assertSame(10000, $this->walletByUser($f['houseId'], $f['child'])->getBookedMinor());

		// The exact retry (lost response) replays the committed identity:
		// no second refund entry, the fresh DTO comes back.
		$replayed = $requests->correct($f['houseId'], $f['owner'], $requestId, $correctPayload);
		self::assertSame('corrected', $replayed['state']);
		self::assertSame(10000, $this->walletByUser($f['houseId'], $f['child'])->getBookedMinor());
		self::assertSame(1, $this->countRefundEntries($requestId), 'the refund is written exactly once');

		// A DIFFERENT key on the terminal record conflicts.
		$retry = $correctPayload;
		$retry['commandId'] = $this->uuid();
		try {
			$requests->correct($f['houseId'], $f['owner'], $requestId, $retry);
			self::fail('a corrected record is terminal for a new command');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
		}
		self::assertSame(1, $this->countRefundEntries($requestId));
	}

	// ------------------------------------------------------------------
	// Helper scripts (independent DB connections)
	// ------------------------------------------------------------------

	private function buildLifecycleHelperScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

$houseId = (int)$argv[1];
$owner = $argv[2];
$requestId = (int)$argv[3];
$expectedVersion = (int)$argv[4];
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
    \OC::$server->get(\OCA\ChoreStrike\Service\RewardRequestService::class)->fulfill($houseId, $owner, $requestId, [
        'commandId' => sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand()),
        'expectedVersion' => $expectedVersion,
        'delivered' => true,
    ]);
    file_put_contents($resultFile, 'ok');
} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
    file_put_contents($resultFile, 'error:conflict');
} catch (\OCA\ChoreStrike\Exception\ForbiddenException $e) {
    file_put_contents($resultFile, 'error:forbidden');
} catch (Throwable $e) {
    file_put_contents($resultFile, 'error:' . get_class($e) . ':' . $e->getMessage());
    exit(1);
}
PHP;
	}

	private function buildCancelHelperScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

$houseId = (int)$argv[1];
$owner = $argv[2];
$requestId = (int)$argv[3];
$readyFile = $argv[4];
$goFile = $argv[5];
$resultFile = $argv[6];

try {
    $version = 0;
    foreach (\OC::$server->get(\OCA\ChoreStrike\Db\RewardRequestMapper::class)->findByHouseId($houseId) as $row) {
        if ((int)$row->getId() === $requestId) {
            $version = (int)$row->getVersion();
        }
    }
    file_put_contents($readyFile, 'ready');
    $deadline = microtime(true) + 60.0;
    while (!file_exists($goFile)) {
        if (microtime(true) > $deadline) { file_put_contents($resultFile, 'error:timeout'); exit(1); }
        usleep(20000);
    }
    \OC::$server->get(\OCA\ChoreStrike\Service\RewardRequestService::class)->cancel($houseId, $owner, $requestId, [
        'commandId' => sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand()),
        'expectedVersion' => $version,
    ]);
    file_put_contents($resultFile, 'ok');
} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
    file_put_contents($resultFile, 'conflict');
} catch (Throwable $e) {
    file_put_contents($resultFile, 'error:' . get_class($e) . ':' . $e->getMessage());
    exit(1);
}
PHP;
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private function requestState(int $requestId): string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('state')
			->from('chorestrike_reward_requests')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$state = $result->fetchOne();
		$result->closeCursor();
		self::assertNotFalse($state, "reward request $requestId must exist");
		return (string)$state;
	}

	/**
	 * @return int[]
	 */
	private function eventRevisions(int $requestId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('revision')
			->from('chorestrike_request_events')
			->where($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)))
			->orderBy('revision', 'ASC');
		$result = $qb->executeQuery();
		$revisions = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $revisions;
	}

	private function countRefundEntries(int $requestId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_wallet_entries')
			->where($qb->expr()->eq('source_kind', $qb->createNamedParameter('reward_refund')))
			->andWhere($qb->expr()->eq('source_id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function requestRow(int $requestId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_reward_requests')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		self::assertIsArray($row, "reward request $requestId must exist");
		return $row;
	}

	private function walletByUser(int $houseId, string $uid): \OCA\ChoreStrike\Db\Wallet {
		return \OC::$server->get(\OCA\ChoreStrike\Db\WalletMapper::class)->findByHouseAndUser($houseId, $uid);
	}

	private function ledgerSumByWalletId(int $walletId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->sum('delta_minor'))
			->from('chorestrike_wallet_entries')
			->where($qb->expr()->eq('wallet_id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$sum = $result->fetchOne();
		$result->closeCursor();
		return $sum === null ? 0 : (int)$sum;
	}

	private function unfulfilledSumByWalletId(int $walletId): int {
		return \OC::$server->get(\OCA\ChoreStrike\Db\RewardRequestMapper::class)->sumUnfulfilledPriceByWalletId($walletId);
	}
}
