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
 * Entity for storing user availability settings for smart scheduling
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getHouseId()
 * @method void setHouseId(int $houseId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method array getWeekdayAvailability()
 * @method void setWeekdayAvailability(array $availability)
 * @method array getTimeRanges()
 * @method void setTimeRanges(array $timeRanges)
 * @method array getEnergyBudget()
 * @method void setEnergyBudget(array $budget)
 * @method string getPlanningMode()
 * @method void setPlanningMode(string $mode)
 * @method int getMaxTasksPerDay()
 * @method void setMaxTasksPerDay(int $maxTasks)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $date)
 */
class AvailabilitySettings extends Entity implements JsonSerializable
{
    /** @var int */
    protected $houseId;

    /** @var string */
    protected $userId;

    /** @var array */
    protected $weekdayAvailability = [
        'monday' => true,
        'tuesday' => true,
        'wednesday' => true,
        'thursday' => true,
        'friday' => true,
        'saturday' => true,
        'sunday' => true,
    ];

    /** @var array */
    protected $timeRanges = [
        'start' => '09:00',
        'end' => '18:00',
    ];

    /** @var array */
    protected $energyBudget = [
        'monday' => 10,
        'tuesday' => 10,
        'wednesday' => 10,
        'thursday' => 10,
        'friday' => 10,
        'saturday' => 10,
        'sunday' => 10,
    ];

    /** @var string */
    protected $planningMode = 'auto_balance';

    /** @var int */
    protected $maxTasksPerDay = 5;

    /** @var \DateTime */
    protected $updatedAt;

    public function __construct()
    {
        $this->addType('id', 'integer');
        $this->addType('houseId', 'integer');
        $this->addType('userId', 'string');
        $this->addType('weekdayAvailability', 'json');
        $this->addType('timeRanges', 'json');
        $this->addType('energyBudget', 'json');
        $this->addType('planningMode', 'string');
        $this->addType('maxTasksPerDay', 'integer');
        $this->addType('updatedAt', 'datetime');
    }

    /**
     * Get available days as array of day names
     */
    public function getAvailableDays(): array
    {
        return array_keys(array_filter($this->weekdayAvailability));
    }

    /**
     * Check if user is available on specific day
     */
    public function isAvailableOn(string $day): bool
    {
        return $this->weekdayAvailability[strtolower($day)] ?? false;
    }

    /**
     * Get energy budget for specific day
     */
    public function getEnergyForDay(string $day): int
    {
        return $this->energyBudget[strtolower($day)] ?? 0;
    }

    /**
     * Get total weekly energy budget
     */
    public function getTotalWeeklyEnergy(): int
    {
        return array_sum($this->energyBudget);
    }

    /**
     * Calculate available hours per day
     */
    public function getAvailableHours(): float
    {
        $start = \DateTime::createFromFormat('H:i', $this->timeRanges['start']);
        $end = \DateTime::createFromFormat('H:i', $this->timeRanges['end']);

        if ($start && $end) {
            $diff = $start->diff($end);
            return $diff->h + ($diff->i / 60);
        }

        return 8.0; // default 8 hours
    }

    /**
     * Check if planning mode is valid
     */
    public static function isValidPlanningMode(string $mode): bool
    {
        return in_array($mode, [
            'weekend_warrior',
            'daily_cleaner',
            'custom',
            'auto_balance',
        ]);
    }

    /**
     * Get default distribution for planning mode
     */
    public function getDefaultDistribution(): array
    {
        $availableDays = $this->getAvailableDays();
        $dayCount = count($availableDays);

        if ($dayCount === 0) {
            return [];
        }

        switch ($this->planningMode) {
            case 'weekend_warrior':
                return $this->calculateWeekendWarriorDistribution($availableDays);

            case 'daily_cleaner':
                return $this->calculateDailyCleanerDistribution($availableDays);

            case 'auto_balance':
            default:
                return $this->calculateAutoBalanceDistribution($availableDays);
        }
    }

    /**
     * Calculate weekend warrior distribution (80% weekend)
     */
    private function calculateWeekendWarriorDistribution(array $availableDays): array
    {
        $distribution = [];
        $hasWeekend = false;

        foreach ($availableDays as $day) {
            if (in_array($day, ['saturday', 'sunday'])) {
                $distribution[$day] = 0.4; // 40% each day
                $hasWeekend = true;
            } else {
                $distribution[$day] = 0.05; // 5% weekdays
            }
        }

        // If no weekend available, distribute evenly
        if (!$hasWeekend) {
            $perDay = 1.0 / count($availableDays);
            foreach ($availableDays as $day) {
                $distribution[$day] = $perDay;
            }
        }

        return $distribution;
    }

    /**
     * Calculate daily cleaner distribution (even across all days)
     */
    private function calculateDailyCleanerDistribution(array $availableDays): array
    {
        $distribution = [];
        $perDay = 1.0 / count($availableDays);

        foreach ($availableDays as $day) {
            $distribution[$day] = $perDay;
        }

        return $distribution;
    }

    /**
     * Calculate auto-balance distribution based on energy budgets
     */
    private function calculateAutoBalanceDistribution(array $availableDays): array
    {
        $distribution = [];
        $totalEnergy = 0;

        foreach ($availableDays as $day) {
            $totalEnergy += $this->getEnergyForDay($day);
        }

        if ($totalEnergy === 0) {
            return $this->calculateDailyCleanerDistribution($availableDays);
        }

        foreach ($availableDays as $day) {
            $distribution[$day] = $this->getEnergyForDay($day) / $totalEnergy;
        }

        return $distribution;
    }

    /**
     * Convert to JSON representation
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'houseId' => $this->houseId,
            'userId' => $this->userId,
            'weekdayAvailability' => $this->weekdayAvailability,
            'timeRanges' => $this->timeRanges,
            'energyBudget' => $this->energyBudget,
            'planningMode' => $this->planningMode,
            'maxTasksPerDay' => $this->maxTasksPerDay,
            'updatedAt' => $this->updatedAt?->format('c'),
            'availableDays' => $this->getAvailableDays(),
            'totalWeeklyEnergy' => $this->getTotalWeeklyEnergy(),
            'availableHours' => $this->getAvailableHours(),
            'defaultDistribution' => $this->getDefaultDistribution(),
        ];
    }
}
