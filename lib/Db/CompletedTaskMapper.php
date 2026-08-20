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
 * Mapper for CompletedTask entity
 */
class CompletedTaskMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_completed_tasks', CompletedTask::class);
	}

	/**
	 * Find completed task by ID
	 *
	 * @param int $id The completed task ID
	 * @throws DoesNotExistException
	 */
	public function find(int $id): CompletedTask {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Find all completed tasks for a user
	 *
	 * @param int $userId The house user ID
	 * @return CompletedTask[]
	 */
	public function findByUserId(int $userId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_INT)))
			->orderBy('completed_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find recent completions (within last N minutes)
	 *
	 * @param int $userId The house user ID
	 * @param int $minutes Number of minutes to look back
	 * @return CompletedTask[]
	 */
	public function findRecentByUserId(int $userId, int $minutes = 5): array {
		$qb = $this->db->getQueryBuilder();
		$cutoffDate = new \DateTime("-{$minutes} minutes");

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter($cutoffDate, IQueryBuilder::PARAM_DATE)))
			->orderBy('completed_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find completions for a specific room task
	 *
	 * @param int $roomTaskId The room task ID
	 * @return CompletedTask[]
	 */
	public function findByRoomTaskId(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->orderBy('completed_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Get user statistics for a date range.
	 *
	 * Returns task count, total coins, combo count, and the number of
	 * distinct active days in one query (same table / range, no JOIN) so
	 * the medal-check loop makes a single round trip per user instead of
	 * two. DATE() is portable across MariaDB, PostgreSQL, and SQLite.
	 *
	 * @param int $userId The house user ID
	 * @param \DateTime $startDate Start date
	 * @param \DateTime $endDate End date
	 * @return array{taskCount:int, totalCoins:int, comboCount:int, activeDays:int}
	 */
	public function getUserStats(int $userId, \DateTime $startDate, \DateTime $endDate): array {
		$qb = $this->db->getQueryBuilder();

		// Aggregate expressions must go through createFunction — the raw
		// strings would otherwise be quoted as column names and fail on the
		// real DB (exposed by the CS-PLAN-036 equivalence integration test).
		$qb->select($qb->createFunction('COUNT(*) AS task_count'))
			->addSelect($qb->createFunction('SUM(coins_earned) AS total_coins'))
			->addSelect($qb->createFunction('SUM(CASE WHEN was_combo = 1 THEN 1 ELSE 0 END) AS combo_count'))
			->addSelect($qb->createFunction('COUNT(DISTINCT DATE(completed_at)) AS active_days'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter($startDate, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lte('completed_at', $qb->createNamedParameter($endDate, IQueryBuilder::PARAM_DATE)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return [
			'taskCount' => (int)($row['task_count'] ?? 0),
			'totalCoins' => (int)($row['total_coins'] ?? 0),
			'comboCount' => (int)($row['combo_count'] ?? 0),
			'activeDays' => (int)($row['active_days'] ?? 0),
		];
	}

	/**
	 * Get weekly statistics for ALL users of a house in one grouped query (CS-PLAN-036).
	 *
	 * Grouped sibling of getUserStats: same aggregates and DATE() portability,
	 * but GROUP BY user_id over the house's users (joined via
	 * chorestrike_house_users.house_id) so the medal check makes one round
	 * trip instead of one per user. Users without completions in the range
	 * are absent from the map — callers treat a missing key as all-zero
	 * stats (equivalent to getUserStats on an empty range).
	 *
	 * @param int $houseId The house ID
	 * @param \DateTime $startDate Start date
	 * @param \DateTime $endDate End date
	 * @return array<int, array{taskCount:int, totalCoins:int, comboCount:int, activeDays:int}> Map of house user ID → stats
	 */
	public function getUserStatsByHouse(int $houseId, \DateTime $startDate, \DateTime $endDate): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('ct.user_id')
			->addSelect($qb->createFunction('COUNT(*) AS task_count'))
			->addSelect($qb->createFunction('SUM(ct.coins_earned) AS total_coins'))
			->addSelect($qb->createFunction('SUM(CASE WHEN ct.was_combo = 1 THEN 1 ELSE 0 END) AS combo_count'))
			->addSelect($qb->createFunction('COUNT(DISTINCT DATE(ct.completed_at)) AS active_days'))
			->from($this->getTableName(), 'ct')
			->innerJoin('ct', 'chorestrike_house_users', 'hu', $qb->expr()->eq('ct.user_id', 'hu.id'))
			->where($qb->expr()->eq('hu.house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('ct.completed_at', $qb->createNamedParameter($startDate, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lte('ct.completed_at', $qb->createNamedParameter($endDate, IQueryBuilder::PARAM_DATE)))
			->groupBy('ct.user_id');

		$result = $qb->executeQuery();
		$stats = [];
		while ($row = $result->fetch()) {
			$stats[(int)$row['user_id']] = [
				'taskCount' => (int)($row['task_count'] ?? 0),
				'totalCoins' => (int)($row['total_coins'] ?? 0),
				'comboCount' => (int)($row['combo_count'] ?? 0),
				'activeDays' => (int)($row['active_days'] ?? 0),
			];
		}
		$result->closeCursor();

		return $stats;
	}

	/**
	 * Get total consumed energy for a user on a specific date.
	 *
	 * @param int $userId The house user ID
	 * @param \DateTimeInterface $date Date used to determine day boundaries
	 * @return int Total consumed energy points
	 */
	public function getConsumedEnergyForUserOnDate(int $userId, \DateTimeInterface $date): int {
		$startOfDay = new \DateTimeImmutable($date->format('Y-m-d') . ' 00:00:00');
		$endOfDay = $startOfDay->modify('+1 day');

		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COALESCE(SUM(t.energy_cost), 0)'), 'consumed_energy')
			->from($this->getTableName(), 'ct')
			->innerJoin('ct', 'chorestrike_room_tasks', 'rt', $qb->expr()->eq('ct.room_task_id', 'rt.id'))
			->innerJoin('rt', 'chorestrike_tasks', 't', $qb->expr()->eq('rt.task_id', 't.id'))
			->where($qb->expr()->eq('ct.user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('ct.completed_at', $qb->createNamedParameter($startOfDay, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lt('ct.completed_at', $qb->createNamedParameter($endOfDay, IQueryBuilder::PARAM_DATE)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['consumed_energy'] ?? 0);
	}

	/**
	 * Get all completions in a date range for combo detection
	 *
	 * @param \DateTime $startDate Start date
	 * @param \DateTime $endDate End date
	 * @return CompletedTask[]
	 */
	public function findInDateRange(\DateTime $startDate, \DateTime $endDate): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->gte('completed_at', $qb->createNamedParameter($startDate, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lte('completed_at', $qb->createNamedParameter($endDate, IQueryBuilder::PARAM_DATE)))
			->orderBy('completed_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find latest completion for a room task
	 *
	 * @param int $roomTaskId The room task ID
	 * @return CompletedTask|null The latest completion or null
	 */
	public function findLatestByRoomTask(int $roomTaskId): ?CompletedTask {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->orderBy('completed_at', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Find completions pending sync to Nextcloud
	 *
	 * @param int $limit Maximum number of records
	 * @return CompletedTask[] Pending completions
	 */
	public function findPendingSyncs(int $limit = 50): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('synced_to_nextcloud', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->isNotNull('nextcloud_task_id'))
			->orderBy('completed_at', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * Find completions pending sync to Nextcloud, scoped to one house (CS-PLAN-036).
	 *
	 * House-scoped JOIN sibling of findPendingSyncs (completed_tasks has no
	 * denormalized house_id column): completed_tasks → room_tasks → rooms →
	 * floors on floors.house_id. Replaces the per-row ownership chain
	 * (3 queries × N rows) plus the limit×5 overfetch in
	 * TaskSyncService::getPendingSyncsForHouse with one bounded query.
	 *
	 * @param int $houseId The house ID
	 * @param int $limit Maximum number of records
	 * @return CompletedTask[] Pending completions of the house, completed_at ASC
	 */
	public function findPendingSyncsForHouse(int $houseId, int $limit): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('ct.*')
			->from($this->getTableName(), 'ct')
			->innerJoin('ct', 'chorestrike_room_tasks', 'rt', $qb->expr()->eq('ct.room_task_id', 'rt.id'))
			->innerJoin('rt', 'chorestrike_rooms', 'r', $qb->expr()->eq('rt.room_id', 'r.id'))
			->innerJoin('r', 'chorestrike_floors', 'f', $qb->expr()->eq('r.floor_id', 'f.id'))
			->where($qb->expr()->eq('f.house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('ct.synced_to_nextcloud', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->isNotNull('ct.nextcloud_task_id'))
			->orderBy('ct.completed_at', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * Count completions of a room task by a house user on a specific calendar day.
	 *
	 * Used by the duplicate-completion guard to prevent the same user from
	 * earning coins twice for the same room task on the same day.
	 *
	 * @param int $roomTaskId The room task ID
	 * @param int $houseUserId The house user ID (internal)
	 * @param \DateTimeInterface $date Date used to determine day boundaries
	 * @return int Number of completions on that day
	 */
	public function countByRoomTaskAndUserOnDate(int $roomTaskId, int $houseUserId, \DateTimeInterface $date): int {
		$startOfDay = new \DateTimeImmutable($date->format('Y-m-d') . ' 00:00:00');
		$endOfDay = $startOfDay->modify('+1 day');

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter($startOfDay, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lt('completed_at', $qb->createNamedParameter($endOfDay, IQueryBuilder::PARAM_DATE)));

		$result = $qb->executeQuery();
		return (int)$result->fetchOne();
	}

	/**
	 * Count all completions by a house user on a specific calendar day.
	 *
	 * @param int $houseUserId The house user ID
	 * @param \DateTimeInterface $date Date used to determine day boundaries
	 * @return int Number of completions on that day
	 */
	public function countByUserOnDate(int $houseUserId, \DateTimeInterface $date): int {
		$startOfDay = new \DateTimeImmutable($date->format('Y-m-d') . ' 00:00:00');
		$endOfDay = $startOfDay->modify('+1 day');

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter($startOfDay, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lt('completed_at', $qb->createNamedParameter($endOfDay, IQueryBuilder::PARAM_DATE)));

		$result = $qb->executeQuery();
		return (int)$result->fetchOne();
	}

	/**
	 * Build a map of houseUserId → completion count for a specific day.
	 *
	 * Used by the DailyNotificationJob to avoid an N+1 of per-user COUNTs
	 * (the job iterates all house users). One GROUP BY query over the
	 * completed_tasks table returns the per-user counts for everyone who
	 * completed at least one task on the given day; users with zero
	 * completions are absent from the map (caller treats missing keys as 0).
	 *
	 * @param \DateTimeInterface $date Date used to determine day boundaries
	 * @return array<int, int> Map of house user ID → completion count
	 */
	public function countByUserOnDateGrouped(\DateTimeInterface $date): array {
		$startOfDay = new \DateTimeImmutable($date->format('Y-m-d') . ' 00:00:00');
		$endOfDay = $startOfDay->modify('+1 day');

		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->addSelect($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->gte('completed_at', $qb->createNamedParameter($startOfDay, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lt('completed_at', $qb->createNamedParameter($endOfDay, IQueryBuilder::PARAM_DATE)))
			->groupBy('user_id');

		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$counts[(int)$row['user_id']] = (int)$row['cnt'];
		}
		$result->closeCursor();
		return $counts;
	}

	/**
	 * Get the highest single-room completion count for a user in [start, end].
	 *
	 * Used by the room_guardian medal check (concentration in one room).
	 * Joins completed_tasks → room_tasks to group by room_id, orders by
	 * count descending, and returns the top row's count (0 when no completions).
	 *
	 * @param int $userId The house user ID
	 * @param \DateTime $start Inclusive range start
	 * @param \DateTime $end Inclusive range end
	 * @return int Highest per-room completion count
	 */
	public function getMaxRoomCompletionCountByUser(int $userId, \DateTime $start, \DateTime $end): int {
		$qb = $this->db->getQueryBuilder();
		$countExpr = $qb->createFunction('COUNT(*)');
		$qb->select('rt.room_id')
			->addSelect($qb->createFunction('COUNT(*) AS room_count'))
			->from($this->getTableName(), 'ct')
			->innerJoin('ct', 'chorestrike_room_tasks', 'rt', $qb->expr()->eq('ct.room_task_id', 'rt.id'))
			->where($qb->expr()->eq('ct.user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('ct.completed_at', $qb->createNamedParameter($start, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lte('ct.completed_at', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATE)))
			->groupBy('rt.room_id')
			->orderBy($countExpr, 'DESC')
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		// GROUP BY + LIMIT 1 yields zero rows when the user has no
		// completions, so fetch() returns false — guard before offset access.
		if ($row === false) {
			return 0;
		}

		return (int)($row['room_count'] ?? 0);
	}

	/**
	 * Get the highest single-room completion count per user for a whole house (CS-PLAN-036).
	 *
	 * Grouped sibling of getMaxRoomCompletionCountByUser: groups by
	 * (user_id, room_id) over the house's users (joined via
	 * chorestrike_house_users.house_id), then reduces to the per-user
	 * maximum in PHP. Users without completions are absent from the map —
	 * callers treat a missing key as 0 (equivalent to the per-user method
	 * on an empty range).
	 *
	 * @param int $houseId The house ID
	 * @param \DateTime $start Inclusive range start
	 * @param \DateTime $end Inclusive range end
	 * @return array<int, int> Map of house user ID → highest per-room completion count
	 */
	public function getMaxRoomCompletionCountByHouse(int $houseId, \DateTime $start, \DateTime $end): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ct.user_id')
			->addSelect('rt.room_id')
			->addSelect($qb->createFunction('COUNT(*) AS room_count'))
			->from($this->getTableName(), 'ct')
			->innerJoin('ct', 'chorestrike_room_tasks', 'rt', $qb->expr()->eq('ct.room_task_id', 'rt.id'))
			->innerJoin('ct', 'chorestrike_house_users', 'hu', $qb->expr()->eq('ct.user_id', 'hu.id'))
			->where($qb->expr()->eq('hu.house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('ct.completed_at', $qb->createNamedParameter($start, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lte('ct.completed_at', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATE)))
			->groupBy('ct.user_id')
			->addGroupBy('rt.room_id');

		$result = $qb->executeQuery();
		$max = [];
		while ($row = $result->fetch()) {
			$userId = (int)$row['user_id'];
			$count = (int)($row['room_count'] ?? 0);
			if (!isset($max[$userId]) || $count > $max[$userId]) {
				$max[$userId] = $count;
			}
		}
		$result->closeCursor();

		return $max;
	}

	/**
	 * Find distinct room_task_ids with at least one completion within a date range for a house.
	 *
	 * Used by the weekly household goal as the numerator (distinct tasks done
	 * this week) and as the "completed" part of the denominator (so a task
	 * that just shifted out of the active-due set still counts as part of
	 * this week's planned work). Joins completed_tasks → room_tasks → rooms →
	 * floors to scope by house because completed_tasks has no denormalized
	 * house_id column.
	 *
	 * Uses COUNT of DISTINCT room_task_id semantics: a daily task completed
	 * three times this week contributes 1 unit, matching the "this task was
	 * done this week" intuition rather than "we did N chores".
	 *
	 * @param int $houseId The house ID
	 * @param \DateTimeInterface $start Inclusive range start
	 * @param \DateTimeInterface $end Inclusive range end
	 * @return int[] Distinct room task IDs with a completion in the range
	 */
	public function findCompletedRoomTaskIdsBetweenByHouse(int $houseId, \DateTimeInterface $start, \DateTimeInterface $end): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ct.room_task_id')
			->from($this->getTableName(), 'ct')
			->innerJoin('ct', 'chorestrike_room_tasks', 'rt', $qb->expr()->eq('ct.room_task_id', 'rt.id'))
			->innerJoin('rt', 'chorestrike_rooms', 'r', $qb->expr()->eq('rt.room_id', 'r.id'))
			->innerJoin('r', 'chorestrike_floors', 'f', $qb->expr()->eq('r.floor_id', 'f.id'))
			->where($qb->expr()->eq('f.house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('ct.completed_at', $qb->createNamedParameter($start, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lte('ct.completed_at', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATE)))
			->groupBy('ct.room_task_id');

		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['room_task_id'];
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * Delete all completions of the given room tasks in one statement
	 * (CS-PLAN-037 cascade, decision D2: deletions of a room task take
	 * their completion history with it — medals/streaks/coins are
	 * denormalized snapshots and stay valid).
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
	 * Find completion rows in a date range for a house, enriched with task/room names.
	 *
	 * Returns projected rows (not entities) for the calendar range endpoint.
	 * Each row: {roomTaskId, taskId, taskName, roomId, roomName, completedAt, completedByUserId}.
	 *
	 * @param int $houseId The house ID
	 * @param \DateTimeInterface $start Inclusive range start
	 * @param \DateTimeInterface $end Inclusive range end
	 * @return array<int, array{roomTaskId: int, taskId: int, taskName: string, roomId: int, roomName: string, completedAt: string, completedByUserId: int}>
	 */
	public function findCompletionsBetweenByHouse(int $houseId, \DateTimeInterface $start, \DateTimeInterface $end): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ct.room_task_id', 'rt.task_id', 't.name AS task_name', 'rt.room_id', 'r.name AS room_name', 'ct.completed_at', 'ct.user_id')
			->from($this->getTableName(), 'ct')
			->innerJoin('ct', 'chorestrike_room_tasks', 'rt', $qb->expr()->eq('ct.room_task_id', 'rt.id'))
			->innerJoin('rt', 'chorestrike_tasks', 't', $qb->expr()->eq('rt.task_id', 't.id'))
			->innerJoin('rt', 'chorestrike_rooms', 'r', $qb->expr()->eq('rt.room_id', 'r.id'))
			->innerJoin('r', 'chorestrike_floors', 'f', $qb->expr()->eq('r.floor_id', 'f.id'))
			->where($qb->expr()->eq('f.house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('ct.completed_at', $qb->createNamedParameter($start, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lte('ct.completed_at', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATE)))
			->orderBy('ct.completed_at', 'ASC');

		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = [
				'roomTaskId' => (int)$row['room_task_id'],
				'taskId' => (int)$row['task_id'],
				'taskName' => $row['task_name'],
				'roomId' => (int)$row['room_id'],
				'roomName' => $row['room_name'],
				'completedAt' => $row['completed_at'],
				'completedByUserId' => (int)$row['user_id'],
			];
		}
		$result->closeCursor();
		return $rows;
	}
}
