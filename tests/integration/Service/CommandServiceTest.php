<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Service\CommandService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * CS-PLAN-071 WP0 T0: command receipts (parent ??3.2/??3.5).
 *
 * One receipt per (house, actor, commandUuid): identical payload replays
 * with a replay marker (the aggregate is NOT re-executed), a changed
 * payload is a 409 conflict, and the receipt commits only together with
 * the domain facts ??? a failure in the aggregate leaves no receipt behind.
 *
 * No production endpoint adopts commandId in WP0 (decision D6); the
 * service is exercised directly. The house lock is taken FIRST by the
 * service itself ??? the aggregate must not re-acquire it.
 */
class CommandServiceTest extends IntegrationTestCase {
	/** @var int[] */
	private array $houseIds = [];

	protected function tearDown(): void {
		foreach ($this->houseIds as $houseId) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_commands')
				->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		parent::tearDown();
	}

	private function createTestHouse(string $owner, string $name): int {
		$houseId = $this->createHouse($owner, $name);
		$this->houseIds[] = $houseId;
		return $houseId;
	}

	private function service(): CommandService {
		return \OC::$server->get(CommandService::class);
	}

	private function findHouseUserId(int $houseId, string $ncUserId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($ncUserId)));
		$result = $qb->executeQuery();
		$id = (int)$result->fetchOne();
		$result->closeCursor();
		self::assertGreaterThan(0, $id, "house user row for {$ncUserId} must exist");
		return $id;
	}

	/**
	 * FirstRun_CommitsReceiptWithResultIdentity
	 */
	public function testFirstRun_CommitsReceiptWithResultIdentity(): void {
		$houseId = $this->createTestHouse('cmd_owner', 'CMD House');

		$run = $this->service()->runCommand(
			$houseId,
			$this->findHouseUserId($houseId, 'cmd_owner'),
			'33333333-3333-4333-8333-333333333333',
			'test.action',
			['taskId' => 5, 'mode' => 'normal'],
			static fn (): array => ['taskId' => 5, 'roomTaskId' => 7],
		);

		self::assertFalse($run['replay']);
		self::assertSame(['taskId' => 5, 'roomTaskId' => 7], $run['result']);
		self::assertSame('committed', $run['receipt']->getStatus());

		$row = $this->fetchReceipt($houseId, '33333333-3333-4333-8333-333333333333');
		self::assertNotNull($row, 'the receipt row must be committed');
		self::assertSame('test.action', $row['action']);
		self::assertSame(64, strlen((string)$row['payload_hash']), 'the payload hash is a SHA-256 hex digest');
		self::assertStringContainsString('"taskId"', (string)$row['result_refs'], 'the result identity is stored');
	}

	/**
	 * SameKeySamePayload_ReplaysWithoutReexecution
	 */
	public function testSameKeySamePayload_ReplaysWithoutReexecution(): void {
		$houseId = $this->createTestHouse('cmd_owner', 'CMD House');

		$executions = 0;
		$aggregate = function () use (&$executions): array {
			$executions++;
			return ['taskId' => 5, 'executions' => $executions];
		};
		$actorId = $this->findHouseUserId($houseId, 'cmd_owner');
		$runOnce = function () use ($houseId, $actorId, &$aggregate): array {
			return $this->service()->runCommand(
				$houseId,
				$actorId,
				'44444444-4444-4444-8444-444444444444',
				'test.action',
				['taskId' => 5],
				$aggregate,
			);
		};

		$first = $runOnce();
		$second = $runOnce();

		self::assertFalse($first['replay']);
		self::assertTrue($second['replay'], 'an identical retry must be served from the receipt');
		self::assertSame(1, $executions, 'the aggregate must NOT re-execute on replay');
		self::assertSame($first['result'], $second['result'], 'the replay returns the ORIGINAL result identity');
		self::assertSame($first['receipt']->getId(), $second['receipt']->getId());
	}

	/**
	 * SameKeyDifferentPayload_IsAConflict409
	 */
	public function testSameKeyDifferentPayload_IsAConflict(): void {
		$houseId = $this->createTestHouse('cmd_owner', 'CMD House');
		$actorId = $this->findHouseUserId($houseId, 'cmd_owner');

		$this->service()->runCommand(
			$houseId,
			$actorId,
			'55555555-5555-4555-8555-555555555555',
			'test.action',
			['taskId' => 5],
			static fn (): array => ['taskId' => 5],
		);

		try {
			$this->service()->runCommand(
				$houseId,
				$actorId,
				'55555555-5555-4555-8555-555555555555',
				'test.action',
				['taskId' => 6],
				static fn (): array => ['taskId' => 6],
			);
			self::fail('a reused key with a changed payload must be a 409 conflict');
		} catch (ConflictException $e) {
			self::assertStringContainsString('different input', $e->getMessage());
		}

		// Exactly one receipt exists.
		$rows = $this->fetchReceipts($houseId);
		self::assertCount(1, $rows);
	}

	/**
	 * FailureAfterFirstFact_RollsBackReceiptAndDomainFacts
	 *
	 * The receipt commits only together with the aggregate: a failing
	 * aggregate leaves NO receipt, so a corrected retry is a fresh
	 * first execution (never a phantom replay).
	 */
	public function testFailureAfterFirstFact_RollsBackReceiptAndDomainFacts(): void {
		$houseId = $this->createTestHouse('cmd_owner', 'CMD House');
		$actorId = $this->findHouseUserId($houseId, 'cmd_owner');
		$sideEffectRows = 0;

		try {
			$this->service()->runCommand(
				$houseId,
				$actorId,
				'66666666-6666-4666-8666-666666666666',
				'test.action',
				['taskId' => 5],
				function () use (&$sideEffectRows): array {
					// A domain fact committed inside the same transaction???
					$qb = $this->db->getQueryBuilder();
					$qb->insert('chorestrike_wallets')
						->values([
							'house_id' => $qb->createNamedParameter(999999, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
							'nextcloud_user_id' => $qb->createNamedParameter('cmd_probe'),
							'booked_minor' => $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
							'reserved_minor' => $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
							'version' => $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
							'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
							'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
						])
						->executeStatement();
					$sideEffectRows++;
					// ???then the aggregate fails.
					throw new \RuntimeException('aggregate failed');
				},
			);
			self::fail('the failing aggregate must propagate');
		} catch (\RuntimeException $e) {
			self::assertSame('aggregate failed', $e->getMessage());
		}

		self::assertSame(1, $sideEffectRows, 'sanity: the aggregate ran');

		// Nothing survived: no receipt, no wallet fact.
		self::assertNull(
			$this->fetchReceipt($houseId, '66666666-6666-4666-8666-666666666666'),
			'a failing aggregate must leave no receipt behind'
		);
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_wallets')
			->where($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter('cmd_probe')));
		$result = $qb->executeQuery();
		$walletCount = (int)$result->fetchOne();
		$result->closeCursor();
		self::assertSame(0, $walletCount, 'the domain fact rolled back with the receipt');
	}

	/**
	 * ReplayReadsAuthorizedProjectionsFresh
	 *
	 * A replay marker never overwrites newer client state with stale
	 * totals: the stored result identity is the ORIGINAL run's result, and
	 * the caller re-reads current projections itself (demonstrated by
	 * mutating the house state between runs and observing the untouched
	 * stored identity).
	 */
	public function testReplayReturnsOriginalIdentityWhileStateMayDrift(): void {
		$houseId = $this->createTestHouse('cmd_owner', 'CMD House');
		$actorId = $this->findHouseUserId($houseId, 'cmd_owner');

		$first = $this->service()->runCommand(
			$houseId,
			$actorId,
			'77777777-7777-4777-8777-777777777777',
			'test.action',
			['taskId' => 5],
			static fn (): array => ['taskId' => 5, 'stateVersionSeen' => 0],
		);

		// State drifts between the original run and the replay.
		$houseMapper = \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class);
		$houseMapper->bumpStateVersion($houseId);

		$second = $this->service()->runCommand(
			$houseId,
			$actorId,
			'77777777-7777-4777-8777-777777777777',
			'test.action',
			['taskId' => 5],
			static fn (): array => ['taskId' => 5, 'stateVersionSeen' => 1],
		);

		self::assertTrue($second['replay']);
		self::assertSame(0, $second['result']['stateVersionSeen'], 'the replay returns the ORIGINAL identity');
		self::assertSame(1, (int)$this->fetchHouseStateVersion($houseId), 'the drifted state is untouched by the replay');
	}

	/**
	 * OversizedCommandUuid_IsRejectedAsInputError
	 *
	 * A command key that cannot fit the schema must be a 400 input
	 * error, never a database failure misreported as a 409 conflict
	 * (review finding: catch-all 409 mapping).
	 */
	public function testOversizedCommandUuid_IsRejectedAsInputError(): void {
		$houseId = $this->createTestHouse('cmd_owner', 'CMD House');

		try {
			$this->service()->runCommand(
				$houseId,
				$this->findHouseUserId($houseId, 'cmd_owner'),
				str_repeat('a', 37),
				'test.action',
				['taskId' => 5],
				static fn (): array => ['taskId' => 5],
			);
			self::fail('an oversized commandUuid must be rejected as input');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('commandUuid', $e->getMessage());
		} catch (ConflictException $e) {
			self::fail('an input error must not surface as a 409 conflict');
		}
	}

	/**
	 * OversizedAction_IsRejectedAsInputError
	 */
	public function testOversizedAction_IsRejectedAsInputError(): void {
		$houseId = $this->createTestHouse('cmd_owner', 'CMD House');

		try {
			$this->service()->runCommand(
				$houseId,
				$this->findHouseUserId($houseId, 'cmd_owner'),
				'88888888-8888-4888-8888-888888888888',
				str_repeat('a', 65),
				['taskId' => 5],
				static fn (): array => ['taskId' => 5],
			);
			self::fail('an oversized action must be rejected as input');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('action', $e->getMessage());
		} catch (ConflictException $e) {
			self::fail('an input error must not surface as a 409 conflict');
		}
	}

	/**
	 * UnencodablePayload_IsRejected_NeverHashCollided
	 *
	 * Two different unencodable payloads must never collapse onto the
	 * shared sha256('') hash and replay each other's result identity
	 * (review finding: silent json_encode failure).
	 */
	public function testUnencodablePayload_IsRejectedNeverHashCollided(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('not JSON-serializable');

		$this->service()->hashPayload(['value' => \NAN]);
	}

	/**
	 * WP7GuardRunsOnExecutionAndReplay_BeforeTheReceiptAnswers (§3.4)
	 *
	 * The optional under-lock authorization seam runs after the house
	 * lock and BEFORE the receipt lookup on BOTH paths: a demoted or
	 * departed actor can never replay an old receipt into an effect.
	 */
	public function testWp7GuardRunsOnExecutionAndReplay_BeforeTheReceiptAnswers(): void {
		$houseId = $this->createTestHouse('cmd_owner', 'CMD Guard House');
		$actorId = $this->findHouseUserId($houseId, 'cmd_owner');
		$guardCalls = 0;

		$run = static fn (): array => ['taskId' => 5];
		$guard = function (int $guardedHouseId, int $guardedActorId) use (&$guardCalls, $houseId, $actorId): void {
			$guardCalls++;
			self::assertSame($houseId, $guardedHouseId);
			self::assertSame($actorId, $guardedActorId);
		};

		$first = $this->service()->runCommand($houseId, $actorId, '99999999-9999-4999-8999-999999999999', 'test.action', ['taskId' => 5], $run, $guard);
		self::assertFalse($first['replay']);
		self::assertSame(1, $guardCalls, 'the guard runs on first execution');

		$second = $this->service()->runCommand($houseId, $actorId, '99999999-9999-4999-8999-999999999999', 'test.action', ['taskId' => 5], $run, $guard);
		self::assertTrue($second['replay'], 'the receipt replays');
		self::assertSame(2, $guardCalls, 'the guard runs on replay too, before the receipt answers');
	}

	/**
	 * Wp7GuardDenialOnReplay_NeverAnswersWithTheStoredIdentity
	 */
	public function testWp7GuardDenialOnReplay_NeverAnswersWithTheStoredIdentity(): void {
		$houseId = $this->createTestHouse('cmd_owner', 'CMD Guard Deny House');
		$actorId = $this->findHouseUserId($houseId, 'cmd_owner');

		$guard = static function (int $houseId, int $actorHouseUserId): void {
			throw new \OCA\ChoreStrike\Exception\ForbiddenException('Not a member of this house');
		};

		$this->service()->runCommand($houseId, $actorId, 'aaaa9999-9999-4999-8999-999999999999', 'test.action', ['taskId' => 5], static fn (): array => ['taskId' => 5]);
		self::assertNotNull($this->fetchReceipt($houseId, 'aaaa9999-9999-4999-8999-999999999999'));

		// The demoted actor's replay dies on the guard — even though the
		// aggregate would be skipped.
		try {
			$this->service()->runCommand($houseId, $actorId, 'aaaa9999-9999-4999-8999-999999999999', 'test.action', ['taskId' => 5], static fn (): array => ['taskId' => 5], $guard);
			self::fail('a guard denial must deny the replay');
		} catch (\OCA\ChoreStrike\Exception\ForbiddenException $e) {
		}
	}

	private function fetchReceipt(int $houseId, string $uuid): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_commands')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('command_uuid', $qb->createNamedParameter($uuid)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchReceipts(int $houseId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_commands')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	private function fetchHouseStateVersion(int $houseId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('state_version')
			->from('chorestrike_houses')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($houseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$value = (int)$result->fetchOne();
		$result->closeCursor();
		return $value;
	}
}
