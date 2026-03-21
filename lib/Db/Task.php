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
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method int getEnergyCost()
 * @method void setEnergyCost(int $energyCost)
 * @method int getPriority()
 * @method void setPriority(int $priority)
 * @method string getTaskTier()
 * @method void setTaskTier(string $taskTier)
 * @method string getFrequencyType()
 * @method void setFrequencyType(string $frequencyType)
 * @method int getFrequencyValue()
 * @method void setFrequencyValue(int $frequencyValue)
 * @method int getEstimatedMinutes()
 * @method void setEstimatedMinutes(int $estimatedMinutes)
 * @method string getCategory()
 * @method void setCategory(string $category)
 * @method bool getIsTemplate()
 * @method void setIsTemplate(bool $isTemplate)
 * @method string getScopeType()
 * @method void setScopeType(string $scopeType)
 * @method string|null getScopeLabel()
 * @method void setScopeLabel(?string $scopeLabel)
 * @method string getRecurrenceMode()
 * @method void setRecurrenceMode(string $recurrenceMode)
 * @method \DateTime|null getActiveFrom()
 * @method void setActiveFrom(?\DateTime $activeFrom)
 * @method \DateTime|null getActiveUntil()
 * @method void setActiveUntil(?\DateTime $activeUntil)
 * @method bool getIsVoluntary()
 * @method void setIsVoluntary(bool $isVoluntary)
 * @method string|null getReminderType()
 * @method void setReminderType(?string $reminderType)
 * @method int|null getReminderMinutesBefore()
 * @method void setReminderMinutesBefore(?int $reminderMinutesBefore)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method string getSchedulingMode()
 * @method void setSchedulingMode(string $schedulingMode)
 * @method \DateTime|null getFixedScheduleTime()
 * @method void setFixedScheduleTime(?\DateTime $fixedScheduleTime)
 * @method string|null getFixedScheduleDays()
 * @method void setFixedScheduleDays(?string $fixedScheduleDays)
 * @method string|null getInstructions()
 * @method void setInstructions(?string $instructions)
 * @method string|null getTips()
 * @method void setTips(?string $tips)
 * @method int|null getEstimatedMinutesMin()
 * @method void setEstimatedMinutesMin(?int $estimatedMinutesMin)
 * @method int|null getEstimatedMinutesMax()
 * @method void setEstimatedMinutesMax(?int $estimatedMinutesMax)
 * @method bool getAllowTeamwork()
 * @method void setAllowTeamwork(bool $allowTeamwork)
 * @method int|null getMaxParticipants()
 * @method void setMaxParticipants(?int $maxParticipants)
 * @method float getTeamSparkMultiplier()
 * @method void setTeamSparkMultiplier(float $teamSparkMultiplier)
 * @method int getFlexDays()
 * @method void setFlexDays(int $flexDays)
 */
class Task extends Entity implements JsonSerializable
{
    /** @var string */
    protected $name;

    /** @var string|null */
    protected $description;

    /** @var int */
    protected $energyCost = 1;

    /** @var int */
    protected $priority = 2;

    /** @var string */
    protected $taskTier = 'silver';

    /** @var string */
    protected $frequencyType = 'weekly';

    /** @var int */
    protected $frequencyValue = 1;

    /** @var int */
    protected $estimatedMinutes = 15;

    /** @var string */
    protected $category = 'cleaning';

    /** @var bool */
    protected $isTemplate = false;

    /** @var string */
    protected $scopeType = 'room';

    /** @var string|null */
    protected $scopeLabel;

    /** @var string */
    protected $recurrenceMode = 'recurring';

    /** @var \DateTime|null */
    protected $activeFrom;

    /** @var \DateTime|null */
    protected $activeUntil;

    /** @var bool */
    protected $isVoluntary = false;

    /** @var string|null */
    protected $reminderType;

    /** @var int|null */
    protected $reminderMinutesBefore;

    /** @var \DateTime */
    protected $createdAt;

    /** @var string */
    protected $schedulingMode = 'dynamic';

    /** @var \DateTime|null */
    protected $fixedScheduleTime;

    /** @var string|null */
    protected $fixedScheduleDays;

    /** @var string|null */
    protected $instructions;

    /** @var string|null */
    protected $tips;

    /** @var int|null */
    protected $estimatedMinutesMin;

    /** @var int|null */
    protected $estimatedMinutesMax;

    /** @var bool */
    protected $allowTeamwork = false;

    /** @var int|null */
    protected $maxParticipants;

    /** @var float */
    protected $teamSparkMultiplier = 1.0;

    /** @var int */
    protected $flexDays = 0;

    public function __construct()
    {
        $this->addType('id', 'integer');
        $this->addType('name', 'string');
        $this->addType('description', 'string');
        $this->addType('energyCost', 'integer');
        $this->addType('priority', 'integer');
        $this->addType('taskTier', 'string');
        $this->addType('frequencyType', 'string');
        $this->addType('frequencyValue', 'integer');
        $this->addType('estimatedMinutes', 'integer');
        $this->addType('category', 'string');
        $this->addType('isTemplate', 'boolean');
        $this->addType('scopeType', 'string');
        $this->addType('scopeLabel', 'string');
        $this->addType('recurrenceMode', 'string');
        $this->addType('activeFrom', 'datetime');
        $this->addType('activeUntil', 'datetime');
        $this->addType('isVoluntary', 'boolean');
        $this->addType('reminderType', 'string');
        $this->addType('reminderMinutesBefore', 'integer');
        $this->addType('createdAt', 'datetime');
        $this->addType('schedulingMode', 'string');
        $this->addType('fixedScheduleTime', 'datetime');
        $this->addType('fixedScheduleDays', 'string');
        $this->addType('instructions', 'string');
        $this->addType('tips', 'string');
        $this->addType('estimatedMinutesMin', 'integer');
        $this->addType('estimatedMinutesMax', 'integer');
        $this->addType('allowTeamwork', 'boolean');
        $this->addType('maxParticipants', 'integer');
        $this->addType('teamSparkMultiplier', 'decimal');
        $this->addType('flexDays', 'integer');
    }

    /**
     * Convert task to JSON representation
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'instructions' => $this->instructions,
            'tips' => $this->tips,
            'energyCost' => $this->energyCost,
            'priority' => $this->priority,
            'taskTier' => $this->taskTier,
            'frequencyType' => $this->frequencyType,
            'frequencyValue' => $this->frequencyValue,
            'estimatedMinutes' => $this->estimatedMinutes,
            'estimatedMinutesMin' => $this->estimatedMinutesMin,
            'estimatedMinutesMax' => $this->estimatedMinutesMax,
            'category' => $this->category,
            'isTemplate' => $this->isTemplate,
            'scopeType' => $this->scopeType,
            'scopeLabel' => $this->scopeLabel,
            'recurrenceMode' => $this->recurrenceMode,
            'activeFrom' => $this->activeFrom?->format('c'),
            'activeUntil' => $this->activeUntil?->format('c'),
            'isVoluntary' => $this->isVoluntary,
            'schedulingMode' => $this->schedulingMode,
            'fixedScheduleTime' => $this->fixedScheduleTime?->format('H:i:s'),
            'fixedScheduleDays' => $this->fixedScheduleDays,
            'allowTeamwork' => $this->allowTeamwork,
            'maxParticipants' => $this->maxParticipants,
            'teamSparkMultiplier' => $this->teamSparkMultiplier,
            'flexDays' => $this->flexDays,
            'createdAt' => $this->createdAt?->format('c'),
        ];
    }

    /**
     * Get estimated time as range string (e.g., "10-15 min" or "15 min")
     */
    public function getEstimatedTimeRange(): string
    {
        if ($this->estimatedMinutesMin !== null && $this->estimatedMinutesMax !== null) {
            return "{$this->estimatedMinutesMin}-{$this->estimatedMinutesMax} min";
        }
        return "{$this->estimatedMinutes} min";
    }

    /**
     * Get task tier as label
     */
    public function getTaskTierLabel(): string
    {
        return match($this->taskTier) {
            'bronze' => 'Bronze',
            'silver' => 'Silver',
            'gold' => 'Gold',
            default => 'Silver'
        };
    }

    /**
     * Get task tier multiplier
     */
    public function getTaskTierMultiplier(): float
    {
        return match($this->taskTier) {
            'bronze' => 1.0,
            'silver' => 1.5,
            'gold' => 2.0,
            default => 1.5
        };
    }

    /**
     * Get priority as string
     */
    public function getPriorityLabel(): string
    {
        return match($this->priority) {
            1 => 'low',
            2 => 'medium',
            3 => 'high',
            default => 'medium'
        };
    }

    /**
     * Get frequency as human readable string
     */
    public function getFrequencyLabel(): string
    {
        return match($this->frequencyType) {
            'daily' => 'Daily',
            'weekly' => $this->frequencyValue === 1 ? 'Weekly' : "Every {$this->frequencyValue} weeks",
            'monthly' => $this->frequencyValue === 1 ? 'Monthly' : "Every {$this->frequencyValue} months",
            'custom' => 'Custom',
            default => 'Weekly'
        };
    }

    /**
     * Validate task data
     */
    public function validate(): array
    {
        $errors = [];

        if (empty(trim($this->name))) {
            $errors[] = 'Task name is required';
        }

        if ($this->energyCost < 1 || $this->energyCost > 5) {
            $errors[] = 'Energy cost must be between 1 and 5';
        }

        if ($this->priority < 1 || $this->priority > 3) {
            $errors[] = 'Priority must be between 1 and 3';
        }

        if ($this->estimatedMinutes < 1 || $this->estimatedMinutes > 480) {
            $errors[] = 'Estimated time must be between 1 and 480 minutes';
        }

        if (!in_array($this->frequencyType, ['daily', 'weekly', 'monthly', 'custom'])) {
            $errors[] = 'Invalid frequency type';
        }

        if ($this->frequencyValue < 1) {
            $errors[] = 'Frequency value must be at least 1';
        }

        // Validate scopeType
        if (!in_array($this->scopeType, ['room', 'floor', 'house', 'user'])) {
            $errors[] = 'Invalid scope type';
        }

        // Validate recurrenceMode
        if (!in_array($this->recurrenceMode, ['one_time', 'recurring'])) {
            $errors[] = 'Invalid recurrence mode';
        }

        // Validate date range
        if ($this->activeFrom !== null && $this->activeUntil !== null) {
            if ($this->activeUntil < $this->activeFrom) {
                $errors[] = 'Active until must be equal or after active from';
            }
        }

        // Validate schedulingMode
        if (!in_array($this->schedulingMode, ['dynamic', 'static'])) {
            $errors[] = 'Invalid scheduling mode';
        }

        // Validate static scheduling requirements
        if ($this->schedulingMode === 'static') {
            if ($this->fixedScheduleTime === null) {
                $errors[] = 'Fixed schedule time is required for static scheduling';
            }
            if ($this->frequencyType === 'weekly' && empty($this->fixedScheduleDays)) {
                $errors[] = 'At least one weekday is required for weekly static scheduling';
            }
        }

        return $errors;
    }
}
