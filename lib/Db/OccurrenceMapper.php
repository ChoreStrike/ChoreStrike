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
 * Read model of the retained stored occurrences (ADR-021): windows are
 * derived, so nothing writes these rows any more. The recognitions read
 * the weeks from before the cutover.
 *
 * @template-extends QBMapper<Occurrence>
 */
class OccurrenceMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_occurrences', Occurrence::class);
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
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter(Occurrence::KIND_SCHEDULED, IQueryBuilder::PARAM_STR)))
			->andWhere($this->onOrAfter($qb, 'planned_date', $fromKey))
			->andWhere($this->onOrBefore($qb, 'planned_date', $toKey))
			->orderBy('room_task_id', 'ASC')
			->addOrderBy('schedule_rev_id', 'ASC')
			->addOrderBy('ordinal', 'ASC');
		return $this->findEntities($qb);
	}

	/*
	 * Day bounds on DATE columns. Nextcloud binds an entity's date field as
	 * 'Y-m-d 00:00:00'; PostgreSQL and MySQL store the day, SQLite keeps the
	 * text and compares strings, so `<=`, `=` and `>` against a bare day key
	 * put that day after itself there. `>=` a day and `<` the next day hold
	 * for either form on every provider. Bare keys, because Doctrine's
	 * PARAM_DATE conversion rejects plain 'Y-m-d' strings.
	 */

	private function onOrAfter(IQueryBuilder $qb, string $column, string $dayKey): string {
		return $qb->expr()->gte($column, $qb->createNamedParameter($dayKey, IQueryBuilder::PARAM_STR));
	}

	private function onOrBefore(IQueryBuilder $qb, string $column, string $dayKey): string {
		return $qb->expr()->lt($column, $qb->createNamedParameter(self::dayAfter($dayKey), IQueryBuilder::PARAM_STR));
	}

	private static function dayAfter(string $dayKey): string {
		return (new \DateTimeImmutable($dayKey, new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
	}
}
