<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getHouseId()
 * @method void setHouseId(int $houseId)
 * @method string getNextcloudUserId()
 * @method void setNextcloudUserId(string $nextcloudUserId)
 * @method string getDisplayName()
 * @method void setDisplayName(string $displayName)
 * @method int getContributionScore()
 * @method void setContributionScore(int $contributionScore)
 * @method int getCoins()
 * @method void setCoins(int $coins)
 * @method float getCoinMultiplier()
 * @method void setCoinMultiplier(float $coinMultiplier)
 * @method int getCurrentStreak()
 * @method void setCurrentStreak(int $currentStreak)
 * @method int getLongestStreak()
 * @method void setLongestStreak(int $longestStreak)
 * @method \DateTime|null getLastActivity()
 * @method void setLastActivity(?\DateTime $lastActivity)
 * @method string getRole()
 * @method void setRole(string $role)
 * @method \DateTime getJoinedAt()
 * @method void setJoinedAt(\DateTime $joinedAt)
 * @method array getSchedulingPreferences()
 * @method void setSchedulingPreferences(array $preferences)
 * @method int getStreakFreezes()
 * @method void setStreakFreezes(int $streakFreezes)
 * @method string|null getSelectedTitlePack()
 * @method void setSelectedTitlePack(?string $selectedTitlePack)
 * @method array getUnlockedTitles()
 * @method void setUnlockedTitles(array $unlockedTitles)
 * @method \DateTime|null getLastFreezeDate()
 * @method void setLastFreezeDate(?\DateTime $lastFreezeDate)
 */
class HouseUser extends Entity implements JsonSerializable
{
    /** @var int */
    protected $houseId;

    /** @var string */
    protected $nextcloudUserId;

    /** @var string */
    protected $displayName;

    /** @var int */
    protected $contributionScore = 0;

    /** @var int */
    protected $coins = 0;

    /** @var float */
    protected $coinMultiplier = 1.0;

    /** @var int */
    protected $currentStreak = 0;

    /** @var \DateTime|null */
    protected $lastActivity;

    /** @var string */
    protected $role = 'member';

    /** @var \DateTime */
    protected $joinedAt;

    /** @var array */
    protected $schedulingPreferences = [];

    /** @var int */
    protected $longestStreak = 0;

    /** @var int */
    protected $streakFreezes = 1;

    /** @var string|null Selected title pack key (null = no pack chosen). */
    protected $selectedTitlePack = null;

    /** @var array Cache of `{pack}.{tier}` titles the user has unlocked. */
    protected $unlockedTitles = [];

    /** @var \DateTime|null Last day the streak-freeze job processed this user (CR-SVC-03 idempotency). */
    protected $lastFreezeDate = null;

    public function __construct()
    {
        $this->addType('id', 'integer');
        $this->addType('houseId', 'integer');
        $this->addType('nextcloudUserId', 'string');
        $this->addType('displayName', 'string');
        $this->addType('contributionScore', 'integer');
        $this->addType('coins', 'integer');
        $this->addType('coinMultiplier', 'decimal');
        $this->addType('currentStreak', 'integer');
        $this->addType('longestStreak', 'integer');
        $this->addType('lastActivity', 'datetime');
        $this->addType('role', 'string');
        $this->addType('joinedAt', 'datetime');
        $this->addType('schedulingPreferences', 'json');
        $this->addType('streakFreezes', 'integer');
        $this->addType('selectedTitlePack', 'string');
        $this->addType('unlockedTitles', 'json');
        $this->addType('lastFreezeDate', 'date');
    }

    /**
     * Convert house user to JSON representation
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'houseId' => $this->houseId,
            'nextcloudUserId' => $this->nextcloudUserId,
            'displayName' => $this->displayName,
            'contributionScore' => $this->contributionScore,
            'coins' => $this->coins,
            'coinMultiplier' => $this->coinMultiplier,
            'currentStreak' => $this->currentStreak,
            'longestStreak' => $this->longestStreak,
            'lastActivity' => $this->lastActivity?->format('c'),
            'role' => $this->role,
            'joinedAt' => $this->joinedAt?->format('c'),
            'schedulingPreferences' => $this->schedulingPreferences,
            'streakFreezes' => $this->streakFreezes,
            'selectedTitlePack' => $this->selectedTitlePack,
        ];
    }

    /**
     * Add contribution score
     *
     * @param int $points Points to add
     */
    public function addContributionScore(int $points): void
    {
        $this->setContributionScore($this->contributionScore + $points);
    }

    /**
     * Add coins
     *
     * @param int $coins Coins to add
     */
    public function addCoins(int $coins): void
    {
        $this->setCoins($this->coins + $coins);
    }

    /**
     * Calculate streak with respect to absence days
     *
     * @param array $weekdayAvailability Weekday availability from scheduling preferences
     * @return int New streak value
     */
    public function calculateStreakWithAbsence(array $weekdayAvailability): int
    {
        $lastActivity = $this->getLastActivity();

        // If no previous activity, start streak at 1
        if ($lastActivity === null) {
            return 1;
        }

        $today = new \DateTime();
        $today->setTime(0, 0, 0);
        $lastActivityDate = clone $lastActivity;
        $lastActivityDate->setTime(0, 0, 0);

        // Check if last activity was today already
        if ($lastActivityDate->format('Y-m-d') === $today->format('Y-m-d')) {
            return $this->currentStreak; // Already completed today
        }

        // Check for missed available days between last activity and today
        $daysSinceLastActivity = (int)$lastActivityDate->diff($today)->days;

        // If last activity was yesterday, increment streak
        if ($daysSinceLastActivity === 1) {
            return $this->currentStreak + 1;
        }

        // Check each day between last activity and today (excluding yesterday and today)
        $checkDate = clone $lastActivityDate;
        $checkDate->modify('+1 day');

        while ($checkDate < $today) {
            $dayName = strtolower($checkDate->format('l'));
            $isAvailable = $weekdayAvailability[$dayName] ?? true;

            // If user was available but didn't complete a task, reset streak
            if ($isAvailable) {
                return 1; // Reset to 1 (today's completion)
            }

            $checkDate->modify('+1 day');
        }

        // No missed available days found, increment streak
        return $this->currentStreak + 1;
    }

    /**
     * Update streak respecting absence days
     *
     * @param array $weekdayAvailability Weekday availability from scheduling preferences
     */
    public function updateStreakWithAbsence(array $weekdayAvailability): void
    {
        $this->setCurrentStreak($this->calculateStreakWithAbsence($weekdayAvailability));
    }

    /**
     * Reset streak
     */
    public function resetStreak(): void
    {
        $this->setCurrentStreak(0);
    }

    /**
     * Update last activity timestamp
     */
    public function touch(): void
    {
        $this->setLastActivity(new \DateTime());
    }

    /**
     * Check if user is owner
     */
    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /**
     * Check if user is child
     */
    public function isChild(): bool
    {
        return $this->role === 'child';
    }

    /**
     * Get role label (English only - i18n should be handled in frontend)
     */
    public function getRoleLabel(): string
    {
        return match($this->role) {
            'owner' => 'Owner',
            'member' => 'Member',
            'child' => 'Child',
            default => 'Member'
        };
    }

    /**
     * Get rank based on contribution score (English only - i18n should be handled in frontend)
     */
    public function getRank(): string
    {
        $score = $this->contributionScore;

        return match(true) {
            $score >= 5000 => 'Legend',
            $score >= 2500 => 'Champion',
            $score >= 1000 => 'Guardian',
            $score >= 500 => 'Keeper',
            $score >= 200 => 'Helper',
            $score >= 50 => 'Beginner',
            default => 'Novice'
        };
    }

    /**
     * Increment streak
     */
    public function incrementStreak(): void
    {
        $newStreak = $this->currentStreak + 1;
        $this->setCurrentStreak($newStreak);
        if ($newStreak > $this->longestStreak) {
            $this->setLongestStreak($newStreak);
        }
    }

    /**
     * Consume one streak freeze. Returns true if a freeze was consumed, false if none available.
     */
    public function consumeFreeze(): bool
    {
        if ($this->streakFreezes <= 0) {
            return false;
        }
        $this->setStreakFreezes($this->streakFreezes - 1);
        return true;
    }

    /**
     * Restock streak freezes to the configured amount, capped at the given maximum.
     *
     * @param int $count Target freeze count
     * @param int $cap Maximum allowed freezes
     */
    public function restockFreezes(int $count, int $cap = 2): void
    {
        $this->setStreakFreezes(min($count, $cap));
    }

    /**
     * Award a bonus freeze (e.g. from combo milestone), respecting the cap.
     *
     * @param int $cap Maximum allowed freezes
     */
    public function awardBonusFreeze(int $cap = 2): void
    {
        if ($this->streakFreezes < $cap) {
            $this->setStreakFreezes($this->streakFreezes + 1);
        }
    }

    /**
     * Append a `{pack}.{tier}` title id to the unlocked-titles cache.
     *
     * Idempotent: returns true only when the title was newly added (drives
     * the unlock-detection "is this a new unlock?" check). The cache is a
     * denormalized mirror of the stat-derived unlock set, so a house reset
     * clears it without data loss.
     *
     * @param string $tierId Canonical `{pack}.{tier}` identifier
     * @return bool True when the title was newly cached
     */
    public function addUnlockedTitle(string $tierId): bool
    {
        $current = $this->unlockedTitles ?? [];
        if (in_array($tierId, $current, true)) {
            return false;
        }
        $current[] = $tierId;
        $this->setUnlockedTitles($current);
        return true;
    }
}
