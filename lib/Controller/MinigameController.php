<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\MinigameService;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Controller;
use OCP\IRequest;

/**
 * API controller for minigame scores and leaderboards
 */
class MinigameController extends Controller
{
    public function __construct(
        IRequest $request,
        private HouseService $houseService,
        private MinigameService $minigameService,
        private AuthorizationService $authService
    ) {
        parent::__construct('chorestrike', $request);
    }

    /**
     * Get leaderboard for a specific game
     *
     * @NoAdminRequired
     * @param string $gameId The game ID (e.g., 'snake')
     * @param int $limit Maximum number of entries
     */
    public function leaderboard(string $gameId = 'snake', int $limit = 10): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->ensureHouseMember($house->getId());

            $leaderboard = $this->minigameService->getLeaderboard($house->getId(), $gameId, $limit);

            return new JSONResponse([
                'success' => true,
                'data' => $leaderboard
            ]);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'LEADERBOARD_ERROR',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 500);
        }
    }

    /**
     * Get weekly leaderboard for a specific game
     *
     * @NoAdminRequired
     * @param string $gameId The game ID
     * @param int $limit Maximum number of entries
     */
    public function weeklyLeaderboard(string $gameId = 'snake', int $limit = 10): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->ensureHouseMember($house->getId());

            $leaderboard = $this->minigameService->getWeeklyLeaderboard($house->getId(), $gameId, $limit);

            return new JSONResponse([
                'success' => true,
                'data' => $leaderboard
            ]);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'LEADERBOARD_ERROR',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 500);
        }
    }

    /**
     * Save a game score
     *
     * @NoAdminRequired
     * @param string $gameId The game ID
     * @param int $score The achieved score
     * @param array $boostsUsed List of boost IDs used
     */
    public function saveScore(string $gameId = 'snake', int $score = 0, array $boostsUsed = []): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->ensureHouseMember($house->getId());

            $userId = $this->authService->getCurrentUserId();
            $savedScore = $this->minigameService->saveScore(
                $house->getId(),
                $userId,
                $gameId,
                $score,
                $boostsUsed
            );

            // Calculate coins earned (1 Coin per 10 points)
            $coinsEarned = (int) floor($score / 10);

            return new JSONResponse([
                'success' => true,
                'data' => [
                    'id' => $savedScore->getId(),
                    'score' => $savedScore->getScore(),
                    'gameId' => $savedScore->getGameId(),
                    'boostsUsed' => $savedScore->getBoostsUsed(),
                    'createdAt' => $savedScore->getCreatedAt()?->format('c'),
                    'coinsEarned' => $coinsEarned,
                ]
            ]);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'SAVE_SCORE_ERROR',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 500);
        }
    }

    /**
     * Get current user's coins balance.
     *
     * @NoAdminRequired
     */
    public function coins(): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->ensureHouseMember($house->getId());

            $userId = $this->authService->getCurrentUserId();
            $coins = $this->minigameService->getUserCoins($house->getId(), $userId);

            return new JSONResponse([
                'success' => true,
                'data' => [
                    'currentCoins' => $coins
                ]
            ]);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'COINS_ERROR',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 500);
        }
    }

    /**
     * Get personal best score for current user
     *
     * @NoAdminRequired
     * @param string $gameId The game ID
     */
    public function personalBest(string $gameId = 'snake'): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->ensureHouseMember($house->getId());

            $userId = $this->authService->getCurrentUserId();
            $personalBest = $this->minigameService->getPersonalBest($house->getId(), $userId, $gameId);

            return new JSONResponse([
                'success' => true,
                'data' => $personalBest
            ]);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'PERSONAL_BEST_ERROR',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 500);
        }
    }

    /**
     * Get current user's rank in leaderboard
     *
     * @NoAdminRequired
     * @param string $gameId The game ID
     */
    public function userRank(string $gameId = 'snake'): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->ensureHouseMember($house->getId());

            $userId = $this->authService->getCurrentUserId();
            $rank = $this->minigameService->getUserRank($house->getId(), $userId, $gameId);

            return new JSONResponse([
                'success' => true,
                'data' => [
                    'rank' => $rank,
                    'gameId' => $gameId
                ]
            ]);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'USER_RANK_ERROR',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 500);
        }
    }

    /**
     * Get available boosts and their costs
     *
     * @NoAdminRequired
     */
    public function boosts(): JSONResponse
    {
        try {
            $boosts = $this->minigameService->getAvailableBoosts();

            return new JSONResponse([
                'success' => true,
                'data' => $boosts
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'BOOSTS_ERROR',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 500);
        }
    }

    /**
     * Purchase a boost
     *
     * @NoAdminRequired
     * @param string $boostId The boost ID to purchase
     */
    public function purchaseBoost(string $boostId): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->ensureHouseMember($house->getId());

            $userId = $this->authService->getCurrentUserId();
            $result = $this->minigameService->purchaseBoost($house->getId(), $userId, $boostId);

            return new JSONResponse([
                'success' => true,
                'data' => $result
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'PURCHASE_BOOST_ERROR',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 400);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'PURCHASE_BOOST_ERROR',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 500);
        }
    }

    /**
     * Get recent scores for current user
     *
     * @NoAdminRequired
     * @param string $gameId The game ID
     * @param int $limit Maximum number of scores
     */
    public function recentScores(string $gameId = 'snake', int $limit = 5): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->ensureHouseMember($house->getId());

            $userId = $this->authService->getCurrentUserId();
            $scores = $this->minigameService->getRecentScores($house->getId(), $userId, $gameId, $limit);

            return new JSONResponse([
                'success' => true,
                'data' => $scores
            ]);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'RECENT_SCORES_ERROR',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 500);
        }
    }
}
