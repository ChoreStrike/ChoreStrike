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
	 */
	public function expireQueuedBefore(string $dayKey, \DateTimeInterface $now, int $limit = 500): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('disposition', $qb->createNamedParameter(DigestChange::DISPOSITION_EXPIRED))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('disposition', $qb->createNamedParameter(DigestChange::DISPOSITION_QUEUED)))
			->andWhere($qb->expr()->lt('target_slot', $qb->createNamedParameter($dayKey)))
			->setMaxResults($limit)
			->executeStatement();
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
