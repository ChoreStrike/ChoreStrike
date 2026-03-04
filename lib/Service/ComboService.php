<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\Combo;
use OCA\ChoreStrike\Db\ComboMapper;

/**
 * Service for managing combo system
 */
class ComboService
{
    public function __construct(
        private ComboMapper $comboMapper,
    ) {
    }

    /**
     * Start new combo for user
     */
    public function startCombo(int $houseId, string $userId): Combo
    {
        $combo = new Combo();
        $combo->setHouseId($houseId);
        $combo->setUserId($userId);
        $combo->setTaskCount(0);
        $combo->setStartedAt(new \DateTime());
        $combo->setLastTaskAt(new \DateTime());
        $combo->setComboBonusPoints(0);
        $combo->setIsActive(true);
        return $this->comboMapper->insert($combo);
    }

    /**
     * Get or create active combo for user
     */
    public function getOrCreateCombo(int $houseId, string $userId): Combo
    {
        $activeCombo = $this->comboMapper->findActiveByUser($houseId, $userId);

        if ($activeCombo === null) {
            return $this->startCombo($houseId, $userId);
        }

        // Check if combo has timed out
        if ($activeCombo->hasTimedOut()) {
            $activeCombo->end();
            $this->comboMapper->update($activeCombo);
            return $this->startCombo($houseId, $userId);
        }

        return $activeCombo;
    }

    /**
     * Add task to combo
     */
    public function addTaskToCombo(int $houseId, string $userId, int $points = 10): Combo
    {
        $combo = $this->getOrCreateCombo($houseId, $userId);
        $combo->addTask($points);
        return $this->comboMapper->update($combo);
    }

    /**
     * End combo and return bonus points
     */
    public function endCombo(int $houseId, string $userId): int
    {
        $activeCombo = $this->comboMapper->findActiveByUser($houseId, $userId);

        if ($activeCombo === null) {
            return 0;
        }

        $bonusPoints = $activeCombo->getComboBonusPoints();
        $activeCombo->end();
        $this->comboMapper->update($activeCombo);

        return $bonusPoints;
    }

    /**
     * Get active combo for user
     */
    public function getActiveCombo(int $houseId, string $userId): ?Combo
    {
        return $this->comboMapper->findActiveByUser($houseId, $userId);
    }

    /**
     * Get combo statistics for house
     */
    public function getComboStats(int $houseId): array
    {
        return $this->comboMapper->getComboStats($houseId);
    }

    /**
     * Get completed combos for user
     *
     * @return Combo[]
     */
    public function getCompletedCombos(int $houseId, string $userId, int $limit = 10): array
    {
        return $this->comboMapper->findCompletedByUser($houseId, $userId, $limit);
    }

    /**
     * Calculate total bonus points from all combos
     */
    public function getTotalComboBonus(int $houseId, string $userId): int
    {
        $completedCombos = $this->getCompletedCombos($houseId, $userId, 100);
        $total = 0;

        foreach ($completedCombos as $combo) {
            $total += $combo->getComboBonusPoints();
        }

        return $total;
    }

    /**
     * Check if user has active combo
     */
    public function hasActiveCombo(int $houseId, string $userId): bool
    {
        $combo = $this->getActiveCombo($houseId, $userId);
        return $combo !== null && !$combo->hasTimedOut();
    }

    /**
     * Get combo progress (task count and bonus)
     */
    public function getComboProgress(int $houseId, string $userId): array
    {
        $combo = $this->getActiveCombo($houseId, $userId);

        if ($combo === null || !$combo->getIsActive()) {
            return [
                'hasCombo' => false,
                'taskCount' => 0,
                'bonusPoints' => 0,
                'multiplier' => 1.0,
            ];
        }

        return [
            'hasCombo' => true,
            'taskCount' => $combo->getTaskCount(),
            'bonusPoints' => $combo->getComboBonusPoints(),
            'multiplier' => $combo->getMultiplier(),
            'timedOut' => $combo->hasTimedOut(),
        ];
    }
}
