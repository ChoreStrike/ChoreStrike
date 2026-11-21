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
 * @template-extends QBMapper<OccurrenceMember>
 */
class OccurrenceMemberMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_occ_members', OccurrenceMember::class);
	}

	/**
	 * Members of many occurrences in one query.
	 *
	 * @param int[] $occurrenceIds
	 * @return array<int, OccurrenceMember[]> Map of occurrence ID => members
	 */
	public function findByOccurrenceIds(array $occurrenceIds): array {
		$occurrenceIds = array_values(array_map('intval', $occurrenceIds));
		if ($occurrenceIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$rows = $this->findEntities($qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('occurrence_id', $qb->createNamedParameter($occurrenceIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id', 'ASC'));

		$grouped = [];
		foreach ($rows as $row) {
			$grouped[$row->getOccurrenceId()][] = $row;
		}
		return $grouped;
	}

	/**
	 * Purge every member link of a wallet (account erasure: the shared
	 * occurrence facts stay, the person's links do not).
	 */
	public function deleteByWalletId(int $walletId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('wallet_id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
