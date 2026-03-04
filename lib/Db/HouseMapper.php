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
 * @template-extends QBMapper<House>
 */
class HouseMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'chorestrike_houses', House::class);
    }

    /**
     * Find house by Nextcloud instance ID
     *
     * @param string $instanceId The Nextcloud instance ID
     * @throws DoesNotExistException if not found
     */
    public function findByInstanceId(string $instanceId): House
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('nextcloud_instance_id', $qb->createNamedParameter($instanceId)));

        return $this->findEntity($qb);
    }

    /**
     * Find house by ID
     *
     * @param int $id The house ID
     * @throws DoesNotExistException if not found
     */
    public function find(int $id): House
    {
        return parent::find($id);
    }

    /**
     * Get all houses
     *
     * @return House[]
     */
    public function findAll(): array
    {
        return parent::findAll();
    }

    /**
     * Create or update house for instance
     *
     * @param string $instanceId The Nextcloud instance ID
     * @param string $name The house name
     */
    public function createForInstance(string $instanceId, string $name): House
    {
        try {
            $house = $this->findByInstanceId($instanceId);
            $house->setName($name);
            $house->touch();
            return $this->update($house);
        } catch (DoesNotExistException $e) {
            $house = new House();
            $house->setNextcloudInstanceId($instanceId);
            $house->setName($name);
            $house->setHouseHealth(100);
            $now = new \DateTime();
            $house->setCreatedAt($now);
            $house->setUpdatedAt($now);
            return $this->insert($house);
        }
    }

    /**
     * Update house health
     *
     * @param int $houseId The house ID
     * @param int $health The new health value (0-100)
     */
    public function updateHealth(int $houseId, int $health): void
    {
        $health = max(0, min(100, $health)); // Clamp between 0 and 100

        $qb = $this->db->getQueryBuilder();

        $qb->update($this->getTableName())
            ->set('house_health', $qb->createNamedParameter($health, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }
}
