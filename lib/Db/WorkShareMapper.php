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
 * @template-extends QBMapper<WorkShare>
 */
class WorkShareMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_work_shares', WorkShare::class);
	}

	/**
	 * All share rows of one allocation revision (evidence + replay input).
	 *
	 * @return WorkShare[]
	 */
	public function findByCompletionAndRevision(int $completionId, int $allocationRevision): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('completion_id', $qb->createNamedParameter($completionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('allocation_revision', $qb->createNamedParameter($allocationRevision, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Selected allocation of a bounded completion page in one query.
	 *
	 * @param int[] $completionIds
	 * @return array<int, list<WorkShare>> completionId => selected shares
	 */
	public function findSelectedByCompletionIds(array $completionIds): array {
		$completionIds = array_values(array_unique(array_map('intval', $completionIds)));
		if ($completionIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('ws.*')
			->from($this->getTableName(), 'ws')
			->innerJoin('ws', 'chorestrike_completed_tasks', 'ct', $qb->expr()->eq('ct.id', 'ws.completion_id'))
			->where($qb->expr()->in('ws.completion_id', $qb->createNamedParameter($completionIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('ws.allocation_revision', 'ct.allocation_revision'))
			->orderBy('ws.id', 'ASC');

		$sharesByCompletion = [];
		foreach ($this->findEntities($qb) as $share) {
			$sharesByCompletion[$share->getCompletionId()][] = $share;
		}
		return $sharesByCompletion;
	}

	/**
	 * Every share row of one completion across all revisions (audit view).
	 *
	 * @return WorkShare[]
	 */
	public function findByCompletionId(int $completionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('completion_id', $qb->createNamedParameter($completionId, IQueryBuilder::PARAM_INT)))
			->orderBy('allocation_revision', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Valid selected shares joined to their non-undone completions within a
	 * completed-instant window — the capacity seam (WP3 §3.2). One row per
	 * participant share; each participated occurrence is counted once per
	 * participant by the caller (occurrence-identity dedupe).
	 *
	 * @return array<int, array{completionId: int, walletId: int|null, energyMinor: int, completedAt: \DateTime, workDate: \DateTime|null, workZone: string|null, occurrenceKey: string|null}>
	 */
	public function findSelectedSharesBetweenByHouse(int $houseId, \DateTimeInterface $start, \DateTimeInterface $end): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ws.completion_id', 'ws.wallet_id', 'ws.energy_minor')
			->addSelect('ct.completed_at', 'ct.work_date', 'ct.work_zone', 'ct.schedule_rev_id', 'ct.occurrence_ordinal')
			->from('chorestrike_work_shares', 'ws')
			->innerJoin('ws', 'chorestrike_completed_tasks', 'ct', $qb->expr()->eq('ct.id', 'ws.completion_id'))
			->innerJoin('ws', 'chorestrike_house_users', 'hu', $qb->expr()->eq('hu.wallet_id', 'ws.wallet_id'))
			->where($qb->expr()->eq('hu.house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('ws.allocation_revision', 'ct.allocation_revision'))
			->andWhere($qb->expr()->isNull('ct.undone_at'))
			->andWhere($qb->expr()->gte('ct.completed_at', $qb->createNamedParameter($start, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lt('ct.completed_at', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATE)))
			->orderBy('ws.id', 'ASC');

		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$revId = $row['schedule_rev_id'] !== null ? (int)$row['schedule_rev_id'] : null;
			$ordinal = $row['occurrence_ordinal'] !== null ? (int)$row['occurrence_ordinal'] : null;
			$rows[] = [
				'completionId' => (int)$row['completion_id'],
				'walletId' => $row['wallet_id'] !== null ? (int)$row['wallet_id'] : null,
				'energyMinor' => (int)$row['energy_minor'],
				'completedAt' => new \DateTime((string)$row['completed_at']),
				'workDate' => $row['work_date'] !== null ? new \DateTime((string)$row['work_date']) : null,
				'workZone' => $row['work_zone'] !== null ? (string)$row['work_zone'] : null,
				'occurrenceKey' => $revId !== null && $ordinal !== null ? $revId . ':' . $ordinal : null,
			];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Sum of every participant's selected share minor units of one
	 * completion (the caller validates conservation when writing).
	 *
	 * @return array{energyMinor: int, coinMinor: int}
	 */
	public function sumRevision(int $completionId, int $allocationRevision): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COALESCE(SUM(energy_minor), 0) AS energy_minor'))
			->addSelect($qb->createFunction('COALESCE(SUM(coin_minor), 0) AS coin_minor'))
			->from($this->getTableName())
			->where($qb->expr()->eq('completion_id', $qb->createNamedParameter($completionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('allocation_revision', $qb->createNamedParameter($allocationRevision, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return [
			'energyMinor' => (int)($row['energy_minor'] ?? 0),
			'coinMinor' => (int)($row['coin_minor'] ?? 0),
		];
	}

	/**
	 * Sum of the valid SELECTED share energy minor per wallet (the
	 * contribution_minor cache input; WP3 §3.1 — derived from valid
	 * selected work shares only).
	 *
	 * @param int[] $walletIds
	 * @return array<int, int> walletId => energy minor
	 */
	public function sumValidSelectedByWallet(array $walletIds): array {
		$walletIds = array_values(array_unique(array_filter(array_map('intval', $walletIds))));
		if ($walletIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('ws.wallet_id')
			->selectAlias($qb->createFunction('COALESCE(SUM(ws.energy_minor), 0)'), 'energy_minor')
			->from($this->getTableName(), 'ws')
			->innerJoin('ws', 'chorestrike_completed_tasks', 'ct', $qb->expr()->eq('ct.id', 'ws.completion_id'))
			->where($qb->expr()->in('ws.wallet_id', $qb->createNamedParameter($walletIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('ws.allocation_revision', 'ct.allocation_revision'))
			->andWhere($qb->expr()->isNull('ct.undone_at'))
			->groupBy('ws.wallet_id');

		$result = $qb->executeQuery();
		$sums = [];
		while ($row = $result->fetch()) {
			$sums[(int)$row['wallet_id']] = (int)$row['energy_minor'];
		}
		$result->closeCursor();

		return $sums;
	}

	/**
	 * Erasure sweep (WP3 §3.6): detach the erased subject's wallet from
	 * their share rows — the non-identifying shared work facts stay.
	 */
	public function anonymizeWallet(int $walletId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('wallet_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('wallet_id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Test-mode full reset: clear the table in safe order.
	 */
	public function deleteAll(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())->executeStatement();
	}
}
