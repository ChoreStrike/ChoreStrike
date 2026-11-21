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
		// CS-PLAN-072 WP1 keyed progress by the occurrence public key
		// (schedule_rev_id, ordinal) + item. Since ADR-021 the ticks live
		// per cycle (CycleProgressMapper); these rows are retained, only
		// cleaned up with their room task and anonymized.
		parent::__construct($db, 'chorestrike_chk_progress', RoomTaskChecklistProgress::class);
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

	/** Account erasure: the retained ticks stay, the member who ticked goes. */
	public function anonymizeChecker(int $houseUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('checked_by_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('checked_by_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
