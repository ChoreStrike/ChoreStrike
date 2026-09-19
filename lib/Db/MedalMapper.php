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
 * Mapper for Medal entity
 */
class MedalMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_medals', Medal::class);
	}

	/**
	 * Find medal by ID
	 *
	 * @param int $id The medal ID
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Medal {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Find all medals for multiple users in one query (CS-PLAN-036).
	 *
	 * IN-clause batch lookup grouped in PHP into a map user_id → Medal[]
	 * (each list ordered awarded_at DESC, matching findByUserId). Users
	 * without medals are absent from the map — callers treat a missing
	 * key as an empty list.
	 *
	 * @param int[] $userIds The house user IDs
	 * @return array<int, Medal[]> Map of house user ID → list of medals
	 */
	public function findByUserIds(array $userIds): array {
		if (empty($userIds)) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('user_id', $qb->createNamedParameter($userIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('awarded_at', 'DESC');

		$map = [];
		foreach ($this->findEntities($qb) as $medal) {
			$map[$medal->getUserId()][] = $medal;
		}

		return $map;
	}

	/**
	 * Find specific medal type for user in week
	 *
	 * @param int $userId The house user ID
	 * @param string $medalType The medal type
	 * @param string $weekStart Week start date (YYYY-MM-DD)
	 * @throws DoesNotExistException
	 */
	public function findByUserTypeAndWeek(int $userId, string $medalType, string $weekStart): Medal {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('medal_type', $qb->createNamedParameter($medalType, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('week_start', $qb->createNamedParameter($weekStart, IQueryBuilder::PARAM_STR)));

		return $this->findEntity($qb);
	}

	/**
	 * Delete every medal of the given memberships in one statement
	 * (CS-PLAN-071 account erasure; medals are personal award rows).
	 *
	 * @param int[] $houseUserIds The house-user IDs
	 */
	public function deleteByUserIds(array $houseUserIds): void {
		$houseUserIds = array_values(array_map('intval', $houseUserIds));
		if ($houseUserIds === []) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->in('user_id', $qb->createNamedParameter($houseUserIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}
}
