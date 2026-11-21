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
 * @template-extends QBMapper<ScheduleRevision>
 */
class ScheduleRevisionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_schedule_revs', ScheduleRevision::class);
	}

	/**
	 * All revisions of a set of room tasks (one batched query).
	 *
	 * @param int[] $roomTaskIds
	 * @return ScheduleRevision[]
	 */
	public function findByRoomTasks(array $roomTaskIds): array {
		$roomTaskIds = array_values(array_map('intval', $roomTaskIds));
		if ($roomTaskIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('room_task_id', $qb->createNamedParameter($roomTaskIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('room_task_id', 'ASC')
			->addOrderBy('revision', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Anonymize a wallet's user references inside every default-membership
	 * snapshot of this house (account erasure): the numeric wallet fact
	 * stays (non-reidentifiable), the account binding is stripped.
	 */
	public function anonymizeDefaultMembers(int $houseId, int $walletId): void {
		$roomTaskIds = $this->roomTaskIdsOfHouse($houseId);
		if ($roomTaskIds === []) {
			return;
		}
		foreach ($this->findByRoomTasks($roomTaskIds) as $revision) {
			$members = $revision->getDefaultMembers();
			if (!is_array($members)) {
				continue;
			}
			$changed = false;
			foreach ($members as $index => $member) {
				if (is_array($member) && (int)($member['walletId'] ?? 0) === $walletId) {
					$members[$index]['userId'] = null;
					$changed = true;
				}
			}
			if ($changed) {
				$revision->setDefaultMembers($members);
				$this->update($revision);
			}
		}
	}

	/**
	 * @return int[]
	 */
	private function roomTaskIdsOfHouse(int $houseId): array {
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('rt.id')
			->from('chorestrike_room_tasks', 'rt')
			->innerJoin('rt', 'chorestrike_rooms', 'r', 'rt.room_id = r.id')
			->innerJoin('r', 'chorestrike_floors', 'f', 'r.floor_id = f.id')
			->where($qb->expr()->eq('f.house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->executeQuery()
			->fetchAll();
		return array_map(static fn (array $row): int => (int)$row['id'], $rows);
	}
}
