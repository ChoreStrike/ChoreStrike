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
	 * Get weekly valid-work statistics for ALL users of a house in one
	 * grouped query (CS-PLAN-036; WP3 reshape: no Coin/combo aggregates —
	 * money is wallet authority and combos are retired; medals evaluate
	 * valid task counts and active days only).
	 *
	 * Grouped sibling of getUserStats: DATE() portability, GROUP BY user_id
	 * over the house's users (joined via chorestrike_house_users.house_id).
	 * Users without completions in the range are absent from the map —
	 * callers treat a missing key as all-zero stats.
	 *
	 * @param int $houseId The house ID
	 * @param \DateTime $startDate Start date
	 * @param \DateTime $endDate End date
	 * @return array<int, array{taskCount:int, activeDays:int}> Map of house user ID → stats
	 */
	public function getUserStatsByHouse(int $houseId, \DateTime $startDate, \DateTime $endDate): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('ct.user_id')
			->addSelect($qb->createFunction('COUNT(*) AS task_count'))
			->addSelect($qb->createFunction('COUNT(DISTINCT DATE(ct.completed_at)) AS active_days'))
			->from($this->getTableName(), 'ct')
			->innerJoin('ct', 'chorestrike_house_users', 'hu', $qb->expr()->eq('ct.user_id', 'hu.id'))
			->where($qb->expr()->eq('hu.house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('ct.completed_at', $qb->createNamedParameter($startDate, IQueryBuilder::PARAM_DATE)))
			// Half-open window [start, end) (CS-PLAN-060 §3.6): callers pass
			// the exclusive upper bound, so a completion exactly at the next
			// window start counts in exactly one window.
			->andWhere($qb->expr()->lt('ct.completed_at', $qb->createNamedParameter($endDate, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->isNull('ct.undone_at'))
			->groupBy('ct.user_id');

		$result = $qb->executeQuery();
		$stats = [];
		while ($row = $result->fetch()) {
			$stats[(int)$row['user_id']] = [
				'taskCount' => (int)($row['task_count'] ?? 0),
				'activeDays' => (int)($row['active_days'] ?? 0),
			];
		}
		$result->closeCursor();

		return $stats;
	}

	/**
	 * Occurrence/undo ledger state for the given room tasks within a
	 * window (CS-PLAN-065, ADR-012 fingerprint input): total completion
	 * rows and how many of them carry an `undone_at` mark. Counts — not
	 * row identity — so the value is stable across identical states.
	 *
	 * @param array<int> $roomTaskIds The room task IDs
	 * @param \DateTimeInterface $start Inclusive window start
	 * @param \DateTimeInterface $end Exclusive window end
	 * @return array<int, array{total: int, undone: int}>
	 */
	public function findUndoStateByRoomTasks(array $roomTaskIds, \DateTimeInterface $start, \DateTimeInterface $end): array {
		if (empty($roomTaskIds)) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('ct.room_task_id')
			->selectAlias($qb->createFunction('COUNT(*)'), 'total')
			->selectAlias($qb->createFunction('COUNT(ct.undone_at)'), 'undone')
			->from($this->getTableName(), 'ct')
			->where($qb->expr()->in('ct.room_task_id', $qb->createNamedParameter($roomTaskIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->gte('ct.completed_at', $qb->createNamedParameter($start, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lt('ct.completed_at', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATE)))
			->groupBy('ct.room_task_id');

		$result = $qb->executeQuery();
		$state = [];
		while ($row = $result->fetch()) {
			$state[(int)$row['room_task_id']] = [
				'total' => (int)$row['total'],
				'undone' => (int)$row['undone'],
			];
		}
		$result->closeCursor();

		return $state;
	}

	/**
	 * Count completions of a room task by a house user within a day window.
	 *
	 * Used by the duplicate-completion guard to prevent the same user from
	 * earning coins twice for the same room task on the same day.
	 * Decision D11: undone rows are excluded — undo → same-day re-completion
	 * is a legal, non-duplicate occurrence.
	 *
	 * @param int $roomTaskId The room task ID
	 * @param int $houseUserId The house user ID (internal)
	 * @param \DateTimeInterface $start Inclusive window start (house-TZ day start)
	 * @param \DateTimeInterface $end Exclusive window end (house-TZ next-day start)
	 * @return int Number of completions in the window
	 */
	public function countByRoomTaskAndUserOnDate(int $roomTaskId, int $houseUserId, \DateTimeInterface $start, \DateTimeInterface $end): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter($start, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lt('completed_at', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->isNull('undone_at'));

		$result = $qb->executeQuery();
		return (int)$result->fetchOne();
	}

	/**
	 * Count all completions by a house user within a day window.
	 *
	 * @param int $houseUserId The house user ID
	 * @param \DateTimeInterface $start Inclusive window start
	 * @param \DateTimeInterface $end Exclusive window end
	 * @return int Number of completions in the window
	 */
	public function countByUserOnDate(int $houseUserId, \DateTimeInterface $start, \DateTimeInterface $end): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter($start, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lt('completed_at', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATE)));

		$result = $qb->executeQuery();
		return (int)$result->fetchOne();
	}

	/**
	 * Build a map of houseUserId → completion count within a day window.
	 *
	 * Used by the DailyNotificationJob to avoid an N+1 of per-user COUNTs
	 * (the job iterates all house users). One GROUP BY query over the
	 * completed_tasks table returns the per-user counts for everyone who
	 * completed at least one task in the window; users with zero
	 * completions are absent from the map (caller treats missing keys as 0).
	 *
	 * @param \DateTimeInterface $start Inclusive window start (house-TZ day start)
	 * @param \DateTimeInterface $end Exclusive window end (house-TZ next-day start)
	 * @return array<int, int> Map of house user ID → completion count
	 */
	public function countByUserOnDateGrouped(\DateTimeInterface $start, \DateTimeInterface $end): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->addSelect($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->gte('completed_at', $qb->createNamedParameter($start, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lt('completed_at', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATE)))
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
	 * @param \DateTime $end Exclusive range end
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
			->andWhere($qb->expr()->lt('ct.completed_at', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATE)))
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
	 * Decision D11: undone rows are excluded — undo deflates the weekly goal.
	 *
	 * @param int $houseId The house ID
	 * @param \DateTimeInterface $start Inclusive range start
	 * @param \DateTimeInterface $end Exclusive range end
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
			->andWhere($qb->expr()->lt('ct.completed_at', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->isNull('ct.undone_at'))
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
	 * Anonymize the actor reference of the completions recorded by the
	 * given house membership (CS-PLAN-071 account erasure, decision D3):
	 * `user_id` is nulled; the anonymous shared work facts (time, energy,
	 * coins-earned totals, room effects — combo partner facts since the
	 * CS-PLAN-064 reshape live in the deltas JSON) are retained. No-op for
	 * rows already anonymous.
	 *
	 * @param int $houseUserId The erased membership's house-user ID
	 */
	public function anonymizeActor(int $houseUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Count distinct room tasks with at least one completion, restricted to
	 * a caller-supplied room-task id set.
	 *
	 * Numerator for the CR-SVC-31 root fix (CS-PLAN-040): the room-stats
	 * completionRate counts REAL completions — the share of the room's
	 * tasks with a completion row — instead of `(total − active)/total`,
	 * which counted deactivated/skipped tasks as completed. The id-set
	 * restriction is the visibility-aware successor of the old room-scoped
	 * variant (CS-PLAN-061 review fix): the stats caller passes only the
	 * room tasks visible to the viewer, so completions of invisible tasks
	 * can neither inflate the numerator nor confirm hidden tasks exist.
	 *
	 * @param int[] $roomTaskIds The room task IDs to count completions in
	 * @return int Number of distinct completed room tasks in the set
	 */
	public function countDistinctCompletedRoomTasksInSet(array $roomTaskIds): int {
		$roomTaskIds = array_values(array_map('intval', $roomTaskIds));
		if ($roomTaskIds === []) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(DISTINCT ct.room_task_id) AS completed_count'))
			->from($this->getTableName(), 'ct')
			->where($qb->expr()->in('ct.room_task_id', $qb->createNamedParameter($roomTaskIds, IQueryBuilder::PARAM_INT_ARRAY)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['completed_count'] ?? 0);
	}

	/**
	 * Find completions of one house in a period with task/room names
	 * projected flat.
	 *
	 * House scoping goes through the rooms → floors chain, so rows whose
	 * room task is gone are excluded here (unlike
	 * {@see findActivityBetweenByHouse}, which keeps them for null-safe
	 * enrichment).
	 *
	 * @param int $houseId The house ID
	 * @param \DateTimeInterface $start Inclusive range start
	 * @param \DateTimeInterface $end Exclusive range end
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
			->andWhere($qb->expr()->lt('ct.completed_at', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATE)))
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

	/**
	 * Find activity-feed completions for a house, newest first (CS-PLAN-040).
	 *
	 * House scoping goes through the completer's house_users membership
	 * (ct.user_id = hu.id) rather than the rooms → floors chain, so rows
	 * whose room task was later deleted still appear — the ActivityService
	 * enrichment renders their missing task/room references as fallbacks.
	 *
	 * @param int $houseId The house ID
	 * @param \DateTimeInterface $start Inclusive range start
	 * @param \DateTimeInterface $end Exclusive range end
	 * @param int $limit Maximum number of rows
	 * @param int|null $houseUserId Optional house-user filter (internal id)
	 * @return CompletedTask[] Completions, completed_at DESC
	 */
	public function findActivityBetweenByHouse(
		int $houseId,
		\DateTimeInterface $start,
		\DateTimeInterface $end,
		int $limit,
		?int $houseUserId = null,
	): array {
		$qb = $this->buildActivityQuery($houseId, $start, $end, $houseUserId);
		$qb->orderBy('ct.completed_at', 'DESC')->addOrderBy('ct.id', 'DESC')->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * Count activity-feed completions for a house in a period (CS-PLAN-040).
	 *
	 * Same filters as {@see findActivityBetweenByHouse} without the limit —
	 * the `total` of the /house/activity response.
	 *
	 * @param int $houseId The house ID
	 * @param \DateTimeInterface $start Inclusive range start
	 * @param \DateTimeInterface $end Exclusive range end
	 * @param int|null $houseUserId Optional house-user filter (internal id)
	 * @return int Number of completions in the period
	 */
	public function countActivityByHouse(
		int $houseId,
		\DateTimeInterface $start,
		\DateTimeInterface $end,
		?int $houseUserId = null,
	): int {
		$qb = $this->buildActivityQuery($houseId, $start, $end, $houseUserId);
		$qb->select($qb->func()->count('*', 'cnt'));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}

	/**
	 * Find the newest still-revertible completion of a room task (WP5, D3).
	 *
	 * `undone_at IS NULL` + newest `completed_at`: the LIFO undo unit for
	 * the room task. `id DESC` closes same-second ties deterministically
	 * (the mapper-wide read-order convention, RF-058-13) — two
	 * revertible rows with identical `completed_at` resolve to the
	 * actually-newer insert. NULL when nothing is revertible — the undo
	 * aggregate treats a miss as the idempotent zero-write no-op.
	 *
	 * Callers re-check revertibility under the room-task lock; this lookup
	 * is a plain read and must not be the final gate for a mutating path.
	 */
	public function findNewestRevertibleByRoomTask(int $roomTaskId): ?CompletedTask {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('undone_at'))
			->orderBy('completed_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Mark a ledger row undone (WP5): stamps `undone_at` so the occurrence
	 * stops being revertible and drops out of every D11-filtered read.
	 */
	public function markUndone(int $id, \DateTime $at): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('undone_at', $qb->createNamedParameter($at, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Whether a completion row is still VALID (exists and is not undone) —
	 * the rhythm evaluator's per-occurrence fulfilment probe.
	 */
	public function isValidCompletion(int $id): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('undone_at'));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}

	/**
	 * Valid completion IDs for one room-event batch (at most 500 IDs).
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	public function findValidIds(array $ids): array {
		if ($ids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter(array_values(array_unique($ids)), IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->isNull('undone_at'));

		$result = $qb->executeQuery();
		$validIds = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $validIds;
	}

	/**
	 * Bump the optimistic completion version (WP3 §3.4): attribution
	 * increments the version and moves the selected allocation revision.
	 *
	 * @return int The new version
	 */
	public function advanceVersion(int $id, int $newVersion, int $newAllocationRevision): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('completion_version', $qb->createNamedParameter($newVersion, IQueryBuilder::PARAM_INT))
			->set('allocation_revision', $qb->createNamedParameter($newAllocationRevision, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Valid completion evidence for a bounded occurrence page. Work time and
	 * class must be checked as well as undone_at before granting on-time credit.
	 *
	 * @param int[] $ids
	 * @return array<int, CompletedTask>
	 */
	public function findValidByIds(array $ids): array {
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter(array_values(array_unique($ids)), IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->isNull('undone_at'));
		$byId = [];
		foreach ($this->findEntities($qb) as $completion) {
			$byId[(int)$completion->getId()] = $completion;
		}
		return $byId;
	}

	/**
	 * Shared FROM/WHERE of the two activity queries (list + count).
	 * Half-open window [start, end) (CS-PLAN-060 §3.6).
	 *
	 * CS-PLAN-071 (Q-070-02, decision D3): the house scope resolves through
	 * the room-task chain (room_task → room → floor), NOT through the
	 * actor's membership — erased completions (user_id NULL) and
	 * departed-member completions stay visible as anonymous shared facts;
	 * ActivityService renders their actors via the localized placeholder.
	 * Plain JOINs without active filters: names still resolve through
	 * tombstoned rows (Q-70-01).
	 */
	private function buildActivityQuery(
		int $houseId,
		\DateTimeInterface $start,
		\DateTimeInterface $end,
		?int $houseUserId,
	): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ct.*')
			->from($this->getTableName(), 'ct')
			->innerJoin('ct', 'chorestrike_room_tasks', 'rt', $qb->expr()->eq('ct.room_task_id', 'rt.id'))
			->innerJoin('rt', 'chorestrike_rooms', 'r', $qb->expr()->eq('rt.room_id', 'r.id'))
			->innerJoin('r', 'chorestrike_floors', 'f', $qb->expr()->eq('r.floor_id', 'f.id'))
			->where($qb->expr()->eq('f.house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('ct.completed_at', $qb->createNamedParameter($start, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lt('ct.completed_at', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATE)));

		if ($houseUserId !== null) {
			$qb->andWhere($qb->expr()->eq('ct.user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)));
		}

		return $qb;
	}
}
