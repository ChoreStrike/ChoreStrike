<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\ICompositeExpression;
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
	 * The wallets credited on one house-local day by the valid completions
	 * of a room task, from their selected allocation (ADR-021 decision 8:
	 * a bonus task pays each person once per day).
	 *
	 * @param string $dayKey The work day (`Y-m-d`)
	 * @param int|null $exceptCompletionId A completion to leave out
	 * @return list<int> Wallet ids
	 */
	public function findWalletIdsCreditedOn(int $roomTaskId, string $dayKey, ?int $exceptCompletionId = null): array {
		return $this->findWalletIdsCreditedOnByRoomTasks([$roomTaskId], $dayKey, $exceptCompletionId)[$roomTaskId] ?? [];
	}

	/**
	 * The wallets credited on one house-local day on each of the given
	 * room tasks, from the selected allocations of their valid completions:
	 * the one "credited on a day" rule of the bonus rows of *Today* and of
	 * the once-per-person rule ({@see findWalletIdsCreditedOn()}).
	 *
	 * @param int[] $roomTaskIds
	 * @param string $dayKey The work day (`Y-m-d`)
	 * @param int|null $exceptCompletionId A completion to leave out
	 * @return array<int, list<int>> Room task id to wallet ids; room tasks without a credit are absent
	 */
	public function findWalletIdsCreditedOnByRoomTasks(array $roomTaskIds, string $dayKey, ?int $exceptCompletionId = null): array {
		$roomTaskIds = array_values(array_unique(array_map('intval', $roomTaskIds)));
		if ($roomTaskIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('ct.room_task_id', 'ws.wallet_id')
			->from($this->getTableName(), 'ws')
			->innerJoin('ws', 'chorestrike_completed_tasks', 'ct', $qb->expr()->eq('ct.id', 'ws.completion_id'))
			->where($qb->expr()->in('ct.room_task_id', $qb->createNamedParameter($roomTaskIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('ws.allocation_revision', 'ct.allocation_revision'))
			->andWhere($qb->expr()->isNull('ct.undone_at'))
			->andWhere($qb->expr()->isNotNull('ws.wallet_id'))
			// The portable day comparison: from the day, before the day after.
			->andWhere($qb->expr()->gte('ct.work_date', $qb->createNamedParameter($dayKey)))
			->andWhere($qb->expr()->lt('ct.work_date', $qb->createNamedParameter(
				(new \DateTimeImmutable($dayKey, new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d')
			)));
		if ($exceptCompletionId !== null) {
			$qb->andWhere($qb->expr()->neq('ct.id', $qb->createNamedParameter($exceptCompletionId, IQueryBuilder::PARAM_INT)));
		}
		$result = $qb->executeQuery();
		$credited = [];
		foreach ($result->fetchAll() as $row) {
			$credited[(int)$row['room_task_id']][(int)$row['wallet_id']] = true;
		}
		$result->closeCursor();
		return array_map('array_keys', $credited);
	}

	/**
	 * Count of finished chores per wallet (CS-PLAN-086 §3.2): the distinct
	 * VALID completions in whose SELECTED allocation revision the wallet
	 * holds a share — the attribution of {@see sumValidSelectedByWallet()},
	 * so helpers count and an undone completion does not.
	 *
	 * CS-PLAN-090 D-090-66: a wallet with a lower bound counts only
	 * completions above it (an arcade reset); the query stays one grouped
	 * read for every wallet (RF-090-26).
	 *
	 * @param int[] $walletIds
	 * @param array<int, int> $afterByWallet walletId => exclusive lower completion id
	 * @return array<int, int> walletId => finished chores (wallets without any are omitted)
	 */
	public function countValidSelectedByWallet(array $walletIds, array $afterByWallet = []): array {
		$walletIds = array_values(array_unique(array_filter(array_map('intval', $walletIds))));
		if ($walletIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('ws.wallet_id')
			->selectAlias($qb->createFunction('COUNT(DISTINCT ws.completion_id)'), 'finished')
			->from($this->getTableName(), 'ws')
			->innerJoin('ws', 'chorestrike_completed_tasks', 'ct', $qb->expr()->eq('ct.id', 'ws.completion_id'))
			->where($this->walletScope($qb, $walletIds, $afterByWallet))
			->andWhere($qb->expr()->eq('ws.allocation_revision', 'ct.allocation_revision'))
			->andWhere($qb->expr()->isNull('ct.undone_at'))
			->groupBy('ws.wallet_id');

		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$counts[(int)$row['wallet_id']] = (int)$row['finished'];
		}
		$result->closeCursor();

		return $counts;
	}

	/**
	 * The wallet filter of {@see countValidSelectedByWallet()}: wallets
	 * sharing a lower bound form one `IN` group, so the filter grows with
	 * the distinct bounds, not with the wallets.
	 *
	 * @param int[] $walletIds
	 * @param array<int, int> $afterByWallet
	 */
	private function walletScope(IQueryBuilder $qb, array $walletIds, array $afterByWallet): string|ICompositeExpression {
		$groups = [];
		foreach ($walletIds as $walletId) {
			$groups[max(0, (int)($afterByWallet[$walletId] ?? 0))][] = $walletId;
		}

		$parts = [];
		foreach ($groups as $after => $ids) {
			$in = $qb->expr()->in('ws.wallet_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY));
			$parts[] = $after === 0
				? $in
				: $qb->expr()->andX($in, $qb->expr()->gt('ws.completion_id', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)));
		}
		return count($parts) === 1 ? $parts[0] : $qb->expr()->orX(...$parts);
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
