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
 * @template-extends QBMapper<RoomTask>
 */
class RoomTaskMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_room_tasks', RoomTask::class);
	}

	/**
	 * Find a room task by ID and acquire an exclusive lock on its row.
	 *
	 * Mirrors HouseUserMapper::findForUpdate: the lock AND the freshest
	 * committed entity come back together, so callers never make decisions
	 * (overdue takeover, claim state, recurrence from next_due) on a
	 * pre-lock snapshot (CR-SVC-53: under READ COMMITTED a concurrent winner
	 * completing the same overdue task advances next_due; a stale snapshot
	 * produced spurious overdue-takeover notifications and recurrence from
	 * the old next_due).
	 *
	 * - MySQL/MariaDB/PostgreSQL: a true locking read (SELECT … FOR UPDATE)
	 *   acquires the exclusive row lock and returns the latest committed
	 *   state in one statement.
	 * - SQLite: no FOR UPDATE support. A no-op self-assignment UPDATE
	 *   (writes is_active's own current value — never pre-read data, so it
	 *   cannot clobber a concurrent commit; CR-SVC-04 defect class) acquires
	 *   the database-wide write lock and serializes writers; the re-read
	 *   afterwards sees the latest committed state.
	 *
	 * Must be called inside an open transaction.
	 *
	 * @param int $roomTaskId The room task ID to lock
	 * @throws DoesNotExistException if the row does not exist
	 */
	public function lockForUpdate(int $roomTaskId): RoomTask {
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('is_active', $qb->createFunction('is_active'))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();

			return $this->find($roomTaskId);
		}

		$sql = 'SELECT * FROM *PREFIX*' . $this->getTableName() . ' WHERE id = :id FOR UPDATE';
		$result = $this->db->executeQuery($sql, ['id' => $roomTaskId], ['id' => IQueryBuilder::PARAM_INT]);
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			throw new DoesNotExistException('RoomTask with id ' . $roomTaskId . ' does not exist');
		}

		return $this->mapRowToEntity($row);
	}

	/**
	 * Find room task by ID
	 *
	 * @param int $id The room task ID
	 * @throws DoesNotExistException if not found
	 */
	public function find(int $id): RoomTask {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Get all room tasks for a room
	 *
	 * @param int $roomId The room ID
	 * @param bool $activeOnly Whether to return only active tasks
	 * @return RoomTask[]
	 */
	public function findByRoomId(int $roomId, bool $activeOnly = true): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));

		if ($activeOnly) {
			$qb->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		}

		$qb->orderBy('next_due', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Find all room tasks of a house (joins through rooms → floors),
	 * ordered by the deterministic `next_due, created_at, id` read order
	 * (CS-PLAN-067 D1) — the one batched house-wide fetch that replaces the
	 * per-room request loop of TaskManager and SchedulingView.
	 *
	 * @param int $houseId The house ID
	 * @param bool $activeOnly Whether to return only active tasks
	 * @return RoomTask[]
	 */
	public function findByHouse(int $houseId, bool $activeOnly = true): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('rt.*')
			->from($this->getTableName(), 'rt');
		$this->applyHouseScope($qb, 'rt', $houseId);

		if ($activeOnly) {
			$qb->andWhere($qb->expr()->eq('rt.is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		}

		$qb->orderBy('rt.next_due', 'ASC')
			->addOrderBy('rt.created_at', 'ASC')
			->addOrderBy('rt.id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Get overdue tasks for a specific house (joins through rooms → floors).
	 *
	 * @param int $houseId The house ID
	 * @param int|null $roomId Optional room ID to filter by
	 * @return RoomTask[]
	 */
	public function findOverdueByHouse(int $houseId, ?int $roomId = null): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('rt.*')
			->from($this->getTableName(), 'rt');
		$this->applyHouseScope($qb, 'rt', $houseId);
		$qb->andWhere($qb->expr()->lt('rt.next_due', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->eq('rt.is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		if ($roomId !== null) {
			$qb->andWhere($qb->expr()->eq('rt.room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
		}

		$qb->orderBy('rt.next_due', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Get tasks due today for a specific house (joins through rooms → floors).
	 *
	 * CS-PLAN-057: the "today" window is supplied as explicit `[start, end)`
	 * bounds (house-TZ day, CalendarService::dayBounds) instead of being
	 * derived at server-TZ midnight.
	 *
	 * @param int $houseId The house ID
	 * @param \DateTimeInterface $start Inclusive day start (house-TZ midnight instant)
	 * @param \DateTimeInterface $end Exclusive next-day start
	 * @param int|null $roomId Optional room ID to filter by
	 * @return RoomTask[]
	 */
	public function findDueTodayByHouse(int $houseId, \DateTimeInterface $start, \DateTimeInterface $end, ?int $roomId = null): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('rt.*')
			->from($this->getTableName(), 'rt');
		$this->applyHouseScope($qb, 'rt', $houseId);
		$qb->andWhere($qb->expr()->gte('rt.next_due', $qb->createNamedParameter($start, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lt('rt.next_due', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->eq('rt.is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		if ($roomId !== null) {
			$qb->andWhere($qb->expr()->eq('rt.room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
		}

		$qb->orderBy('rt.next_due', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Get tasks due within the next N days for a specific house
	 * (joins through rooms → floors).
	 *
	 * @param int $houseId The house ID
	 * @param int $days Number of days
	 * @param int|null $roomId Optional room ID to filter by
	 * @return RoomTask[]
	 */
	public function findDueWithinDaysByHouse(int $houseId, int $days, ?int $roomId = null): array {
		$today = new \DateTime();
		$future = clone $today;
		$future->add(new \DateInterval("P{$days}D"));

		$qb = $this->db->getQueryBuilder();

		$qb->select('rt.*')
			->from($this->getTableName(), 'rt');
		$this->applyHouseScope($qb, 'rt', $houseId);
		$qb->andWhere($qb->expr()->lte('rt.next_due', $qb->createNamedParameter($future, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->gte('rt.next_due', $qb->createNamedParameter($today, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->eq('rt.is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		if ($roomId !== null) {
			$qb->andWhere($qb->expr()->eq('rt.room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
		}

		$qb->orderBy('rt.next_due', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Create a new room task
	 *
	 * Assignees are NOT stored here — the canonical assignee store is the
	 * `chorestrike_task_assignees` join table (CS-PLAN-060 WP1, D2); the
	 * owning service writes join rows after insert.
	 *
	 * @param int $roomId The room ID
	 * @param int $taskId The task ID
	 * @param \DateTime $nextDue The next due date
	 * @param int|null $scopeId Optional scope ID
	 * @param \DateTime|null $idealDueDate Optional ideal due date for flex scheduling
	 */
	public function create(int $roomId, int $taskId, \DateTime $nextDue, ?int $scopeId = null, ?\DateTime $idealDueDate = null): RoomTask {
		$roomTask = new RoomTask();
		$roomTask->setRoomId($roomId);
		$roomTask->setTaskId($taskId);
		$roomTask->setNextDue($nextDue);
		$roomTask->setScopeId($scopeId);
		$roomTask->setIdealDueDate($idealDueDate);
		$roomTask->setIsActive(true);
		$roomTask->setCreatedAt(new \DateTime());

		return $this->insert($roomTask);
	}

	/**
	 * Find room tasks by scope ID
	 *
	 * @param int $scopeId The scope ID
	 * @param bool $activeOnly Whether to return only active tasks
	 * @return RoomTask[]
	 */
	public function findByScopeId(int $scopeId, bool $activeOnly = true): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('scope_id', $qb->createNamedParameter($scopeId, IQueryBuilder::PARAM_INT)));

		if ($activeOnly) {
			$qb->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		}

		$qb->orderBy('next_due', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Deactivate task
	 *
	 * @param int $roomTaskId The room task ID
	 */
	public function deactivate(int $roomTaskId): void {
		$qb = $this->db->getQueryBuilder();

		$qb->update($this->getTableName())
			->set('is_active', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Update next due date
	 *
	 * @param int $roomTaskId The room task ID
	 * @param \DateTime $nextDue The new due date
	 */
	public function updateNextDue(int $roomTaskId, \DateTime $nextDue): void {
		$qb = $this->db->getQueryBuilder();

		$qb->update($this->getTableName())
			->set('next_due', $qb->createNamedParameter($nextDue, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Check if room already has this task
	 *
	 * @param int $roomId The room ID
	 * @param int $taskId The task ID
	 * @return bool
	 */
	public function exists(int $roomId, int $taskId): bool {
		$qb = $this->db->getQueryBuilder();

		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		return $result->fetchOne() !== false;
	}

	/**
	 * Find daily tasks for a house
	 *
	 * Matches tasks whose next_due falls on the given calendar day via a
	 * [start, end) range comparison — NOT `DATE(rt.next_due) = :day`, which
	 * the query builder escapes as a quoted column name and 500s on every
	 * engine (BUG-API-DAILY-001). Range pattern mirrors
	 * CompletedTaskMapper::countByUserOnDate().
	 *
	 * CS-PLAN-057: the day window is supplied as explicit `[start, end)`
	 * bounds (house-TZ day, CalendarService::dayBounds).
	 *
	 * @param int $houseId The house ID
	 * @param \DateTimeInterface $start Inclusive day start
	 * @param \DateTimeInterface $end Exclusive next-day start
	 * @return RoomTask[]
	 */
	public function findDailyTasks(int $houseId, \DateTimeInterface $start, \DateTimeInterface $end): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('rt.*')
			->from($this->getTableName(), 'rt');
		$this->applyHouseScope($qb, 'rt', $houseId);
		$qb->andWhere($qb->expr()->gte('rt.next_due', $qb->createNamedParameter($start, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lt('rt.next_due', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->eq('rt.is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('rt.next_due', 'ASC')
			// CS-PLAN-065 §3.3: the deterministic daily-read order is
			// `nextDue, createdAt, id` — the id closes the tie so two rows
			// created in the same second keep a stable relative order across
			// engines (the child-limit slicing depends on it).
			->addOrderBy('rt.created_at', 'ASC')
			->addOrderBy('rt.id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Find active room tasks whose next_due falls on a calendar day — across
	 * ALL houses (FEAT-NOTIFY-001).
	 *
	 * Job-only query (D4): background jobs are instance-global (like
	 * findAllForJobs()), so this method deliberately has NO house scope.
	 * Do not reuse it for house-facing API endpoints — use the house-scoped
	 * variants (findDailyTasks, findDueTodayByHouse) instead.
	 *
	 * Same [start, end) range-comparison pattern as findDailyTasks (never
	 * DATE(next_due) = :day — see BUG-API-DAILY-001).
	 *
	 * @param \DateTimeInterface $start Inclusive day start
	 * @param \DateTimeInterface $end Exclusive next-day start
	 * @return RoomTask[] Active room tasks due within the day window
	 */
	public function findDueOnDate(\DateTimeInterface $start, \DateTimeInterface $end): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->andWhere($qb->expr()->gte('next_due', $qb->createNamedParameter($start, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lt('next_due', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('next_due', 'ASC')
			->addOrderBy('created_at', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Find the IDs of all room tasks in the given rooms in one query
	 * (CS-PLAN-037 cascade base).
	 *
	 * @param int[] $roomIds The room IDs
	 * @return int[] List of room task IDs
	 */
	public function findIdsByRoomIds(array $roomIds): array {
		$roomIds = array_values(array_map('intval', $roomIds));
		if ($roomIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->in('room_id', $qb->createNamedParameter($roomIds, IQueryBuilder::PARAM_INT_ARRAY)));

		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * Find the IDs of all room tasks of a room.
	 *
	 * @param int $roomId The room ID
	 * @return int[] List of room task IDs
	 */
	public function findIdsByRoomId(int $roomId): array {
		return $this->findIdsByRoomIds([$roomId]);
	}

	/**
	 * Delete all room tasks in the given rooms in one statement
	 * (CS-PLAN-037 cascade; dependents must be cleaned up by the caller
	 * first — the schema has no FK constraints).
	 *
	 * @param int[] $roomIds The room IDs
	 */
	public function deleteByRoomIds(array $roomIds): void {
		$roomIds = array_values(array_map('intval', $roomIds));
		if ($roomIds === []) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->in('room_id', $qb->createNamedParameter($roomIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}

	/**
	 * Delete all room tasks of a room in one statement.
	 *
	 * @param int $roomId The room ID
	 */
	public function deleteByRoomId(int $roomId): void {
		$this->deleteByRoomIds([$roomId]);
	}

	/**
	 * Find multiple room tasks by IDs, scoped to one house, in one query
	 * (CS-PLAN-037, CR-SVC-60).
	 *
	 * IN-clause fetch joined through rooms → floors filtered on
	 * floors.house_id — the batch replacement for the per-item 3-query
	 * ownership chain. Ids that are missing or belong to another house
	 * are simply absent from the result (skip-on-missing semantics).
	 *
	 * @param int[] $roomTaskIds The room task IDs
	 * @param int $houseId The house ID scope
	 * @return RoomTask[] Room tasks of the house (unordered)
	 */
	public function findByIdsScopedToHouse(array $roomTaskIds, int $houseId): array {
		$roomTaskIds = array_values(array_map('intval', $roomTaskIds));
		if ($roomTaskIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('rt.*')
			->from($this->getTableName(), 'rt');
		$this->applyHouseScope($qb, 'rt', $houseId);
		$qb->andWhere($qb->expr()->in('rt.id', $qb->createNamedParameter($roomTaskIds, IQueryBuilder::PARAM_INT_ARRAY)));

		return $this->findEntities($qb);
	}

	/**
	 * Apply the house-scope JOIN chain to a room_tasks query.
	 *
	 * chorestrike_room_tasks has no denormalized house_id column, so every
	 * house-scoped query must INNER JOIN rooms → floors and pin
	 * floors.house_id. Centralising the chain here keeps all house-scoped
	 * callers in sync if the schema ever changes (floor soft-delete,
	 * multi-house isolation, etc.).
	 *
	 * Must be called after `from()` and before any caller-specific ANDs.
	 *
	 * @param IQueryBuilder $qb The query builder to mutate
	 * @param string $roomTaskAlias The alias used for chorestrike_room_tasks in the FROM/SELECT
	 * @param int $houseId The house ID to scope to
	 */
	private function applyHouseScope(IQueryBuilder $qb, string $roomTaskAlias, int $houseId): void {
		$qb->innerJoin($roomTaskAlias, 'chorestrike_rooms', 'r', $qb->expr()->eq("{$roomTaskAlias}.room_id", 'r.id'))
			->innerJoin('r', 'chorestrike_floors', 'f', $qb->expr()->eq('r.floor_id', 'f.id'))
			->where($qb->expr()->eq('f.house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));
	}

	/**
	 * Find active room tasks whose next_due is on or before a bound, for a house.
	 *
	 * The recurrence-projection base set for the calendar range endpoint:
	 * returns every active task that could recur into a future window
	 * (next_due <= end), so projectOccurrences() can expand each into its
	 * in-window occurrences. Reuses applyHouseScope for house isolation.
	 *
	 * @param int $houseId The house ID
	 * @param \DateTimeInterface $end Inclusive upper bound for next_due
	 * @return RoomTask[] Active room tasks due on or before $end
	 */
	public function findActiveDueUpToByHouse(int $houseId, \DateTimeInterface $end): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('rt.*')
			->from($this->getTableName(), 'rt');
		$this->applyHouseScope($qb, 'rt', $houseId);
		$qb->andWhere($qb->expr()->eq('rt.is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->lte('rt.next_due', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATE)))
			->orderBy('rt.next_due', 'ASC')
			->addOrderBy('rt.created_at', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Find active room tasks whose next_due falls within a date range for a house.
	 *
	 * Entity-returning sibling of findActiveDueIdsBetweenByHouse for the
	 * calendar range endpoint. Reuses applyHouseScope for house isolation.
	 *
	 * @param int $houseId The house ID
	 * @param \DateTimeInterface $start Inclusive range start
	 * @param \DateTimeInterface $end Inclusive range end
	 * @return RoomTask[] Active room tasks due in [start, end]
	 */
	public function findActiveDueBetweenByHouse(int $houseId, \DateTimeInterface $start, \DateTimeInterface $end): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('rt.*')
			->from($this->getTableName(), 'rt');
		$this->applyHouseScope($qb, 'rt', $houseId);
		$qb->andWhere($qb->expr()->eq('rt.is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->gte('rt.next_due', $qb->createNamedParameter($start, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lte('rt.next_due', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATE)))
			->orderBy('rt.next_due', 'ASC')
			->addOrderBy('rt.created_at', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Find IDs of active room tasks whose next_due falls within a date range for a house.
	 *
	 * Used by the weekly household goal to determine the "still-open" part of
	 * the denominator (tasks on the radar this week). Joins through rooms →
	 * floors to scope by house because chorestrike_room_tasks has no
	 * denormalized house_id column.
	 *
	 * Returns IDs (not entities) so HouseService can union them with the
	 * completed-this-week ID set without re-fetching rows.
	 *
	 * @param int $houseId The house ID
	 * @param \DateTimeInterface $start Inclusive range start
	 * @param \DateTimeInterface $end Exclusive range end
	 * @return int[] List of room task IDs
	 */
	public function findActiveDueIdsBetweenByHouse(int $houseId, \DateTimeInterface $start, \DateTimeInterface $end): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('rt.id')
			->from($this->getTableName(), 'rt');
		$this->applyHouseScope($qb, 'rt', $houseId);
		$qb->andWhere($qb->expr()->eq('rt.is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->gte('rt.next_due', $qb->createNamedParameter($start, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lt('rt.next_due', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATE)));

		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * Find the (distinct) house IDs a task is linked to via the
	 * room-task → room → floor chain (CR-053-01 guard primitive).
	 *
	 * An empty result means the task has no room-task linkage yet — the
	 * caller treats that as house-linked (single-house-per-instance
	 * today, pinned by ChecklistApiTest).
	 *
	 * @param int $taskId The task ID
	 * @return int[] Distinct house IDs of the task's room tasks
	 */
	public function findHouseIdsForTask(int $taskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.house_id')
			->from($this->getTableName(), 'rt')
			->innerJoin('rt', 'chorestrike_rooms', 'r', $qb->expr()->eq('rt.room_id', 'r.id'))
			->innerJoin('r', 'chorestrike_floors', 'f', $qb->expr()->eq('r.floor_id', 'f.id'))
			->where($qb->expr()->eq('rt.task_id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$houseIds = [];
		while ($row = $result->fetch()) {
			$houseIds[(int)$row['house_id']] = true;
		}
		$result->closeCursor();

		return array_keys($houseIds);
	}
}
