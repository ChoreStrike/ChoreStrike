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
class MinigameScoreMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'chorestrike_minigame_scores', MinigameScore::class);
    }

    /**
     * Find all scores for a house and game
     *
     * @return MinigameScore[]
     */
    public function findByHouseAndGame(int $houseId, string $gameId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('game_id', $qb->createNamedParameter($gameId, IQueryBuilder::PARAM_STR)))
            ->orderBy('score', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Get leaderboard for a house and game (top N scores)
     *
     * @return MinigameScore[]
     */
    public function getLeaderboard(int $houseId, string $gameId, int $limit = 10): array
    {
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
    public function getPersonalBest(int $houseId, string $userId, string $gameId): ?MinigameScore
    {
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
    public function findByUserAndGame(string $userId, string $gameId, int $limit = 10): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
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
        int $limit = 10
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
     */
    public function getUserRank(int $houseId, string $userId, string $gameId): int
    {
        // Get all scores for this house/game ordered by score DESC
        $qb = $this->db->getQueryBuilder();
        
        $qb->select('user_id', 'score')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('game_id', $qb->createNamedParameter($gameId, IQueryBuilder::PARAM_STR)))
            ->orderBy('score', 'DESC');

        $result = $qb->executeQuery();
        $rank = 1;
        $found = false;
        
        while ($row = $result->fetch()) {
            if ($row['user_id'] === $userId) {
                $found = true;
                break;
            }
            $rank++;
        }
        
        $result->closeCursor();
        
        return $found ? $rank : 0;
    }
}
