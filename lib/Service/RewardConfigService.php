<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCP\IAppConfig;

/**
 * Service for managing reward system configuration
 */
class RewardConfigService
{
    private const APP_NAME = 'chorestrike';

    // Config keys with defaults
    public const KEY_TIER_BRONZE_MULTIPLIER = 'tier_bronze_multiplier';
    public const KEY_TIER_SILVER_MULTIPLIER = 'tier_silver_multiplier';
    public const KEY_TIER_GOLD_MULTIPLIER = 'tier_gold_multiplier';
    public const KEY_EARLY_BONUS_THRESHOLD_DAYS = 'early_bonus_threshold_days';
    public const KEY_EARLY_BONUS_MULTIPLIER = 'early_bonus_multiplier';
    public const KEY_ENERGY_MAX_PER_TASK = 'energy_max_per_task';
    public const KEY_COIN_BASE_PER_ENERGY = 'coin_base_per_energy';
    public const KEY_STREAK_FREEZES_PER_WEEK = 'streak_freezes_per_week';
    public const KEY_STREAK_FREEZE_CAP = 'streak_freeze_cap';
    public const KEY_COMBO_BONUS_FREEZE_THRESHOLD = 'combo_bonus_freeze_threshold';
    public const KEY_WEEKLY_GOAL_TARGET_PERCENT = 'weekly_goal_target_percent';

    private IAppConfig $config;

    public function __construct(IAppConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Get tier multiplier for a given tier
     *
     * @param string $tier The tier (bronze, silver, gold)
     */
    public function getTierMultiplier(string $tier): float
    {
        return match ($tier) {
            'bronze' => (float) $this->config->getValueString(
                self::APP_NAME,
                self::KEY_TIER_BRONZE_MULTIPLIER,
                '1.0'
            ),
            'silver' => (float) $this->config->getValueString(
                self::APP_NAME,
                self::KEY_TIER_SILVER_MULTIPLIER,
                '1.5'
            ),
            'gold' => (float) $this->config->getValueString(
                self::APP_NAME,
                self::KEY_TIER_GOLD_MULTIPLIER,
                '2.0'
            ),
            default => 1.0,
        };
    }

    /**
     * Get early completion bonus threshold in days
     */
    public function getEarlyBonusThresholdDays(): int
    {
        return (int) $this->config->getValueString(
            self::APP_NAME,
            self::KEY_EARLY_BONUS_THRESHOLD_DAYS,
            '21'
        );
    }

    /**
     * Get early completion bonus multiplier
     */
    public function getEarlyBonusMultiplier(): float
    {
        return (float) $this->config->getValueString(
            self::APP_NAME,
            self::KEY_EARLY_BONUS_MULTIPLIER,
            '1.5'
        );
    }

    /**
     * Get maximum energy per task
     */
    public function getEnergyMaxPerTask(): int
    {
        return (int) $this->config->getValueString(
            self::APP_NAME,
            self::KEY_ENERGY_MAX_PER_TASK,
            '9'
        );
    }

    /**
     * Get base coins per energy point
     */
    public function getCoinBasePerEnergy(): int
    {
        return (int) $this->config->getValueString(
            self::APP_NAME,
            self::KEY_COIN_BASE_PER_ENERGY,
            '10'
        );
    }

    /**
     * Get number of streak freezes restocked per week
     */
    public function getStreakFreezesPerWeek(): int
    {
        return (int) $this->config->getValueString(
            self::APP_NAME,
            self::KEY_STREAK_FREEZES_PER_WEEK,
            '1'
        );
    }

    /**
     * Get maximum number of streak freezes a user can hold
     */
    public function getStreakFreezeCap(): int
    {
        return (int) $this->config->getValueString(
            self::APP_NAME,
            self::KEY_STREAK_FREEZE_CAP,
            '2'
        );
    }

    /**
     * Get combo count threshold for earning a bonus freeze
     */
    public function getComboBonusFreezeThreshold(): int
    {
        return (int) $this->config->getValueString(
            self::APP_NAME,
            self::KEY_COMBO_BONUS_FREEZE_THRESHOLD,
            '5'
        );
    }

    /**
     * Get the weekly household goal completion target as a percent (0-100).
     *
     * The household "reaches" its weekly goal when the share of due tasks
     * completed this week is at least this percent. Default 80 (matches the
     * Phase 3 plan recommendation: forgiving enough for family life).
     */
    public function getWeeklyGoalTargetPercent(): int
    {
        $value = (int) $this->config->getValueString(
            self::APP_NAME,
            self::KEY_WEEKLY_GOAL_TARGET_PERCENT,
            '80'
        );
        // Clamp to a sane range; an admin could store anything via IAppConfig.
        return max(1, min(100, $value));
    }

    /**
     * Set streak freezes per week
     */
    public function setStreakFreezesPerWeek(int $count): void
    {
        $this->config->setValueString(
            self::APP_NAME,
            self::KEY_STREAK_FREEZES_PER_WEEK,
            (string) $count
        );
    }

    /**
     * Set streak freeze cap
     */
    public function setStreakFreezeCap(int $cap): void
    {
        $this->config->setValueString(
            self::APP_NAME,
            self::KEY_STREAK_FREEZE_CAP,
            (string) $cap
        );
    }

    /**
     * Set combo bonus freeze threshold
     */
    public function setComboBonusFreezeThreshold(int $threshold): void
    {
        $this->config->setValueString(
            self::APP_NAME,
            self::KEY_COMBO_BONUS_FREEZE_THRESHOLD,
            (string) $threshold
        );
    }

    /**
     * Set weekly household goal target percent
     *
     * @param int $percent Target percent (clamped to 1-100)
     */
    public function setWeeklyGoalTargetPercent(int $percent): void
    {
        $this->config->setValueString(
            self::APP_NAME,
            self::KEY_WEEKLY_GOAL_TARGET_PERCENT,
            (string) max(1, min(100, $percent))
        );
    }

    /**
     * Set tier multiplier
     *
     * @param string $tier The tier (bronze, silver, gold)
     * @param float $value The multiplier value
     */
    public function setTierMultiplier(string $tier, float $value): void
    {
        $key = match ($tier) {
            'bronze' => self::KEY_TIER_BRONZE_MULTIPLIER,
            'silver' => self::KEY_TIER_SILVER_MULTIPLIER,
            'gold' => self::KEY_TIER_GOLD_MULTIPLIER,
            default => null,
        };

        if ($key !== null) {
            $this->config->setValueString(self::APP_NAME, $key, (string) $value);
        }
    }

    /**
     * Set early bonus threshold days
     */
    public function setEarlyBonusThresholdDays(int $days): void
    {
        $this->config->setValueString(
            self::APP_NAME,
            self::KEY_EARLY_BONUS_THRESHOLD_DAYS,
            (string) $days
        );
    }

    /**
     * Set early bonus multiplier
     */
    public function setEarlyBonusMultiplier(float $multiplier): void
    {
        $this->config->setValueString(
            self::APP_NAME,
            self::KEY_EARLY_BONUS_MULTIPLIER,
            (string) $multiplier
        );
    }

    /**
     * Set max energy per task
     */
    public function setEnergyMaxPerTask(int $max): void
    {
        $this->config->setValueString(
            self::APP_NAME,
            self::KEY_ENERGY_MAX_PER_TASK,
            (string) $max
        );
    }

    /**
     * Set base coins per energy
     */
    public function setCoinBasePerEnergy(int $base): void
    {
        $this->config->setValueString(
            self::APP_NAME,
            self::KEY_COIN_BASE_PER_ENERGY,
            (string) $base
        );
    }

    /**
     * Get all config values as array
     */
    public function getAllConfig(): array
    {
        return [
            'tierBronzeMultiplier' => $this->getTierMultiplier('bronze'),
            'tierSilverMultiplier' => $this->getTierMultiplier('silver'),
            'tierGoldMultiplier' => $this->getTierMultiplier('gold'),
            'earlyBonusThresholdDays' => $this->getEarlyBonusThresholdDays(),
            'earlyBonusMultiplier' => $this->getEarlyBonusMultiplier(),
            'energyMaxPerTask' => $this->getEnergyMaxPerTask(),
            'coinBasePerEnergy' => $this->getCoinBasePerEnergy(),
            'streakFreezesPerWeek' => $this->getStreakFreezesPerWeek(),
            'streakFreezeCap' => $this->getStreakFreezeCap(),
            'comboBonusFreezeThreshold' => $this->getComboBonusFreezeThreshold(),
            'weeklyGoalTargetPercent' => $this->getWeeklyGoalTargetPercent(),
        ];
    }

    /**
     * Update multiple config values at once
     *
     * @param array $values Key-value pairs to update
     */
    public function updateConfig(array $values): void
    {
        if (isset($values['tierBronzeMultiplier'])) {
            $this->setTierMultiplier('bronze', (float) $values['tierBronzeMultiplier']);
        }
        if (isset($values['tierSilverMultiplier'])) {
            $this->setTierMultiplier('silver', (float) $values['tierSilverMultiplier']);
        }
        if (isset($values['tierGoldMultiplier'])) {
            $this->setTierMultiplier('gold', (float) $values['tierGoldMultiplier']);
        }
        if (isset($values['earlyBonusThresholdDays'])) {
            $this->setEarlyBonusThresholdDays((int) $values['earlyBonusThresholdDays']);
        }
        if (isset($values['earlyBonusMultiplier'])) {
            $this->setEarlyBonusMultiplier((float) $values['earlyBonusMultiplier']);
        }
        if (isset($values['energyMaxPerTask'])) {
            $this->setEnergyMaxPerTask((int) $values['energyMaxPerTask']);
        }
        if (isset($values['coinBasePerEnergy'])) {
            $this->setCoinBasePerEnergy((int) $values['coinBasePerEnergy']);
        }
        if (isset($values['streakFreezesPerWeek'])) {
            $this->setStreakFreezesPerWeek((int) $values['streakFreezesPerWeek']);
        }
        if (isset($values['streakFreezeCap'])) {
            $this->setStreakFreezeCap((int) $values['streakFreezeCap']);
        }
        if (isset($values['comboBonusFreezeThreshold'])) {
            $this->setComboBonusFreezeThreshold((int) $values['comboBonusFreezeThreshold']);
        }
        if (isset($values['weeklyGoalTargetPercent'])) {
            $this->setWeeklyGoalTargetPercent((int) $values['weeklyGoalTargetPercent']);
        }
    }
}
