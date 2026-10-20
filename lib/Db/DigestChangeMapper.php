<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<DigestChange>
 */
class DigestChangeMapper extends QBMapper {
	/** Upper bound of ids per expiry statement. */
	private const EXPIRE_BATCH_LIMIT = 500;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_digest_changes', DigestChange::class);
	}

	/**
	 * Queue or coalesce one change (C5m): the unique
	 * (house_user_id, object_type, object_id, target_slot) identity makes
	 * a repeated write an UPDATE of the existing row (latest relevant
	 * state per object per slot) — retry/replay inside the same source
	 * action can never enqueue twice. Callers run inside their existing
	 * source transaction (the house lock is already held).
	 */
	public function queueOrCoalesce(DigestChange $change): DigestChange {
		$existing = $this->findByIdentity(
			$change->getHouseUserId(),
			$change->getObjectType(),
			$change->getObjectId(),
			$change->getTargetSlot()
		);
		if ($existing !== null) {
			$existing->setObjectRevision($change->getObjectRevision());
			$existing->setChangeTime($change->getChangeTime());
			$existing->setDisposition(DigestChange::DISPOSITION_QUEUED);
			$existing->setUpdatedAt($change->getUpdatedAt());
			return $this->update($existing);
		}
		return $this->insert($change);
	}

	/**
	 * The queued/consumed rows of one member's target slot (dispatch
	 * read); non-queued dispositions stay for diagnostics only.
	 *
	 * @return DigestChange[]
	 */
	public function findBySlot(int $houseUserId, string $targetSlot): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('target_slot', $qb->createNamedParameter($targetSlot)))
			->orderBy('change_time', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Mark the given rows consumed (delivered inside the summary).
	 *
	 * @param int[] $ids
	 */
	public function markConsumed(array $ids, \DateTimeInterface $now): void {
		if ($ids === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('disposition', $qb->createNamedParameter(DigestChange::DISPOSITION_CONSUMED))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}

	/**
	 * Expire queued rows of one member for one slot (a missed slot
	 * expires its queued rows permanently — C5m cutoff semantics; in-app
	 * state is unaffected).
	 */
	public function expireForSlot(int $houseUserId, string $targetSlot, \DateTimeInterface $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('disposition', $qb->createNamedParameter(DigestChange::DISPOSITION_EXPIRED))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('house_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('target_slot', $qb->createNamedParameter($targetSlot)))
			->andWhere($qb->expr()->eq('disposition', $qb->createNamedParameter(DigestChange::DISPOSITION_QUEUED)))
			->executeStatement();
	}

	/**
	 * Expire queued rows whose target slot's LOCAL DAY has fully elapsed
	 * everywhere (covers slots that were never materialized because the
	 * member was opted out): every queued row for a target_slot day
	 * before $dayKey is dead — a missed slot never catches up.
	 *
	 * Doctrine never emits LIMIT on an UPDATE, so the sweep runs in batches
	 * of at most $limit (capped at EXPIRE_BATCH_LIMIT) rows: a bounded,
	 * id-ordered SELECT, then an UPDATE ... WHERE id IN (...) that repeats
	 * the queued and cutoff predicates, so a row delivered or moved after
	 * the SELECT keeps its newer state. The id watermark is fixed when the
	 * sweep starts and the cursor only moves forward, which keeps the sweep
	 * finite under concurrent writers; rows queued meanwhile wait for the
	 * next sweep (TECH-DIGEST-001).
	 *
	 * @return int Rows moved to expired.
	 * @throws \InvalidArgumentException For a batch size below one.
	 */
	public function expireQueuedBefore(string $dayKey, \DateTimeInterface $now, int $limit = self::EXPIRE_BATCH_LIMIT): int {
		if ($limit < 1) {
			throw new \InvalidArgumentException('The expiry batch size must be positive');
		}
		$batchSize = min($limit, self::EXPIRE_BATCH_LIMIT);
		$watermark = $this->maxQueuedIdBefore($dayKey);
		$cursor = 0;
		$expired = 0;
		while ($watermark !== null && $cursor < $watermark) {
			$ids = $this->queuedIdsBefore($dayKey, $cursor, $watermark, $batchSize);
			if ($ids === []) {
				break;
			}
			$expired += $this->expireIds($ids, $dayKey, $now);
			$cursor = max($ids);
		}
		return $expired;
	}

	private function maxQueuedIdBefore(string $dayKey): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('id'))
			->from($this->getTableName())
			->where($qb->expr()->eq('disposition', $qb->createNamedParameter(DigestChange::DISPOSITION_QUEUED)))
			->andWhere($qb->expr()->lt('target_slot', $qb->createNamedParameter($dayKey)));
		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();
		return $max === false || $max === null ? null : (int)$max;
	}

	/**
	 * @return list<int> Ascending ids of queued rows before the cutoff in (cursor, watermark].
	 */
	private function queuedIdsBefore(string $dayKey, int $cursor, int $watermark, int $batchSize): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('disposition', $qb->createNamedParameter(DigestChange::DISPOSITION_QUEUED)))
			->andWhere($qb->expr()->lt('target_slot', $qb->createNamedParameter($dayKey)))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($cursor, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('id', $qb->createNamedParameter($watermark, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults($batchSize);
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return array_map(static fn (array $row): int => (int)$row['id'], $rows);
	}

	/**
	 * One batch UPDATE. The queued and cutoff conditions are checked again
	 * here, not only in the SELECT.
	 *
	 * @param list<int> $ids
	 * @return int Rows actually expired.
	 */
	protected function expireIds(array $ids, string $dayKey, \DateTimeInterface $now): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('disposition', $qb->createNamedParameter(DigestChange::DISPOSITION_EXPIRED))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('disposition', $qb->createNamedParameter(DigestChange::DISPOSITION_QUEUED)))
			->andWhere($qb->expr()->lt('target_slot', $qb->createNamedParameter($dayKey)));
		return $qb->executeStatement();
	}

	/**
	 * Delete the membership's queued history (departure / erasure — the
	 * ONLY deletion paths).
	 */
	public function deleteByHouseAndUser(int $houseId, int $houseUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('house_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Remove every queued/consumed row referencing the given objects of
	 * one type in a house (WP7 erasure: references to purged reward
	 * requests die for ALL recipients, not only the erased person's own
	 * queue).
	 *
	 * @param string[] $objectIds
	 */
	public function deleteByObjectIds(int $houseId, string $objectType, array $objectIds): void {
		if ($objectIds === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter($objectType, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->in('object_id', $qb->createNamedParameter($objectIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->executeStatement();
	}

	private function findByIdentity(int $houseUserId, string $objectType, string $objectId, string $targetSlot): ?DigestChange {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter($objectType)))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($objectId)))
			->andWhere($qb->expr()->eq('target_slot', $qb->createNamedParameter($targetSlot)))
			->setMaxResults(1);
		$entities = $this->findEntities($qb);
		return $entities[0] ?? null;
	}
}
