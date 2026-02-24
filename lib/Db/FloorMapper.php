<?php

declare(strict_types=1);

namespace OCA\ChoreWars\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Floor>
 */
class FloorMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorewars_floors', Floor::class);
	}

	/**
	 * Find floor by ID
	 *
	 * @param int $id The floor ID
	 * @throws DoesNotExistException if not found
	 */
	public function find(int $id): Floor {
		return parent::find($id);
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
		return parent::findAll();
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
