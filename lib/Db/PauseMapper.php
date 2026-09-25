<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Pause>
 */
class PauseMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_pauses', Pause::class);
	}

	public function find(int $id): Pause {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * All revisions of one pause group, ascending.
	 *
	 * @return Pause[]
	 */
	public function findByGroup(int $pauseGroupId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('pause_group_id', $qb->createNamedParameter($pauseGroupId, IQueryBuilder::PARAM_INT)))
			->orderBy('revision', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * The latest revision of one pause group.
	 *
	 * @throws DoesNotExistException
	 */
	public function findCurrentByGroup(int $pauseGroupId): Pause {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('pause_group_id', $qb->createNamedParameter($pauseGroupId, IQueryBuilder::PARAM_INT)))
			->orderBy('revision', 'DESC')
			->setMaxResults(1);
		return $this->findEntity($qb);
	}

	/**
	 * Every revision of the house's pause groups, ordered for deterministic
	 * projection (group, revision). The lower evaluator filters states.
	 *
	 * @return Pause[]
	 */
	public function findByHouseId(int $houseId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->orderBy('pause_group_id', 'ASC')
			->addOrderBy('revision', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * The next free revision number of a pause group (max + 1, 1 when
	 * empty). Callers hold the house lock.
	 */
	public function nextGroupRevision(int $pauseGroupId): int {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select($qb->func()->max('revision'))
			->from($this->getTableName())
			->where($qb->expr()->eq('pause_group_id', $qb->createNamedParameter($pauseGroupId, IQueryBuilder::PARAM_INT)))
			->executeQuery();
		$max = (int)$result->fetchOne();
		return $max + 1;
	}

	/**
	 * The next free pause-group id (max + 1, 1 when empty). This app is
	 * instance-single-house by design and creations run under the house
	 * lock, so this is collision-free.
	 */
	public function maxGroupId(): int {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select($qb->func()->max('pause_group_id'))
			->from($this->getTableName())
			->executeQuery();
		$max = (int)$result->fetchOne();
		return $max + 1;
	}

	/**
	 * Append one revision row inside the owning house-first transaction.
	 * The group id is supplied by the caller (a new group for a fresh
	 * pause, the existing group for a revision).
	 */
	public function appendRevision(Pause $pause): Pause {
		$pause->setRevision($this->nextGroupRevision($pause->getPauseGroupId()));
		return $this->insert($pause);
	}

	/**
	 * Cancel every PENDING personal request of one house membership
	 * (Q-073-03 departure rule): the pending rows are stamped `cancelled`
	 * directly — a cancellation is not a new request revision.
	 *
	 * @return int Cancelled row count
	 */
	public function cancelPendingPersonalForSubject(int $houseId, int $walletId): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->update($this->getTableName())
			->set('state', $qb->createNamedParameter(Pause::STATE_CANCELLED, IQueryBuilder::PARAM_STR))
			->set('updated_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('subject_wallet_id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(Pause::STATE_REQUESTED, IQueryBuilder::PARAM_STR)))
			->executeStatement();
	}

	/**
	 * Null the requester/decider references of one membership's pause rows
	 * (account erasure); the shared pause facts stay.
	 */
	public function anonymizeActor(int $houseUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('requester_house_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('requester_house_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('decider_house_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('decider_house_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Delete the personal pause rows of one wallet (account erasure:
	 * personal pause data is removed; house-scope rows keep their
	 * non-reidentifiable shared facts).
	 */
	public function deletePersonalByWalletId(int $walletId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('subject_wallet_id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Delete the house's pauses (test-mode reset).
	 */
	public function deleteByHouseId(int $houseId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
