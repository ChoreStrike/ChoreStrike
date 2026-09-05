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
 * @template-extends QBMapper<Floor>
 */
class FloorMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_floors', Floor::class);
	}

	/**
	 * Find floor by ID
	 *
	 * @param int $id The floor ID
	 * @throws DoesNotExistException if not found
	 */
	public function find(int $id): Floor {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Get all floors for a house, ordered by sort_order
	 *
	 * @param int $houseId The house ID
	 * @return Floor[]
	 */
	public function findByHouseId(int $houseId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_order', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Count the floors of a house (CS-PLAN-062 §3.1).
	 *
	 * The in-transaction first-run gate needs an existence check that does
	 * not materialize entities — a single prepared COUNT instead of the
	 * former find-by-house hydration for a boolean.
	 *
	 * @param int $houseId The house ID
	 */
	public function countByHouseId(int $houseId): int {
		$qb = $this->db->getQueryBuilder();

		$qb->select($qb->func()->count('id'))
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Create a new floor
	 *
	 * @param int $houseId The house ID
	 * @param string $name The floor name
	 * @param int $sortOrder The sort order
	 */
	public function create(int $houseId, string $name, int $sortOrder = 0): Floor {
		$floor = new Floor();
		$floor->setHouseId($houseId);
		$floor->setName($name);
		$floor->setSortOrder($sortOrder);
		$floor->setCreatedAt(new \DateTime());

		return $this->insert($floor);
	}

	/**
	 * Update the sort orders of multiple floors in one CASE-WHEN UPDATE
	 * (CS-PLAN-037, CR-SVC-60).
	 *
	 * `UPDATE floors SET sort_order = CASE id WHEN :i0 THEN :o0 … ELSE
	 * sort_order END WHERE id IN (…)`. Ids and orders are validated ints
	 * inlined via `sprintf('%d')` — no user strings reach the SQL, so no
	 * parameter placeholders are needed inside the CASE list. The ELSE
	 * arm keeps floors not in the map untouched; the IN clause scopes the
	 * statement to the given ids. Portable across MariaDB/SQLite/PostgreSQL.
	 *
	 * @param array<int, int> $floorIdToOrder Map of floor ID => new sort order
	 */
	public function updateSortOrders(array $floorIdToOrder): void {
		if (empty($floorIdToOrder)) {
			return;
		}

		$whens = [];
		$floorIds = [];
		foreach ($floorIdToOrder as $floorId => $sortOrder) {
			$floorIds[] = (int)$floorId;
			$whens[] = sprintf('WHEN %d THEN %d', (int)$floorId, (int)$sortOrder);
		}
		$case = 'CASE id ' . implode(' ', $whens) . ' ELSE sort_order END';

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('sort_order', $qb->createFunction($case))
			->where($qb->expr()->in('id', $qb->createNamedParameter($floorIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}
}
