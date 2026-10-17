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
use OCA\ChoreStrike\Exception\ImplausibleScoreException;
use OCP\IL10N;

/**
 * Service for managing minigame scores and leaderboards.
 *
 * CS-PLAN-070 WP3 (T10/D7d no-Coin arcade cutover): scores are family
 * fun only — they never write wallet entries, contribution or any reward.
 * The former Coin minting (floor(score/10)), boost purchase/catalog and
 * Coin-balance reads are retired with their routes and client consumers.
 *
 * CS-PLAN-086: game ids, score ceilings and the duration plausibility rules
 * live in {@see ArcadeCatalog}; every read and write names its game
 * explicitly and an unknown id is rejected.
 */
class MinigameService {
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
	 * @param string $gameId A game id of the {@see ArcadeCatalog}
	 * @param int $score The achieved score
	 * @param int $durationMs Active play time of the round in milliseconds
	 * @return MinigameScore The persisted score entity
	 * @throws \InvalidArgumentException for an unknown game, a score outside the game's bounds (CR-SVC-05) or a duration outside the game's bounds
	 * @throws ImplausibleScoreException when the score is not reachable in the reported play time (D-086-04)
	 * @throws \OCA\ChoreStrike\Exception\RateLimitException when the per-user daily save cap is reached (D7)
	 */
	public function saveScore(int $houseId, string $userId, string $gameId, int $score, int $durationMs): MinigameScore {
		$this->assertValidSubmission($houseId, $userId, $gameId, $score, $durationMs);

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
	 * Reject a submission before any persistence: unknown game, score
	 * outside the game's ceiling (CR-SVC-05), duration outside the game's
	 * bounds, or a score not reachable in that duration (D-086-04).
	 *
	 * @throws \InvalidArgumentException
	 * @throws ImplausibleScoreException
	 */
	private function assertValidSubmission(int $houseId, string $userId, string $gameId, int $score, int $durationMs): void {
		$this->assertKnownGame($gameId);
		$rules = ArcadeCatalog::rules($gameId);
		if ($score < 0 || $score > $rules['maxScore']) {
			throw new \InvalidArgumentException(
				$this->l10n->t('Score must be between 0 and %1$d', [$rules['maxScore']])
			);
		}
		if ($durationMs < 1 || $durationMs > $rules['maxDurationMs']) {
			throw new \InvalidArgumentException(
				$this->l10n->t('Play time must be between 1 and %1$d milliseconds', [$rules['maxDurationMs']])
			);
		}
		if (!ArcadeCatalog::isPlausible($gameId, $score, $durationMs)) {
			$this->appLogger->warning('Minigame score save rejected: implausible score', [
				'event' => 'minigame_implausible_score',
				'service' => 'MinigameService',
				'houseId' => $houseId,
				'userId' => $userId,
				'gameId' => $gameId,
				'score' => $score,
				'durationMs' => $durationMs,
			]);
			throw new ImplausibleScoreException(
				$this->l10n->t('This score is not possible in the time played.')
			);
		}
	}

	/**
	 * @throws \InvalidArgumentException for a game id the catalog does not know
	 */
	private function assertKnownGame(string $gameId): void {
		if (!ArcadeCatalog::has($gameId)) {
			throw new \InvalidArgumentException($this->l10n->t('Unknown game'));
		}
	}

	/**
	 * Get leaderboard for a house and game
	 *
	 * @param int $houseId The house ID
	 * @param string $gameId The game ID
	 * @param int $limit Maximum number of entries
	 * @return array Array of scores with user display names
	 * @throws \InvalidArgumentException for an unknown game
	 */
	public function getLeaderboard(int $houseId, string $gameId, int $limit = 10): array {
		$this->assertKnownGame($gameId);
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
	 * @throws \InvalidArgumentException for an unknown game
	 */
	public function getWeeklyLeaderboard(int $houseId, string $gameId, int $limit = 10): array {
		$this->assertKnownGame($gameId);
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
	 * @throws \InvalidArgumentException for an unknown game
	 */
	public function getPersonalBest(int $houseId, string $userId, string $gameId): ?array {
		$this->assertKnownGame($gameId);
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
	 * @throws \InvalidArgumentException for an unknown game
	 */
	public function getUserRank(int $houseId, string $userId, string $gameId): int {
		$this->assertKnownGame($gameId);
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
	 * @throws \InvalidArgumentException for an unknown game
	 */
	public function getRecentScores(int $houseId, string $userId, string $gameId, int $limit = 5): array {
		$this->assertKnownGame($gameId);
		$scores = $this->scoreMapper->findByUserAndGame($houseId, $userId, $gameId, $limit);

		return array_map(fn ($score) => [
			'id' => $score->getId(),
			'score' => $score->getScore(),
			'createdAt' => $score->getCreatedAt()?->format('c'),
		], $scores);
	}
}
