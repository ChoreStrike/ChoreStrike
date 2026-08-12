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
	 * Get all floors
	 *
	 * @return Floor[]
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->orderBy('sort_order', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Create a new floor
	 *
	 * @param int $houseId The house ID
	 * @param string $name The floor name
	 * @param int $sortOrder The sort order
	 */
	public function create(int $houseId, string $name, int $sortOrder = 0): Floor {
		$createdAt = new \DateTime();

		$insertQb = $this->db->getQueryBuilder();
		$insertQb->insert($this->getTableName())
			->values([
				'house_id' => $insertQb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT),
				'name' => $insertQb->createNamedParameter($name),
				'sort_order' => $insertQb->createNamedParameter($sortOrder, IQueryBuilder::PARAM_INT),
				'created_at' => $insertQb->createNamedParameter($createdAt, IQueryBuilder::PARAM_DATE),
			]);

		$insertQb->executeStatement();

		$selectQb = $this->db->getQueryBuilder();
		$selectQb->select('*')
			->from($this->getTableName())
			->where($selectQb->expr()->eq('house_id', $selectQb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($selectQb->expr()->eq('name', $selectQb->createNamedParameter($name)))
			->andWhere($selectQb->expr()->eq('sort_order', $selectQb->createNamedParameter($sortOrder, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);

		return $this->findEntity($selectQb);
	}

	/**
	 * Update floor sort order
	 *
	 * @param int $floorId The floor ID
	 * @param int $sortOrder The new sort order
	 */
	public function updateSortOrder(int $floorId, int $sortOrder): void {
		$qb = $this->db->getQueryBuilder();

		$qb->update($this->getTableName())
			->set('sort_order', $qb->createNamedParameter($sortOrder, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($floorId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Delete floor and all associated rooms
	 *
	 * @param int $floorId The floor ID
	 */
	public function deleteWithRooms(int $floorId): void {
		// Delete rooms first (cascade will handle this, but being explicit)
		$roomMapper = new RoomMapper($this->db);
		$rooms = $roomMapper->findByFloorId($floorId);
		foreach ($rooms as $room) {
			$roomMapper->delete($room);
		}

		// Delete the floor
		$floor = $this->find($floorId);
		$this->delete($floor);
	}
}
