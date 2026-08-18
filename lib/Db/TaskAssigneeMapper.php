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
 * @template-extends QBMapper<TaskAssignee>
 */
class TaskAssigneeMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_task_assignees', TaskAssignee::class);
	}

	/**
	 * Get all assignees for a room task
	 *
	 * @param int $roomTaskId The room task ID
	 * @return TaskAssignee[]
	 */
	public function findByRoomTask(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->orderBy('assigned_at', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Get the Nextcloud user IDs assigned to a room task
	 *
	 * @param int $roomTaskId The room task ID
	 * @return string[] List of Nextcloud user IDs
	 */
	public function findUserIdsByRoomTask(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('user_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$userIds = [];
		while ($row = $result->fetch()) {
			$userIds[] = (string)$row['user_id'];
		}
		$result->closeCursor();

		return $userIds;
	}

	/**
	 * Get the Nextcloud user IDs assigned to multiple room tasks in one query.
	 *
	 * @param int[] $roomTaskIds The room task IDs
	 * @return array<int, string[]> Map of room task ID => list of Nextcloud user IDs
	 */
	public function findUserIdsByRoomTasks(array $roomTaskIds): array {
		$result = array_fill_keys($roomTaskIds, []);
		if (empty($roomTaskIds)) {
			return $result;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('room_task_id', 'user_id')
			->from($this->getTableName())
			->where($qb->expr()->in('room_task_id', $qb->createNamedParameter($roomTaskIds, IQueryBuilder::PARAM_INT_ARRAY)));

		$res = $qb->executeQuery();
		while ($row = $res->fetch()) {
			$roomTaskId = (int)$row['room_task_id'];
			$result[$roomTaskId][] = (string)$row['user_id'];
		}
		$res->closeCursor();

		return $result;
	}

	/**
	 * Merge the legacy single assignee (room_tasks.assigned_to_user_id) into a
	 * list of join-table assignee user IDs.
	 *
	 * Centralized so the completion gate, the detail payload, and the list
	 * payload compute the same effective assignee set and cannot drift.
	 *
	 * @param string[] $userIds Join-table assignee user IDs
	 * @param string|null $legacyAssignee Legacy room_tasks.assigned_to_user_id
	 * @return string[] Unique merged user IDs
	 */
	public static function mergeLegacyAssignee(array $userIds, ?string $legacyAssignee): array {
		if ($legacyAssignee !== null && $legacyAssignee !== '' && !in_array($legacyAssignee, $userIds, true)) {
			$userIds[] = $legacyAssignee;
		}

		return $userIds;
	}

	/**
	 * Check if a user is assigned to a room task
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string $userId The Nextcloud user ID
	 */
	public function isAssignee(int $roomTaskId, string $userId): bool {
		$qb = $this->db->getQueryBuilder();

		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		return $result->fetchOne() !== false;
	}

	/**
	 * Add an assignee to a room task
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string $userId The Nextcloud user ID
	 * @param string|null $assignedBy The Nextcloud user ID who assigned (or null)
	 */
	public function addAssignee(int $roomTaskId, string $userId, ?string $assignedBy = null): TaskAssignee {
		$assignee = new TaskAssignee();
		$assignee->setRoomTaskId($roomTaskId);
		$assignee->setUserId($userId);
		$assignee->setAssignedBy($assignedBy);
		$assignee->setAssignedAt(new \DateTime());

		return $this->insert($assignee);
	}

	/**
	 * Remove an assignee from a room task
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string $userId The Nextcloud user ID
	 */
	public function removeAssignee(int $roomTaskId, string $userId): void {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$qb->executeStatement();
	}

	/**
	 * Delete all assignees for a room task
	 *
	 * @param int $roomTaskId The room task ID
	 */
	public function deleteByRoomTask(int $roomTaskId): void {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Delete all assignee rows of a removed member, scoped to one house
	 * (CR-SVC-26).
	 *
	 * `DELETE FROM task_assignees WHERE user_id = :user AND room_task_id IN
	 * (house-scoped room_tasks → rooms → floors subquery)` — built with the
	 * query-builder subquery pattern (the house parameter is bound on the
	 * OUTER builder and its placeholder embedded into the subquery SQL, so
	 * the parameter is bound exactly once where execution happens). No raw
	 * SQL.
	 *
	 * Called transactionally by HouseService::removeHouseMember so a
	 * member removal leaves no orphaned assignee references behind.
	 *
	 * @param string $ncUserId The Nextcloud user ID of the removed member
	 * @param int $houseId The house ID scope
	 */
	public function deleteByUserForHouse(string $ncUserId, int $houseId): void {
		$qb = $this->db->getQueryBuilder();
		$houseIdParam = $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT);

		$roomTasksInHouse = $this->db->getQueryBuilder();
		$roomTasksInHouse->select('rt.id')
			->from('chorestrike_room_tasks', 'rt')
			->innerJoin('rt', 'chorestrike_rooms', 'r', $roomTasksInHouse->expr()->eq('rt.room_id', 'r.id'))
			->innerJoin('r', 'chorestrike_floors', 'f', $roomTasksInHouse->expr()->eq('r.floor_id', 'f.id'))
			->where($roomTasksInHouse->expr()->eq('f.house_id', $houseIdParam));

		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($ncUserId)))
			->andWhere($qb->expr()->in('room_task_id', $qb->createFunction($roomTasksInHouse->getSQL())));

		$qb->executeStatement();
	}

	/**
	 * Replace the full set of assignees for a room task
	 *
	 * Removes existing assignees and inserts the provided list. Duplicate user
	 * IDs in the input are collapsed. The unique DB constraint also protects
	 * against duplicates.
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string[] $userIds List of Nextcloud user IDs to assign
	 * @param string|null $assignedBy The Nextcloud user ID who assigned (or null)
	 */
	public function replaceAssignees(int $roomTaskId, array $userIds, ?string $assignedBy = null): void {
		$uniqueIds = array_values(array_unique(array_filter($userIds, fn ($id) => is_string($id) && trim($id) !== '')));

		// Atomic delete-then-insert: a failure mid-replace must not leave the
		// task with a partial assignee set and the previous assignees removed.
		$this->db->beginTransaction();
		try {
			$this->deleteByRoomTask($roomTaskId);
			foreach ($uniqueIds as $userId) {
				$this->addAssignee($roomTaskId, $userId, $assignedBy);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}
