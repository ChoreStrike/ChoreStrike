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
 * Sub-task ticks per cycle (ADR-021 decision 3): keyed by
 * `(room_task_id, cycle_key, checklist_item_id)`; each row carries a
 * progress revision so a stale tick conflicts instead of landing in a
 * later cycle.
 *
 * @template-extends QBMapper<CycleProgress>
 */
class CycleProgressMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_cycle_progress', CycleProgress::class);
	}

	/**
	 * @return CycleProgress[]
	 */
	public function findByCycle(int $roomTaskId, string $cycleKey): array {
		return $this->findEntities($this->cycleQuery($roomTaskId, $cycleKey));
	}

	/**
	 * Find one tick and acquire an exclusive row lock (SQLite-tolerant,
	 * ADR-007 pattern). Null when the row does not exist. Must be called
	 * inside an open transaction.
	 */
	public function findForUpdate(int $roomTaskId, string $cycleKey, int $checklistItemId): ?CycleProgress {
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
			// No FOR UPDATE on SQLite: a self-assignment UPDATE takes the
			// database-wide write lock; the re-read sees the committed state.
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('is_checked', $qb->createFunction('is_checked'))
				->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('cycle_key', $qb->createNamedParameter($cycleKey)))
				->andWhere($qb->expr()->eq('checklist_item_id', $qb->createNamedParameter($checklistItemId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$rows = $this->findEntities($this->itemQuery($roomTaskId, $cycleKey, $checklistItemId));
			return $rows[0] ?? null;
		}

		$result = $this->db->executeQuery(
			'SELECT * FROM *PREFIX*' . $this->getTableName()
			. ' WHERE room_task_id = :roomTaskId AND cycle_key = :cycleKey AND checklist_item_id = :itemId FOR UPDATE',
			['roomTaskId' => $roomTaskId, 'cycleKey' => $cycleKey, 'itemId' => $checklistItemId],
			['roomTaskId' => IQueryBuilder::PARAM_INT, 'cycleKey' => IQueryBuilder::PARAM_STR, 'itemId' => IQueryBuilder::PARAM_INT]
		);
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $this->mapRowToEntity($row);
	}

	/**
	 * Toggle one item of one cycle (locked read-modify-write) and advance
	 * the row's revision. The caller owns the house-first transaction. The
	 * first tick tolerates the unique-constraint race: it re-reads the
	 * winner's row and flips it.
	 */
	public function toggleLocked(int $roomTaskId, string $cycleKey, int $checklistItemId, ?int $userId): CycleProgress {
		$progress = $this->findForUpdate($roomTaskId, $cycleKey, $checklistItemId);
		if ($progress !== null) {
			return $this->update($this->flip($progress, $userId));
		}

		$progress = new CycleProgress();
		$progress->setRoomTaskId($roomTaskId);
		$progress->setCycleKey($cycleKey);
		$progress->setChecklistItemId($checklistItemId);
		$progress->setIsChecked(true);
		$progress->setCheckedAt(new \DateTime());
		$progress->setCheckedByUserId($userId);
		$progress->setRevision(1);

		// PostgreSQL aborts the transaction on a duplicate insert: isolate
		// only this speculative write and keep the caller's aggregate.
		$this->db->executeStatement('SAVEPOINT cs_cycle_tick');
		try {
			$progress = $this->insert($progress);
		} catch (\OCP\DB\Exception $insertException) {
			if ($insertException->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $insertException;
			}
			$this->db->executeStatement('ROLLBACK TO SAVEPOINT cs_cycle_tick');
			$winner = $this->findForUpdate($roomTaskId, $cycleKey, $checklistItemId);
			if ($winner === null) {
				throw $insertException;
			}
			$progress = $this->update($this->flip($winner, $userId));
		}
		$this->db->executeStatement('RELEASE SAVEPOINT cs_cycle_tick');
		return $progress;
	}

	/**
	 * @return array{total: int, checked: int, unchecked: int, percentage: float|int}
	 */
	public function progressStats(int $roomTaskId, string $cycleKey, int $itemCount): array {
		$checked = count(array_filter(
			$this->findByCycle($roomTaskId, $cycleKey),
			static fn (CycleProgress $row): bool => $row->getIsChecked()
		));
		$checked = min($checked, $itemCount);
		return [
			'total' => $itemCount,
			'checked' => $checked,
			'unchecked' => max(0, $itemCount - $checked),
			'percentage' => $itemCount > 0 ? round(($checked / $itemCount) * 100, 1) : 0,
		];
	}

	/**
	 * Delete all ticks of the given room tasks (tombstone and test cleanup).
	 *
	 * @param int[] $roomTaskIds
	 */
	public function deleteByRoomTaskIds(array $roomTaskIds): void {
		foreach (array_chunk(array_values(array_map('intval', $roomTaskIds)), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->in('room_task_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}

	/** Account erasure: the ticks stay as shared facts, the member who ticked goes. */
	public function anonymizeChecker(int $houseUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('checked_by_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('checked_by_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private function flip(CycleProgress $progress, ?int $userId): CycleProgress {
		$progress->setIsChecked(!$progress->getIsChecked());
		$progress->setCheckedAt($progress->getIsChecked() ? new \DateTime() : null);
		$progress->setCheckedByUserId($progress->getIsChecked() ? $userId : null);
		$progress->setRevision($progress->getRevision() + 1);
		return $progress;
	}

	private function cycleQuery(int $roomTaskId, string $cycleKey): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		return $qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('cycle_key', $qb->createNamedParameter($cycleKey)));
	}

	private function itemQuery(int $roomTaskId, string $cycleKey, int $checklistItemId): IQueryBuilder {
		$qb = $this->cycleQuery($roomTaskId, $cycleKey);
		return $qb->andWhere($qb->expr()->eq('checklist_item_id', $qb->createNamedParameter($checklistItemId, IQueryBuilder::PARAM_INT)));
	}
}
