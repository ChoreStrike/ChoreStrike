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
 * Closed cycles (ADR-021 decision 3). The unique `(room_task_id,
 * cycle_key)` key backs exactly-once: one closed row per cycle, whoever
 * writes first.
 *
 * @template-extends QBMapper<Cycle>
 */
class CycleMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_cycles', Cycle::class);
	}

	/** The closed row of one cycle of a room task; null while the cycle is open. */
	public function findByKey(int $roomTaskId, string $cycleKey): ?Cycle {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('cycle_key', $qb->createNamedParameter($cycleKey)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Insert a closed cycle unless its key is already closed. The losing
	 * side of a race gets false and the transaction stays usable: the
	 * speculative insert runs inside a savepoint, because PostgreSQL
	 * aborts the whole transaction on a duplicate.
	 *
	 * @return bool Whether this call wrote the row
	 */
	public function insertIfAbsent(Cycle $cycle): bool {
		if ($this->findByKey($cycle->getRoomTaskId(), $cycle->getCycleKey()) !== null) {
			return false;
		}
		if (!$this->db->inTransaction()) {
			return $this->tryInsert($cycle);
		}
		$this->db->executeStatement('SAVEPOINT cs_cycle_insert');
		if ($this->tryInsert($cycle)) {
			$this->db->executeStatement('RELEASE SAVEPOINT cs_cycle_insert');
			return true;
		}
		$this->db->executeStatement('ROLLBACK TO SAVEPOINT cs_cycle_insert');
		$this->db->executeStatement('RELEASE SAVEPOINT cs_cycle_insert');
		return false;
	}

	private function tryInsert(Cycle $cycle): bool {
		try {
			$this->insert($cycle);
			return true;
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			return false;
		}
	}

	/**
	 * The done row of a completion; null for a completion from before the
	 * derived model. Scoped by room task so the unique key's prefix serves it.
	 */
	public function findByCompletion(int $roomTaskId, int $completionId): ?Cycle {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('completion_id', $qb->createNamedParameter($completionId, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Delete the done row of a completion (undo: the cycle reopens), scoped
	 * by room task so the unique key's prefix serves the lookup.
	 */
	public function deleteByCompletionId(int $roomTaskId, int $completionId): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('completion_id', $qb->createNamedParameter($completionId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Delete the missed row of one cycle: an undo made that cycle current
	 * again, so it is open work, not a miss (final review of CS-PLAN-091).
	 */
	public function deleteMissed(int $roomTaskId, string $cycleKey): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('cycle_key', $qb->createNamedParameter($cycleKey)))
			->andWhere($qb->expr()->eq('outcome', $qb->createNamedParameter(Cycle::OUTCOME_MISSED)))
			->executeStatement();
	}

	/**
	 * The latest rule day of each room task that already has a closed
	 * row, so the timeline job walks only what is new.
	 *
	 * @param int[] $roomTaskIds
	 * @return array<int, string> Room task id to `Y-m-d`
	 */
	public function latestDayKeyedCentres(array $roomTaskIds): array {
		$centres = [];
		foreach (array_chunk(array_values(array_map('intval', $roomTaskIds)), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('room_task_id')
				->selectAlias($qb->func()->max('cycle_key'), 'max_key')
				->from($this->getTableName())
				->where($qb->expr()->in('room_task_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->groupBy('room_task_id');
			$result = $qb->executeQuery();
			foreach ($result->fetchAll() as $row) {
				if (str_starts_with((string)$row['max_key'], 'd')) {
					$centres[(int)$row['room_task_id']] = substr((string)$row['max_key'], 1, 10);
				}
			}
			$result->closeCursor();
		}
		return $centres;
	}

	/**
	 * Closed cycles of a house whose window ends inside the given
	 * house-local days (both inclusive), oldest first.
	 *
	 * @return Cycle[]
	 */
	public function findByHouseEndingBetween(int $houseId, string $fromKey, string $toKey): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('window_end_on', $qb->createNamedParameter($fromKey)))
			->andWhere($qb->expr()->lt('window_end_on', $qb->createNamedParameter(DayKey::after($toKey))))
			->orderBy('window_end_on', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Account erasure: the wallet leaves the responsibility of every closed
	 * cycle of the house. The cycle itself stays as a shared fact; like an
	 * erased work share it no longer names the person.
	 */
	public function forgetWallet(int $houseId, int $walletId): void {
		$afterId = 0;
		do {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
				->orderBy('id', 'ASC')
				->setMaxResults(500);
			$page = $this->findEntities($qb);
			foreach ($page as $cycle) {
				$afterId = (int)$cycle->getId();
				$kept = array_values(array_diff($cycle->responsibleWalletIds(), [$walletId]));
				if (count($kept) !== count($cycle->responsibleWalletIds())) {
					$cycle->setResponsible($kept);
					$this->update($cycle);
				}
			}
		} while (count($page) === 500);
	}

	/**
	 * One page of a house's closed cycles from a day on, in
	 * `(window end, id)` order — the cursor of the rhythm's lifetime scan.
	 *
	 * @return Cycle[]
	 */
	public function findClosedPage(int $houseId, string $fromKey, ?string $afterDay, int $afterId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('window_end_on', $qb->createNamedParameter($fromKey)))
			->orderBy('window_end_on', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults(max(1, $limit));
		if ($afterDay !== null) {
			$dayAfter = DayKey::after($afterDay);
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->gte('window_end_on', $qb->createNamedParameter($dayAfter)),
				$qb->expr()->andX(
					$qb->expr()->gte('window_end_on', $qb->createNamedParameter($afterDay)),
					$qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT))
				)
			));
		}
		return $this->findEntities($qb);
	}
}
