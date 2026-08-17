<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Medal;
use OCA\ChoreStrike\Db\MedalMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Service for game logic and mechanics
 */
class GameLogicService {
	private const MEDAL_TEAM_ANCHOR_MIN_TASKS = 10;
	private const MEDAL_STREAK_KEEPER_MIN_DAYS = 7;
	private const MEDAL_FLOW_KEEPER_MIN_ACTIVE_DAYS = 4;
	private const MEDAL_ROOM_GUARDIAN_MIN_PER_ROOM = 5;
	private const MEDAL_TEAM_PLAYER_MIN_COMBOS = 3;

	private HouseUserMapper $houseUserMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private MedalMapper $medalMapper;
	private RoomTaskMapper $roomTaskMapper;
	private RewardConfigService $configService;
	private NotificationService $notificationService;
	private ?TitleService $titleService;

	public function __construct(
		HouseUserMapper $houseUserMapper,
		CompletedTaskMapper $completedTaskMapper,
		MedalMapper $medalMapper,
		RoomTaskMapper $roomTaskMapper,
		RewardConfigService $configService,
		NotificationService $notificationService,
		?TitleService $titleService = null,
	) {
		$this->houseUserMapper = $houseUserMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->medalMapper = $medalMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->configService = $configService;
		$this->notificationService = $notificationService;
		$this->titleService = $titleService;
	}

	/**
	 * Handle task completion with game logic
	 *
	 * @param int $roomTaskId The room task ID
	 * @param int $userId The house user ID
	 * @param Task $task The task entity
	 * @param int $roomCleanliness Current room cleanliness — completion context
	 *                             only; NOT part of the payout math (CR-SVC-57: contribution = energy cost)
	 * @param int|null $nextcloudTaskId Optional Nextcloud task id stamped on the
	 *                                  completion row (CR-SVC-46): sync completions pass the linked id so
	 *                                  {@see \OCA\ChoreStrike\Db\CompletedTaskMapper::findPendingSyncs()}
	 *                                  picks the row up for ChoreStrike → Nextcloud sync-out.
	 * @return array{
	 *     contributionScore: int, coins: int, isCombo: bool, daysEarly: int,
	 *     earlyMultiplier: float, currentStreak: int, totalContribution: int,
	 *     totalCoins: int, streakFreezeUsed: bool, streakFreezesRemaining: int,
	 *     comboBonusFreeze: bool, newTitle: array<string, mixed>|null
	 * } Result payload; `newTitle` (CR-SVC-12) must be dispatched by the caller
	 *   after commit, never inside the transaction.
	 */
	public function handleTaskCompletion(int $roomTaskId, int $userId, Task $task, int $roomCleanliness, ?int $nextcloudTaskId = null): array {
		// CR-SVC-04: lock the house_user row for the duration of this read-
		// modify-write so two concurrent same-user completions cannot clobber
		// each other's coins/streak update. Runs inside the caller's open
		// transaction (RoomService::completeRoomTask).
		$user = $this->houseUserMapper->findForUpdate($userId);
		$roomTask = $this->roomTaskMapper->find($roomTaskId);

		$contributionScore = $this->calculateContributionScore($task);
		$recentCompletions = $this->completedTaskMapper->findRecentByUserId($userId, 5);
		$isCombo = count($recentCompletions) >= 2;
		$daysEarly = $this->calculateDaysEarly($roomTask);
		$earlyMultiplier = $this->calculateEarlyBonus($daysEarly);
		$coins = $this->calculateCoins($task, (float)$user->getCoinMultiplier(), $isCombo, $earlyMultiplier);

		$completedTask = new CompletedTask();
		$completedTask->setRoomTaskId($roomTaskId);
		$completedTask->setUserId($userId);
		$completedTask->setCompletedAt(new \DateTime());
		$completedTask->setCoinsEarned($coins);
		$completedTask->setWasCombo($isCombo);
		if ($nextcloudTaskId !== null) {
			// CR-SVC-46: stamp the linked Nextcloud task id inside the
			// canonical transaction so findPendingSyncs() (which filters on
			// completed_tasks.nextcloud_task_id) sees sync completions.
			$completedTask->setNextcloudTaskId($nextcloudTaskId);
		}
		$this->completedTaskMapper->insert($completedTask);

		$user->addContributionScore($contributionScore);
		$user->addCoins($coins);

		$preferences = $user->getSchedulingPreferences();
		$weekdayAvailability = $preferences['weekdayAvailability'] ?? [];
		$newStreak = $user->calculateStreakWithAbsence($weekdayAvailability);
		$freezeUsed = false;
		$cap = $this->configService->getStreakFreezeCap();

		if ($newStreak <= 1 && $user->getCurrentStreak() > 0) {
			if ($user->consumeFreeze()) {
				$freezeUsed = true;
			} else {
				$user->setCurrentStreak($newStreak);
			}
		} else {
			$user->setCurrentStreak($newStreak);
		}

		$comboBonusFreeze = false;
		if ($isCombo) {
			$recentForCombo = $this->completedTaskMapper->findRecentByUserId($userId, 10);
			$comboCount = count($recentForCombo);
			$threshold = $this->configService->getComboBonusFreezeThreshold();
			if ($comboCount >= $threshold && $user->getStreakFreezes() < $cap) {
				$user->awardBonusFreeze($cap);
				$comboBonusFreeze = true;
			}
		}

		// Track the all-time longest streak off the effective current streak
		// (after any freeze preservation). This is the progression signal the
		// team_energy title pack unlocks on, so it must advance here.
		$effectiveStreak = $user->getCurrentStreak();
		if ($effectiveStreak > $user->getLongestStreak()) {
			$user->setLongestStreak($effectiveStreak);
		}

		$user->touch();

		// Title unlock detection (CS-PLAN-006). Detects newly-crossed tier
		// thresholds across all packs, mutates the user's unlocked-titles
		// cache in-memory (so it is persisted by the update below in the
		// same write), and picks one new title to celebrate. Gated behind
		// the titlesEnabled feature flag so an aggressive celebration cadence
		// can be disabled without a redeploy.
		$newTitle = null;
		if ($this->titleService !== null && $this->configService->getTitlesEnabled()) {
			$unlocked = $this->titleService->detectUnlock($user);
			if (!empty($unlocked)) {
				// Prefer a new title in the user's selected pack for the
				// celebration; otherwise fall back to the first new unlock.
				$selectedPack = $user->getSelectedTitlePack();
				$newTitle = $unlocked[0];
				foreach ($unlocked as $candidate) {
					if ($candidate['pack'] === $selectedPack) {
						$newTitle = $candidate;
						break;
					}
				}
			}
		}

		$this->houseUserMapper->update($user);

		// CR-SVC-12: the title-unlock notification is NOT dispatched here.
		// Returning the payload lets RoomService::completeRoomTask dispatch it
		// AFTER commit() so a rolled-back completion never raises a stale
		// title notification (mirrors the takeover/weekly-goal deferral).
		return [
			'contributionScore' => $contributionScore,
			'coins' => $coins,
			'isCombo' => $isCombo,
			'daysEarly' => $daysEarly,
			'earlyMultiplier' => $earlyMultiplier,
			'currentStreak' => $user->getCurrentStreak(),
			'totalContribution' => $user->getContributionScore(),
			'totalCoins' => $user->getCoins(),
			'streakFreezeUsed' => $freezeUsed,
			'streakFreezesRemaining' => $user->getStreakFreezes(),
			'comboBonusFreeze' => $comboBonusFreeze,
			'newTitle' => $newTitle,
		];
	}

	/**
	 * Calculate contribution score for task completion
	 *
	 * Documented simplification (CR-SVC-57): contribution = energy cost.
	 * Room cleanliness deliberately does NOT factor in — folding it in would
	 * change gamification payout behavior and needs its own review first.
	 *
	 * @param Task $task The task
	 */
	public function calculateContributionScore(Task $task): int {
		return $task->getEnergyCost();
	}

	/**
	 * Calculate Coins for task completion
	 *
	 * @param Task $task The task
	 * @param float $personalMultiplier The user's personal coin multiplier
	 * @param bool $isCombo Whether this is part of a combo
	 * @param float $earlyMultiplier Early completion bonus multiplier
	 */
	public function calculateCoins(Task $task, float $personalMultiplier = 1.0, bool $isCombo = false, float $earlyMultiplier = 1.0): int {
		// Formula: Energy × baseRate × tierMultiplier × personalMultiplier × earlyMultiplier × comboMultiplier
		$baseRate = $this->configService->getCoinBasePerEnergy();
		$coins = $task->getEnergyCost() * $baseRate * $task->getTaskTierMultiplier() * $personalMultiplier * $earlyMultiplier;

		if ($isCombo) {
			$coins *= $this->configService->getComboMultiplier();
		}

		return (int)round($coins);
	}

	/**
	 * Calculate days early for task completion
	 *
	 * @param RoomTask $roomTask The room task
	 * @return int Days early (negative if late, 0 if no ideal due date)
	 */
	public function calculateDaysEarly(RoomTask $roomTask): int {
		$idealDueDate = $roomTask->getIdealDueDate();
		if ($idealDueDate === null) {
			return 0;
		}

		$now = new \DateTime();
		$diff = $idealDueDate->getTimestamp() - $now->getTimestamp();
		return (int)round($diff / 86400); // Convert seconds to days
	}

	/**
	 * Calculate early completion bonus multiplier
	 *
	 * @param int $daysEarly Days before ideal due date (negative if late)
	 * @return float Multiplier (1.0 if no bonus, >1.0 if early bonus applies)
	 */
	public function calculateEarlyBonus(int $daysEarly): float {
		if ($daysEarly <= 0) {
			return 1.0;
		}

		$threshold = $this->configService->getEarlyBonusThresholdDays();
		if ($daysEarly >= $threshold) {
			return $this->configService->getEarlyBonusMultiplier();
		}

		return 1.0;
	}

	/**
	 * Get game state for a house
	 *
	 * @param int $houseId The house ID
	 */
	public function getGameState(int $houseId): array {
		$users = $this->houseUserMapper->findByHouseId($houseId);

		return [
			'users' => array_map(fn ($user) => $this->serializeUser($user), $users),
		];
	}

	/**
	 * Get leaderboard for a house
	 *
	 * @param int $houseId The house ID
	 * @param int $limit Maximum number of users
	 */
	public function getLeaderboard(int $houseId, int $limit = 10): array {
		return array_map(
			fn ($user) => $this->serializeUser($user),
			$this->houseUserMapper->getLeaderboard($houseId, $limit)
		);
	}

	/**
	 * Check and award medals for weekly achievements
	 *
	 * @param int $houseId The house ID
	 */
	public function checkAndAwardMedals(int $houseId): array {
		$weekStart = $this->getWeekStart();
		$weekEnd = new \DateTime($weekStart);
		$weekEnd->modify('+7 days');

		$users = $this->houseUserMapper->findByHouseId($houseId);
		$awardedMedals = [];

		foreach ($users as $user) {
			$stats = $this->completedTaskMapper->getUserStats(
				$user->getId(),
				new \DateTime($weekStart),
				$weekEnd
			);

			if ($stats['taskCount'] >= self::MEDAL_TEAM_ANCHOR_MIN_TASKS) {
				$medal = $this->awardMedalIfNew($user, 'team_anchor', $stats['taskCount'], $weekStart);
				if ($medal !== null) {
					$awardedMedals[] = $medal;
				}
			}

			if ($user->getCurrentStreak() >= self::MEDAL_STREAK_KEEPER_MIN_DAYS) {
				$medal = $this->awardMedalIfNew($user, 'streak_keeper', $user->getCurrentStreak(), $weekStart);
				if ($medal !== null) {
					$awardedMedals[] = $medal;
				}
			}

			$activeDays = $stats['activeDays'] ?? 0;
			if ($activeDays >= self::MEDAL_FLOW_KEEPER_MIN_ACTIVE_DAYS) {
				$medal = $this->awardMedalIfNew($user, 'flow_keeper', $activeDays, $weekStart);
				if ($medal !== null) {
					$awardedMedals[] = $medal;
				}
			}

			$maxPerRoom = $this->completedTaskMapper->getMaxRoomCompletionCountByUser(
				$user->getId(),
				new \DateTime($weekStart),
				$weekEnd
			);
			if ($maxPerRoom >= self::MEDAL_ROOM_GUARDIAN_MIN_PER_ROOM) {
				$medal = $this->awardMedalIfNew($user, 'room_guardian', $maxPerRoom, $weekStart);
				if ($medal !== null) {
					$awardedMedals[] = $medal;
				}
			}

			// team_player is driven by comboCount (wasCombo flag), not the
			// dead isTeamCompletion/participantCount/teamSparkBonus residue.
			$combos = $stats['comboCount'] ?? 0;
			if ($combos >= self::MEDAL_TEAM_PLAYER_MIN_COMBOS) {
				$medal = $this->awardMedalIfNew($user, 'team_player', $combos, $weekStart);
				if ($medal !== null) {
					$awardedMedals[] = $medal;
				}
			}
		}

		return $awardedMedals;
	}

	/**
	 * Award a medal to a user if they don't already have one of this type for
	 * the given week, and notify them on the new award.
	 *
	 * Returns null if the medal already existed (idempotent — re-checking
	 * medals in a week doesn't re-notify the user). Returns the new Medal
	 * entity otherwise, and dispatches a best-effort notification to the
	 * member's Nextcloud account so they see it in the bell + mobile feed.
	 *
	 * Race-safe: the read-then-insert window is closed by catching the
	 * unique-constraint violation on insert (the loser of two concurrent
	 * first-time awards re-reads the existing medal and returns null).
	 *
	 * @param HouseUser $user The recipient house user
	 * @param string $medalType Type of medal
	 * @param int $value Medal value
	 * @param string $weekStart Week start date (Y-m-d)
	 */
	private function awardMedalIfNew(HouseUser $user, string $medalType, int $value, string $weekStart): ?Medal {
		try {
			// Already exists — idempotent no-op.
			$this->medalMapper->findByUserTypeAndWeek($user->getId(), $medalType, $weekStart);
			return null;
		} catch (DoesNotExistException $e) {
			// Not found — create + notify.
		}

		try {
			$medal = $this->createMedal($user->getId(), $medalType, $value, $weekStart);
		} catch (\OCP\DB\Exception $e) {
			// Only a unique-constraint violation is the expected race-loser
			// case (two concurrent first-time awards for the same user+type+
			// week). Any other DB error (FK violation, deadlock, connection
			// loss, NOT NULL) must propagate so the medal isn't silently
			// lost.
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			return null;
		}

		$nextcloudUserId = $user->getNextcloudUserId();
		if ($nextcloudUserId !== null && $nextcloudUserId !== '') {
			$this->notificationService->notifyMedalAwarded(
				$nextcloudUserId,
				$medalType,
				$value,
				$weekStart
			);
		}

		return $medal;
	}

	/**
	 * Construct and persist a new Medal row.
	 *
	 * Shared by the (single) award path so there is exactly one definition of
	 * how a Medal row is built. Throws the underlying mapper exception on
	 * failure so the caller can react (e.g. awardMedalIfNew treats a unique-
	 * constraint violation as the medal already existing).
	 *
	 * @param int $userId The house user ID
	 * @param string $medalType Type of medal
	 * @param int $value Medal value
	 * @param string $weekStart Week start date (Y-m-d)
	 */
	private function createMedal(int $userId, string $medalType, int $value, string $weekStart): Medal {
		$medal = new Medal();
		$medal->setUserId($userId);
		$medal->setMedalType($medalType);
		$medal->setValue($value);
		$medal->setWeekStart($weekStart);
		$medal->setAwardedAt(new \DateTime());
		return $this->medalMapper->insert($medal);
	}

	/**
	 * Serialize a HouseUser to its JSON shape, enriching it with the
	 * server-computed `currentTitle` when the title system is available.
	 * Delegates to TitleService so the shape stays in sync with UserController.
	 */
	private function serializeUser(HouseUser $user): array {
		$data = $user->jsonSerialize();
		if ($this->titleService !== null) {
			return $this->titleService->enrichWithCurrentTitle($data, $user);
		}
		return $data;
	}

	/**
	 * Get week start date (Monday)
	 */
	private function getWeekStart(): string {
		$date = new \DateTime();
		$day = (int)$date->format('N');
		$diff = $day - 1;
		$date->modify("-{$diff} days");
		return $date->format('Y-m-d');
	}

	/**
	 * Calculate the teamwork bonus for a multi-assignee task completion.
	 *
	 * Formula: ceil((energyCost * coinBasePerEnergy * 0.5) / participantCount)
	 * Capped at the task's base reward (energyCost * coinBasePerEnergy * tierMultiplier)
	 * to prevent inflation. Returns 0 if teamwork bonus is disabled or only one
	 * participant.
	 *
	 * @param Task $task The task being completed
	 * @param array $assigneeUserIds The Nextcloud user IDs of all assignees
	 * @return int The bonus coins per participant
	 */
	public function calculateTeamworkBonus(Task $task, array $assigneeUserIds): int {
		if (!$this->configService->getTeamworkBonusEnabled()) {
			return 0;
		}

		$participantCount = count($assigneeUserIds);
		if ($participantCount <= 1) {
			return 0;
		}

		$baseRate = $this->configService->getCoinBasePerEnergy();
		$rawBonus = (int)ceil(($task->getEnergyCost() * $baseRate * 0.5) / $participantCount);

		$baseReward = (int)round($task->getEnergyCost() * $baseRate * $task->getTaskTierMultiplier());
		$maxPct = $this->configService->getTeamworkBonusMaxPct();
		$cap = (int)floor($baseReward * $maxPct / 100);

		return min($rawBonus, max(0, $cap));
	}

	/**
	 * Get all medals for a house with user names
	 *
	 * @param int $houseId The house ID
	 * @return array Array of medals with user names
	 */
	public function getMedals(int $houseId): array {
		$users = $this->houseUserMapper->findByHouseId($houseId);
		$medals = [];

		foreach ($users as $user) {
			$userMedals = $this->medalMapper->findByUserId($user->getId());
			foreach ($userMedals as $medal) {
				$medals[] = [
					'id' => $medal->getId(),
					'userId' => $medal->getUserId(),
					'userName' => $user->getDisplayName(),
					'medalType' => $medal->getMedalType(),
					'value' => $medal->getValue(),
					'weekStart' => $medal->getWeekStart(),
					'awardedAt' => $medal->getAwardedAt()?->format('c'),
				];
			}
		}

		return $medals;
	}

}
