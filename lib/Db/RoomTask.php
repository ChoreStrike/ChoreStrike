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
 */
class RoomTask extends Entity implements JsonSerializable {

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

	public function __construct() {
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
	}

	/**
	 * Convert room task to JSON representation
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'roomId' => $this->roomId,
			'taskId' => $this->taskId,
			'nextDue' => $this->nextDue?->format('c'),
			'lastCompleted' => $this->lastCompleted?->format('c'),
			'assignedToUserId' => $this->assignedToUserId,
			'assignedToGroup' => $this->assignedToGroup,
			'assignmentType' => $this->assignmentType,
			'claimedByUser' => $this->claimedByUser,
			'isActive' => $this->isActive,
			'scopeId' => $this->scopeId,
			'nextcloudTaskId' => $this->nextcloudTaskId,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}

	/**
	 * Check if task is overdue
	 */
	public function isOverdue(): bool {
		if ($this->nextDue === null) {
			return false;
		}
		return $this->nextDue < new \DateTime() && $this->isActive;
	}

	/**
	 * Check if task is due today
	 */
	public function isDueToday(): bool {
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
	public function isDueWithinDays(int $days): bool {
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
	public function getDaysUntilDue(): int {
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
	 */
	public function markCompleted(
		\DateTime $completedAt,
		string $frequencyType,
		int $frequencyValue,
		string $recurrenceMode = 'recurring',
		string $schedulingMode = 'dynamic',
		?\DateTime $activeUntil = null,
		?\DateTime $fixedScheduleTime = null,
		?string $fixedScheduleDays = null
	): void {
		$this->setLastCompleted($completedAt);

		// Handle one-time tasks - deactivate after completion
		if ($recurrenceMode === 'one_time') {
			$this->setIsActive(false);
			$this->setNextDue($completedAt);
			return;
		}

		// Calculate next due date based on scheduling mode
		if ($schedulingMode === 'static') {
			$nextDue = $this->calculateNextStaticDue(
				$completedAt,
				$frequencyType,
				$frequencyValue,
				$fixedScheduleTime,
				$fixedScheduleDays
			);
		} else {
			// Dynamic scheduling - shift from completion time
			$nextDue = $this->calculateNextDynamicDue($completedAt, $frequencyType, $frequencyValue);
		}

		// Check if next due date exceeds active window
		if ($activeUntil !== null && $nextDue > $activeUntil) {
			$this->setIsActive(false);
			$this->setNextDue($activeUntil);
		} else {
			$this->setNextDue($nextDue);
		}
	}

	/**
	 * Calculate next due date for dynamic scheduling (shifts with completion)
	 */
	private function calculateNextDynamicDue(\DateTime $completedAt, string $frequencyType, int $frequencyValue): \DateTime {
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
	private function findNextWeekday(\DateTime $from, array $weekdays, int $weeksInterval = 1): \DateTime {
		$dayMap = [
			'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
			'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7
		];

		// Normalize weekday names
		$targetDays = array_map(fn($day) => strtolower(trim($day)), $weekdays);
		$targetDays = array_filter($targetDays, fn($day) => isset($dayMap[$day]));

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
			$firstTargetDay = min(array_map(fn($day) => $dayMap[$day], $targetDays));
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
	public function getStatus(): string {
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
}
