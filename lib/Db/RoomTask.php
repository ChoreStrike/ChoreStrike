<?php

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
	 * @param \DateTime|null $activeUntil The active until date for recurring tasks
	 */
	public function markCompleted(\DateTime $completedAt, string $frequencyType, int $frequencyValue, string $recurrenceMode = 'recurring', ?\DateTime $activeUntil = null): void {
		$this->setLastCompleted($completedAt);

		// Handle one-time tasks - deactivate after completion
		if ($recurrenceMode === 'one_time') {
			$this->setIsActive(false);
			$this->setNextDue($completedAt);
			return;
		}

		// Calculate next due date based on frequency
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

		// Check if next due date exceeds active window
		if ($activeUntil !== null && $nextDue > $activeUntil) {
			$this->setIsActive(false);
			$this->setNextDue($activeUntil);
		} else {
			$this->setNextDue($nextDue);
		}
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
