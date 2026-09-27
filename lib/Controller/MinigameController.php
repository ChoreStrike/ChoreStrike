<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\RateLimitException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\MinigameService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * API controller for minigame scores and leaderboards
 */
class MinigameController extends Controller {
	use ControllerLoggingTrait;

	private IL10N $l;

	public function __construct(
		IRequest $request,
		private HouseService $houseService,
		private MinigameService $minigameService,
		private AuthorizationService $authService,
		IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->l = $l;
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * Get leaderboard for a specific game
	 *
	 * @NoAdminRequired
	 * @param string $gameId The game ID (e.g., 'snake')
	 * @param int $limit Maximum number of entries
	 */
	public function leaderboard(string $gameId = 'snake', int $limit = 10): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->assertHouseMember($house->getId());

			$leaderboard = $this->minigameService->getLeaderboard($house->getId(), $gameId, $limit);

			return new JSONResponse([
				'success' => true,
				'data' => $leaderboard
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('leaderboard', $e);
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
	public function weeklyLeaderboard(string $gameId = 'snake', int $limit = 10): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->assertHouseMember($house->getId());

			$leaderboard = $this->minigameService->getWeeklyLeaderboard($house->getId(), $gameId, $limit);

			return new JSONResponse([
				'success' => true,
				'data' => $leaderboard
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('leaderboard', $e);
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
	 * Save a game score (WP3: scores only — never wallet/contribution).
	 *
	 * @NoAdminRequired
	 * @param string $gameId The game ID
	 * @param int $score The achieved score
	 */
	public function saveScore(string $gameId = 'snake', int $score = 0): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->assertHouseMember($house->getId());

			$userId = $this->authService->getCurrentUserId();
			$savedScore = $this->minigameService->saveScore(
				$house->getId(),
				$userId,
				$gameId,
				$score
			);

			return new JSONResponse([
				'success' => true,
				'data' => [
					'id' => $savedScore->getId(),
					'score' => $savedScore->getScore(),
					'gameId' => $savedScore->getGameId(),
					'createdAt' => $savedScore->getCreatedAt()?->format('c'),
				]
			]);
		} catch (\InvalidArgumentException $e) {
			// Service-curated messages (score bounds, unknown game) — safe to
			// surface verbatim; safeMessage() would replace them with the
			// generic fallback.
			return ControllerErrorFormatter::validation($e);
		} catch (RateLimitException) {
			// D7: per-user daily save cap → 429 MINIGAME_RATE_LIMITED.
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'MINIGAME_RATE_LIMITED',
					'message' => $this->l->t('Too many score submissions today. Try again tomorrow.'),
				],
			], 429);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('saveScore', $e);
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
	 * Get personal best score for current user
	 *
	 * @NoAdminRequired
	 * @param string $gameId The game ID
	 */
	public function personalBest(string $gameId = 'snake'): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->assertHouseMember($house->getId());

			$userId = $this->authService->getCurrentUserId();
			$personalBest = $this->minigameService->getPersonalBest($house->getId(), $userId, $gameId);

			return new JSONResponse([
				'success' => true,
				'data' => $personalBest
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('personalBest', $e);
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
	public function userRank(string $gameId = 'snake'): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->assertHouseMember($house->getId());

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
			$this->logControllerException('userRank', $e);
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
	 * Get recent scores for current user
	 *
	 * @NoAdminRequired
	 * @param string $gameId The game ID
	 * @param int $limit Maximum number of scores
	 */
	public function recentScores(string $gameId = 'snake', int $limit = 5): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->assertHouseMember($house->getId());

			$userId = $this->authService->getCurrentUserId();
			$scores = $this->minigameService->getRecentScores($house->getId(), $userId, $gameId, $limit);

			return new JSONResponse([
				'success' => true,
				'data' => $scores
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('recentScores', $e);
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
