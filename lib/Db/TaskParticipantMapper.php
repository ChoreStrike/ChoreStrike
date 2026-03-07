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
 * @template-extends QBMapper<TaskParticipant>
 */
class TaskParticipantMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'chorestrike_task_participants', TaskParticipant::class);
    }

    /**
     * Find participant by ID
     *
     * @param int $id The participant ID
     */
    public function find(int $id): TaskParticipant
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        
        return $this->findEntity($qb);
    }

    /**
     * Get all participants for a completed task
     *
     * @param int $completedTaskId The completed task ID
     * @return TaskParticipant[]
     */
    public function findByCompletedTask(int $completedTaskId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('completed_task_id', $qb->createNamedParameter($completedTaskId, IQueryBuilder::PARAM_INT)))
            ->orderBy('joined_at', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Get all tasks a user has participated in
     *
     * @param string $userId The user ID
     * @return TaskParticipant[]
     */
    public function findByUser(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('joined_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Check if a user is already participating in a task
     *
     * @param int $completedTaskId The completed task ID
     * @param string $userId The user ID
     */
    public function isParticipating(int $completedTaskId, string $userId): bool
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('completed_task_id', $qb->createNamedParameter($completedTaskId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        return $result->fetchOne() !== false;
    }

    /**
     * Add a participant to a task
     *
     * @param int $completedTaskId The completed task ID
     * @param string $userId The user ID
     * @param string $participationType 'primary' or 'helper'
     * @param int $sparksEarned Sparks earned for participation
     */
    public function addParticipant(int $completedTaskId, string $userId, string $participationType = 'helper', int $sparksEarned = 0): TaskParticipant
    {
        $participant = new TaskParticipant();
        $participant->setCompletedTaskId($completedTaskId);
        $participant->setUserId($userId);
        $participant->setParticipationType($participationType);
        $participant->setSparksEarned($sparksEarned);
        $participant->setJoinedAt(new \DateTime());

        return $this->insert($participant);
    }

    /**
     * Remove a participant from a task
     *
     * @param int $completedTaskId The completed task ID
     * @param string $userId The user ID
     */
    public function removeParticipant(int $completedTaskId, string $userId): void
    {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('completed_task_id', $qb->createNamedParameter($completedTaskId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $qb->executeStatement();
    }

    /**
     * Update sparks for a participant
     *
     * @param int $participantId The participant ID
     * @param int $sparksEarned New sparks value
     */
    public function updateSparks(int $participantId, int $sparksEarned): void
    {
        $qb = $this->db->getQueryBuilder();

        $qb->update($this->getTableName())
            ->set('sparks_earned', $qb->createNamedParameter($sparksEarned, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($participantId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Get participant count for a completed task
     *
     * @param int $completedTaskId The completed task ID
     */
    public function getParticipantCount(int $completedTaskId): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->func()->count('*'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('completed_task_id', $qb->createNamedParameter($completedTaskId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        return (int)$result->fetchOne();
    }

    /**
     * Delete all participants for a completed task
     *
     * @param int $completedTaskId The completed task ID
     */
    public function deleteByCompletedTask(int $completedTaskId): void
    {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('completed_task_id', $qb->createNamedParameter($completedTaskId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }
}
