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
	 * @return OccurrenceMember[] Members of one occurrence.
	 */
	public function findByOccurrence(int $occurrenceId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('occurrence_id', $qb->createNamedParameter($occurrenceId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
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
	 * Insert a member row; tolerant of the natural-key race (the house lock
	 * makes this unreachable in practice). Called inside the owning
	 * transaction.
	 */
	public function addMember(
		int $occurrenceId,
		int $walletId,
		string $role,
		bool $obligated,
		int $sharePct,
	): OccurrenceMember {
		$member = new OccurrenceMember();
		$member->setOccurrenceId($occurrenceId);
		$member->setWalletId($walletId);
		$member->setRole($role);
		$member->setObligated($obligated);
		$member->setSharePct($sharePct);
		$member->setDisposition(OccurrenceMember::DISPOSITION_ACTIVE);
		$member->setCreatedAt(new \DateTime());
		return $this->insert($member);
	}

	/**
	 * Replace the member set of an occurrence inside the owning transaction
	 * (agreement writer path).
	 *
	 * @param array<int, array{walletId: int, role: string, obligated: bool, sharePct: int}> $members
	 */
	public function replaceForOccurrence(int $occurrenceId, array $members): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('occurrence_id', $qb->createNamedParameter($occurrenceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		foreach ($members as $member) {
			$this->addMember(
				$occurrenceId,
				(int)$member['walletId'],
				(string)$member['role'],
				(bool)$member['obligated'],
				(int)$member['sharePct'],
			);
		}
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

	/**
	 * Delete member rows of the given occurrences (occurrence deletion /
	 * undo successor release).
	 *
	 * @param int[] $occurrenceIds
	 */
	public function deleteByOccurrenceIds(array $occurrenceIds): void {
		$occurrenceIds = array_values(array_map('intval', $occurrenceIds));
		if ($occurrenceIds === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->in('occurrence_id', $qb->createNamedParameter($occurrenceIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}
}
