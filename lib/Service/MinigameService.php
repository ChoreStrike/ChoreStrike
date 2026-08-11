<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MinigameScore;
use OCA\ChoreStrike\Db\MinigameScoreMapper;

/**
 * Service for managing minigame scores and leaderboards
 */
class MinigameService
{
    /**
     * Available boosts with their costs in Coins
     */
    public const BOOST_COSTS = [
        'slow_motion' => 10,      // 20% slower
        'ghost_mode' => 20,       // 1 extra life
        'magnet' => 30,           // Auto-collect nearby
        'shrink' => 15,           // Shrink snake periodically
        'no_obstacles' => 25,     // Remove obstacles
    ];

    /**
     * Sane per-game score ceiling (CR-SVC-05).
     *
     * Client scores are untrusted: an unbounded value minted unlimited Coins
     * via floor(score/10) and a negative value drained Coins. This ceiling is
     * generous for any realistic game (a snake high score is in the low
     * thousands) while blocking INT_MAX coin farming and negative drains.
     */
    public const MAX_SCORE = 100000;

    private MinigameScoreMapper $scoreMapper;
    private HouseUserMapper $houseUserMapper;

    public function __construct(
        MinigameScoreMapper $scoreMapper,
        HouseUserMapper $houseUserMapper
    ) {
        $this->scoreMapper = $scoreMapper;
        $this->houseUserMapper = $houseUserMapper;
    }

    /**
     * Save a game score
     *
     * @param int $houseId The house ID
     * @param string $userId The Nextcloud user ID
     * @param string $gameId The game ID (e.g., 'snake')
     * @param int $score The achieved score
     * @param array $boostsUsed List of boost IDs used during the game
     * @return MinigameScore The persisted score entity
     * @throws \InvalidArgumentException if the score is out of bounds (CR-SVC-05)
     */
    public function saveScore(int $houseId, string $userId, string $gameId, int $score, array $boostsUsed = []): MinigameScore
    {
        // CR-SVC-05: bound the client-supplied score before any insert or coin
        // award. Reject negatives (coin drain) and over-cap values (coin
        // farming via floor(score/10)).
        if ($score < 0 || $score > self::MAX_SCORE) {
            throw new \InvalidArgumentException(
                'Score must be between 0 and ' . self::MAX_SCORE
            );
        }

        $scoreEntity = new MinigameScore();
        $scoreEntity->setHouseId($houseId);
        $scoreEntity->setUserId($userId);
        $scoreEntity->setGameId($gameId);
        $scoreEntity->setScore($score);
        $scoreEntity->setBoostsUsed(!empty($boostsUsed) ? $boostsUsed : null);
        $scoreEntity->setCreatedAt(new \DateTime());

        $savedScore = $this->scoreMapper->insert($scoreEntity);

        // Award Coins for playing (1 Coin per 10 points)
        $coinsEarned = (int) floor($score / 10);
        if ($coinsEarned > 0) {
            $user = $this->houseUserMapper->findByHouseIdAndNextcloudUserId($houseId, $userId);
            if ($user) {
                $user->addCoins($coinsEarned);
                $this->houseUserMapper->update($user);
            }
        }

        return $savedScore;
    }

    /**
     * Get leaderboard for a house and game
     *
     * @param int $houseId The house ID
     * @param string $gameId The game ID
     * @param int $limit Maximum number of entries
     * @return array Array of scores with user display names
     */
    public function getLeaderboard(int $houseId, string $gameId, int $limit = 10): array
    {
        $scores = $this->scoreMapper->getLeaderboard($houseId, $gameId, $limit);
        
        // Enrich with user display names
        $result = [];
        foreach ($scores as $score) {
            $user = $this->houseUserMapper->findByHouseIdAndNextcloudUserId($houseId, $score->getUserId());
            $result[] = [
                'id' => $score->getId(),
                'userId' => $score->getUserId(),
                'displayName' => $user ? $user->getDisplayName() : $score->getUserId(),
                'score' => $score->getScore(),
                'boostsUsed' => $score->getBoostsUsed(),
                'createdAt' => $score->getCreatedAt()?->format('c'),
            ];
        }

        return $result;
    }

    /**
     * Get weekly leaderboard for a house and game
     *
     * @param int $houseId The house ID
     * @param string $gameId The game ID
     * @param int $limit Maximum number of entries
     * @return array Array of scores with user display names
     */
    public function getWeeklyLeaderboard(int $houseId, string $gameId, int $limit = 10): array
    {
        $weekStart = new \DateTime('monday this week');
        $scores = $this->scoreMapper->getLeaderboardForPeriod($houseId, $gameId, $weekStart, $limit);
        
        // Enrich with user display names
        $result = [];
        foreach ($scores as $score) {
            $user = $this->houseUserMapper->findByHouseIdAndNextcloudUserId($houseId, $score->getUserId());
            $result[] = [
                'id' => $score->getId(),
                'userId' => $score->getUserId(),
                'displayName' => $user ? $user->getDisplayName() : $score->getUserId(),
                'score' => $score->getScore(),
                'boostsUsed' => $score->getBoostsUsed(),
                'createdAt' => $score->getCreatedAt()?->format('c'),
            ];
        }

        return $result;
    }

    /**
     * Get personal best score for a user in a specific game
     *
     * @param int $houseId The house ID
     * @param string $userId The Nextcloud user ID
     * @param string $gameId The game ID
     * @return array|null Personal best data or null if no scores exist
     */
    public function getPersonalBest(int $houseId, string $userId, string $gameId): ?array
    {
        $score = $this->scoreMapper->getPersonalBest($houseId, $userId, $gameId);
        
        if ($score === null) {
            return null;
        }

        return [
            'id' => $score->getId(),
            'score' => $score->getScore(),
            'boostsUsed' => $score->getBoostsUsed(),
            'createdAt' => $score->getCreatedAt()?->format('c'),
        ];
    }

    /**
     * Get user's rank in the leaderboard
     *
     * @param int $houseId The house ID
     * @param string $userId The Nextcloud user ID
     * @param string $gameId The game ID
     * @return int The user's rank (1-based) or 0 if not ranked
     */
    public function getUserRank(int $houseId, string $userId, string $gameId): int
    {
        return $this->scoreMapper->getUserRank($houseId, $userId, $gameId);
    }

    /**
     * Purchase a boost for a game
     *
     * @param int $houseId The house ID
     * @param string $userId The Nextcloud user ID
     * @param string $boostId The boost ID to purchase
     * @return array Result with success status and remaining coins
     * @throws \InvalidArgumentException If boost is unknown or insufficient coins
     */
    public function purchaseBoost(int $houseId, string $userId, string $boostId): array
    {
        // Validate boost exists
        if (!isset(self::BOOST_COSTS[$boostId])) {
            throw new \InvalidArgumentException("Unknown boost: {$boostId}");
        }

        $cost = self::BOOST_COSTS[$boostId];
        $user = $this->houseUserMapper->findByHouseIdAndNextcloudUserId($houseId, $userId);

        if ($user === null) {
            throw new \InvalidArgumentException("User not found in house");
        }

        // Check if user has enough coins
        if ($user->getCoins() < $cost) {
            throw new \InvalidArgumentException("Insufficient coins. Need {$cost}, have {$user->getCoins()}");
        }

        // Deduct coins
        $user->setCoins(max(0, $user->getCoins() - $cost));
        $this->houseUserMapper->update($user);

        return [
            'success' => true,
            'boostId' => $boostId,
            'cost' => $cost,
            'remainingCoins' => $user->getCoins(),
        ];
    }

    /**
     * Get available boosts and their costs
     *
     * @return array Map of boost ID to cost in Sparks
     */
    public function getAvailableBoosts(): array
    {
        return self::BOOST_COSTS;
    }

    /**
     * Get user's current coins balance.
     *
     * @param int $houseId The house ID
     * @param string $userId The Nextcloud user ID
     * @return int Current coins balance
     */
    public function getUserCoins(int $houseId, string $userId): int
    {
        $user = $this->houseUserMapper->findByHouseIdAndNextcloudUserId($houseId, $userId);

        if ($user === null) {
            return 0;
        }

        return $user->getCoins();
    }

    /**
     * Get recent scores for a user
     *
     * @param int $houseId The house ID
     * @param string $userId The Nextcloud user ID
     * @param string $gameId The game ID
     * @param int $limit Maximum number of scores
     * @return array Array of recent scores
     */
    public function getRecentScores(int $houseId, string $userId, string $gameId, int $limit = 5): array
    {
        $scores = $this->scoreMapper->findByUserAndGame($houseId, $userId, $gameId, $limit);
        
        return array_map(fn ($score) => [
            'id' => $score->getId(),
            'score' => $score->getScore(),
            'boostsUsed' => $score->getBoostsUsed(),
            'createdAt' => $score->getCreatedAt()?->format('c'),
        ], $scores);
    }
}
