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
 * @template-extends QBMapper<RewardRequest>
 */
class RewardRequestMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_reward_requests', RewardRequest::class);
	}

	/**
	 * Find a request by ID (NC31 QBMapper carries no base find()).
	 *
	 * @throws DoesNotExistException if the row does not exist
	 */
	public function find(int $id): RewardRequest {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Find a request by ID and acquire an exclusive row lock (ADR-007
	 * pattern; SQLite via the no-op self-assignment UPDATE). Must be
	 * called inside an open transaction, after the house lock and any
	 * wallet locks (reward rows sit in the final child-row lock group).
	 *
	 * @throws DoesNotExistException if the row does not exist
	 */
	public function findForUpdate(int $id): RewardRequest {
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('version', $qb->createFunction('version'))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();

			return $this->find($id);
		}

		$sql = 'SELECT * FROM *PREFIX*' . $this->getTableName() . ' WHERE id = :id FOR UPDATE';
		$result = $this->db->executeQuery($sql, ['id' => $id], ['id' => IQueryBuilder::PARAM_INT]);
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			throw new DoesNotExistException('Reward request with id ' . $id . ' does not exist');
		}

		return $this->mapRowToEntity($row);
	}

	/**
	 * Bounded stable-cursor page of one wallet's requests (scope=mine),
	 * newest first.
	 *
	 * @return RewardRequest[]
	 */
	public function findByWalletId(int $walletId, ?string $state = null, ?int $cursorId = null, int $limit = 50): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('wallet_id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT)));
		if ($state !== null) {
			$qb->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($state, IQueryBuilder::PARAM_STR)));
		}
		if ($cursorId !== null) {
			$qb->andWhere($qb->expr()->lt('id', $qb->createNamedParameter($cursorId, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('id', 'DESC')
			->setMaxResults(max(1, min(100, $limit)));

		return $this->findEntities($qb);
	}

	/**
	 * Bounded stable-cursor page of one house's requests (scope=house,
	 * owner administration), newest first.
	 *
	 * @return RewardRequest[]
	 */
	public function findByHouseId(int $houseId, ?string $state = null, ?int $cursorId = null, int $limit = 50): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));
		if ($state !== null) {
			$qb->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($state, IQueryBuilder::PARAM_STR)));
		}
		if ($cursorId !== null) {
			$qb->andWhere($qb->expr()->lt('id', $qb->createNamedParameter($cursorId, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('id', 'DESC')
			->setMaxResults(max(1, min(100, $limit)));

		return $this->findEntities($qb);
	}

	/**
	 * Every unfulfilled (pending/approved) request of one wallet under
	 * the current transaction (role-rebase and departure hooks run while
	 * the house lock serializes same-house writers).
	 *
	 * @return RewardRequest[]
	 */
	public function findUnfulfilledByWalletId(int $walletId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('wallet_id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('state', $qb->createNamedParameter(RewardRequest::STATES_UNFULFILLED, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * The summed reservation of one wallet's unfulfilled requests — the
	 * test/audit reconciliation source for `wallets.reserved_minor`
	 * (reads never repair caches).
	 */
	public function sumUnfulfilledPriceByWalletId(int $walletId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->sum('price_minor'))
			->from($this->getTableName())
			->where($qb->expr()->eq('wallet_id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('state', $qb->createNamedParameter(RewardRequest::STATES_UNFULFILLED, IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$sum = $result->fetchOne();
		$result->closeCursor();

		return $sum === null ? 0 : (int)$sum;
	}

	/**
	 * Every request row of the given wallets (erasure; both the
	 * active-membership and the orphan-wallet path).
	 *
	 * @param int[] $walletIds
	 * @return RewardRequest[]
	 */
	public function findByWalletIds(array $walletIds): array {
		if ($walletIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('wallet_id', $qb->createNamedParameter($walletIds, IQueryBuilder::PARAM_INT_ARRAY)));

		return $this->findEntities($qb);
	}

	/**
	 * Detach the erased approver from retained request rows of OTHER
	 * people (the erased person's own requests died with the purge).
	 */
	public function anonymizeApproverByMembership(int $houseUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('approved_by_house_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('approved_by_house_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Detach the erased approver by wallet provenance (covers
	 * already-departed actors whose membership row is gone).
	 */
	public function anonymizeApproverByWallet(int $walletId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('approved_by_house_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('approved_by_wallet_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('approved_by_wallet_id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
