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
 * @template-extends QBMapper<RoomTask>
 */
class RoomTaskMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'chorestrike_room_tasks', RoomTask::class);
    }

    /**
     * Find room task by ID
     *
     * @param int $id The room task ID
     * @throws DoesNotExistException if not found
     */
    public function find(int $id): RoomTask
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * Find tasks by house ID
     *
     * @param int $houseId The house ID
     * @return RoomTask[] Array of tasks
     */
    public function findByHouseId(int $houseId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Find unscheduled tasks for a house
     *
     * @param int $houseId The house ID
     * @return RoomTask[] Array of unscheduled tasks
     */
    public function findUnscheduledByHouseId(int $houseId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('assigned_to'))
            ->andWhere($qb->expr()->isNull('completed_at'))
            ->orderBy('priority', 'ASC')
            ->addOrderBy('deadline', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find tasks assigned to a specific user
     *
     * @param int $houseId The house ID
     * @param int $assignedTo The user ID
     * @return RoomTask[] Array of assigned tasks
     */
    public function findByAssignedUser(int $houseId, int $assignedTo): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('assigned_to', $qb->createNamedParameter($assignedTo, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('completed_at'))
            ->orderBy('deadline', 'ASC')
            ->addOrderBy('priority', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find tasks with deadlines in a date range
     *
     * @param int $houseId The house ID
     * @param string $startDate Start date (Y-m-d format)
     * @param string $endDate End date (Y-m-d format)
     * @return RoomTask[] Array of tasks with deadlines
     */
    public function findByDeadlineRange(int $houseId, string $startDate, string $endDate): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNotNull('deadline'))
            ->andWhere($qb->expr()->gte('deadline', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('deadline', $qb->createNamedParameter($endDate)))
            ->andWhere($qb->expr()->isNull('completed_at'))
            ->orderBy('deadline', 'ASC');

        return $this->findEntities($qb);
    }


    /**
     * Find tasks scheduled for a specific date
     *
     * @param int $houseId The house ID
     * @param string $date Date (Y-m-d format)
     * @return RoomTask[] Array of tasks scheduled for the date
     */
    public function findByScheduledDate(int $houseId, string $date): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('scheduled_for', $qb->createNamedParameter($date)))
            ->andWhere($qb->expr()->isNull('completed_at'))
            ->orderBy('priority', 'ASC')
            ->addOrderBy('deadline', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Get all room tasks for a room
     *
     * @param int $roomId The room ID
     * @param bool $activeOnly Whether to return only active tasks
     * @return RoomTask[]
     */
    public function findByRoomId(int $roomId, bool $activeOnly = true): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));

        if ($activeOnly) {
            $qb->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }

        $qb->orderBy('next_due', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Get all room tasks for a user
     *
     * @param string $userId The user ID
     * @param bool $activeOnly Whether to return only active tasks
     * @return RoomTask[]
     */
    public function findByUserId(string $userId, bool $activeOnly = true): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('assigned_to_user_id', $qb->createNamedParameter($userId)));

        if ($activeOnly) {
            $qb->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }

        $qb->orderBy('next_due', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Get overdue tasks
     *
     * @param int|null $roomId Optional room ID to filter by
     * @return RoomTask[]
     */
    public function findOverdue(?int $roomId = null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->lt('next_due', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE)))
            ->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

        if ($roomId !== null) {
            $qb->andWhere($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
        }

        $qb->orderBy('next_due', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Get tasks due today
     *
     * @param int|null $roomId Optional room ID to filter by
     * @return RoomTask[]
     */
    public function findDueToday(?int $roomId = null): array
    {
        $today = new \DateTime();
        $today->setTime(0, 0, 0);
        $tomorrow = clone $today;
        $tomorrow->add(new \DateInterval('P1D'));

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->gte('next_due', $qb->createNamedParameter($today, IQueryBuilder::PARAM_DATE)))
            ->andWhere($qb->expr()->lt('next_due', $qb->createNamedParameter($tomorrow, IQueryBuilder::PARAM_DATE)))
            ->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

        if ($roomId !== null) {
            $qb->andWhere($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
        }

        $qb->orderBy('next_due', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Get tasks due within the next N days
     *
     * @param int $days Number of days
     * @param int|null $roomId Optional room ID to filter by
     * @return RoomTask[]
     */
    public function findDueWithinDays(int $days, ?int $roomId = null): array
    {
        $today = new \DateTime();
        $future = clone $today;
        $future->add(new \DateInterval("P{$days}D"));

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->lte('next_due', $qb->createNamedParameter($future, IQueryBuilder::PARAM_DATE)))
            ->andWhere($qb->expr()->gte('next_due', $qb->createNamedParameter($today, IQueryBuilder::PARAM_DATE)))
            ->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

        if ($roomId !== null) {
            $qb->andWhere($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
        }

        $qb->orderBy('next_due', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Create a new room task
     *
     * @param int $roomId The room ID
     * @param int $taskId The task ID
     * @param \DateTime $nextDue The next due date
     * @param string|null $assignedToUserId Optional assigned user ID
     * @param int|null $scopeId Optional scope ID
     */
    public function create(int $roomId, int $taskId, \DateTime $nextDue, ?string $assignedToUserId = null, ?int $scopeId = null): RoomTask
    {
        $roomTask = new RoomTask();
        $roomTask->setRoomId($roomId);
        $roomTask->setTaskId($taskId);
        $roomTask->setNextDue($nextDue);
        $roomTask->setAssignedToUserId($assignedToUserId);
        $roomTask->setScopeId($scopeId);
        $roomTask->setIsActive(true);
        $roomTask->setCreatedAt(new \DateTime());

        return $this->insert($roomTask);
    }

    /**
     * Find room task linked to a Nextcloud task
     *
     * @param int $nextcloudTaskId The Nextcloud task ID
     */
    public function findByNextcloudTaskId(int $nextcloudTaskId): ?RoomTask
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('nextcloud_task_id', $qb->createNamedParameter($nextcloudTaskId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        $entities = $this->findEntities($qb);
        return $entities[0] ?? null;
    }

    /**
     * Find room tasks by scope ID
     *
     * @param int $scopeId The scope ID
     * @param bool $activeOnly Whether to return only active tasks
     * @return RoomTask[]
     */
    public function findByScopeId(int $scopeId, bool $activeOnly = true): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('scope_id', $qb->createNamedParameter($scopeId, IQueryBuilder::PARAM_INT)));

        if ($activeOnly) {
            $qb->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }

        $qb->orderBy('next_due', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Get all room tasks for a group
     *
     * @param string $groupId The group ID
     * @param bool $activeOnly Whether to return only active tasks
     * @return RoomTask[]
     */
    public function findByGroupId(string $groupId, bool $activeOnly = true): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('assigned_to_group', $qb->createNamedParameter($groupId)))
            ->andWhere($qb->expr()->eq('assignment_type', $qb->createNamedParameter('group')));

        if ($activeOnly) {
            $qb->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }

        $qb->orderBy('next_due', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find all tasks visible to a user (assigned directly or via group)
     *
     * @param string $userId The user ID
     * @param array $groupIds The group IDs the user belongs to
     * @param bool $activeOnly Whether to return only active tasks
     * @return RoomTask[]
     */
    public function findVisibleToUser(string $userId, array $groupIds, bool $activeOnly = true): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName());

        // Build OR condition: assigned to user OR assigned to any of user's groups
        $conditions = [
            $qb->expr()->eq('assigned_to_user_id', $qb->createNamedParameter($userId))
        ];

        if (!empty($groupIds)) {
            $groupConditions = [];
            foreach ($groupIds as $groupId) {
                $groupConditions[] = $qb->expr()->eq('assigned_to_group', $qb->createNamedParameter($groupId));
            }
            $conditions[] = $qb->expr()->andX(
                $qb->expr()->eq('assignment_type', $qb->createNamedParameter('group')),
                $qb->expr()->orX(...$groupConditions)
            );
        }

        $qb->where($qb->expr()->orX(...$conditions));

        if ($activeOnly) {
            $qb->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }

        $qb->orderBy('next_due', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Assign task to group
     *
     * @param int $roomTaskId The room task ID
     * @param string $groupId The group ID
     */
    public function assignToGroup(int $roomTaskId, string $groupId): void
    {
        $qb = $this->db->getQueryBuilder();

        $qb->update($this->getTableName())
            ->set('assigned_to_group', $qb->createNamedParameter($groupId))
            ->set('assignment_type', $qb->createNamedParameter('group'))
            ->set('claimed_by_user', $qb->createNamedParameter(null))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Claim a group-assigned task for a user
     *
     * @param int $roomTaskId The room task ID
     * @param string $userId The claiming user ID
     */
    public function claimForUser(int $roomTaskId, string $userId): void
    {
        $qb = $this->db->getQueryBuilder();

        $qb->update($this->getTableName())
            ->set('claimed_by_user', $qb->createNamedParameter($userId))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('assignment_type', $qb->createNamedParameter('group')));

        $qb->executeStatement();
    }

    /**
     * Unclaim a group-assigned task
     *
     * @param int $roomTaskId The room task ID
     */
    public function unclaim(int $roomTaskId): void
    {
        $qb = $this->db->getQueryBuilder();

        $qb->update($this->getTableName())
            ->set('claimed_by_user', $qb->createNamedParameter(null))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Assign task to user
     *
     * @param int $roomTaskId The room task ID
     * @param string $userId The user ID
     */
    public function assignToUser(int $roomTaskId, string $userId): void
    {
        $qb = $this->db->getQueryBuilder();

        $qb->update($this->getTableName())
            ->set('assigned_to_user_id', $qb->createNamedParameter($userId))
            ->set('assignment_type', $qb->createNamedParameter('user'))
            ->set('assigned_to_group', $qb->createNamedParameter(null))
            ->set('claimed_by_user', $qb->createNamedParameter(null))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Deactivate task
     *
     * @param int $roomTaskId The room task ID
     */
    public function deactivate(int $roomTaskId): void
    {
        $qb = $this->db->getQueryBuilder();

        $qb->update($this->getTableName())
            ->set('is_active', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Reactivate task
     *
     * @param int $roomTaskId The room task ID
     */
    public function reactivate(int $roomTaskId): void
    {
        $qb = $this->db->getQueryBuilder();

        $qb->update($this->getTableName())
            ->set('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Update next due date
     *
     * @param int $roomTaskId The room task ID
     * @param \DateTime $nextDue The new due date
     */
    public function updateNextDue(int $roomTaskId, \DateTime $nextDue): void
    {
        $qb = $this->db->getQueryBuilder();

        $qb->update($this->getTableName())
            ->set('next_due', $qb->createNamedParameter($nextDue, IQueryBuilder::PARAM_DATE))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Check if room already has this task
     *
     * @param int $roomId The room ID
     * @param int $taskId The task ID
     * @return bool
     */
    public function exists(int $roomId, int $taskId): bool
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        return $result->fetchOne() !== false;
    }

    /**
     * Find daily tasks for a house
     *
     * @param int $houseId The house ID
     * @param DateTime $date The date to find tasks for
     * @return RoomTask[]
     */
    public function findDailyTasks(int $houseId, \DateTime $date): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('rt.*')
            ->from($this->getTableName(), 'rt')
            ->innerJoin('rt', 'chorestrike_rooms', 'r', $qb->expr()->eq('rt.room_id', 'r.id'))
            ->innerJoin('r', 'chorestrike_floors', 'f', $qb->expr()->eq('r.floor_id', 'f.id'))
            ->where($qb->expr()->eq('f.house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('DATE(rt.next_due)', $qb->createNamedParameter($date->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('rt.is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('rt.next_due', 'ASC')
            ->addOrderBy('rt.created_at', 'ASC');

        return $this->findEntities($qb);
    }
}
