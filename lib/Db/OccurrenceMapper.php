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
 * @template-extends QBMapper<Occurrence>
 */
class OccurrenceMapper extends QBMapper {
	/** Deterministic enumeration guard for bounded generation passes. */
	public const MAX_GENERATE_OCCURRENCES = 1000;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_occurrences', Occurrence::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Occurrence {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Resolve one occurrence by its public key parts, scoped to a room task.
	 *
	 * @throws DoesNotExistException
	 */
	public function findByKey(int $roomTaskId, int $scheduleRevId, int $ordinal): Occurrence {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('schedule_rev_id', $qb->createNamedParameter($scheduleRevId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('ordinal', $qb->createNamedParameter($ordinal, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * All occurrences of one room task in (revision, ordinal) order.
	 *
	 * @return Occurrence[]
	 */
	public function findByRoomTask(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->orderBy('schedule_rev_id', 'ASC')
			->addOrderBy('ordinal', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Occurrences of one revision in ordinal order.
	 *
	 * @return Occurrence[]
	 */
	public function findByRevision(int $scheduleRevId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('schedule_rev_id', $qb->createNamedParameter($scheduleRevId, IQueryBuilder::PARAM_INT)))
			->orderBy('ordinal', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * The earliest open (unfulfilled, uncovered) scheduled occurrence of a
	 * room task — the single indexed row the derived `next_due` projection
	 * follows (RF-072-WP1-09: no full-history load per job pass).
	 */
	public function findFirstOpenScheduledByRoomTask(int $roomTaskId): ?Occurrence {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('completion_id'))
			->andWhere($qb->expr()->isNull('covered_by_completion_id'))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter(Occurrence::KIND_SCHEDULED, IQueryBuilder::PARAM_STR)))
			->orderBy('day_start', 'ASC')
			->addOrderBy('schedule_rev_id', 'ASC')
			->addOrderBy('ordinal', 'ASC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Open (unfulfilled, uncovered) occurrences of the given room tasks in
	 * (room task, revision, ordinal) order — the live backlog and selection
	 * candidates of every age, without loading fulfilled history
	 * (RF-072-WP1-10/11 batch reads).
	 *
	 * @param int[] $roomTaskIds
	 * @return Occurrence[]
	 */
	public function findOpenByRoomTasks(array $roomTaskIds): array {
		$roomTaskIds = array_values(array_map('intval', $roomTaskIds));
		if ($roomTaskIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('room_task_id', $qb->createNamedParameter($roomTaskIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->isNull('completion_id'))
			->andWhere($qb->expr()->isNull('covered_by_completion_id'))
			->orderBy('room_task_id', 'ASC')
			->addOrderBy('schedule_rev_id', 'ASC')
			->addOrderBy('ordinal', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Occurrences of the given room tasks planned inside an inclusive
	 * local-date window (any status) — the bounded row window behind the
	 * windowed agreed derivations (RF-072-WP1-10).
	 *
	 * @param int[] $roomTaskIds
	 * @return Occurrence[]
	 */
	public function findByRoomTasksPlannedBetween(array $roomTaskIds, string $fromKey, string $toKey): array {
		$roomTaskIds = array_values(array_map('intval', $roomTaskIds));
		if ($roomTaskIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('room_task_id', $qb->createNamedParameter($roomTaskIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->gte('planned_date', $qb->createNamedParameter($fromKey, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lte('planned_date', $qb->createNamedParameter($toKey, IQueryBuilder::PARAM_STR)))
			->orderBy('room_task_id', 'ASC')
			->addOrderBy('schedule_rev_id', 'ASC')
			->addOrderBy('ordinal', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * The latest expired, unfulfilled, uncovered SCHEDULED occurrence of a
	 * room task (the catch-up target) — or null when no miss exists.
	 * Voluntary rows are never catch-up targets (RF-072-WP1-14).
	 */
	public function findLatestMissedByRoomTask(int $roomTaskId, \DateTime $now): ?Occurrence {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('completion_id'))
			->andWhere($qb->expr()->isNull('covered_by_completion_id'))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter(Occurrence::KIND_SCHEDULED, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lte('window_end', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE)))
			->orderBy('window_end', 'DESC')
			->addOrderBy('planned_date', 'DESC')
			->addOrderBy('schedule_rev_id', 'DESC')
			->addOrderBy('ordinal', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Insert-or-return: the natural key (schedule_rev_id, ordinal) supplies
	 * idempotency — a lost race re-reads the winner's row. Called inside
	 * the owning house-first transaction (materialization only; the row
	 * keeps version 0).
	 */
	public function insertOrGet(Occurrence $occurrence): Occurrence {
		try {
			return $this->insert($occurrence);
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			return $this->findByKey(
				$occurrence->getRoomTaskId(),
				$occurrence->getScheduleRevId(),
				$occurrence->getOrdinal()
			);
		}
	}

	/**
	 * Delete future-unfulfilled occurrences of a revision beyond a planned
	 * date (interval re-anchoring cleanup: an early completion supersedes
	 * the previously projected pending successor). Never touches fulfilled
	 * or expired rows. Called inside the owning transaction.
	 */
	public function deleteFutureUnfulfilledOfRevision(ScheduleRevision $revision, \DateTime $fromDateExclusive): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('schedule_rev_id', $qb->createNamedParameter($revision->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('completion_id'))
			->andWhere($qb->expr()->isNull('covered_by_completion_id'))
			->andWhere($qb->expr()->gt('planned_date', $qb->createNamedParameter($fromDateExclusive->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
			->executeStatement();
	}

	/**
	 * All occurrences of a house (batched read model for the daily/
	 * overdue/calendar derivation; ordered by room task, revision,
	 * ordinal).
	 *
	 * @return Occurrence[]
	 */
	public function findByHouseId(int $houseId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->orderBy('room_task_id', 'ASC')
			->addOrderBy('schedule_rev_id', 'ASC')
			->addOrderBy('ordinal', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Open (unfulfilled, uncovered) SCHEDULED occurrences of a house —
	 * the batched capacity/parallel input of the WP2 pause projection and
	 * the whole-day capacity evaluator (RF-072-WP1-10 sibling; no
	 * fulfilled-row fan-out).
	 *
	 * @return Occurrence[]
	 */
	public function findOpenByHouseId(int $houseId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('completion_id'))
			->andWhere($qb->expr()->isNull('covered_by_completion_id'))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter(Occurrence::KIND_SCHEDULED, IQueryBuilder::PARAM_STR)))
			->orderBy('room_task_id', 'ASC')
			->addOrderBy('schedule_rev_id', 'ASC')
			->addOrderBy('ordinal', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * All scheduled occurrences of one house planned inside the bounded
	 * day window (inclusive keys) — the rhythm evaluator's batched input
	 * (fulfilled AND unfulfilled rows; validity is decided per row).
	 *
	 * @return Occurrence[]
	 */
	public function findByHousePlannedBetween(int $houseId, string $fromKey, string $toKey): array {
		$qb = $this->db->getQueryBuilder();
		// NOTE: the day keys bind as strings — Doctrine's PARAM_DATE
		// conversion rejects plain 'Y-m-d' strings.
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter(Occurrence::KIND_SCHEDULED, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->gte('planned_date', $qb->createNamedParameter($fromKey, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lte('planned_date', $qb->createNamedParameter($toKey, IQueryBuilder::PARAM_STR)))
			->orderBy('room_task_id', 'ASC')
			->addOrderBy('schedule_rev_id', 'ASC')
			->addOrderBy('ordinal', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Page scheduled history in planned-day/id order. A fixed-size page keeps
	 * correction/reward projection reads bounded without dropping older runs.
	 *
	 * @return Occurrence[]
	 */
	public function findPlannedPage(int $houseId, string $throughKey, ?string $afterDay, int $afterId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter(Occurrence::KIND_SCHEDULED, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lte('planned_date', $qb->createNamedParameter($throughKey, IQueryBuilder::PARAM_STR)))
			->orderBy('planned_date', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults($limit);
		if ($afterDay !== null) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->gt('planned_date', $qb->createNamedParameter($afterDay, IQueryBuilder::PARAM_STR)),
				$qb->expr()->andX(
					$qb->expr()->eq('planned_date', $qb->createNamedParameter($afterDay, IQueryBuilder::PARAM_STR)),
					$qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT))
				)
			));
		}
		return $this->findEntities($qb);
	}

}
