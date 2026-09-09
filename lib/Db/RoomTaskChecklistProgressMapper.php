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
 * @template-extends QBMapper<RoomTaskChecklistProgress>
 */
class RoomTaskChecklistProgressMapper extends QBMapper {
	public function __construct(
		IDBConnection $db,
		private TransactionExecutor $transactions,
	) {
		// Table name fixed to the migration chain (CS-PLAN-037): the
		// schema creates chorestrike_chk_progress (Version1005) — the
		// previously referenced chorestrike_room_task_checklist_progress
		// never existed, so every real-DB call 500'd.
		parent::__construct($db, 'chorestrike_chk_progress', RoomTaskChecklistProgress::class);
	}

	/**
	 * Find progress entry by ID
	 *
	 * @param int $id
	 * @throws DoesNotExistException
	 */
	public function find(int $id): RoomTaskChecklistProgress {
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
	public function findByRoomTaskId(int $roomTaskId): array {
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
	public function findByRoomTaskAndItem(int $roomTaskId, int $checklistItemId): RoomTaskChecklistProgress {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('checklist_item_id', $qb->createNamedParameter($checklistItemId, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Find a progress row by (room task, item) and acquire an exclusive row
	 * lock (SQLite-tolerant, ADR-007 pattern). Returns null when the row
	 * does not exist. Must be called inside an open transaction.
	 *
	 * @param int $roomTaskId
	 * @param int $checklistItemId
	 */
	public function findForUpdate(int $roomTaskId, int $checklistItemId): ?RoomTaskChecklistProgress {
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
			// No FOR UPDATE on SQLite: a no-op self-assignment UPDATE
			// (writes is_checked's own current value) takes the database-wide
			// write lock; the re-read then sees the latest committed state.
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('is_checked', $qb->createFunction('is_checked'))
				->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('checklist_item_id', $qb->createNamedParameter($checklistItemId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();

			try {
				return $this->findByRoomTaskAndItem($roomTaskId, $checklistItemId);
			} catch (DoesNotExistException $e) {
				return null;
			}
		}

		$sql = 'SELECT * FROM *PREFIX*' . $this->getTableName()
			. ' WHERE room_task_id = :roomTaskId AND checklist_item_id = :itemId FOR UPDATE';
		$result = $this->db->executeQuery(
			$sql,
			['roomTaskId' => $roomTaskId, 'itemId' => $checklistItemId],
			['roomTaskId' => IQueryBuilder::PARAM_INT, 'itemId' => IQueryBuilder::PARAM_INT]
		);
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			return null;
		}

		return $this->mapRowToEntity($row);
	}

	/**
	 * Toggle checklist item status
	 *
	 * Mapper-owned locked read-modify-write (CS-PLAN-060 §3.6, CR-053-19):
	 * the whole toggle runs in one TransactionExecutor transaction and the
	 * existing row (if any) is locked before the flip, so two concurrent
	 * toggles serialize and no update is lost. The insert path is tolerant
	 * of the unique-constraint race: two first-toggles for the same
	 * (room_task, item) — one re-reads the winner's row inside the
	 * transaction and flips it.
	 *
	 * @param int $roomTaskId
	 * @param int $checklistItemId
	 * @param int|null $userId User who checked/unchecked (null if unchecking)
	 */
	public function toggle(int $roomTaskId, int $checklistItemId, ?int $userId): RoomTaskChecklistProgress {
		return $this->transactions->run(function () use ($roomTaskId, $checklistItemId, $userId) {
			$progress = $this->findForUpdate($roomTaskId, $checklistItemId);

			if ($progress !== null) {
				// Toggle the existing entry
				$progress->setIsChecked(!$progress->getIsChecked());
				$progress->setCheckedAt($progress->getIsChecked() ? new \DateTime() : null);
				$progress->setCheckedByUserId($progress->getIsChecked() ? $userId : null);

				return $this->update($progress);
			}

			// Create new checked entry
			$progress = new RoomTaskChecklistProgress();
			$progress->setRoomTaskId($roomTaskId);
			$progress->setChecklistItemId($checklistItemId);
			$progress->setIsChecked(true);
			$progress->setCheckedAt(new \DateTime());
			$progress->setCheckedByUserId($userId);

			try {
				return $this->insert($progress);
			} catch (\OCP\DB\Exception $insertException) {
				if ($insertException->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $insertException;
				}
				// Lost the unique-constraint race against a concurrent first
				// toggle for the same (room_task, item): that row must now
				// exist (its transaction committed before ours could hit the
				// constraint) — re-read and flip it.
				$progress = $this->findByRoomTaskAndItem($roomTaskId, $checklistItemId);
				$progress->setIsChecked(!$progress->getIsChecked());
				$progress->setCheckedAt($progress->getIsChecked() ? new \DateTime() : null);
				$progress->setCheckedByUserId($progress->getIsChecked() ? $userId : null);

				return $this->update($progress);
			}
		});
	}

	/**
	 * Get progress statistics for a room task
	 *
	 * @param int $roomTaskId
	 * @return array
	 */
	public function getProgressStats(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_checked', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		$result = $qb->executeQuery();
		$checkedCount = (int)$result->fetchOne();

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
	public function countByRoomTaskId(int $roomTaskId): int {
		$qb = $this->db->getQueryBuilder();

		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		return (int)$result->fetchOne();
	}

	/**
	 * Reset all progress for a room task (when task is reset/reopened)
	 *
	 * @param int $roomTaskId
	 */
	public function resetProgress(int $roomTaskId): void {
		$qb = $this->db->getQueryBuilder();

		$qb->update($this->getTableName())
			->set('is_checked', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->set('checked_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('checked_by_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Restore snapshotted progress rows after an undo (CS-PLAN-064 WP5).
	 *
	 * Re-inserts the recorded (checklistItemId, userId, checked, checkedAt)
	 * rows captured before the completion reset. Rows are plain re-inserts
	 * inside the caller's transaction — the undo aggregate holds the room
	 * task lock, so no concurrent toggle can interleave.
	 *
	 * @param int $roomTaskId The room task ID
	 * @param array<int, array{checklistItemId: int, userId: int|null, checked: bool, checkedAt?: string|null}> $rows
	 */
	public function restoreProgress(int $roomTaskId, array $rows): void {
		foreach ($rows as $row) {
			$progress = new RoomTaskChecklistProgress();
			$progress->setRoomTaskId($roomTaskId);
			$progress->setChecklistItemId((int)$row['checklistItemId']);
			$progress->setIsChecked((bool)($row['checked'] ?? false));
			$checkedAt = $row['checkedAt'] ?? null;
			$progress->setCheckedAt($checkedAt !== null && $checkedAt !== '' ? new \DateTime($checkedAt) : null);
			$progress->setCheckedByUserId($row['userId'] ?? null);

			try {
				$this->insert($progress);
			} catch (\OCP\DB\Exception $e) {
				if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				// Row re-created between the aggregate's delete and this
				// insert (defensive; impossible under the room-task lock):
				// the row exists, keep the snapshotted state authoritative.
				$existing = $this->findByRoomTaskAndItem($roomTaskId, (int)$row['checklistItemId']);
				$existing->setIsChecked((bool)($row['checked'] ?? false));
				$existing->setCheckedAt($checkedAt !== null && $checkedAt !== '' ? new \DateTime($checkedAt) : null);
				$existing->setCheckedByUserId($row['userId'] ?? null);
				$this->update($existing);
			}
		}
	}

	/**
	 * Delete all progress entries of the given room tasks in one statement
	 * (CS-PLAN-037 cascade; plural sibling of deleteByRoomTaskId).
	 *
	 * @param int[] $roomTaskIds The room task IDs
	 */
	public function deleteByRoomTaskIds(array $roomTaskIds): void {
		$roomTaskIds = array_values(array_map('intval', $roomTaskIds));
		if ($roomTaskIds === []) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->in('room_task_id', $qb->createNamedParameter($roomTaskIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}

	/**
	 * Delete all progress entries for a room task
	 *
	 * @param int $roomTaskId
	 */
	public function deleteByRoomTaskId(int $roomTaskId): void {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
