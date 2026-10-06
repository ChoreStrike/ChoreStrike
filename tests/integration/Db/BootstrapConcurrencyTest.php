<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\QueryBarrier;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Integration tests for the bootstrap races (CR-053-07, CS-PLAN-060 WP1 §3.2).
 *
 * The races are reproduced deterministically with two real connections (the
 * `proc_open` helper pattern from ConcurrentCompletionTest): the helper
 * process plays the concurrent winner (holds an uncommitted insert / the
 * house-row lock), the test process plays the loser whose insert or lock
 * blocks until the winner commits.
 *
 * MariaDB/PostgreSQL row locks and SQLite write serialization use the same
 * real installed database, with a query-attempt barrier instead of a delay.
 */
class BootstrapConcurrencyTest extends IntegrationTestCase {
	/** @var int[] */
	private array $raceHouseIds = [];

	protected function tearDown(): void {
		if (!empty($this->raceHouseIds)) {
			// Memberships first (FK-free schema), then the raced houses.
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_house_users')
				->where($qb->expr()->in('house_id', $qb->createNamedParameter($this->raceHouseIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();

			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_houses')
				->where($qb->expr()->in('id', $qb->createNamedParameter($this->raceHouseIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}

		parent::tearDown();
	}

	/**
	 * Two concurrent first requests for the same instance must produce
	 * exactly ONE house: the loser's duplicate insert hits the unique index
	 * and re-fetches the winner's committed row (first insert wins).
	 */
	public function testConcurrentCreateForInstance_ExactlyOneHouse(): void {
		$instanceId = 'race-inst-' . uniqid();

		// T1 (helper): insert the house inside an uncommitted transaction,
		// signal, hold briefly, then commit.
		$signalFile = sys_get_temp_dir() . '/cs-house-race-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-house-race-holder-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildHouseInsertHolderScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, $instanceId, $signalFile],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the house-insert holder process');
		$barrier = QueryBarrier::attach($this->db, $signalFile, '/^INSERT INTO .*chorestrike_houses/i');

		try {
			$deadline = microtime(true) + 30.0;
			while (!file_exists($signalFile)) {
				if (microtime(true) > $deadline) {
					$stderr = is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '(closed)';
					$stdout = is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '(closed)';
					self::fail("The house-insert holder did not acquire its insert in time. stderr: $stderr stdout: $stdout");
				}
				usleep(50000);
			}

			// T2 (this process): while T1's insert is uncommitted, our insert
			// blocks on the unique index, T1 commits, we hit the unique
			// violation and must re-fetch T1's house.
			$houseMapper = \OC::$server->get(HouseMapper::class);
			$house = $houseMapper->createForInstance($instanceId, 'Loser House', 'UTC');

			$winnerHouseId = (int)QueryBarrier::finish($process, $pipes);
			self::assertSame($winnerHouseId, $house->getId(), 'the race loser must receive the winner\'s house');
			self::assertSame('Winner House', $house->getName(), 'the loser re-fetched the winner\'s row');
			self::assertTrue($barrier->wasReached());
		} finally {
			$barrier->detach();
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

		$this->raceHouseIds[] = $winnerHouseId ?? 0;

		// Exactly one house for the raced instance id.
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_houses')
			->where($qb->expr()->eq('nextcloud_instance_id', $qb->createNamedParameter($instanceId)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		self::assertSame(1, $count, 'exactly one house may exist for the instance');
	}

	/**
	 * Two distinct users first-touching concurrently: exactly one owner, the
	 * loser is DENIED (invite-only, ADR-010 / CS-PLAN-061 D1) and no second
	 * membership row is created. T1 (helper) wins the house-row lock and
	 * commits the owner membership; T2 (this process) calls ensureHouseMember,
	 * which must block on the house lock, count T1's committed membership, and
	 * throw ForbiddenException instead of auto-joining.
	 */
	public function testConcurrentEnsureHouseMember_ExactlyOneOwner_LoserDenied(): void {
		$houseId = $this->createBareHouse('Owner Race House');
		$this->raceHouseIds[] = $houseId;
		$this->actingAs('race-member-b');

		// T1: lock the house row, insert user-a as owner (uncommitted), hold.
		$signalFile = sys_get_temp_dir() . '/cs-owner-race-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-owner-race-holder-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildOwnerInsertHolderScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, (string)$houseId, $signalFile, 'race-owner-a'],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the owner-insert holder process');
		$barrier = QueryBarrier::attach($this->db, $signalFile, '/^(?:SELECT .*chorestrike_houses.*FOR UPDATE|UPDATE .*chorestrike_houses)/i');

		try {
			$deadline = microtime(true) + 30.0;
			while (!file_exists($signalFile)) {
				if (microtime(true) > $deadline) {
					self::fail('The owner-insert holder did not acquire the house lock in time');
				}
				usleep(50000);
			}

			// T2: ensureHouseMember blocks on the house lock, then sees T1's
			// committed owner membership and must be denied (invite-only):
			// no membership row, ForbiddenException.
			$authService = \OC::$server->get(AuthorizationService::class);

			try {
				$authService->ensureHouseMember($houseId);
				self::fail('the concurrent non-owner must be denied');
			} catch (\OCA\ChoreStrike\Exception\ForbiddenException $e) {
				self::assertTrue($barrier->wasReached());
			}
			QueryBarrier::finish($process, $pipes);
		} finally {
			$barrier->detach();
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
	}

	/**
	 * Exactly one owner in the house after the concurrent first-touch race.
	 *
	 * Split from the denial assertion above: expectException() aborts the
	 * test method at the throw, so the post-race invariants are verified in
	 * their own test on the same fixture pattern.
	 */
	public function testConcurrentEnsureHouseMember_ExactlyOneMembershipAfterRace(): void {
		$houseId = $this->createBareHouse('Owner Race House 2');
		$this->raceHouseIds[] = $houseId;
		$this->actingAs('race-member-b2');

		$signalFile = sys_get_temp_dir() . '/cs-owner-race-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-owner-race-holder-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildOwnerInsertHolderScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, (string)$houseId, $signalFile, 'race-owner-a2'],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the owner-insert holder process');
		$barrier = QueryBarrier::attach($this->db, $signalFile, '/^(?:SELECT .*chorestrike_houses.*FOR UPDATE|UPDATE .*chorestrike_houses)/i');

		try {
			$deadline = microtime(true) + 30.0;
			while (!file_exists($signalFile)) {
				if (microtime(true) > $deadline) {
					self::fail('The owner-insert holder did not acquire the house lock in time');
				}
				usleep(50000);
			}

			$authService = \OC::$server->get(AuthorizationService::class);
			try {
				$authService->ensureHouseMember($houseId);
				self::fail('the second concurrent first-toucher must be denied (invite-only)');
			} catch (\OCA\ChoreStrike\Exception\ForbiddenException $e) {
				// Expected: invite-only after the first owner.
			}
			self::assertTrue($barrier->wasReached());
			QueryBarrier::finish($process, $pipes);
		} finally {
			$barrier->detach();
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

		// Exactly one owner in the house — the loser's denial left no row.
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('role', $qb->createNamedParameter('owner')));
		$result = $qb->executeQuery();
		$ownerCount = (int)$result->fetchOne();
		$result->closeCursor();
		self::assertSame(1, $ownerCount, 'exactly one owner may exist after the concurrent first-touch');

		// Total memberships: T1's owner only — the loser was not auto-joined.
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$total = (int)$result->fetchOne();
		$result->closeCursor();
		self::assertSame(1, $total);
	}

	/**
	 * The createOrGet race loser receives the winner's existing row (no 500,
	 * no duplicate) — the unique-constraint violation is handled.
	 */
	public function testCreateOrGet_RaceLoserReceivesExistingRow(): void {
		$houseId = $this->createBareHouse('CreateOrGet Race House');
		$this->raceHouseIds[] = $houseId;

		// T1: insert the membership row uncommitted, hold, commit.
		$signalFile = sys_get_temp_dir() . '/.cs-cog-race-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-cog-race-holder-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildMembershipInsertHolderScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, (string)$houseId, $signalFile, 'race-cog-user'],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the membership-insert holder process');
		$barrier = QueryBarrier::attach($this->db, $signalFile, '/^INSERT INTO .*chorestrike_house_users/i');

		try {
			$deadline = microtime(true) + 30.0;
			while (!file_exists($signalFile)) {
				if (microtime(true) > $deadline) {
					self::fail('The membership-insert holder did not acquire its insert in time');
				}
				usleep(50000);
			}

			// T2: the insert blocks on uniq_house_user, T1 commits, the
			// unique violation must be handled by re-fetching.
			$mapper = \OC::$server->get(HouseUserMapper::class);
			$houseUser = $mapper->createOrGet($houseId, 'race-cog-user', 'Race COG', 'member');

			self::assertSame('owner', $houseUser->getRole(), 'the loser receives the winner\'s row (winner inserted role owner)');
			self::assertTrue($barrier->wasReached());
			QueryBarrier::finish($process, $pipes);
		} finally {
			$barrier->detach();
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

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter('race-cog-user')));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		self::assertSame(1, $count, 'no duplicate membership row may exist');
	}

	/**
	 * The T1 helper: boots Nextcloud, opens a transaction, inserts the house
	 * for the instance id in argv (uncommitted), signals the parent, holds
	 * briefly, then commits.
	 */
	private function buildHouseInsertHolderScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';
require '/var/www/html/custom_apps/chorestrike/tests/integration/Support/QueryBarrier.php';

$instanceId = $argv[1];
$signalFile = $argv[2];

$db = \OC::$server->get(\OCP\IDBConnection::class);
$mapper = \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class);

$db->beginTransaction();
try {
    $house = $mapper->createForInstance($instanceId, 'Winner House', 'UTC');
    \OCA\ChoreStrike\Tests\Integration\Support\QueryBarrier::hold($signalFile);
    $db->commit();
    echo $house->getId();
} catch (Throwable $e) {
    $db->rollBack();
    file_put_contents($signalFile, 'locked');
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
PHP;
	}

	/**
	 * The T1 helper: boots Nextcloud, opens a transaction, locks the house
	 * row, inserts the owner membership (uncommitted), signals the parent,
	 * holds briefly, then commits.
	 */
	private function buildOwnerInsertHolderScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';
require '/var/www/html/custom_apps/chorestrike/tests/integration/Support/QueryBarrier.php';

$houseId = (int)$argv[1];
$signalFile = $argv[2];
$userId = $argv[3];

$db = \OC::$server->get(\OCP\IDBConnection::class);

$db->beginTransaction();
\OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->findForUpdate($houseId);

$qb = $db->getQueryBuilder();
$qb->insert('chorestrike_house_users')
    ->values([
        'house_id' => $qb->createNamedParameter($houseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        'nextcloud_user_id' => $qb->createNamedParameter($userId),
        'display_name' => $qb->createNamedParameter('Race Owner A'),
        'role' => $qb->createNamedParameter('owner'),
        'contribution_minor' => $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        'current_streak' => $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        'longest_streak' => $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        'joined_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
    ]);
$qb->executeStatement();

\OCA\ChoreStrike\Tests\Integration\Support\QueryBarrier::hold($signalFile);
$db->commit();
PHP;
	}

	/**
	 * The T1 helper: boots Nextcloud, opens a transaction, inserts the
	 * membership row (uncommitted), signals the parent, holds briefly, then
	 * commits.
	 */
	private function buildMembershipInsertHolderScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';
require '/var/www/html/custom_apps/chorestrike/tests/integration/Support/QueryBarrier.php';

$houseId = (int)$argv[1];
$signalFile = $argv[2];
$userId = $argv[3];

$db = \OC::$server->get(\OCP\IDBConnection::class);

$db->beginTransaction();
$qb = $db->getQueryBuilder();
$qb->insert('chorestrike_house_users')
    ->values([
        'house_id' => $qb->createNamedParameter($houseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        'nextcloud_user_id' => $qb->createNamedParameter($userId),
        'display_name' => $qb->createNamedParameter('Race COG Winner'),
        'role' => $qb->createNamedParameter('owner'),
        'contribution_minor' => $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        'current_streak' => $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        'longest_streak' => $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        'joined_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
    ]);
$qb->executeStatement();

\OCA\ChoreStrike\Tests\Integration\Support\QueryBarrier::hold($signalFile);
$db->commit();
PHP;
	}

}
