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
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCP\IL10N;

/**
 * Service for managing minigame scores and leaderboards
 */
class MinigameService {
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
	private TransactionExecutor $transactions;
	private IL10N $l10n;
	private AppLogger $appLogger;
	private \OCA\ChoreStrike\Db\HouseMapper $houseMapper;
	private CalendarService $calendarService;

	public function __construct(
		MinigameScoreMapper $scoreMapper,
		HouseUserMapper $houseUserMapper,
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
	 * Save a game score and award the earned Coins atomically (CR-SVC-54).
	 *
	 * The score insert and the coin increment run in one transaction behind
	 * `HouseUserMapper::findForUpdate()` (ADR-007 pattern): two concurrent
	 * same-user submissions can no longer clobber each other's increment,
	 * and a failure between insert and coin award rolls both back. A missing
	 * house_user row keeps the previous behavior: the score is saved, 0
	 * Coins are awarded — and the response now reports that honestly
	 * (CR-SVC-51: the awarded amount is returned by the service instead of
	 * being recomputed from client input by the controller).
	 *
	 * @param int $houseId The house ID
	 * @param string $userId The Nextcloud user ID
	 * @param string $gameId The game ID (e.g., 'snake')
	 * @param int $score The achieved score
	 * @param array $boostsUsed List of boost IDs used during the game
	 * @return array{score: MinigameScore, coinsEarned: int} The persisted score entity and the Coins actually awarded
	 * @throws \InvalidArgumentException if the score is out of bounds (CR-SVC-05)
	 */
	public function saveScore(int $houseId, string $userId, string $gameId, int $score, array $boostsUsed = []): array {
		// CR-SVC-05: bound the client-supplied score before any insert or coin
		// award. Reject negatives (coin drain) and over-cap values (coin
		// farming via floor(score/10)).
		if ($score < 0 || $score > self::MAX_SCORE) {
			throw new \InvalidArgumentException(
				$this->l10n->t('Score must be between 0 and %1$d', [self::MAX_SCORE])
			);
		}

		$scoreEntity = new MinigameScore();
		$scoreEntity->setHouseId($houseId);
		$scoreEntity->setUserId($userId);
		$scoreEntity->setGameId($gameId);
		$scoreEntity->setScore($score);
		$scoreEntity->setBoostsUsed(!empty($boostsUsed) ? $boostsUsed : null);
		$scoreEntity->setCreatedAt(new \DateTime());

		// Award Coins for playing (1 Coin per 10 points). Resolving the
		// house_user id before the transaction is fine — the row is then
		// locked by primary key inside it.
		$coinsEarned = (int)floor($score / 10);
		$user = $coinsEarned > 0
			? $this->houseUserMapper->findByHouseIdAndNextcloudUserId($houseId, $userId)
			: null;
		$awardedCoins = 0;

		$this->transactions->begin();
		try {
			$savedScore = $this->scoreMapper->insert($scoreEntity);

			if ($user !== null) {
				$lockedUser = $this->houseUserMapper->findForUpdate($user->getId());
				$lockedUser->addCoins($coinsEarned);
				$lockedUser->touch();
				$this->houseUserMapper->update($lockedUser);
				$awardedCoins = $coinsEarned;
			}

			$this->transactions->commit();
		} catch (\Throwable $e) {
			$this->transactions->rollBack();
			$this->appLogger->logException('MinigameService: score save rolled back', $e, [
				'event' => 'minigame_score_rollback',
				'service' => 'MinigameService',
				'houseId' => $houseId,
				'userId' => $userId,
				'gameId' => $gameId,
			]);
			throw $e;
		}

		return [
			'score' => $savedScore,
			'coinsEarned' => $awardedCoins,
		];
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
	 * CalendarService::weekStart, which has correct "monday this week"
	 * semantics — the former `'monday this week'` relative format returned
	 * the NEXT Monday when queried on a Sunday (CR-P2-11 / CR-053-11a).
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
	 * CS-PLAN-036): a single findByHouseIdAndNextcloudUserIds query replaces
	 * the per-row membership fetch. Members without a house_users row (e.g.
	 * removed mid-flight) keep the userId-as-name fallback.
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
	public function getPersonalBest(int $houseId, string $userId, string $gameId): ?array {
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
	public function getUserRank(int $houseId, string $userId, string $gameId): int {
		return $this->scoreMapper->getUserRank($houseId, $userId, $gameId);
	}

	/**
	 * Purchase a boost for a game
	 *
	 * Lock-check-deduct under a transaction (CR-SVC-23): the sufficiency
	 * check runs on the locked house_user row, so two concurrent purchases
	 * with a single-boost balance cannot both pass the check and buy two
	 * boosts while paying once (ADR-007 lock pattern).
	 *
	 * The thrown messages are client-safe (surfaced verbatim by the
	 * controller's validation() 400 envelope) and therefore localized
	 * (CR-SVC-37); parameters use printf-style positional placeholders —
	 * t() feeds them through vsprintf(), named {token}s would reach the
	 * client unsubstituted.
	 *
	 * @param int $houseId The house ID
	 * @param string $userId The Nextcloud user ID
	 * @param string $boostId The boost ID to purchase
	 * @return array Result with success status and remaining coins
	 * @throws \InvalidArgumentException If boost is unknown or insufficient coins
	 */
	public function purchaseBoost(int $houseId, string $userId, string $boostId): array {
		// Validate boost exists
		if (!isset(self::BOOST_COSTS[$boostId])) {
			throw new \InvalidArgumentException($this->l10n->t('Unknown boost: %1$s', [$boostId]));
		}

		$cost = self::BOOST_COSTS[$boostId];
		$user = $this->houseUserMapper->findByHouseIdAndNextcloudUserId($houseId, $userId);

		if ($user === null) {
			throw new \InvalidArgumentException($this->l10n->t('User not found in house'));
		}

		$this->transactions->begin();
		try {
			// Check sufficiency on the LOCKED row — the authoritative
			// balance. No max(0, …) mask on the deduction: the locked check
			// guarantees sufficiency and the mask would only hide bugs.
			$lockedUser = $this->houseUserMapper->findForUpdate($user->getId());
			if ($lockedUser->getCoins() < $cost) {
				throw new \InvalidArgumentException(
					$this->l10n->t('Insufficient coins. Need %1$d, have %2$d', [$cost, $lockedUser->getCoins()])
				);
			}

			$lockedUser->setCoins($lockedUser->getCoins() - $cost);
			$lockedUser->touch();
			$this->houseUserMapper->update($lockedUser);

			$this->transactions->commit();
		} catch (\Throwable $e) {
			$this->transactions->rollBack();
			$this->appLogger->logException('MinigameService: boost purchase rolled back', $e, [
				'event' => 'minigame_boost_purchase_rollback',
				'service' => 'MinigameService',
				'houseId' => $houseId,
				'userId' => $userId,
				'boostId' => $boostId,
			]);
			throw $e;
		}

		return [
			'success' => true,
			'boostId' => $boostId,
			'cost' => $cost,
			'remainingCoins' => $lockedUser->getCoins(),
		];
	}

	/**
	 * Get available boosts and their costs
	 *
	 * @return array Map of boost ID to cost in Coins
	 */
	public function getAvailableBoosts(): array {
		return self::BOOST_COSTS;
	}

	/**
	 * Get user's current coins balance.
	 *
	 * @param int $houseId The house ID
	 * @param string $userId The Nextcloud user ID
	 * @return int Current coins balance
	 */
	public function getUserCoins(int $houseId, string $userId): int {
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
	public function getRecentScores(int $houseId, string $userId, string $gameId, int $limit = 5): array {
		$scores = $this->scoreMapper->findByUserAndGame($houseId, $userId, $gameId, $limit);

		return array_map(fn ($score) => [
			'id' => $score->getId(),
			'score' => $score->getScore(),
			'boostsUsed' => $score->getBoostsUsed(),
			'createdAt' => $score->getCreatedAt()?->format('c'),
		], $scores);
	}
}
