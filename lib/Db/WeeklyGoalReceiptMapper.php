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
	 * The weeks that have due work, in bounded pages: stored occurrences by
	 * their planned week and closed cycles by the week their window ended
	 * (ADR-021). Earlier weeks can gain eligibility after a correction; the
	 * unique receipt protects earlier celebrations when one is revisited.
	 * Weeks before `$fromWeek` are left out.
	 *
	 * @return list<string>
	 */
	public function plannedWeeksPage(int $houseId, string $beforeWeek, ?string $afterWeek, int $limit, string $fromWeek = '0000-00-00'): array {
		$weeks = [];
		foreach (['chorestrike_occurrences' => true, 'chorestrike_cycles' => false] as $table => $scheduledOnly) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('week_start')
				->from($table)
				->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->gte('week_start', $qb->createNamedParameter($fromWeek, IQueryBuilder::PARAM_STR)))
				->andWhere($qb->expr()->lt('week_start', $qb->createNamedParameter($beforeWeek, IQueryBuilder::PARAM_STR)))
				->groupBy('week_start')
				->orderBy('week_start', 'ASC')
				->setMaxResults($limit);
			if ($scheduledOnly) {
				$qb->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter(Occurrence::KIND_SCHEDULED, IQueryBuilder::PARAM_STR)));
			}
			if ($afterWeek !== null) {
				$qb->andWhere($qb->expr()->gt('week_start', $qb->createNamedParameter($afterWeek, IQueryBuilder::PARAM_STR)));
			}
			$result = $qb->executeQuery();
			foreach ($result->fetchAll() as $row) {
				$weeks[substr((string)$row['week_start'], 0, 10)] = true;
			}
			$result->closeCursor();
		}
		ksort($weeks, SORT_STRING);
		return array_slice(array_keys($weeks), 0, $limit);
	}
}
