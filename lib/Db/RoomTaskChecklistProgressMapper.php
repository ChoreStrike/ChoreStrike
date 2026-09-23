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
	) {
		// Table name fixed to the migration chain (CS-PLAN-037): the
		// schema creates chorestrike_chk_progress (Version1005) — the
		// previously referenced chorestrike_room_task_checklist_progress
		// never existed, so every real-DB call 500'd.
		//
		// CS-PLAN-072 WP1: progress is keyed by the occurrence public key
		// (schedule_rev_id, ordinal) + item; each row carries a progress
		// revision so stale mutations conflict (409) instead of leaking
		// into a later occurrence.
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
	 * All progress rows of a room task (any occurrence)
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
	 * Progress rows of one occurrence (its public key parts).
	 *
	 * @return RoomTaskChecklistProgress[]
	 */
	public function findByOccurrence(int $roomTaskId, int $scheduleRevId, int $ordinal): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('schedule_rev_id', $qb->createNamedParameter($scheduleRevId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('ordinal', $qb->createNamedParameter($ordinal, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	/**
	 * Get progress for a specific item of one occurrence
	 *
	 * @throws DoesNotExistException
	 */
	public function findByOccurrenceAndItem(int $roomTaskId, int $scheduleRevId, int $ordinal, int $checklistItemId): RoomTaskChecklistProgress {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('schedule_rev_id', $qb->createNamedParameter($scheduleRevId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('ordinal', $qb->createNamedParameter($ordinal, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('checklist_item_id', $qb->createNamedParameter($checklistItemId, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Find a progress row by occurrence key + item and acquire an
	 * exclusive row lock (SQLite-tolerant, ADR-007 pattern). Returns null
	 * when the row does not exist. Must be called inside an open
	 * transaction.
	 */
	public function findForUpdate(int $roomTaskId, int $scheduleRevId, int $ordinal, int $checklistItemId): ?RoomTaskChecklistProgress {
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
			// No FOR UPDATE on SQLite: a no-op self-assignment UPDATE
			// (writes is_checked's own current value) takes the database-wide
			// write lock; the re-read then sees the latest committed state.
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('is_checked', $qb->createFunction('is_checked'))
				->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('schedule_rev_id', $qb->createNamedParameter($scheduleRevId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('ordinal', $qb->createNamedParameter($ordinal, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('checklist_item_id', $qb->createNamedParameter($checklistItemId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();

			try {
				return $this->findByOccurrenceAndItem($roomTaskId, $scheduleRevId, $ordinal, $checklistItemId);
			} catch (DoesNotExistException $e) {
				return null;
			}
		}

		$sql = 'SELECT * FROM *PREFIX*' . $this->getTableName()
			. ' WHERE room_task_id = :roomTaskId AND schedule_rev_id = :revId AND ordinal = :ordinal'
			. ' AND checklist_item_id = :itemId FOR UPDATE';
		$result = $this->db->executeQuery(
			$sql,
			[
				'roomTaskId' => $roomTaskId,
				'revId' => $scheduleRevId,
				'ordinal' => $ordinal,
				'itemId' => $checklistItemId,
			],
			[
				'roomTaskId' => IQueryBuilder::PARAM_INT,
				'revId' => IQueryBuilder::PARAM_INT,
				'ordinal' => IQueryBuilder::PARAM_INT,
				'itemId' => IQueryBuilder::PARAM_INT,
			]
		);
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			return null;
		}

		return $this->mapRowToEntity($row);
	}

	/**
	 * Toggle one item of one occurrence (locked read-modify-write) and
	 * advance the row's progress revision. CS-PLAN-072 WP1: the owning
	 * transaction is the caller's house-first aggregate; progress never
	 * crosses occurrence boundaries. The insert path is tolerant of the
	 * unique-constraint race (re-read the winner's row and flip it).
	 */
	public function toggleLocked(int $roomTaskId, int $scheduleRevId, int $ordinal, int $checklistItemId, ?int $userId): RoomTaskChecklistProgress {
		$progress = $this->findForUpdate($roomTaskId, $scheduleRevId, $ordinal, $checklistItemId);

		if ($progress !== null) {
			$progress->setIsChecked(!$progress->getIsChecked());
			$progress->setCheckedAt($progress->getIsChecked() ? new \DateTime() : null);
			$progress->setCheckedByUserId($progress->getIsChecked() ? $userId : null);
			$progress->setRevision($progress->getRevision() + 1);

			return $this->update($progress);
		}

		// Create new checked entry
		$progress = new RoomTaskChecklistProgress();
		$progress->setRoomTaskId($roomTaskId);
		$progress->setScheduleRevId($scheduleRevId);
		$progress->setOrdinal($ordinal);
		$progress->setChecklistItemId($checklistItemId);
		$progress->setIsChecked(true);
		$progress->setCheckedAt(new \DateTime());
		$progress->setCheckedByUserId($userId);
		$progress->setRevision(1);

		try {
			return $this->insert($progress);
		} catch (\OCP\DB\Exception $insertException) {
			if ($insertException->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $insertException;
			}
			// Lost the unique-constraint race against a concurrent first
			// toggle for the same occurrence item: that row must now exist
			// — re-read and flip it.
			$progress = $this->findByOccurrenceAndItem($roomTaskId, $scheduleRevId, $ordinal, $checklistItemId);
			$progress->setIsChecked(!$progress->getIsChecked());
			$progress->setCheckedAt($progress->getIsChecked() ? new \DateTime() : null);
			$progress->setCheckedByUserId($progress->getIsChecked() ? $userId : null);
			$progress->setRevision($progress->getRevision() + 1);

			return $this->update($progress);
		}
	}

	/**
	 * Progress statistics for one occurrence
	 *
	 * @return array
	 */
	public function getOccurrenceProgressStats(int $roomTaskId, int $scheduleRevId, int $ordinal, int $itemCount): array {
		$checkedCount = 0;
		foreach ($this->findByOccurrence($roomTaskId, $scheduleRevId, $ordinal) as $row) {
			if ($row->getIsChecked()) {
				$checkedCount++;
			}
		}

		return [
			'total' => $itemCount,
			'checked' => min($checkedCount, $itemCount),
			'unchecked' => max(0, $itemCount - $checkedCount),
			'percentage' => $itemCount > 0 ? round((min($checkedCount, $itemCount) / $itemCount) * 100, 1) : 0,
		];
	}

	/**
	 * Delete all progress entries of the given room tasks in one statement
	 * (tombstone/test cleanup cascade).
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
