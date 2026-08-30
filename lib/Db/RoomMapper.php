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
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Find multiple rooms by IDs in one query (CS-PLAN-036).
	 *
	 * IN-clause batch lookup returning a map id → Room. Missing ids are
	 * tolerated and simply absent from the map (skip-on-missing semantics
	 * for callers iterating room tasks).
	 *
	 * @param int[] $ids The room IDs
	 * @return array<int, Room> Map of room ID → Room entity
	 */
	public function findByIds(array $ids): array {
		if (empty($ids)) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		$map = [];
		foreach ($this->findEntities($qb) as $room) {
			$map[$room->getId()] = $room;
		}

		return $map;
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
	 * Get all rooms for a house via floor relation
	 *
	 * @param int $houseId The house ID
	 * @return Room[]
	 */
	public function findByHouseId(int $houseId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('r.*')
			->from($this->getTableName(), 'r')
			->innerJoin('r', 'chorestrike_floors', 'f', $qb->expr()->eq('r.floor_id', 'f.id'))
			->where($qb->expr()->eq('f.house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	/**
	 * Create a new room
	 *
	 * @param int $floorId The floor ID
	 * @param string $name The room name
	 * @param ?string $icon The configured room icon
	 * @param string $roomType The room type (defaults to 'generic')
	 */
	public function create(int $floorId, string $name, ?string $icon = null, string $roomType = 'generic'): Room {
		$room = new Room();
		$room->setFloorId($floorId);
		$room->setName($name);
		$room->setCleanliness(50);
		$room->setMonsterCount(0);
		$room->setIcon($icon ?? 'Home');
		$room->setRoomType($roomType !== '' ? $roomType : 'generic');
		$room->setCreatedAt(new \DateTime());

		return $this->insert($room);
	}

	/**
	 * Reset every room of a house to its initial state in one UPDATE
	 * (CS-PLAN-037, CR-SVC-60).
	 *
	 * `UPDATE rooms SET cleanliness = 100, monster_count = 0,
	 * last_cleaned = NULL WHERE floor_id IN (SELECT id FROM floors WHERE
	 * house_id = :house)` — built with the query-builder subquery pattern
	 * (the house parameter is bound on the OUTER builder and its
	 * placeholder embedded into the subquery SQL, so the parameter is
	 * bound exactly once where execution happens; CR-SVC-26 precedent).
	 * Portable `IN (SELECT …)` instead of the non-portable UPDATE … JOIN.
	 *
	 * @param int $houseId The house ID scope
	 * @return int Number of affected room rows
	 */
	public function resetByHouseId(int $houseId): int {
		$qb = $this->db->getQueryBuilder();
		$houseIdParam = $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT);

		$floorsInHouse = $this->db->getQueryBuilder();
		$floorsInHouse->select('f.id')
			->from('chorestrike_floors', 'f')
			->where($floorsInHouse->expr()->eq('f.house_id', $houseIdParam));

		$qb->update($this->getTableName())
			->set('cleanliness', $qb->createNamedParameter(100, IQueryBuilder::PARAM_INT))
			->set('monster_count', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('last_cleaned', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->in('floor_id', $qb->createFunction($floorsInHouse->getSQL())));

		return $qb->executeStatement();
	}

	/**
	 * Delete all rooms of a floor in one statement (CS-PLAN-037 cascade;
	 * dependents must be cleaned up by the caller first — no FK cascade).
	 *
	 * @param int $floorId The floor ID
	 */
	public function deleteByFloorId(int $floorId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('floor_id', $qb->createNamedParameter($floorId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
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
}
