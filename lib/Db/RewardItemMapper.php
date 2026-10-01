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
 * @template-extends QBMapper<RewardItem>
 */
class RewardItemMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_reward_items', RewardItem::class);
	}

	/**
	 * Find an item by ID (NC31 QBMapper carries no base find()).
	 *
	 * @throws DoesNotExistException if the row does not exist
	 */
	public function find(int $id): RewardItem {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Find an item by ID and acquire an exclusive row lock (ADR-007
	 * pattern; SQLite via the no-op self-assignment UPDATE). Must be
	 * called inside an open transaction, after the house lock.
	 *
	 * @throws DoesNotExistException if the row does not exist
	 */
	public function findForUpdate(int $id): RewardItem {
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
			throw new DoesNotExistException('Reward item with id ' . $id . ' does not exist');
		}

		return $this->mapRowToEntity($row);
	}

	/**
	 * Bounded catalog page for one house (stable ID cursor: rows with
	 * id > $cursorId, ascending).
	 *
	 * @return RewardItem[]
	 */
	public function findByHouseId(int $houseId, ?bool $archived = null, ?int $cursorId = null, int $limit = 50): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));
		if ($archived !== null) {
			$qb->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter($archived, IQueryBuilder::PARAM_BOOL)));
		}
		if ($cursorId !== null) {
			$qb->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($cursorId, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('id', 'ASC')
			->setMaxResults(max(1, min(100, $limit)));

		return $this->findEntities($qb);
	}

	/**
	 * Delete every item the erased person created (privacy erasure
	 * "wishes" purge; other people's requests keep their frozen terms).
	 * Repeated erasure is harmless.
	 */
	public function deleteByCreator(?int $houseUserId, ?int $walletId): void {
		if ($houseUserId === null && $walletId === null) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName());
		if ($houseUserId !== null && $walletId !== null) {
			$qb->where($qb->expr()->orX(
				$qb->expr()->eq('created_by_house_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)),
				$qb->expr()->eq('created_by_wallet_id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT))
			));
		} elseif ($houseUserId !== null) {
			$qb->where($qb->expr()->eq('created_by_house_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)));
		} else {
			$qb->where($qb->expr()->eq('created_by_wallet_id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT)));
		}
		$qb->executeStatement();
	}
}
