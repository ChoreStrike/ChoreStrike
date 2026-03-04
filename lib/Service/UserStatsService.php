<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\UserStats;
use OCA\ChoreStrike\Db\UserStatsMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

/**
 * Service for managing user statistics
 */
class UserStatsService
{
    public function __construct(
        private UserStatsMapper $userStatsMapper,
    ) {
    }

    /**
     * Get or create user stats
     */
    public function getOrCreateStats(int $houseId, string $userId): UserStats
    {
        try {
            return $this->userStatsMapper->findByHouseAndUser($houseId, $userId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException) {
            $stats = new UserStats();
            $stats->setHouseId($houseId);
            $stats->setUserId($userId);
            $stats->setTotalPoints(0);
            $stats->setCurrentStreak(0);
            $stats->setLongestStreak(0);
            $stats->setTasksCompleted(0);
            $stats->setUpdatedAt(new \DateTime());
            return $this->userStatsMapper->insert($stats);
        }
    }

    /**
     * Add points to user
     */
    public function addPoints(int $houseId, string $userId, int $points): UserStats
    {
        $stats = $this->getOrCreateStats($houseId, $userId);
        $stats->addPoints($points);
        return $this->userStatsMapper->update($stats);
    }

    /**
     * Record task completion
     */
    public function recordTaskCompletion(int $houseId, string $userId): UserStats
    {
        $stats = $this->getOrCreateStats($houseId, $userId);
        $stats->incrementTasksCompleted();
        return $this->userStatsMapper->update($stats);
    }

    /**
     * Update streak after task completion
     */
    public function updateStreakAfterCompletion(int $houseId, string $userId, int $newStreak): UserStats
    {
        $stats = $this->getOrCreateStats($houseId, $userId);
        $stats->updateStreak($newStreak);
        return $this->userStatsMapper->update($stats);
    }

    /**
     * Reset streak for user
     */
    public function resetStreak(int $houseId, string $userId): UserStats
    {
        $stats = $this->getOrCreateStats($houseId, $userId);
        $stats->resetStreak();
        return $this->userStatsMapper->update($stats);
    }

    /**
     * Get leaderboard for house
     *
     * @return UserStats[]
     */
    public function getLeaderboard(int $houseId, int $limit = 10): array
    {
        return $this->userStatsMapper->getLeaderboard($houseId, $limit);
    }

    /**
     * Get streak leaderboard
     *
     * @return UserStats[]
     */
    public function getStreakLeaderboard(int $houseId, int $limit = 10): array
    {
        return $this->userStatsMapper->getStreakLeaderboard($houseId, $limit);
    }

    /**
     * Get tasks leaderboard
     *
     * @return UserStats[]
     */
    public function getTasksLeaderboard(int $houseId, int $limit = 10): array
    {
        return $this->userStatsMapper->getTasksLeaderboard($houseId, $limit);
    }

    /**
     * Get all stats for house
     *
     * @return UserStats[]
     */
    public function getHouseStats(int $houseId): array
    {
        return $this->userStatsMapper->findByHouse($houseId);
    }
}
