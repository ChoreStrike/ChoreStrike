<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\DigestChange;
use OCA\ChoreStrike\Db\DigestChangeMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * TECH-DIGEST-001 (CS-PLAN-088 WP-B): `expireQueuedBefore()` expires in
 * bounded batches. Doctrine never emits LIMIT on UPDATE, so each batch is a
 * bounded ordered SELECT of ids followed by an UPDATE ... WHERE id IN (...)
 * that re-applies the queued/cutoff predicates. A fixed upper-id watermark
 * and a forward-only id cursor make the sweep finite under concurrent
 * writers. The interleavings below are deterministic (a hook runs between
 * a batch's SELECT and its UPDATE), never timing-based.
 */
class DigestChangeMapperTest extends IntegrationTestCase {
	private const OLD_DAY = '2026-09-20';
	private const CUTOFF = '2026-09-25';
	private const LATER_DAY = '2026-09-26';

	private int $houseId;
	private int $memberId;
	private DigestChangeMapper $mapper;
	private int $objectSeq = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->houseId = $this->createHouse('dcm-lead');
		$this->memberId = (int)\OC::$server->get(HouseUserMapper::class)
			->findByNextcloudUserId($this->houseId, 'dcm-lead')->getId();
		$this->mapper = \OC::$server->get(DigestChangeMapper::class);
	}

	private function now(): \DateTime {
		return new \DateTime('2026-09-25 03:00:00 UTC');
	}

	private function seed(string $slot, string $disposition = DigestChange::DISPOSITION_QUEUED): int {
		$change = new DigestChange();
		$change->setHouseId($this->houseId);
		$change->setHouseUserId($this->memberId);
		$change->setObjectType('agreement');
		$change->setObjectId((string)(++$this->objectSeq));
		$change->setCategory(DigestChange::CATEGORY_ORG);
		$change->setChangeTime(new \DateTime($slot . ' 10:00:00'));
		$change->setTargetSlot($slot);
		$change->setDisposition($disposition);
		$change->setCreatedAt(new \DateTime($slot . ' 10:00:00 UTC'));
		$change->setUpdatedAt(new \DateTime($slot . ' 10:00:00 UTC'));
		return (int)$this->mapper->insert($change)->getId();
	}

	/** @return list<int> */
	private function seedMany(int $count, string $slot = self::OLD_DAY): array {
		$ids = [];
		for ($i = 0; $i < $count; $i++) {
			$ids[] = $this->seed($slot);
		}
		return $ids;
	}

	private function disposition(int $id): string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('disposition')->from('chorestrike_digest_changes')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();
		return (string)$value;
	}

	private function setDisposition(int $id, string $disposition): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_digest_changes')
			->set('disposition', $qb->createNamedParameter($disposition))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private function setSlot(int $id, string $slot): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_digest_changes')
			->set('target_slot', $qb->createNamedParameter($slot))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * A mapper that records every UPDATE batch and runs $beforeBatch(n, ids)
	 * between the batch's SELECT and its UPDATE.
	 */
	private function recordingMapper(?callable $beforeBatch = null): DigestChangeMapper {
		return new class(\OC::$server->get(IDBConnection::class), $beforeBatch) extends DigestChangeMapper {
			/** @var list<list<int>> */
			public array $batches = [];

			/** @var callable|null */
			private $beforeBatch;

			public function __construct(IDBConnection $db, ?callable $beforeBatch) {
				parent::__construct($db);
				$this->beforeBatch = $beforeBatch;
			}

			protected function expireIds(array $ids, string $dayKey, \DateTimeInterface $now): int {
				$this->batches[] = $ids;
				if ($this->beforeBatch !== null) {
					($this->beforeBatch)(count($this->batches), $ids);
				}
				return parent::expireIds($ids, $dayKey, $now);
			}
		};
	}

	public function testExpireQueuedBefore_MoreRowsThanOneBatch_ExpiresAllWithBoundedStatements(): void {
		$ids = $this->seedMany(7);
		$mapper = $this->recordingMapper();

		$expired = $mapper->expireQueuedBefore(self::CUTOFF, $this->now(), 3);

		self::assertSame(7, $expired);
		self::assertSame([3, 3, 1], array_map('count', $mapper->batches));
		foreach ($ids as $id) {
			self::assertSame(DigestChange::DISPOSITION_EXPIRED, $this->disposition($id));
		}
	}

	public function testExpireQueuedBefore_LimitAboveTheCap_StillBatchesAtFiveHundred(): void {
		$ids = $this->seedMany(501);
		$mapper = $this->recordingMapper();

		$expired = $mapper->expireQueuedBefore(self::CUTOFF, $this->now(), 5000);

		self::assertSame(501, $expired);
		self::assertSame([500, 1], array_map('count', $mapper->batches));
		self::assertSame(DigestChange::DISPOSITION_EXPIRED, $this->disposition($ids[0]));
		self::assertSame(DigestChange::DISPOSITION_EXPIRED, $this->disposition($ids[500]));
	}

	public function testExpireQueuedBefore_NothingQueued_IssuesNoUpdate(): void {
		$mapper = $this->recordingMapper();

		self::assertSame(0, $mapper->expireQueuedBefore(self::CUTOFF, $this->now(), 3));
		self::assertSame([], $mapper->batches);
	}

	public function testExpireQueuedBefore_ExactCutoffDayAndOtherDispositions_AreUntouched(): void {
		$old = $this->seed(self::OLD_DAY);
		$onCutoff = $this->seed(self::CUTOFF);
		$later = $this->seed(self::LATER_DAY);
		$consumed = $this->seed(self::OLD_DAY, DigestChange::DISPOSITION_CONSUMED);
		$alreadyExpired = $this->seed(self::OLD_DAY, DigestChange::DISPOSITION_EXPIRED);

		$expired = $this->mapper->expireQueuedBefore(self::CUTOFF, $this->now(), 2);

		self::assertSame(1, $expired);
		self::assertSame(DigestChange::DISPOSITION_EXPIRED, $this->disposition($old));
		self::assertSame(DigestChange::DISPOSITION_QUEUED, $this->disposition($onCutoff), 'the cutoff day itself is still live');
		self::assertSame(DigestChange::DISPOSITION_QUEUED, $this->disposition($later));
		self::assertSame(DigestChange::DISPOSITION_CONSUMED, $this->disposition($consumed));
		self::assertSame(DigestChange::DISPOSITION_EXPIRED, $this->disposition($alreadyExpired));
	}

	/**
	 * @dataProvider invalidLimits
	 */
	public function testExpireQueuedBefore_NonPositiveLimit_RejectedWithoutWrites(int $limit): void {
		$id = $this->seed(self::OLD_DAY);

		try {
			$this->mapper->expireQueuedBefore(self::CUTOFF, $this->now(), $limit);
			self::fail('a non-positive batch size must be rejected');
		} catch (\InvalidArgumentException $e) {
			self::assertSame(DigestChange::DISPOSITION_QUEUED, $this->disposition($id));
		}
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function invalidLimits(): array {
		return ['zero' => [0], 'negative' => [-5]];
	}

	public function testExpireQueuedBefore_RowConsumedOrMovedAfterSelection_IsNotOverwritten(): void {
		[$a, $b, $c, $d] = $this->seedMany(4);
		$mapper = $this->recordingMapper(function (int $batch) use ($b, $c): void {
			if ($batch === 1) {
				// Between the SELECT and the UPDATE: one row is delivered,
				// another is moved onto the live cutoff day.
				$this->setDisposition($b, DigestChange::DISPOSITION_CONSUMED);
				$this->setSlot($c, self::CUTOFF);
			}
		});

		$expired = $mapper->expireQueuedBefore(self::CUTOFF, $this->now(), 10);

		self::assertSame(2, $expired, 'only the rows still queued and past the cutoff are written');
		self::assertSame(DigestChange::DISPOSITION_EXPIRED, $this->disposition($a));
		self::assertSame(DigestChange::DISPOSITION_CONSUMED, $this->disposition($b), 'a delivered row keeps its newer state');
		self::assertSame(DigestChange::DISPOSITION_QUEUED, $this->disposition($c), 'a moved row is not expired');
		self::assertSame(DigestChange::DISPOSITION_EXPIRED, $this->disposition($d));
	}

	public function testExpireQueuedBefore_RowQueuedAfterTheSweepStarted_WaitsForTheNextSweep(): void {
		$first = $this->seedMany(2);
		$late = null;
		$mapper = $this->recordingMapper(function (int $batch) use (&$late): void {
			if ($batch === 1 && $late === null) {
				$late = $this->seed(self::OLD_DAY);
			}
		});

		self::assertSame(2, $mapper->expireQueuedBefore(self::CUTOFF, $this->now(), 1));

		foreach ($first as $id) {
			self::assertSame(DigestChange::DISPOSITION_EXPIRED, $this->disposition($id));
		}
		self::assertNotNull($late);
		self::assertSame(DigestChange::DISPOSITION_QUEUED, $this->disposition($late), 'beyond the watermark of this sweep');
		self::assertSame(1, $this->mapper->expireQueuedBefore(self::CUTOFF, $this->now(), 1), 'the next sweep picks it up');
		self::assertSame(DigestChange::DISPOSITION_EXPIRED, $this->disposition($late));
	}

	public function testExpireQueuedBefore_RowRequeuedBehindTheCursor_DoesNotLoopTheSweep(): void {
		$ids = $this->seedMany(3);
		$mapper = $this->recordingMapper(function (int $batch) use ($ids): void {
			if ($batch === 2) {
				// The first row was expired by batch 1; a writer requeues it
				// before batch 2. The cursor only moves forward, so this
				// sweep must not visit it again.
				$this->setDisposition($ids[0], DigestChange::DISPOSITION_QUEUED);
			}
		});

		$expired = $mapper->expireQueuedBefore(self::CUTOFF, $this->now(), 1);

		self::assertSame(3, $expired);
		self::assertCount(3, $mapper->batches, 'one batch per row, no revisit');
		self::assertSame(DigestChange::DISPOSITION_QUEUED, $this->disposition($ids[0]));
		self::assertSame(DigestChange::DISPOSITION_EXPIRED, $this->disposition($ids[1]));
		self::assertSame(DigestChange::DISPOSITION_EXPIRED, $this->disposition($ids[2]));
	}
}
