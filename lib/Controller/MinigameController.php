<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\GameLockedException;
use OCA\ChoreStrike\Exception\ImplausibleScoreException;
use OCA\ChoreStrike\Exception\RateLimitException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\ArcadeAccessService;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\MinigameService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * API controller for minigame scores and leaderboards.
 *
 * Every route names its game explicitly; an unknown id is a 400 on reads
 * and writes (CS-PLAN-086). Read `limit` parameters are clamped to
 * 1..MAX_READ_LIMIT at this boundary (RF-086-01). The catalog route gives
 * the member's lock state per game; a save for a locked game is a 403
 * MINIGAME_LOCKED.
 */
class MinigameController extends Controller {
	use ControllerLoggingTrait;

	/** Largest page any score read returns (RF-086-01). */
	public const MAX_READ_LIMIT = 50;

	private IL10N $l;

	public function __construct(
		IRequest $request,
		private HouseService $houseService,
		private MinigameService $minigameService,
		private AuthorizationService $authService,
		private ArcadeAccessService $arcadeAccess,
		IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->l = $l;
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * The member's arcade catalog: every game with its unlock threshold and
	 * lock state, plus the gate flag and the member's finished chores.
	 *
	 * @NoAdminRequired
	 */
	public function catalog(): JSONResponse {
		return $this->memberRead('catalog', 'MINIGAME_CATALOG_ERROR', fn (int $houseId, HouseUser $member): array
			=> $this->arcadeAccess->getCatalog($member));
	}

	/**
	 * Get the all-time leaderboard of a game
	 *
	 * @NoAdminRequired
	 * @param string $gameId The game ID
	 * @param int $limit Maximum number of entries (clamped)
	 */
	public function leaderboard(string $gameId, int $limit = 10): JSONResponse {
		return $this->memberRead('leaderboard', 'LEADERBOARD_ERROR', fn (int $houseId): array
			=> $this->minigameService->getLeaderboard($houseId, $gameId, self::clampLimit($limit)));
	}

	/**
	 * Get the weekly leaderboard of a game
	 *
	 * @NoAdminRequired
	 * @param string $gameId The game ID
	 * @param int $limit Maximum number of entries (clamped)
	 */
	public function weeklyLeaderboard(string $gameId, int $limit = 10): JSONResponse {
		return $this->memberRead('weeklyLeaderboard', 'LEADERBOARD_ERROR', fn (int $houseId): array
			=> $this->minigameService->getWeeklyLeaderboard($houseId, $gameId, self::clampLimit($limit)));
	}

	/**
	 * Save a game score (WP3: scores only — never wallet/contribution).
	 *
	 * @NoAdminRequired
	 * @param string $gameId The game ID
	 * @param int $score The achieved score
	 * @param int|null $durationMs Active play time in milliseconds (required, D-086-04)
	 */
	public function saveScore(string $gameId, int $score = 0, ?int $durationMs = null): JSONResponse {
		try {
			[$houseId] = $this->memberContext();
			if ($durationMs === null) {
				throw new \InvalidArgumentException($this->l->t('Play time is required'));
			}
			$userId = $this->authService->getCurrentUserId();
			$savedScore = $this->minigameService->saveScore($houseId, $userId, $gameId, $score, $durationMs);

			return new JSONResponse([
				'success' => true,
				'data' => [
					'id' => $savedScore->getId(),
					'score' => $savedScore->getScore(),
					'gameId' => $savedScore->getGameId(),
					'createdAt' => $savedScore->getCreatedAt()?->format('c'),
				]
			]);
		} catch (GameLockedException $e) {
			// CS-PLAN-086 §3.2: curated, translated message.
			return new JSONResponse([
				'success' => false,
				'error' => ['code' => 'MINIGAME_LOCKED', 'message' => $e->getMessage()],
			], 403);
		} catch (ImplausibleScoreException $e) {
			// D-086-04: curated, translated message.
			return ControllerErrorFormatter::validation(
				new \InvalidArgumentException($e->getMessage()),
				'MINIGAME_IMPLAUSIBLE_SCORE'
			);
		} catch (\InvalidArgumentException $e) {
			// Service-curated messages (unknown game, score and duration
			// bounds) — safe to surface verbatim; safeMessage() would replace
			// them with the generic fallback.
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
			return ControllerErrorFormatter::serverErrorResponse($e, 'SAVE_SCORE_ERROR');
		}
	}

	/**
	 * Get personal best score for current user
	 *
	 * @NoAdminRequired
	 * @param string $gameId The game ID
	 */
	public function personalBest(string $gameId): JSONResponse {
		return $this->memberRead('personalBest', 'PERSONAL_BEST_ERROR', fn (int $houseId): ?array
			=> $this->minigameService->getPersonalBest($houseId, $this->authService->getCurrentUserId(), $gameId));
	}

	/**
	 * Get current user's rank in leaderboard
	 *
	 * @NoAdminRequired
	 * @param string $gameId The game ID
	 */
	public function userRank(string $gameId): JSONResponse {
		return $this->memberRead('userRank', 'USER_RANK_ERROR', fn (int $houseId): array => [
			'rank' => $this->minigameService->getUserRank($houseId, $this->authService->getCurrentUserId(), $gameId),
			'gameId' => $gameId,
		]);
	}

	/**
	 * Get recent scores for current user
	 *
	 * @NoAdminRequired
	 * @param string $gameId The game ID
	 * @param int $limit Maximum number of scores (clamped)
	 */
	public function recentScores(string $gameId, int $limit = 5): JSONResponse {
		return $this->memberRead('recentScores', 'RECENT_SCORES_ERROR', fn (int $houseId): array
			=> $this->minigameService->getRecentScores(
				$houseId,
				$this->authService->getCurrentUserId(),
				$gameId,
				self::clampLimit($limit)
			));
	}

	/**
	 * Clamp a client page size to 1..MAX_READ_LIMIT (RF-086-01): a large
	 * value never returns the whole house board, a non-positive one never
	 * reaches the database as `LIMIT -n`.
	 */
	private static function clampLimit(int $limit): int {
		return max(1, min(self::MAX_READ_LIMIT, $limit));
	}

	/**
	 * Resolve the instance house and require membership (read-only gate).
	 *
	 * @return array{int, HouseUser} The house id and the caller's membership
	 * @throws ForbiddenException
	 */
	private function memberContext(): array {
		$houseId = $this->houseService->getOrCreateHouse($this->houseService->getInstanceId())->getId();
		return [$houseId, $this->authService->assertHouseMember($houseId)];
	}

	/**
	 * Shared envelope of the arcade reads: membership first (403), then an
	 * unknown game (400), otherwise a logged 500 with the route's code.
	 *
	 * @param callable(int, HouseUser): mixed $read Receives the member's house id and membership
	 */
	private function memberRead(string $action, string $errorCode, callable $read): JSONResponse {
		try {
			[$houseId, $member] = $this->memberContext();
			return new JSONResponse([
				'success' => true,
				'data' => $read($houseId, $member),
			]);
		} catch (\InvalidArgumentException $e) {
			// Thrown by the service for an unknown game only (curated text).
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException($action, $e);
			return ControllerErrorFormatter::serverErrorResponse($e, $errorCode);
		}
	}
}
