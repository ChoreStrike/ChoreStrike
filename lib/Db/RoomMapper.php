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
class RoomMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'chorestrike_rooms', Room::class);
    }

    /**
     * Find room by ID
     *
     * @param int $id The room ID
     * @throws DoesNotExistException if not found
     */
    public function find(int $id): Room
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * Get all rooms for a floor
     *
     * @param int $floorId The floor ID
     * @return Room[]
     */
    public function findByFloorId(int $floorId): array
    {
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
    public function findByHouseId(int $houseId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('r.*')
            ->from($this->getTableName(), 'r')
            ->innerJoin('r', 'chorestrike_floors', 'f', $qb->expr()->eq('r.floor_id', 'f.id'))
            ->where($qb->expr()->eq('f.house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));

        return $this->findEntities($qb);
    }

    /**
     * Get all rooms that need cleaning
     *
     * @param int|null $floorId Optional floor ID to filter by
     * @return Room[]
     */
    public function findNeedingCleaning(?int $floorId = null): array
    {
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
    public function findAll(): array
    {
        return parent::findAll();
    }

    /**
     * Create a new room
     *
     * @param int $floorId The floor ID
     * @param string $name The room name
     * @param ?string $icon The configured room icon
     */
    public function create(int $floorId, string $name, ?string $icon = null): Room
    {
        $room = new Room();
        $room->setFloorId($floorId);
        $room->setName($name);
        $room->setCleanliness(50);
        $room->setMonsterCount(0);
        $room->setIcon($icon ?? 'Home');
        $room->setCreatedAt(new \DateTime());

        return $this->insert($room);
    }

    /**
     * Update room cleanliness
     *
     * @param int $roomId The room ID
     * @param int $cleanliness The new cleanliness value (0-100)
     */
    public function updateCleanliness(int $roomId, int $cleanliness): void
    {
        $cleanliness = max(0, min(100, $cleanliness)); // Clamp between 0 and 100

        $qb = $this->db->getQueryBuilder();

        $qb->update($this->getTableName())
            ->set('cleanliness', $qb->createNamedParameter($cleanliness, IQueryBuilder::PARAM_INT))
            ->set('last_cleaned', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }
}
