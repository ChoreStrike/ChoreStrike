<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<RoomTaskChecklistProgress>
 */
class RoomTaskChecklistProgressMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'chorestrike_room_task_checklist_progress', RoomTaskChecklistProgress::class);
    }

    /**
     * Find progress entry by ID
     *
     * @param int $id
     * @throws DoesNotExistException
     */
    public function find(int $id): RoomTaskChecklistProgress
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * Get all progress entries for a room task
     *
     * @param int $roomTaskId
     * @return RoomTaskChecklistProgress[]
     */
    public function findByRoomTaskId(int $roomTaskId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));

        return $this->findEntities($qb);
    }

    /**
     * Get progress for specific checklist item in a room task
     *
     * @param int $roomTaskId
     * @param int $checklistItemId
     * @throws DoesNotExistException
     */
    public function findByRoomTaskAndItem(int $roomTaskId, int $checklistItemId): RoomTaskChecklistProgress
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('checklist_item_id', $qb->createNamedParameter($checklistItemId, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * Toggle checklist item status
     *
     * @param int $roomTaskId
     * @param int $checklistItemId
     * @param int|null $userId User who checked/unchecked (null if unchecking)
     */
    public function toggle(int $roomTaskId, int $checklistItemId, ?int $userId): RoomTaskChecklistProgress
    {
        try {
            $progress = $this->findByRoomTaskAndItem($roomTaskId, $checklistItemId);

            // Toggle the existing entry
            $progress->setIsChecked(!$progress->getIsChecked());
            $progress->setCheckedAt($progress->getIsChecked() ? new \DateTime() : null);
            $progress->setCheckedByUserId($progress->getIsChecked() ? $userId : null);

            return $this->update($progress);
        } catch (DoesNotExistException $e) {
            // Create new checked entry
            $progress = new RoomTaskChecklistProgress();
            $progress->setRoomTaskId($roomTaskId);
            $progress->setChecklistItemId($checklistItemId);
            $progress->setIsChecked(true);
            $progress->setCheckedAt(new \DateTime());
            $progress->setCheckedByUserId($userId);

            return $this->insert($progress);
        }
    }

    /**
     * Initialize progress entries for all checklist items of a room task
     *
     * @param int $roomTaskId
     * @param array $checklistItemIds
     */
    public function initializeProgress(int $roomTaskId, array $checklistItemIds): void
    {
        foreach ($checklistItemIds as $itemId) {
            try {
                // Check if already exists
                $this->findByRoomTaskAndItem($roomTaskId, $itemId);
            } catch (DoesNotExistException $e) {
                // Create unchecked entry
                $progress = new RoomTaskChecklistProgress();
                $progress->setRoomTaskId($roomTaskId);
                $progress->setChecklistItemId($itemId);
                $progress->setIsChecked(false);
                $progress->setCheckedAt(null);
                $progress->setCheckedByUserId(null);

                $this->insert($progress);
            }
        }
    }

    /**
     * Get progress statistics for a room task
     *
     * @param int $roomTaskId
     * @return array
     */
    public function getProgressStats(int $roomTaskId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->func()->count('*'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('is_checked', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

        $result = $qb->executeQuery();
        $checkedCount = (int) $result->fetchOne();

        $total = $this->countByRoomTaskId($roomTaskId);

        return [
            'total' => $total,
            'checked' => $checkedCount,
            'unchecked' => $total - $checkedCount,
            'percentage' => $total > 0 ? round(($checkedCount / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Count progress entries for a room task
     *
     * @param int $roomTaskId
     */
    public function countByRoomTaskId(int $roomTaskId): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->func()->count('*'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        return (int) $result->fetchOne();
    }

    /**
     * Reset all progress for a room task (when task is reset/reopened)
     *
     * @param int $roomTaskId
     */
    public function resetProgress(int $roomTaskId): void
    {
        $qb = $this->db->getQueryBuilder();

        $qb->update($this->getTableName())
            ->set('is_checked', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
            ->set('checked_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->set('checked_by_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * Delete all progress entries for a room task
     *
     * @param int $roomTaskId
     */
    public function deleteByRoomTaskId(int $roomTaskId): void
    {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }
}
