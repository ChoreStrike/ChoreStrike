<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class WeeklyGoalReceiptMapper {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * One unique claim survives correction, restart and a dismissed bell.
	 */
	public function claim(int $houseId, string $weekKey, \DateTime $now): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_wgoal_receipts')
			->values([
				'house_id' => $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT),
				'week_start' => $qb->createNamedParameter($weekKey, IQueryBuilder::PARAM_STR),
				'claimed_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE),
			]);
		try {
			$qb->executeStatement();
			return true;
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			return false;
		}
	}

	/** @param list<string> $weekKeys @return array<string, true> */
	public function claimedWeeks(int $houseId, array $weekKeys): array {
		if ($weekKeys === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('week_start')
			->from('chorestrike_wgoal_receipts')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('week_start', $qb->createNamedParameter($weekKeys, IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$claimed = [];
		foreach ($result->fetchAll() as $row) {
			$claimed[substr((string)$row['week_start'], 0, 10)] = true;
		}
		$result->closeCursor();
		return $claimed;
	}

	/**
	 * Saved planned-week keys in bounded pages, including old weeks that can
	 * gain eligibility after retrospective correction. The unique receipt
	 * protects earlier notifications when any of those weeks is revisited.
	 *
	 * @return list<string>
	 */
	public function plannedWeeksPage(int $houseId, string $beforeWeek, ?string $afterWeek, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('week_start')
			->from('chorestrike_occurrences')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter(Occurrence::KIND_SCHEDULED, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lt('week_start', $qb->createNamedParameter($beforeWeek, IQueryBuilder::PARAM_STR)))
			->groupBy('week_start')
			->orderBy('week_start', 'ASC')
			->setMaxResults($limit);
		if ($afterWeek !== null) {
			$qb->andWhere($qb->expr()->gt('week_start', $qb->createNamedParameter($afterWeek, IQueryBuilder::PARAM_STR)));
		}
		$result = $qb->executeQuery();
		$weeks = array_map(static fn (array $row): string => substr((string)$row['week_start'], 0, 10), $result->fetchAll());
		$result->closeCursor();
		return $weeks;
	}
}
