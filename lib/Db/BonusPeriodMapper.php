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
 * Bonus periods (ADR-021 decision 11). Every read leaves cancelled
 * periods out: a cancelled period applies to no day.
 *
 * @template-extends QBMapper<BonusPeriod>
 */
class BonusPeriodMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_bonus_periods', BonusPeriod::class);
	}

	/** A period of the house by id, cancelled or not; null for a foreign or unknown id. */
	public function findInHouse(int $id, int $houseId): ?BonusPeriod {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * The periods of a house that are not cancelled and end on or after a
	 * day, earliest first: the running one, then the planned ones.
	 *
	 * @param string $dayKey House-local day (`Y-m-d`)
	 * @return BonusPeriod[]
	 */
	public function findEndingFrom(int $houseId, string $dayKey): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('cancelled_at'))
			->andWhere($qb->expr()->gte('ends_on', $qb->createNamedParameter($dayKey)))
			->orderBy('starts_on', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}
}
