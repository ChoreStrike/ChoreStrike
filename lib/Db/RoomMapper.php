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
	 * Find a room by ID and acquire an exclusive lock on its row
	 * (CS-PLAN-064 WP5, second lock in the completion/undo order
	 * `room_task → room → house_user`).
	 *
	 * Mirrors RoomTaskMapper::lockForUpdate: the lock AND the freshest
	 * committed entity come back together, so the cleanliness write after
	 * the lock can never clobber a concurrent same-room completion.
	 *
	 * - MySQL/MariaDB/PostgreSQL: a true locking read (SELECT … FOR UPDATE)
	 *   acquires the exclusive row lock and returns the latest committed
	 *   state in one statement.
	 * - SQLite: no FOR UPDATE support. A no-op self-assignment UPDATE
	 *   (writes cleanliness's own current value — never pre-read data)
	 *   acquires the database-wide write lock and serializes writers; the
	 *   re-read afterwards sees the latest committed state.
	 *
	 * Must be called inside an open transaction.
	 *
	 * @param int $roomId The room ID to lock
	 * @throws DoesNotExistException if the row does not exist
	 */
	public function lockForUpdate(int $roomId): Room {
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('cleanliness', $qb->createFunction('cleanliness'))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();

			return $this->find($roomId);
		}

		$sql = 'SELECT * FROM *PREFIX*' . $this->getTableName() . ' WHERE id = :id FOR UPDATE';
		$result = $this->db->executeQuery($sql, ['id' => $roomId], ['id' => IQueryBuilder::PARAM_INT]);
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			throw new DoesNotExistException('Room with id ' . $roomId . ' does not exist');
		}

		return $this->mapRowToEntity($row);
	}

	/**
	 * Find multiple rooms by IDs in one query (CS-PLAN-036).
	 *
	 * IN-clause batch lookup returning a map id → Room. Missing ids are
	 * tolerated and simply absent from the map (skip-on-missing semantics
	 * for callers iterating room tasks). Tombstoned rooms are excluded —
	 * every caller enriches active-planning rows (CS-PLAN-071 Q-70-01);
	 * single-room reads that must resolve tombstoned rows use find().
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
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->isNull('deleted_at'));

		$map = [];
		foreach ($this->findEntities($qb) as $room) {
			$map[$room->getId()] = $room;
		}

		return $map;
	}

	/**
	 * Get all live rooms for a floor
	 *
	 * @param int $floorId The floor ID
	 * @return Room[]
	 */
	public function findByFloorId(int $floorId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('floor_id', $qb->createNamedParameter($floorId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'));

		return $this->findEntities($qb);
	}

	/**
	 * Get all live rooms for a house via floor relation
	 *
	 * @param int $houseId The house ID
	 * @return Room[]
	 */
	public function findByHouseId(int $houseId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('r.*')
			->from($this->getTableName(), 'r')
			->innerJoin('r', 'chorestrike_floors', 'f', $qb->expr()->eq('r.floor_id', 'f.id'))
			->where($qb->expr()->eq('f.house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('r.deleted_at'));

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
	 * Reset every LIVE room of a house to its initial state in one UPDATE
	 * (CS-PLAN-037, CR-SVC-60; CS-PLAN-071: tombstoned rooms are excluded).
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
			->where($qb->expr()->in('floor_id', $qb->createFunction($floorsInHouse->getSQL())))
			->andWhere($qb->expr()->isNull('deleted_at'));

		return $qb->executeStatement();
	}

	/**
	 * Tombstone all live rooms of a floor in one statement (CS-PLAN-071
	 * Q-70-01 structural deletion; evidence rows are retained — the former
	 * hard DELETE is gone).
	 *
	 * @param int $floorId The floor ID
	 * @param \DateTime $deletedAt The UTC tombstone instant
	 */
	public function tombstoneByFloorId(int $floorId, \DateTime $deletedAt): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('deleted_at', $qb->createNamedParameter($deletedAt, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('floor_id', $qb->createNamedParameter($floorId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->executeStatement();
	}
}
