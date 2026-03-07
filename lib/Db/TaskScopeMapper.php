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
 * @template-extends QBMapper<TaskScope>
 */
class TaskScopeMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'chorestrike_task_scopes', TaskScope::class);
    }

    /**
     * Find scope by ID.
     *
     * @param int $id The scope ID
     * @throws DoesNotExistException if not found
     */
    public function find(int $id): TaskScope
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * Find all scopes for a house.
     *
     * @param int $houseId The house ID
     * @return TaskScope[]
     */
    public function findByHouseId(int $houseId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
            ->orderBy('type', 'ASC')
            ->addOrderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find all scopes for a specific type.
     *
     * @param int $houseId The house ID
     * @param string $type The scope type
     * @return TaskScope[]
     */
    public function findByType(int $houseId, string $type): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)))
            ->orderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Create a new scope.
     *
     * @param int $houseId The house ID
     * @param string $type The scope type
     * @param string $name The scope label
     * @param int|null $roomId Optional room link for room scopes
     */
    public function create(int $houseId, string $type, string $name, ?int $roomId = null): TaskScope
    {
        $scope = new TaskScope();
        $scope->setHouseId($houseId);
        $scope->setType($type);
        $scope->setName($name);
        $scope->setRoomId($roomId);
        $scope->setCreatedAt(new \DateTime());

        return $this->insert($scope);
    }
}
