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
 * Mapper for UserStats entity
 */
class UserStatsMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_user_stats', UserStats::class);
	}

	/**
	 * Find user stats by house and user ID
	 */
	public function findByHouseAndUser(int $houseId, string $userId): UserStats {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

		return $this->findEntity($qb);
	}

	/**
	 * Find all stats for a house
	 *
	 * @return UserStats[]
	 */
	public function findByHouse(int $houseId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->orderBy('total_points', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Get leaderboard for a house (top N users by points)
	 *
	 * @return UserStats[]
	 */
	public function getLeaderboard(int $houseId, int $limit = 10): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->orderBy('total_points', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * Get leaderboard by streak
	 *
	 * @return UserStats[]
	 */
	public function getStreakLeaderboard(int $houseId, int $limit = 10): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->orderBy('current_streak', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * Get leaderboard by tasks completed
	 *
	 * @return UserStats[]
	 */
	public function getTasksLeaderboard(int $houseId, int $limit = 10): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->orderBy('tasks_completed', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}
}
