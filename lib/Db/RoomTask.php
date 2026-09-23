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
 * @method bool getIsActive()
 * @method void setIsActive(bool $isActive)
 * @method int|null getScopeId()
 * @method void setScopeId(?int $scopeId)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method string|null getClaimedByUser()
 * @method void setClaimedByUser(?string $claimedByUser)
 * @method bool getWasClaimed()
 * @method void setWasClaimed(bool $wasClaimed)
 * @method string|null getOriginalAssignee()
 * @method void setOriginalAssignee(?string $originalAssignee)
 * @method \DateTime|null getClaimedAt()
 * @method void setClaimedAt(?\DateTime $claimedAt)
 * @method \DateTime|null getIdealDueDate()
 * @method void setIdealDueDate(?\DateTime $idealDueDate)
 * @method \DateTime|null getDeletedAt()
 * @method void setDeletedAt(?\DateTime $deletedAt)
 * @method int getDeletedToken()
 * @method void setDeletedToken(int $deletedToken)
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

	/** @var bool */
	protected $isActive = true;

	/** @var int|null */
	protected $scopeId;

	/** @var \DateTime */
	protected $createdAt;

	/** @var string|null */
	protected $claimedByUser;

	/** @var bool */
	protected $wasClaimed = false;

	/** @var string|null */
	protected $originalAssignee;

	/** @var \DateTime|null */
	protected $claimedAt;

	/** @var \DateTime|null */
	protected $idealDueDate;

	/** @var \DateTime|null Tombstone (Q-070-01): NULL = live row. */
	protected $deletedAt;

	/** @var int Tombstone token: 0 = live, = own id once tombstoned (D4). */
	protected $deletedToken = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('roomId', 'integer');
		$this->addType('taskId', 'integer');
		$this->addType('nextDue', 'datetime');
		$this->addType('lastCompleted', 'datetime');
		$this->addType('isActive', 'boolean');
		$this->addType('scopeId', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('claimedByUser', 'string');
		$this->addType('wasClaimed', 'boolean');
		$this->addType('originalAssignee', 'string');
		$this->addType('claimedAt', 'datetime');
		$this->addType('idealDueDate', 'datetime');
		$this->addType('deletedAt', 'datetime');
		$this->addType('deletedToken', 'integer');
	}

	/**
	 * Tombstone this room task in place (CS-PLAN-071 Q-070-01, decision
	 * D4): sets the deleted timestamp, folds the row's own id into
	 * `deleted_token` (keeps `uniq_rt (room_id, task_id, deleted_token)`
	 * satisfiable for a future re-created link) and deactivates the row so
	 * every existing active-read filter excludes it.
	 */
	public function tombstone(\DateTime $deletedAt): void {
		$this->setDeletedAt($deletedAt);
		$this->setDeletedToken($this->getId());
		$this->setIsActive(false);
	}

	/**
	 * Convert room task to JSON representation
	 *
	 * @param \DateTimeZone|null $timeZone House timezone for the derived
	 *                                     `status`/`daysUntilDue` fields (CS-PLAN-057); null falls back to the
	 *                                     server timezone for json_encode-style callers.
	 */
	public function jsonSerialize(?\DateTimeZone $timeZone = null): array {
		return [
			'id' => $this->id,
			'roomId' => $this->roomId,
			'taskId' => $this->taskId,
			'nextDue' => $this->nextDue?->format('c'),
			'lastCompleted' => $this->lastCompleted?->format('c'),
			'isActive' => $this->isActive,
			'scopeId' => $this->scopeId,
			'claimedByUser' => $this->claimedByUser,
			'wasClaimed' => $this->wasClaimed,
			'originalAssignee' => $this->originalAssignee,
			'claimedAt' => $this->claimedAt?->format('c'),
			'idealDueDate' => $this->idealDueDate?->format('c'),
			'createdAt' => $this->createdAt?->format('c'),
			'status' => $this->getStatus($timeZone),
			'daysUntilDue' => $this->getDaysUntilDue($timeZone),
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
	 *
	 * @param \DateTimeZone|null $timeZone House timezone for the day bucket
	 *                                     (CS-PLAN-057); null falls back to the server timezone.
	 */
	public function isDueToday(?\DateTimeZone $timeZone = null): bool {
		if ($this->nextDue === null) {
			return false;
		}

		$today = new \DateTime('now', $timeZone);
		$today->setTime(0, 0, 0);
		$due = clone $this->nextDue;
		if ($timeZone !== null) {
			$due->setTimezone($timeZone);
		}
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
	 * Get days until due (negative if overdue, null when no due date is set)
	 *
	 * @param \DateTimeZone|null $timeZone House timezone for the day bucket
	 *                                     (CS-PLAN-057); null falls back to the server timezone.
	 */
	public function getDaysUntilDue(?\DateTimeZone $timeZone = null): ?int {
		if ($this->nextDue === null) {
			return null;
		}

		$today = new \DateTime('now', $timeZone);
		$today->setTime(0, 0, 0);
		$due = clone $this->nextDue;
		if ($timeZone !== null) {
			$due->setTimezone($timeZone);
		}
		$due->setTime(0, 0, 0);

		$interval = $today->diff($due);
		return $interval->invert ? -(int)$interval->days : (int)$interval->days;
	}

	/**
	 * Mark this task as claimed/taken over by another user.
	 *
	 * Centralizes the claim state transition for the overdue-takeover
	 * (CompletionService::complete records a takeover when a non-assignee
	 * completes another member's overdue task).
	 *
	 * @param string $originalAssignee The user the task was assigned to before the claim
	 * @param string $claimedByUserId The user taking over the task
	 * @param \DateTime|null $at When the claim happened (defaults to now)
	 */
	public function markClaimed(string $originalAssignee, string $claimedByUserId, ?\DateTime $at = null): void {
		$this->setWasClaimed(true);
		$this->setOriginalAssignee($originalAssignee);
		$this->setClaimedByUser($claimedByUserId);
		$this->setClaimedAt($at ?? new \DateTime());
	}

	/**
	 * Get status as string
	 *
	 * @param \DateTimeZone|null $timeZone House timezone for the day bucket
	 *                                     (CS-PLAN-057); null falls back to the server timezone.
	 */
	public function getStatus(?\DateTimeZone $timeZone = null): string {
		if (!$this->isActive) {
			return 'inactive';
		}

		if ($this->isOverdue()) {
			return 'overdue';
		}

		if ($this->isDueToday($timeZone)) {
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
	 * @param \DateTimeZone|null $timeZone House timezone for the window's day
	 *                                     buckets (CS-PLAN-057); null falls back to the server timezone. The
	 *                                     returned date keeps the storage timezone of $baseDate.
	 * @return \DateTime The ideal due date
	 */
	public function calculateIdealDueDate(
		\DateTime $baseDate,
		int $flexDays,
		array $weekdayAvailability = [],
		array $energyBudget = [],
		?\DateTimeZone $timeZone = null,
	): \DateTime {
		// No flex window - just use base date
		if ($flexDays <= 0) {
			return clone $baseDate;
		}

		$windowTz = $timeZone ?? new \DateTimeZone(date_default_timezone_get());

		// Calculate flex window bounds in the bucketing timezone
		$windowStart = \DateTime::createFromInterface($baseDate)->setTimezone($windowTz);
		$windowStart->sub(new \DateInterval("P{$flexDays}D"));
		$windowStart->setTime(0, 0, 0);

		$windowEnd = \DateTime::createFromInterface($baseDate)->setTimezone($windowTz);
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

		// Keep the storage timezone of $baseDate (UTC wall-clock convention)
		return \DateTime::createFromInterface($bestDay)->setTimezone($baseDate->getTimezone());
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
		array $weekdayAvailability,
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
	private function pickBestDayInWindow(array $availableDays, array $energyBudget, \DateTime $baseDate): \DateTime {
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
	public function getFlexWindowBounds(int $flexDays): array {
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
