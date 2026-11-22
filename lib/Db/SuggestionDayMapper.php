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
 * The daily snapshots of the live suggestion (ADR-022 decision 3): one
 * row per house and house-local day under the unique `(house_id,
 * day_key)` key.
 *
 * @template-extends QBMapper<SuggestionDay>
 */
class SuggestionDayMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_suggestion_days', SuggestionDay::class);
	}

	/** The snapshot of one house-local day, or null before the day's first read. */
	public function findDay(int $houseId, string $dayKey): ?SuggestionDay {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('day_key', $qb->createNamedParameter($dayKey)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Store the day's snapshot unless one exists, and return the stored
	 * one: concurrent first reads converge on the row that won the unique
	 * key. The conflict is ignored by the statement itself, so the write
	 * is also safe inside a caller's transaction.
	 *
	 * @param list<array<string, mixed>> $placements
	 */
	public function storeOnce(int $houseId, string $dayKey, array $placements, \DateTimeInterface $now): SuggestionDay {
		$this->db->insertIgnoreConflict($this->getTableName(), [
			'house_id' => $houseId,
			'day_key' => $dayKey,
			'placements' => json_encode($placements, JSON_THROW_ON_ERROR),
			'created_at' => \DateTime::createFromInterface($now)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
		]);
		$stored = $this->findDay($houseId, $dayKey);
		if ($stored === null) {
			throw new \RuntimeException('The suggestion snapshot of the day was not stored');
		}
		return $stored;
	}

	/**
	 * Delete the snapshots of days before the given one (timeline job).
	 *
	 * @return int Deleted rows
	 */
	public function deleteBefore(int $houseId, string $dayKey): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('day_key', $qb->createNamedParameter($dayKey)))
			->executeStatement();
	}
}
