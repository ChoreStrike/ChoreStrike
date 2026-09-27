<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\MinigameScore;
use OCA\ChoreStrike\Db\MinigameScoreMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCP\IL10N;

/**
 * Service for managing minigame scores and leaderboards.
 *
 * CS-PLAN-070 WP3 (T10/D7d no-Coin arcade cutover): scores are family
 * fun only — they never write wallet entries, contribution or any reward.
 * The former Coin minting (floor(score/10)), boost purchase/catalog and
 * Coin-balance reads are retired with their routes and client consumers.
 */
class MinigameService {
	/**
	 * Sane per-game score ceiling (CR-SVC-05).
	 *
	 * Client scores are untrusted: the ceiling is generous for any
	 * realistic game (a snake high score is in the low thousands) while
	 * blocking INT_MAX farming and negative values.
	 */
	public const MAX_SCORE = 100000;

	/**
	 * Whitelist of game ids the API accepts (CS-PLAN-061 §3.7, decision D7):
	 * an unknown gameId is a 400. Keep in sync with the frontend game
	 * catalog (currently only snake).
	 */
	public const VALID_GAMES = ['snake'];

	/**
	 * Per-user daily saveScore cap (CS-PLAN-061 §3.7, decision D7): at most
	 * this many score submissions per house-local day; further submissions
	 * are rejected with 429 MINIGAME_RATE_LIMITED.
	 */
	public const DAILY_SCORE_SAVE_LIMIT = 100;

	private MinigameScoreMapper $scoreMapper;
	private \OCA\ChoreStrike\Db\HouseUserMapper $houseUserMapper;
	private TransactionExecutor $transactions;
	private IL10N $l10n;
	private AppLogger $appLogger;
	private \OCA\ChoreStrike\Db\HouseMapper $houseMapper;
	private CalendarService $calendarService;

	public function __construct(
		MinigameScoreMapper $scoreMapper,
		\OCA\ChoreStrike\Db\HouseUserMapper $houseUserMapper,
		TransactionExecutor $transactions,
		IL10N $l10n,
		AppLogger $appLogger,
		\OCA\ChoreStrike\Db\HouseMapper $houseMapper,
		CalendarService $calendarService,
	) {
		$this->scoreMapper = $scoreMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->transactions = $transactions;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
		$this->houseMapper = $houseMapper;
		$this->calendarService = $calendarService;
	}

	/**
	 * Save a game score (scores only — no monetary effect by contract,
	 * T10/D7d).
	 *
	 * @param int $houseId The house ID
	 * @param string $userId The Nextcloud user ID
	 * @param string $gameId The game ID (e.g., 'snake')
	 * @param int $score The achieved score
	 * @return MinigameScore The persisted score entity
	 * @throws \InvalidArgumentException if the score is out of bounds (CR-SVC-05) or the gameId is unknown (D7)
	 * @throws \OCA\ChoreStrike\Exception\RateLimitException when the per-user daily save cap is reached (D7)
	 */
	public function saveScore(int $houseId, string $userId, string $gameId, int $score): MinigameScore {
		// CR-SVC-05: bound the client-supplied score before any insert.
		if ($score < 0 || $score > self::MAX_SCORE) {
			throw new \InvalidArgumentException(
				$this->l10n->t('Score must be between 0 and %1$d', [self::MAX_SCORE])
			);
		}

		// D7: unknown gameIds are rejected outright.
		if (!in_array($gameId, self::VALID_GAMES, true)) {
			throw new \InvalidArgumentException($this->l10n->t('Unknown game'));
		}

		// D7: per-user daily save cap. The window is the house-local calendar
		// day (CS-PLAN-057 convention), resolved before the write transaction.
		$tz = $this->calendarService->getTimeZone($this->houseMapper->find($houseId));
		[$dayStart, ] = $this->calendarService->dayBounds(new \DateTime(), $tz);
		if ($this->scoreMapper->countByUserSince($houseId, $userId, $dayStart) >= self::DAILY_SCORE_SAVE_LIMIT) {
			$this->appLogger->warning('Minigame score save rejected: daily cap reached', [
				'event' => 'minigame_rate_limited',
				'service' => 'MinigameService',
				'houseId' => $houseId,
				'userId' => $userId,
				'gameId' => $gameId,
			]);
			throw new \OCA\ChoreStrike\Exception\RateLimitException(
				$this->l10n->t('Too many score submissions today. Try again tomorrow.')
			);
		}

		try {
			return $this->transactions->run(function () use ($houseId, $userId, $gameId, $score): MinigameScore {
				// FIRST lock of the documented order (CS-PLAN-071 WP0): the
				// house row — the score write serializes with all other house
				// mutations. The score entity is created per attempt so a
				// transient-lock retry inserts a clean row.
				$this->houseMapper->findForUpdate($houseId);
				if ($this->houseUserMapper->findByHouseAndUser($houseId, $userId) === null) {
					throw new ForbiddenException($this->l10n->t('Not a member of this house'));
				}

				$scoreEntity = new MinigameScore();
				$scoreEntity->setHouseId($houseId);
				$scoreEntity->setUserId($userId);
				$scoreEntity->setGameId($gameId);
				$scoreEntity->setScore($score);
				$scoreEntity->setCreatedAt(new \DateTime());
				return $this->scoreMapper->insert($scoreEntity);
			});
		} catch (ForbiddenException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->appLogger->logException('MinigameService: score save rolled back', $e, [
				'event' => 'minigame_score_rollback',
				'service' => 'MinigameService',
				'houseId' => $houseId,
				'userId' => $userId,
				'gameId' => $gameId,
			]);
			throw $e;
		}
	}

	/**
	 * Get leaderboard for a house and game
	 *
	 * @param int $houseId The house ID
	 * @param string $gameId The game ID
	 * @param int $limit Maximum number of entries
	 * @return array Array of scores with user display names
	 */
	public function getLeaderboard(int $houseId, string $gameId, int $limit = 10): array {
		$scores = $this->scoreMapper->getLeaderboard($houseId, $gameId, $limit);
		return $this->enrichScoresWithDisplayNames($houseId, $scores);
	}

	/**
	 * Get weekly leaderboard for a house and game
	 *
	 * The week runs Monday 00:00 (house TZ, CS-PLAN-057) → now. Built on
	 * CalendarService::weekStart.
	 *
	 * @param int $houseId The house ID
	 * @param string $gameId The game ID
	 * @param int $limit Maximum number of entries
	 * @return array Array of scores with user display names
	 */
	public function getWeeklyLeaderboard(int $houseId, string $gameId, int $limit = 10): array {
		$tz = $this->calendarService->getTimeZone($this->houseMapper->find($houseId));
		$weekStart = $this->calendarService->weekStart(new \DateTime(), $tz);
		$scores = $this->scoreMapper->getLeaderboardForPeriod($houseId, $gameId, $weekStart, $limit);
		return $this->enrichScoresWithDisplayNames($houseId, $scores);
	}

	/**
	 * Enrich score rows with display names via one batch lookup (CR-SVC-56 /
	 * CS-PLAN-036). Members without a house_users row (e.g. removed
	 * mid-flight) keep the userId-as-name fallback.
	 *
	 * @param int $houseId The house ID
	 * @param MinigameScore[] $scores Score rows to enrich
	 * @return array Array of score payload rows with user display names
	 */
	private function enrichScoresWithDisplayNames(int $houseId, array $scores): array {
		$ncUserIds = array_values(array_unique(array_map(
			static fn (MinigameScore $score) => $score->getUserId(),
			$scores
		)));
		$usersByNcId = $this->houseUserMapper->findByHouseIdAndNextcloudUserIds($houseId, $ncUserIds);

		$result = [];
		foreach ($scores as $score) {
			$user = $usersByNcId[$score->getUserId()] ?? null;
			$result[] = [
				'id' => $score->getId(),
				'userId' => $score->getUserId(),
				'displayName' => $user ? $user->getDisplayName() : $score->getUserId(),
				'score' => $score->getScore(),
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
	public function getPersonalBest(int $houseId, string $userId, string $gameId): ?array {
		$score = $this->scoreMapper->getPersonalBest($houseId, $userId, $gameId);

		if ($score === null) {
			return null;
		}

		return [
			'id' => $score->getId(),
			'score' => $score->getScore(),
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
	public function getUserRank(int $houseId, string $userId, string $gameId): int {
		return $this->scoreMapper->getUserRank($houseId, $userId, $gameId);
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
	public function getRecentScores(int $houseId, string $userId, string $gameId, int $limit = 5): array {
		$scores = $this->scoreMapper->findByUserAndGame($houseId, $userId, $gameId, $limit);

		return array_map(fn ($score) => [
			'id' => $score->getId(),
			'score' => $score->getScore(),
			'createdAt' => $score->getCreatedAt()?->format('c'),
		], $scores);
	}
}
