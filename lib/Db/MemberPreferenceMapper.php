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
 * @template-extends QBMapper<MemberPreference>
 */
class MemberPreferenceMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_member_prefs', MemberPreference::class);
	}

	/**
	 * The preference row of one membership, or null when the member is
	 * unconsented (the row exists exactly while one generation is live).
	 */
	public function findByHouseAndUser(int $houseId, int $houseUserId): ?MemberPreference {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('house_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Every preference row of a house (ranking sweep input).
	 *
	 * @return MemberPreference[]
	 */
	public function findByHouseId(int $houseId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->orderBy('house_user_id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Insert-or-return: the unique (house, membership) identity supplies
	 * idempotency for concurrent first consents — a lost race re-reads the
	 * winner's row. Called inside the owning house-first transaction.
	 *
	 * @throws \OCP\DB\Exception when the insert fails for any reason other
	 *                           than the unique-constraint race
	 */
	public function insertOrGet(MemberPreference $preference): MemberPreference {
		try {
			return $this->insert($preference);
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			$existing = $this->findByHouseAndUser(
				$preference->getHouseId(),
				$preference->getHouseUserId()
			);
			if ($existing === null) {
				throw $e;
			}
			return $existing;
		}
	}

	/**
	 * Delete the membership's preference row (withdrawal / departure).
	 * Idempotent — no row is a no-op.
	 */
	public function deleteByHouseAndUser(int $houseId, int $houseUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('house_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Delete every preference row of a house (ranking-flag disable
	 * invalidates every generation; re-enabling starts everyone
	 * unconsented).
	 */
	public function deleteByHouseId(int $houseId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
