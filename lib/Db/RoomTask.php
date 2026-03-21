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
 * @method int getRoomId()
 * @method void setRoomId(int $roomId)
 * @method int getTaskId()
 * @method void setTaskId(int $taskId)
 * @method \DateTime getNextDue()
 * @method void setNextDue(\DateTime $nextDue)
 * @method \DateTime|null getLastCompleted()
 * @method void setLastCompleted(?\DateTime $lastCompleted)
 * @method string|null getAssignedToUserId()
 * @method void setAssignedToUserId(?string $assignedToUserId)
 * @method bool getIsActive()
 * @method void setIsActive(bool $isActive)
 * @method int|null getScopeId()
 * @method void setScopeId(?int $scopeId)
 * @method int|null getNextcloudTaskId()
 * @method void setNextcloudTaskId(?int $nextcloudTaskId)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method string|null getAssignedToGroup()
 * @method void setAssignedToGroup(?string $assignedToGroup)
 * @method string getAssignmentType()
 * @method void setAssignmentType(string $assignmentType)
 * @method string|null getClaimedByUser()
 * @method void setClaimedByUser(?string $claimedByUser)
 * @method bool getWasClaimed()
 * @method void setWasClaimed(bool $wasClaimed)
 * @method string|null getOriginalAssignee()
 * @method void setOriginalAssignee(?string $originalAssignee)
 * @method \DateTime|null getClaimedAt()
 * @method void setClaimedAt(?\DateTime $claimedAt)
 * @method string|null getAssignedByUserId()
 * @method void setAssignedByUserId(?string $assignedByUserId)
 * @method \DateTime|null getAssignedAt()
 * @method void setAssignedAt(?\DateTime $assignedAt)
 * @method \DateTime|null getIdealDueDate()
 * @method void setIdealDueDate(?\DateTime $idealDueDate)
 */
class RoomTask extends Entity implements JsonSerializable
{
    /** @var int */
    protected $roomId;

    /** @var int */
    protected $taskId;

    /** @var \DateTime */
    protected $nextDue;

    /** @var \DateTime|null */
    protected $lastCompleted;

    /** @var string|null */
    protected $assignedToUserId;

    /** @var bool */
    protected $isActive = true;

    /** @var int|null */
    protected $scopeId;

    /** @var int|null */
    protected $nextcloudTaskId;

    /** @var \DateTime */
    protected $createdAt;

    /** @var string|null */
    protected $assignedToGroup;

    /** @var string */
    protected $assignmentType = 'user';

    /** @var string|null */
    protected $claimedByUser;

    /** @var bool */
    protected $wasClaimed = false;

    /** @var string|null */
    protected $originalAssignee;

    /** @var \DateTime|null */
    protected $claimedAt;

    /** @var string|null */
    protected $assignedByUserId;

    /** @var \DateTime|null */
    protected $assignedAt;

    /** @var \DateTime|null */
    protected $idealDueDate;

    public function __construct()
    {
        $this->addType('id', 'integer');
        $this->addType('roomId', 'integer');
        $this->addType('taskId', 'integer');
        $this->addType('nextDue', 'datetime');
        $this->addType('lastCompleted', 'datetime');
        $this->addType('assignedToUserId', 'string');
        $this->addType('isActive', 'boolean');
        $this->addType('scopeId', 'integer');
        $this->addType('nextcloudTaskId', 'integer');
        $this->addType('createdAt', 'datetime');
        $this->addType('assignedToGroup', 'string');
        $this->addType('assignmentType', 'string');
        $this->addType('claimedByUser', 'string');
        $this->addType('wasClaimed', 'boolean');
        $this->addType('originalAssignee', 'string');
        $this->addType('claimedAt', 'datetime');
        $this->addType('assignedByUserId', 'string');
        $this->addType('assignedAt', 'datetime');
        $this->addType('idealDueDate', 'datetime');
    }

    /**
     * Convert room task to JSON representation
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'roomId' => $this->roomId,
            'taskId' => $this->taskId,
            'nextDue' => $this->nextDue?->format('c'),
            'lastCompleted' => $this->lastCompleted?->format('c'),
            'assignedToUserId' => $this->assignedToUserId,
            'assignedToGroup' => $this->assignedToGroup,
            'assignmentType' => $this->assignmentType,
            'isActive' => $this->isActive,
            'scopeId' => $this->scopeId,
            'nextcloudTaskId' => $this->nextcloudTaskId,
            'claimedByUser' => $this->claimedByUser,
            'wasClaimed' => $this->wasClaimed,
            'originalAssignee' => $this->originalAssignee,
            'claimedAt' => $this->claimedAt?->format('c'),
            'assignedByUserId' => $this->assignedByUserId,
            'assignedAt' => $this->assignedAt?->format('c'),
            'idealDueDate' => $this->idealDueDate?->format('c'),
            'createdAt' => $this->createdAt?->format('c'),
        ];
    }

    /**
     * Check if task is overdue
     */
    public function isOverdue(): bool
    {
        if ($this->nextDue === null) {
            return false;
        }
        return $this->nextDue < new \DateTime() && $this->isActive;
    }

    /**
     * Check if task is due today
     */
    public function isDueToday(): bool
    {
        if ($this->nextDue === null) {
            return false;
        }

        $today = new \DateTime();
        $today->setTime(0, 0, 0);
        $due = clone $this->nextDue;
        $due->setTime(0, 0, 0);

        return $due->getTimestamp() === $today->getTimestamp() && $this->isActive;
    }

    /**
     * Check if task is due within the next N days
     */
    public function isDueWithinDays(int $days): bool
    {
        if ($this->nextDue === null) {
            return false;
        }

        $future = new \DateTime();
        $future->add(new \DateInterval("P{$days}D"));

        return $this->nextDue <= $future && $this->isActive;
    }

    /**
     * Get days until due (negative if overdue)
     */
    public function getDaysUntilDue(): int
    {
        $today = new \DateTime();
        $today->setTime(0, 0, 0);
        $due = clone $this->nextDue;
        $due->setTime(0, 0, 0);

        $interval = $today->diff($due);
        return $interval->invert ? -(int)$interval->days : (int)$interval->days;
    }

    /**
     * Mark task as completed and calculate next due date
     *
     * @param \DateTime $completedAt The completion time
     * @param string $frequencyType The frequency type
     * @param int $frequencyValue The frequency value
     * @param string $recurrenceMode The recurrence mode ('one_time' or 'recurring')
     * @param string $schedulingMode The scheduling mode ('dynamic' or 'static')
     * @param \DateTime|null $activeUntil The active until date for recurring tasks
     * @param \DateTime|null $fixedScheduleTime The fixed schedule time (for static mode)
     * @param string|null $fixedScheduleDays JSON array of weekdays (for static+weekly mode)
     * @param int $flexDays Number of flex days for flexible scheduling (0 = no flex)
     * @param array $weekdayAvailability User's weekday availability for flex scheduling
     * @param array $energyBudget User's daily energy budget for flex scheduling
     */
    public function markCompleted(
        \DateTime $completedAt,
        string $frequencyType,
        int $frequencyValue,
        string $recurrenceMode = 'recurring',
        string $schedulingMode = 'dynamic',
        ?\DateTime $activeUntil = null,
        ?\DateTime $fixedScheduleTime = null,
        ?string $fixedScheduleDays = null,
        int $flexDays = 0,
        array $weekdayAvailability = [],
        array $energyBudget = []
    ): void {
        $this->setLastCompleted($completedAt);

        // Handle one-time tasks - deactivate after completion
        if ($recurrenceMode === 'one_time') {
            $this->setIsActive(false);
            $this->setNextDue($completedAt);
            $this->setIdealDueDate(null);
            return;
        }

        // Calculate base next due date based on scheduling mode
        if ($schedulingMode === 'static') {
            $baseDue = $this->calculateNextStaticDue(
                $completedAt,
                $frequencyType,
                $frequencyValue,
                $fixedScheduleTime,
                $fixedScheduleDays
            );
        } else {
            // Dynamic scheduling - shift from completion time
            $baseDue = $this->calculateNextDynamicDue($completedAt, $frequencyType, $frequencyValue);
        }

        // Calculate ideal due date within flex window
        $idealDue = $this->calculateIdealDueDate($baseDue, $flexDays, $weekdayAvailability, $energyBudget);

        // Check if next due date exceeds active window
        if ($activeUntil !== null && $idealDue > $activeUntil) {
            $this->setIsActive(false);
            $this->setNextDue($activeUntil);
            $this->setIdealDueDate(null);
        } else {
            $this->setNextDue($idealDue);
            $this->setIdealDueDate($idealDue);
        }
    }

    /**
     * Calculate next due date for dynamic scheduling (shifts with completion)
     */
    private function calculateNextDynamicDue(\DateTime $completedAt, string $frequencyType, int $frequencyValue): \DateTime
    {
        $nextDue = clone $completedAt;

        switch ($frequencyType) {
            case 'daily':
                $nextDue->add(new \DateInterval("P{$frequencyValue}D"));
                break;
            case 'weekly':
                $nextDue->add(new \DateInterval("P{$frequencyValue}W"));
                break;
            case 'monthly':
                $nextDue->add(new \DateInterval("P{$frequencyValue}M"));
                break;
            case 'custom':
                // For custom, default to 1 week
                $nextDue->add(new \DateInterval('P1W'));
                break;
        }

        return $nextDue;
    }

    /**
     * Calculate next due date for static scheduling (fixed time slots)
     */
    private function calculateNextStaticDue(
        \DateTime $completedAt,
        string $frequencyType,
        int $frequencyValue,
        ?\DateTime $fixedScheduleTime,
        ?string $fixedScheduleDays
    ): \DateTime {
        $nextDue = clone $completedAt;

        // Set time to fixed schedule time
        if ($fixedScheduleTime !== null) {
            $nextDue->setTime(
                (int)$fixedScheduleTime->format('H'),
                (int)$fixedScheduleTime->format('i'),
                0
            );
        }

        // For daily tasks, just add the days
        if ($frequencyType === 'daily') {
            $nextDue->add(new \DateInterval("P{$frequencyValue}D"));
            return $nextDue;
        }

        // For weekly tasks, find the next occurrence of specified weekdays
        if ($frequencyType === 'weekly' && $fixedScheduleDays) {
            $days = json_decode($fixedScheduleDays, true) ?: [];
            if (!empty($days)) {
                // Move to next occurrence after completedAt
                $nextDue->add(new \DateInterval('P1D'));
                return $this->findNextWeekday($nextDue, $days, $frequencyValue);
            }
        }

        // For monthly tasks, add months
        if ($frequencyType === 'monthly') {
            $nextDue->add(new \DateInterval("P{$frequencyValue}M"));
            return $nextDue;
        }

        // Fallback: add 1 week
        $nextDue->add(new \DateInterval('P1W'));
        return $nextDue;
    }

    /**
     * Find the next occurrence of specified weekdays
     */
    private function findNextWeekday(\DateTime $from, array $weekdays, int $weeksInterval = 1): \DateTime
    {
        $dayMap = [
            'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
            'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7
        ];

        // Normalize weekday names
        $targetDays = array_map(fn ($day) => strtolower(trim($day)), $weekdays);
        $targetDays = array_filter($targetDays, fn ($day) => isset($dayMap[$day]));

        if (empty($targetDays)) {
            return $from;
        }

        $current = clone $from;
        $currentDayNum = (int)$current->format('N');

        // Find the next matching day within the same week
        $daysToCheck = [];
        foreach ($targetDays as $targetDay) {
            $targetDayNum = $dayMap[$targetDay];
            if ($targetDayNum > $currentDayNum) {
                $daysToCheck[] = $targetDayNum - $currentDayNum;
            }
        }

        if (!empty($daysToCheck)) {
            // Found a day later this week
            $daysToAdd = min($daysToCheck);
            $current->add(new \DateInterval("P{$daysToAdd}D"));
        } else {
            // Need to go to next week
            $firstTargetDay = min(array_map(fn ($day) => $dayMap[$day], $targetDays));
            $daysToNextWeek = 7 - $currentDayNum + $firstTargetDay;
            if ($weeksInterval > 1) {
                $daysToNextWeek += ($weeksInterval - 1) * 7;
            }
            $current->add(new \DateInterval("P{$daysToNextWeek}D"));
        }

        return $current;
    }

    /**
     * Get status as string
     */
    public function getStatus(): string
    {
        if (!$this->isActive) {
            return 'inactive';
        }

        if ($this->isOverdue()) {
            return 'overdue';
        }

        if ($this->isDueToday()) {
            return 'due_today';
        }

        if ($this->isDueWithinDays(3)) {
            return 'due_soon';
        }

        return 'upcoming';
    }

    /**
     * Calculate the ideal due date within a flex window
     *
     * The flex window is: [baseDate - flexDays, baseDate + flexDays]
     * This method picks the ideal due date based on scheduling preferences.
     *
     * @param \DateTime $baseDate The base due date (e.g., last completed + interval)
     * @param int $flexDays Number of flex days (0 = no flex, just use base date)
     * @param array $weekdayAvailability User's weekday availability (e.g., ['monday' => true, ...])
     * @param array $energyBudget User's daily energy budget (e.g., ['monday' => 10, ...])
     * @return \DateTime The ideal due date
     */
    public function calculateIdealDueDate(
        \DateTime $baseDate,
        int $flexDays,
        array $weekdayAvailability = [],
        array $energyBudget = []
    ): \DateTime {
        // No flex window - just use base date
        if ($flexDays <= 0) {
            return clone $baseDate;
        }

        // Calculate flex window bounds
        $windowStart = clone $baseDate;
        $windowStart->sub(new \DateInterval("P{$flexDays}D"));
        $windowStart->setTime(0, 0, 0);

        $windowEnd = clone $baseDate;
        $windowEnd->add(new \DateInterval("P{$flexDays}D"));
        $windowEnd->setTime(23, 59, 59);

        // Find all available days within the window
        $availableDays = $this->findAvailableDaysInWindow($windowStart, $windowEnd, $weekdayAvailability);

        // If no available days, fall back to base date
        if (empty($availableDays)) {
            return clone $baseDate;
        }

        // Score each available day based on energy budget and pick the best
        $bestDay = $this->pickBestDayInWindow($availableDays, $energyBudget, $baseDate);

        return $bestDay;
    }

    /**
     * Find all available days within a date window
     *
     * @param \DateTime $windowStart Start of window
     * @param \DateTime $windowEnd End of window
     * @param array $weekdayAvailability User's weekday availability
     * @return array Array of \DateTime objects for available days
     */
    private function findAvailableDaysInWindow(
        \DateTime $windowStart,
        \DateTime $windowEnd,
        array $weekdayAvailability
    ): array {
        $availableDays = [];
        $current = clone $windowStart;

        // If no availability set, assume all days are available
        $hasAvailability = !empty($weekdayAvailability);

        while ($current <= $windowEnd) {
            $dayName = strtolower($current->format('l'));
            $isAvailable = $hasAvailability ? ($weekdayAvailability[$dayName] ?? true) : true;

            if ($isAvailable) {
                $availableDays[] = clone $current;
            }

            $current->add(new \DateInterval('P1D'));
        }

        return $availableDays;
    }

    /**
     * Pick the best day from available days within flex window
     *
     * Prioritizes:
     * 1. Days with higher energy budget (more capacity)
     * 2. Days closer to the base date (less deviation)
     *
     * @param array $availableDays Array of \DateTime objects
     * @param array $energyBudget User's daily energy budget
     * @param \DateTime $baseDate The base due date for proximity scoring
     * @return \DateTime The best day
     */
    private function pickBestDayInWindow(array $availableDays, array $energyBudget, \DateTime $baseDate): \DateTime
    {
        $hasEnergyBudget = !empty($energyBudget);
        $bestDay = $availableDays[0];
        $bestScore = -1;

        foreach ($availableDays as $day) {
            $score = 0;

            // Energy budget score (0-10 points)
            if ($hasEnergyBudget) {
                $dayName = strtolower($day->format('l'));
                $energy = $energyBudget[$dayName] ?? 10;
                $score += min($energy, 10); // Cap at 10 for scoring
            } else {
                $score += 5; // Default score if no budget set
            }

            // Proximity score (0-5 points, closer to base = higher)
            $daysDiff = abs($day->diff($baseDate)->days);
            $proximityScore = max(0, 5 - $daysDiff);
            $score += $proximityScore;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestDay = $day;
            }
        }

        return $bestDay;
    }

    /**
     * Get flex window bounds for display purposes
     *
     * @param int $flexDays Number of flex days
     * @return array{earliest: \DateTime|null, ideal: \DateTime|null, latest: \DateTime|null}
     */
    public function getFlexWindowBounds(int $flexDays): array
    {
        $ideal = $this->idealDueDate ?? $this->nextDue;

        if ($flexDays <= 0 || $ideal === null) {
            return [
                'earliest' => null,
                'ideal' => $ideal,
                'latest' => null,
            ];
        }

        $earliest = clone $ideal;
        $earliest->sub(new \DateInterval("P{$flexDays}D"));

        $latest = clone $ideal;
        $latest->add(new \DateInterval("P{$flexDays}D"));

        return [
            'earliest' => $earliest,
            'ideal' => $ideal,
            'latest' => $latest,
        ];
    }
}
