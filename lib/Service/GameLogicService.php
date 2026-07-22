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
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Service for game logic and mechanics
 */
class GameLogicService
{
    private HouseUserMapper $houseUserMapper;
    private CompletedTaskMapper $completedTaskMapper;
    private MedalMapper $medalMapper;
    private RoomTaskMapper $roomTaskMapper;
    private RewardConfigService $configService;
    private NotificationService $notificationService;

    public function __construct(
        HouseUserMapper $houseUserMapper,
        CompletedTaskMapper $completedTaskMapper,
        MedalMapper $medalMapper,
        RoomTaskMapper $roomTaskMapper,
        RewardConfigService $configService,
        NotificationService $notificationService
    ) {
        $this->houseUserMapper = $houseUserMapper;
        $this->completedTaskMapper = $completedTaskMapper;
        $this->medalMapper = $medalMapper;
        $this->roomTaskMapper = $roomTaskMapper;
        $this->configService = $configService;
        $this->notificationService = $notificationService;
    }

    /**
     * Handle task completion with game logic
     *
     * @param int $roomTaskId The room task ID
     * @param int $userId The house user ID
     * @param Task $task The task entity
     * @param int $roomCleanliness Current room cleanliness
     */
    public function handleTaskCompletion(int $roomTaskId, int $userId, Task $task, int $roomCleanliness): array
    {
        $user = $this->houseUserMapper->find($userId);
        $roomTask = $this->roomTaskMapper->find($roomTaskId);

        $contributionScore = $this->calculateContributionScore($task, $roomCleanliness);
        $recentCompletions = $this->completedTaskMapper->findRecentByUserId($userId, 5);
        $isCombo = count($recentCompletions) >= 2;
        $daysEarly = $this->calculateDaysEarly($roomTask);
        $earlyMultiplier = $this->calculateEarlyBonus($daysEarly);
        $coins = $this->calculateCoins($task, (float) $user->getCoinMultiplier(), $isCombo, $earlyMultiplier);

        $completedTask = new CompletedTask();
        $completedTask->setRoomTaskId($roomTaskId);
        $completedTask->setUserId($userId);
        $completedTask->setCompletedAt(new \DateTime());
        $completedTask->setCoinsEarned($coins);
        $completedTask->setWasCombo($isCombo);
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

        $user->touch();
        $this->houseUserMapper->update($user);

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
        ];
    }

    /**
     * Calculate contribution score for task completion
     *
     * @param Task $task The task
     * @param int $roomCleanliness Current room cleanliness (0-100)
     */
    public function calculateContributionScore(Task $task, int $roomCleanliness): int
    {
        // Simplified: contribution = energy cost (no multipliers)
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
    public function calculateCoins(Task $task, float $personalMultiplier = 1.0, bool $isCombo = false, float $earlyMultiplier = 1.0): int
    {
        // Formula: Energy × baseRate × tierMultiplier × personalMultiplier × earlyMultiplier × comboMultiplier
        $baseRate = $this->configService->getCoinBasePerEnergy();
        $coins = $task->getEnergyCost() * $baseRate * $task->getTaskTierMultiplier() * $personalMultiplier * $earlyMultiplier;

        if ($isCombo) {
            $coins *= 2;
        }

        return (int) round($coins);
    }

    /**
     * Calculate days early for task completion
     *
     * @param RoomTask $roomTask The room task
     * @return int Days early (negative if late, 0 if no ideal due date)
     */
    public function calculateDaysEarly(RoomTask $roomTask): int
    {
        $idealDueDate = $roomTask->getIdealDueDate();
        if ($idealDueDate === null) {
            return 0;
        }

        $now = new \DateTime();
        $diff = $idealDueDate->getTimestamp() - $now->getTimestamp();
        return (int) round($diff / 86400); // Convert seconds to days
    }

    /**
     * Calculate early completion bonus multiplier
     *
     * @param int $daysEarly Days before ideal due date (negative if late)
     * @return float Multiplier (1.0 if no bonus, >1.0 if early bonus applies)
     */
    public function calculateEarlyBonus(int $daysEarly): float
    {
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
    public function getGameState(int $houseId): array
    {
        $users = $this->houseUserMapper->findByHouseId($houseId);

        return [
            'users' => array_map(fn ($user) => $user->jsonSerialize(), $users),
        ];
    }

    /**
     * Get leaderboard for a house
     *
     * @param int $houseId The house ID
     * @param int $limit Maximum number of users
     */
    public function getLeaderboard(int $houseId, int $limit = 10): array
    {
        return array_map(
            fn ($user) => $user->jsonSerialize(),
            $this->houseUserMapper->getLeaderboard($houseId, $limit)
        );
    }

    /**
     * Check and award medals for weekly achievements
     *
     * @param int $houseId The house ID
     */
    public function checkAndAwardMedals(int $houseId): array
    {
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

            if ($stats['taskCount'] >= 10) {
                $medal = $this->awardMedalIfNew($user, 'team_anchor', $stats['taskCount'], $weekStart);
                if ($medal !== null) {
                    $awardedMedals[] = $medal;
                }
            }

            if ($user->getCurrentStreak() >= 7) {
                $medal = $this->awardMedalIfNew($user, 'streak_keeper', $user->getCurrentStreak(), $weekStart);
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
    private function awardMedalIfNew(HouseUser $user, string $medalType, int $value, string $weekStart): ?Medal
    {
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
    private function createMedal(int $userId, string $medalType, int $value, string $weekStart): Medal
    {
        $medal = new Medal();
        $medal->setUserId($userId);
        $medal->setMedalType($medalType);
        $medal->setValue($value);
        $medal->setWeekStart($weekStart);
        $medal->setAwardedAt(new \DateTime());
        return $this->medalMapper->insert($medal);
    }

    /**
     * Get week start date (Monday)
     */
    private function getWeekStart(): string
    {
        $date = new \DateTime();
        $day = (int)$date->format('N');
        $diff = $day - 1;
        $date->modify("-{$diff} days");
        return $date->format('Y-m-d');
    }

    /**
     * Calculate points for task completion
     *
     * @param Task $task The task
     * @param int $difficulty Task difficulty (1-5)
     * @param int $priority Task priority (1-3)
     * @param bool $isBonus Whether this is a bonus completion
     */
    public function calculatePoints(Task $task, int $difficulty = 1, int $priority = 1, bool $isBonus = false): int
    {
        $basePoints = $difficulty * $priority * 10;

        if ($isBonus) {
            $basePoints = (int)($basePoints * 1.5);
        }

        return $basePoints;
    }

    /**
     * Calculate streak bonus points
     *
     * @param int $currentStreak Current streak count
     */
    public function calculateStreakBonus(int $currentStreak): int
    {
        return $currentStreak * 5;
    }

    /**
     * Calculate combo bonus points
     *
     * @param int $comboCount Number of tasks completed in combo
     */
    public function calculateComboBonus(int $comboCount): int
    {
        return $comboCount * 10;
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
    public function calculateTeamworkBonus(Task $task, array $assigneeUserIds): int
    {
        if (!$this->configService->getTeamworkBonusEnabled()) {
            return 0;
        }

        $participantCount = count($assigneeUserIds);
        if ($participantCount <= 1) {
            return 0;
        }

        $baseRate = $this->configService->getCoinBasePerEnergy();
        $rawBonus = (int) ceil(($task->getEnergyCost() * $baseRate * 0.5) / $participantCount);

        $baseReward = (int) round($task->getEnergyCost() * $baseRate * $task->getTaskTierMultiplier());
        $maxPct = $this->configService->getTeamworkBonusMaxPct();
        $cap = (int) floor($baseReward * $maxPct / 100);

        return min($rawBonus, max(0, $cap));
    }

    /**
     * Get all medals for a house with user names
     *
     * @param int $houseId The house ID
     * @return array Array of medals with user names
     */
    public function getMedals(int $houseId): array
    {
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

    /**
     * Update user streak after task completion
     *
     * @param int $currentStreak Current streak
     * @param bool $completedToday Whether user completed task today
     */
    public function updateStreakCount(int $currentStreak, bool $completedToday = true): int
    {
        if (!$completedToday) {
            return 1;
        }

        return $currentStreak + 1;
    }

    /**
     * Check if streak should be reset
     *
     * @param \DateTime $lastCompletionDate Last completion date
     * @param array $weekdayAvailability Weekday availability
     */
    public function shouldResetStreak(\DateTime $lastCompletionDate, array $weekdayAvailability): bool
    {
        $today = new \DateTime();
        $today->setTime(0, 0, 0);
        $lastDate = clone $lastCompletionDate;
        $lastDate->setTime(0, 0, 0);

        $daysSinceCompletion = (int)$lastDate->diff($today)->days;

        // If completed today, don't reset
        if ($daysSinceCompletion === 0) {
            return false;
        }

        // If completed yesterday, check if available today
        if ($daysSinceCompletion === 1) {
            $todayName = strtolower($today->format('l'));
            $isAvailableToday = $weekdayAvailability[$todayName] ?? true;
            // Reset streak if NOT available today
            return !$isAvailableToday;
        }

        // If more than 1 day, reset
        return true;
    }
}
