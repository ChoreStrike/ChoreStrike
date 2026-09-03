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
 * Mapper for MinigameScore entity
 */
class MinigameScoreMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_minigame_scores', MinigameScore::class);
	}

	/**
	 * Get leaderboard for a house and game (top N scores)
	 *
	 * @return MinigameScore[]
	 */
	public function getLeaderboard(int $houseId, string $gameId, int $limit = 10): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('game_id', $qb->createNamedParameter($gameId, IQueryBuilder::PARAM_STR)))
			->orderBy('score', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * Get personal best score for a user in a specific game
	 */
	public function getPersonalBest(int $houseId, string $userId, string $gameId): ?MinigameScore {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('game_id', $qb->createNamedParameter($gameId, IQueryBuilder::PARAM_STR)))
			->orderBy('score', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Get all scores for a user in a specific game
	 *
	 * @return MinigameScore[]
	 */
	public function findByUserAndGame(int $houseId, string $userId, string $gameId, int $limit = 10): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('game_id', $qb->createNamedParameter($gameId, IQueryBuilder::PARAM_STR)))
			->orderBy('score', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * Get leaderboard for a house and game within a time period
	 *
	 * @return MinigameScore[]
	 */
	public function getLeaderboardForPeriod(
		int $houseId,
		string $gameId,
		\DateTime $since,
		int $limit = 10,
	): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('game_id', $qb->createNamedParameter($gameId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATE)))
			->orderBy('score', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * Get user's rank in leaderboard for a specific game
	 *
	 * Rank = number of users whose BEST score in this house/game beats the
	 * user's best, plus 1 (0 when the user has no score). Bounded: two
	 * aggregate queries replace the former unbounded PHP scan over the whole
	 * house+game result set (CS-PLAN-060 §3.6).
	 */
	public function getUserRank(int $houseId, string $userId, string $gameId): int {
		// The user's best score in this house/game.
		$bestQb = $this->db->getQueryBuilder();
		$bestQb->select($bestQb->func()->max('score'))
			->from($this->getTableName())
			->where($bestQb->expr()->eq('house_id', $bestQb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($bestQb->expr()->eq('user_id', $bestQb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($bestQb->expr()->eq('game_id', $bestQb->createNamedParameter($gameId, IQueryBuilder::PARAM_STR)));

		$result = $bestQb->executeQuery();
		$bestScore = $result->fetchOne();
		$result->closeCursor();

		if ($bestScore === null || $bestScore === false) {
			return 0;
		}

		// Count DISTINCT users whose best beats ours (their top row's score
		// is their best, so matching on top rows is exact), rank = count + 1.
		$rankQb = $this->db->getQueryBuilder();
		$rankQb->select($rankQb->createFunction('COUNT(DISTINCT user_id)'))
			->from($this->getTableName())
			->where($rankQb->expr()->eq('house_id', $rankQb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($rankQb->expr()->eq('game_id', $rankQb->createNamedParameter($gameId, IQueryBuilder::PARAM_STR)))
			->andWhere($rankQb->expr()->gt('score', $rankQb->createNamedParameter($bestScore, IQueryBuilder::PARAM_INT)));

		$result = $rankQb->executeQuery();
		$betterUsers = (int)$result->fetchOne();
		$result->closeCursor();

		return $betterUsers + 1;
	}
}
