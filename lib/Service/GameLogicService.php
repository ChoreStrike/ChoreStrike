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
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;

/**
 * Service for the completion-reward orchestration (coins, combo, streak,
 * freezes, teamwork, titles). The weekly medal cluster lives in
 * {@see MedalService} (CS-PLAN-067 §3.2).
 */
class GameLogicService {
	private HouseUserMapper $houseUserMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private RewardConfigService $configService;
	private ?TitleService $titleService;

	public function __construct(
		HouseUserMapper $houseUserMapper,
		CompletedTaskMapper $completedTaskMapper,
		RewardConfigService $configService,
		?TitleService $titleService = null,
	) {
		$this->houseUserMapper = $houseUserMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->configService = $configService;
		$this->titleService = $titleService;
	}

	/**
	 * Handle task completion with game logic
	 *
	 * CR-SVC-50 / CS-PLAN-036: the locked RoomTask entity is threaded in
	 * by the caller (CompletionService::complete holds it since its
	 * lockForUpdate) — no re-fetch inside the house_user lock. The two
	 * completion-window queries are merged into ONE pre-insert 10-minute
	 * fetch; the 5-minute combo window is derived in PHP by timestamp
	 * filtering (the argument is a minute window, not a row limit).
	 *
	 * CS-PLAN-064 WP5 (decision D9): the teamwork bonus is folded into this
	 * single locked house-user write — the caller passes the canonical
	 * assignee id set and this method awards base coins + teamwork bonus in
	 * one update. The bonus formula ({@see calculateTeamworkBonus}) and its
	 * amount are unchanged; only the write site moved (the former second
	 * unlocked read-modify-write in RoomService is gone), which also makes
	 * the bonus effect-record-visible for the undo ledger.
	 *
	 * @param RoomTask $roomTask The locked room task entity (held by the caller)
	 * @param int $userId The house user ID
	 * @param Task $task The task entity
	 * @param \DateTimeZone|null $timeZone The house timezone for day-key
	 *                                     decisions (CS-PLAN-057: days-early compare, streak day compare);
	 *                                     resolved by the calling service.
	 * @param array $assigneeUserIds Canonical assignee Nextcloud user ids —
	 *                               teamwork bonus input (D9); empty = no bonus.
	 * @param CompletedTask $ledgerRow CS-PLAN-064 WP5 / CS-PLAN-067 §3.2:
	 *                                 REQUIRED caller-owned ledger entity
	 *                                 (carrying `occurrence_due`,
	 *                                 `before_state`). This method fills the
	 *                                 reward outputs (roomTaskId/userId/
	 *                                 completedAt/coinsEarned/wasCombo) but
	 *                                 does NOT insert — the caller inserts
	 *                                 exactly one ledger row with the
	 *                                 completed effect record after the
	 *                                 reward phase. The former test-only
	 *                                 self-inserting branch is gone.
	 * @return array{
	 *     contributionScore: int, coins: int, teamworkBonus: int, isCombo: bool,
	 *     daysEarly: int, earlyMultiplier: float, currentStreak: int,
	 *     longestStreak: int, totalContribution: int, totalCoins: int,
	 *     streakFreezeUsed: bool, streakFreezesRemaining: int,
	 *     comboBonusFreeze: bool, titlesAdded: list<string>,
	 *     newTitle: array<string, mixed>|null
	 * } Result payload; `newTitle` (CR-SVC-12) must be dispatched by the caller
	 *   after commit, never inside the transaction.
	 */
	public function handleTaskCompletion(RoomTask $roomTask, int $userId, Task $task, ?\DateTimeZone $timeZone = null, array $assigneeUserIds = [], ?CompletedTask $ledgerRow = null): array {
		if ($ledgerRow === null) {
			// CS-PLAN-067 §3.2: the caller-owned ledger row is part of the
			// aggregate contract — exactly one insert with a complete effect
			// record happens in CompletionService::complete.
			throw new \InvalidArgumentException('handleTaskCompletion requires a caller-owned ledger row');
		}
		// CR-SVC-04: lock the house_user row for the duration of this read-
		// modify-write so two concurrent same-user completions cannot clobber
		// each other's coins/streak update. Runs inside the caller's open
		// transaction (CompletionService::complete).
		$user = $this->houseUserMapper->findForUpdate($userId);
		$roomTaskId = $roomTask->getId();

		// One 10-minute window fetch, taken BEFORE the completion insert.
		// $isCombo (5-minute window) is derived in PHP; the combo-bonus
		// count below adds 1 for the in-flight completion because the old
		// post-insert 10-minute query included it.
		$comboWindow = $this->completedTaskMapper->findRecentByUserId($userId, 10);
		$fiveMinuteCutoff = (new \DateTime())->modify('-5 minutes');
		$recentInComboWindow = array_filter(
			$comboWindow,
			fn (CompletedTask $c) => $c->getCompletedAt() !== null && $c->getCompletedAt() >= $fiveMinuteCutoff
		);
		$isCombo = count($recentInComboWindow) >= 2;

		$contributionScore = $this->calculateContributionScore($task);
		$daysEarly = $this->calculateDaysEarly($roomTask, null, $timeZone);
		$earlyMultiplier = $this->calculateEarlyBonus($daysEarly);
		$coins = $this->calculateCoins($task, (float)$user->getCoinMultiplier(), $isCombo, $earlyMultiplier);

		// Teamwork bonus (decision D9): additive on top of the base reward,
		// folded into the SAME locked write as the base coins. Re-fetching
		// and re-updating the house user after handleTaskCompletion (the old
		// RoomService seam) would be an unlocked read-modify-write — the
		// bonus now never leaves this critical section.
		$teamworkBonus = $this->calculateTeamworkBonus($task, $assigneeUserIds);

		// Caller-owned ledger row (CS-PLAN-064 WP5): fill the reward outputs
		// but NEVER insert here — CompletionService inserts exactly one row
		// with the full effect record after this phase.
		$completedTask = $ledgerRow;
		$completedTask->setRoomTaskId($roomTaskId);
		$completedTask->setUserId($userId);
		$completedTask->setCompletedAt(new \DateTime());
		$completedTask->setCoinsEarned($coins);
		$completedTask->setWasCombo($isCombo);

		$user->addContributionScore($contributionScore);
		$user->addCoins($coins);
		if ($teamworkBonus > 0) {
			$user->addCoins($teamworkBonus);
		}

		$preferences = $user->getSchedulingPreferences();
		$weekdayAvailability = $preferences['weekdayAvailability'] ?? [];
		$newStreak = $user->calculateStreakWithAbsence($weekdayAvailability, $timeZone);
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
			// +1: the in-flight completion counts toward the threshold —
			// the old post-insert 10-minute query included it, this fetch
			// runs pre-insert.
			$comboCount = count($comboWindow) + 1;
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
		$titlesAdded = [];
		if ($this->titleService !== null && $this->configService->getTitlesEnabled()) {
			$unlocked = $this->titleService->detectUnlock($user);
			if (!empty($unlocked)) {
				// Canonical `{pack}.{tierKey}` ids (TitleCatalog::tierId) so
				// the undo aggregate can remove exactly these keys from the
				// unlocked-titles cache (CS-PLAN-064 effect record).
				$titlesAdded = array_map(
					static fn (array $tier) => $tier['pack'] . '.' . $tier['tierKey'],
					$unlocked
				);
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
		// Returning the payload lets CompletionService::complete dispatch it
		// AFTER commit() so a rolled-back completion never raises a stale
		// title notification (mirrors the takeover/weekly-goal deferral).
		return [
			'contributionScore' => $contributionScore,
			'coins' => $coins,
			'teamworkBonus' => $teamworkBonus,
			'isCombo' => $isCombo,
			'daysEarly' => $daysEarly,
			'earlyMultiplier' => $earlyMultiplier,
			'currentStreak' => $user->getCurrentStreak(),
			'longestStreak' => $user->getLongestStreak(),
			'totalContribution' => $user->getContributionScore(),
			'totalCoins' => $user->getCoins(),
			'streakFreezeUsed' => $freezeUsed,
			'streakFreezesRemaining' => $user->getStreakFreezes(),
			'comboBonusFreeze' => $comboBonusFreeze,
			'titlesAdded' => $titlesAdded,
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
	 * Calendar-day diff (CR-SVC-32): both sides are normalized to local
	 * midnight before diffing, so DST transitions and near-midnight
	 * completions cannot flip the count by ±1 day the way dividing a raw
	 * second-difference by 86400 does. A task completed on the day it is
	 * due counts as 0 days early regardless of the time of day.
	 *
	 * CS-PLAN-057: with $timeZone supplied, "local midnight" means the
	 * house's midnight; otherwise the server timezone.
	 *
	 * @param RoomTask $roomTask The room task
	 * @param \DateTime|null $now Completion moment (defaults to the current time; injectable for tests)
	 * @param \DateTimeZone|null $timeZone House timezone for the day keys (CS-PLAN-057)
	 * @return int Days early (negative if late, 0 if no ideal due date)
	 */
	public function calculateDaysEarly(RoomTask $roomTask, ?\DateTime $now = null, ?\DateTimeZone $timeZone = null): int {
		$idealDueDate = $roomTask->getIdealDueDate();
		if ($idealDueDate === null) {
			return 0;
		}

		$dueDay = \DateTime::createFromInterface($idealDueDate);
		$today = $now !== null ? \DateTime::createFromInterface($now) : new \DateTime();
		if ($timeZone !== null) {
			$dueDay->setTimezone($timeZone);
			$today->setTimezone($timeZone);
		}
		$dueDay->setTime(0, 0, 0);
		$today->setTime(0, 0, 0);

		$diff = $today->diff($dueDay);
		return $diff->invert === 1 ? -(int)$diff->days : (int)$diff->days;
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

	// CS-PLAN-067 §3.2: the weekly medal cluster (checkAndAwardMedals,
	// awardMedalIfNew, createMedal) and the house medal listing moved to
	// MedalService — one cohesive medal domain owner; award timing and the
	// race-safe unique-constraint handling are unchanged.

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
}
