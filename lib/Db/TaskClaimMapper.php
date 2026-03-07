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
 * @template-extends QBMapper<TaskClaim>
 */
class TaskClaimMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'chorestrike_task_claims', TaskClaim::class);
    }

    /**
     * Find claim by ID
     *
     * @param int $id The claim ID
     */
    public function find(int $id): TaskClaim
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        
        return $this->findEntity($qb);
    }

    /**
     * Get all claims for a room task
     *
     * @param int $roomTaskId The room task ID
     * @return TaskClaim[]
     */
    public function findByRoomTask(int $roomTaskId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
            ->orderBy('claimed_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Get all claims by a user
     *
     * @param string $userId The user ID
     * @return TaskClaim[]
     */
    public function findByClaimer(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('claimed_by_user', $qb->createNamedParameter($userId)))
            ->orderBy('claimed_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Get all claims from tasks originally assigned to a user
     *
     * @param string $userId The original assignee user ID
     * @return TaskClaim[]
     */
    public function findByOriginalAssignee(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('original_assignee', $qb->createNamedParameter($userId)))
            ->orderBy('claimed_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Create a new claim record
     *
     * @param int $roomTaskId The room task ID
     * @param string $claimedByUser The user claiming the task
     * @param string|null $originalAssignee The original assignee (if any)
     * @param int $daysOverdue Days overdue when claimed
     */
    public function createClaim(int $roomTaskId, string $claimedByUser, ?string $originalAssignee, int $daysOverdue): TaskClaim
    {
        $claim = new TaskClaim();
        $claim->setRoomTaskId($roomTaskId);
        $claim->setClaimedByUser($claimedByUser);
        $claim->setOriginalAssignee($originalAssignee);
        $claim->setDaysOverdue($daysOverdue);
        $claim->setClaimedAt(new \DateTime());
        $claim->setWasCompleted(false);
        $claim->setBonusSparks(0);

        return $this->insert($claim);
    }

    /**
     * Mark a claim as completed
     *
     * @param int $claimId The claim ID
     * @param int $bonusSparks Bonus sparks awarded
     */
    public function markCompleted(int $claimId, int $bonusSparks): void
    {
        $qb = $this->db->getQueryBuilder();

        $qb->update($this->getTableName())
            ->set('was_completed', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
            ->set('completed_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
            ->set('bonus_sparks', $qb->createNamedParameter($bonusSparks, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($claimId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Get claim statistics for a user
     *
     * @param string $userId The user ID
     * @return array{total: int, completed: int, totalBonus: int}
     */
    public function getClaimStats(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select(
            $qb->func()->count('*'),
            $qb->func()->sum($qb->expr()->case(
                [$qb->expr()->eq('was_completed', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))],
                [1],
                0
            )),
            $qb->func()->sum('bonus_sparks')
        )
            ->from($this->getTableName())
            ->where($qb->expr()->eq('claimed_by_user', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $row = $result->fetch();

        return [
            'total' => (int)($row['count'] ?? 0),
            'completed' => (int)($row['sum'] ?? 0),
            'totalBonus' => (int)($row['sum_bonus_sparks'] ?? 0),
        ];
    }
}
