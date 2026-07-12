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
 * Mapper for CompletedTask entity
 */
class CompletedTaskMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'chorestrike_completed_tasks', CompletedTask::class);
    }

    /**
     * Find completed task by ID
     *
     * @param int $id The completed task ID
     * @throws DoesNotExistException
     */
    public function find(int $id): CompletedTask
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * Find all completed tasks for a user
     *
     * @param int $userId The house user ID
     * @return CompletedTask[]
     */
    public function findByUserId(int $userId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_INT)))
            ->orderBy('completed_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Find recent completions (within last N minutes)
     *
     * @param int $userId The house user ID
     * @param int $minutes Number of minutes to look back
     * @return CompletedTask[]
     */
    public function findRecentByUserId(int $userId, int $minutes = 5): array
    {
        $qb = $this->db->getQueryBuilder();
        $cutoffDate = new \DateTime("-{$minutes} minutes");

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter($cutoffDate, IQueryBuilder::PARAM_DATE)))
            ->orderBy('completed_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Find completions for a specific room task
     *
     * @param int $roomTaskId The room task ID
     * @return CompletedTask[]
     */
    public function findByRoomTaskId(int $roomTaskId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
            ->orderBy('completed_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Get user statistics for a date range
     *
     * @param int $userId The house user ID
     * @param \DateTime $startDate Start date
     * @param \DateTime $endDate End date
     */
    public function getUserStats(int $userId, \DateTime $startDate, \DateTime $endDate): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('COUNT(*) as task_count')
            ->addSelect('SUM(coins_earned) as total_coins')
            ->addSelect('SUM(CASE WHEN was_combo = 1 THEN 1 ELSE 0 END) as combo_count')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter($startDate, IQueryBuilder::PARAM_DATE)))
            ->andWhere($qb->expr()->lte('completed_at', $qb->createNamedParameter($endDate, IQueryBuilder::PARAM_DATE)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return [
            'taskCount' => (int)($row['task_count'] ?? 0),
            'totalCoins' => (int)($row['total_coins'] ?? 0),
            'comboCount' => (int)($row['combo_count'] ?? 0),
        ];
    }

    /**
     * Get total consumed energy for a user on a specific date.
     *
     * @param int $userId The house user ID
     * @param \DateTimeInterface $date Date used to determine day boundaries
     * @return int Total consumed energy points
     */
    public function getConsumedEnergyForUserOnDate(int $userId, \DateTimeInterface $date): int
    {
        $startOfDay = new \DateTimeImmutable($date->format('Y-m-d') . ' 00:00:00');
        $endOfDay = $startOfDay->modify('+1 day');

        $qb = $this->db->getQueryBuilder();
        $qb->select('COALESCE(SUM(t.energy_cost), 0) AS consumed_energy')
            ->from($this->getTableName(), 'ct')
            ->innerJoin('ct', 'chorestrike_room_tasks', 'rt', $qb->expr()->eq('ct.room_task_id', 'rt.id'))
            ->innerJoin('rt', 'chorestrike_tasks', 't', $qb->expr()->eq('rt.task_id', 't.id'))
            ->where($qb->expr()->eq('ct.user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('ct.completed_at', $qb->createNamedParameter($startOfDay, IQueryBuilder::PARAM_DATE)))
            ->andWhere($qb->expr()->lt('ct.completed_at', $qb->createNamedParameter($endOfDay, IQueryBuilder::PARAM_DATE)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['consumed_energy'] ?? 0);
    }

    /**
     * Get all completions in a date range for combo detection
     *
     * @param \DateTime $startDate Start date
     * @param \DateTime $endDate End date
     * @return CompletedTask[]
     */
    public function findInDateRange(\DateTime $startDate, \DateTime $endDate): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->gte('completed_at', $qb->createNamedParameter($startDate, IQueryBuilder::PARAM_DATE)))
            ->andWhere($qb->expr()->lte('completed_at', $qb->createNamedParameter($endDate, IQueryBuilder::PARAM_DATE)))
            ->orderBy('completed_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Find latest completion for a room task
     *
     * @param int $roomTaskId The room task ID
     * @return CompletedTask|null The latest completion or null
     */
    public function findLatestByRoomTask(int $roomTaskId): ?CompletedTask
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
            ->orderBy('completed_at', 'DESC')
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Find completions pending sync to Nextcloud
     *
     * @param int $limit Maximum number of records
     * @return CompletedTask[] Pending completions
     */
    public function findPendingSyncs(int $limit = 50): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('synced_to_nextcloud', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->isNotNull('nextcloud_task_id'))
            ->orderBy('completed_at', 'ASC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * Count completions of a room task by a house user on a specific calendar day.
     *
     * Used by the duplicate-completion guard to prevent the same user from
     * earning coins twice for the same room task on the same day.
     *
     * @param int $roomTaskId The room task ID
     * @param int $houseUserId The house user ID (internal)
     * @param \DateTimeInterface $date Date used to determine day boundaries
     * @return int Number of completions on that day
     */
    public function countByRoomTaskAndUserOnDate(int $roomTaskId, int $houseUserId, \DateTimeInterface $date): int
    {
        $startOfDay = new \DateTimeImmutable($date->format('Y-m-d') . ' 00:00:00');
        $endOfDay = $startOfDay->modify('+1 day');

        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter($startOfDay, IQueryBuilder::PARAM_DATE)))
            ->andWhere($qb->expr()->lt('completed_at', $qb->createNamedParameter($endOfDay, IQueryBuilder::PARAM_DATE)));

        $result = $qb->executeQuery();
        return (int)$result->fetchOne();
    }
}
