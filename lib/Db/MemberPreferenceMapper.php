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
	 * The preference row of one membership, or null when the member has
	 * never saved preferences or consented.
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
	 * Every preference row of one membership across all houses (erasure
	 * sweep input for a specific membership id namespace).
	 *
	 * @return MemberPreference[]
	 */
	public function findAllByHouseUserIds(array $houseUserIds): array {
		if ($houseUserIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('house_user_id', $qb->createNamedParameter($houseUserIds, IQueryBuilder::PARAM_INT_ARRAY)));
		return $this->findEntities($qb);
	}

	/**
	 * Insert-or-return: the unique (house, membership) identity supplies
	 * idempotency for concurrent first writes — a lost race re-reads the
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
	 * Reset ONLY the ranking-consent columns of one membership's row
	 * (withdraw, Q-076-03 A): generation 0, Monday/zone cleared, shared
	 * version advanced. Delivery preferences survive. Idempotent — no
	 * row is a no-op. Returns the affected row count.
	 */
	public function clearRankingConsentByHouseAndUser(int $houseId, int $houseUserId, \DateTimeInterface $now): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->update($this->getTableName())
			->set('ranking_consent_generation', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('ranking_effective_monday', $qb->createNamedParameter(null))
			->set('ranking_saved_zone', $qb->createNamedParameter(null))
			->set('version', $qb->createFunction('version + 1'))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('house_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Reset ONLY the ranking-consent columns of EVERY row of a house
	 * (house ranking flag disable, Q-076-03 A): delivery preferences
	 * survive; re-enabling starts everyone unconsented.
	 */
	public function clearRankingConsentByHouseId(int $houseId, \DateTimeInterface $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('ranking_consent_generation', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('ranking_effective_monday', $qb->createNamedParameter(null))
			->set('ranking_saved_zone', $qb->createNamedParameter(null))
			->set('version', $qb->createFunction('version + 1'))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Delete the membership's preference row (departure / account
	 * erasure — the ONLY deletion paths, Q-076-03 A). Idempotent — no
	 * row is a no-op.
	 */
	public function deleteByHouseAndUser(int $houseId, int $houseUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('house_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
