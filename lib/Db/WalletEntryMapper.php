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
 * @template-extends QBMapper<WalletEntry>
 */
class WalletEntryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_wallet_entries', WalletEntry::class);
	}

	/**
	 * All ledger entries of a wallet (ascending id = append order).
	 *
	 * @return WalletEntry[]
	 */
	public function findByWalletId(int $walletId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('wallet_id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Delete every entry of the given wallets in one statement (account
	 * erasure sweep). No runtime writer exists before WP3 (decision D1) —
	 * in WP0 the table is empty, the sweep is future-proof plumbing.
	 *
	 * @param int[] $walletIds
	 */
	public function deleteByWalletIds(array $walletIds): void {
		$walletIds = array_values(array_map('intval', $walletIds));
		if ($walletIds === []) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->in('wallet_id', $qb->createNamedParameter($walletIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}
}
