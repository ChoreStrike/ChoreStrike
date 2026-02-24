<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Room>
 */
class RoomMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_rooms', Room::class);
	}

	/**
	 * Find room by ID
	 *
	 * @param int $id The room ID
	 * @throws DoesNotExistException if not found
	 */
	public function find(int $id): Room {
		return parent::find($id);
	}

	/**
	 * Get all rooms for a floor
	 *
	 * @param int $floorId The floor ID
	 * @return Room[]
	 */
	public function findByFloorId(int $floorId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('floor_id', $qb->createNamedParameter($floorId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	/**
	 * Get all rooms that need cleaning
	 *
	 * @param int|null $floorId Optional floor ID to filter by
	 * @return Room[]
	 */
	public function findNeedingCleaning(?int $floorId = null): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->lt('cleanliness', $qb->createNamedParameter(60, IQueryBuilder::PARAM_INT)));

		if ($floorId !== null) {
			$qb->andWhere($qb->expr()->eq('floor_id', $qb->createNamedParameter($floorId, IQueryBuilder::PARAM_INT)));
		}

		return $this->findEntities($qb);
	}

	/**
	 * Get all rooms
	 *
	 * @return Room[]
	 */
	public function findAll(): array {
		return parent::findAll();
	}

	/**
	 * Create a new room
	 *
	 * @param int $floorId The floor ID
	 * @param string $name The room name
	 */
	public function create(int $floorId, string $name): Room {
		$room = new Room();
		$room->setFloorId($floorId);
		$room->setName($name);
		$room->setCleanliness(50);
		$room->setMonsterCount(0);
		$room->setCreatedAt(new \DateTime());

		return $this->insert($room);
	}

	/**
	 * Update room cleanliness
	 *
	 * @param int $roomId The room ID
	 * @param int $cleanliness The new cleanliness value (0-100)
	 */
	public function updateCleanliness(int $roomId, int $cleanliness): void {
		$cleanliness = max(0, min(100, $cleanliness)); // Clamp between 0 and 100
		
		$qb = $this->db->getQueryBuilder();
		
		$qb->update($this->getTableName())
			->set('cleanliness', $qb->createNamedParameter($cleanliness, IQueryBuilder::PARAM_INT))
			->set('last_cleaned', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
		
		$qb->executeStatement();
	}

	/**
	 * Update monster count
	 *
	 * @param int $roomId The room ID
	 * @param int $monsterCount The new monster count
	 */
	public function updateMonsterCount(int $roomId, int $monsterCount): void {
		$monsterCount = max(0, min(4, $monsterCount)); // Clamp between 0 and 4
		
		$qb = $this->db->getQueryBuilder();
		
		$qb->update($this->getTableName())
			->set('monster_count', $qb->createNamedParameter($monsterCount, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
		
		$qb->executeStatement();
	}
}
