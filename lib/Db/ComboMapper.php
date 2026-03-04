<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for Combo entity
 */
class ComboMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'chorestrike_combos', Combo::class);
    }

    /**
     * Find active combo for user
     */
    public function findActiveByUser(int $houseId, string $userId): ?Combo
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('last_task_at', 'DESC')
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Find all active combos in house
     *
     * @return Combo[]
     */
    public function findActiveByHouse(int $houseId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('task_count', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Find completed combos (inactive) for user
     *
     * @return Combo[]
     */
    public function findCompletedByUser(int $houseId, string $userId, int $limit = 10): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('last_task_at', 'DESC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * Get combo statistics for house
     */
    public function getComboStats(int $houseId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select(
            'user_id',
            $qb->func()->count('*', 'combo_count'),
            $qb->func()->max('task_count', 'max_combo'),
            $qb->func()->sum('combo_bonus_points', 'total_bonus')
        )
            ->from($this->getTableName())
            ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
            ->groupBy('user_id');

        return $qb->executeQuery()->fetchAllAssociative();
    }
}
