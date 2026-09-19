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
	private const WEEKDAY_MAP = [
		'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4,
		'friday' => 5, 'saturday' => 6, 'sunday' => 7,
	];

	private const MAX_PROJECTION_OCCURRENCES = 1000;

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
	 * Mark task as completed and calculate next due date
	 *
	 * @param \DateTime $completedAt The completion time
	 * @param string $frequencyType The frequency type
	 * @param int $frequencyValue The frequency value
	 * @param string $recurrenceMode The recurrence mode (Task::RECURRENCE_ONCE or Task::RECURRENCE_RECURRING)
	 * @param string $schedulingMode The scheduling mode ('dynamic' or 'static')
	 * @param \DateTime|null $activeUntil The active until date for recurring tasks
	 * @param \DateTime|null $fixedScheduleTime The fixed schedule time (for static mode)
	 * @param string|null $fixedScheduleDays JSON array of weekdays (for static+weekly mode)
	 * @param int $flexDays Number of flex days for flexible scheduling (0 = no flex)
	 * @param array $weekdayAvailability User's weekday availability for flex scheduling
	 * @param array $energyBudget User's daily energy budget for flex scheduling
	 * @param \DateTimeZone|null $timeZone House timezone for the flex window
	 *                                     day buckets (CS-PLAN-057); null falls back to the server timezone.
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
		array $energyBudget = [],
		?\DateTimeZone $timeZone = null,
	): void {
		$this->setLastCompleted($completedAt);

		// Handle one-time tasks - deactivate after completion
		if ($recurrenceMode === Task::RECURRENCE_ONCE) {
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
		$idealDue = $this->calculateIdealDueDate($baseDue, $flexDays, $weekdayAvailability, $energyBudget, $timeZone);

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
		?string $fixedScheduleDays,
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
		// Normalize weekday names
		$targetDays = array_map(fn ($day) => strtolower(trim($day)), $weekdays);
		$targetDays = array_filter($targetDays, fn ($day) => isset(self::WEEKDAY_MAP[$day]));

		if (empty($targetDays)) {
			return $from;
		}

		$current = clone $from;
		$currentDayNum = (int)$current->format('N');

		// Find the next matching day within the same week
		$daysToCheck = [];
		foreach ($targetDays as $targetDay) {
			$targetDayNum = self::WEEKDAY_MAP[$targetDay];
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
			$firstTargetDay = min(array_map(fn ($day) => self::WEEKDAY_MAP[$day], $targetDays));
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
	 * Project the future due dates this task would have within [from, to].
	 *
	 * Recurring room tasks store only their next due date, but the calendar
	 * needs to show every occurrence in the visible window (e.g. a daily task
	 * on each of the 30 days). This enumerates those occurrences by replaying
	 * the same recurrence rules used at completion time
	 * (calculateNextDynamicDue / findNextWeekday), so the projected calendar
	 * always agrees with the dates the scheduler actually produces.
	 *
	 * The anchor is the stored nextDue; occurrences are projected forward only
	 * (never before nextDue) and clamped to [from, to] and to activeUntil.
	 *
	 * @param \DateTimeInterface $from Inclusive window start (day boundary)
	 * @param \DateTimeInterface $to Inclusive window end (day boundary)
	 * @param string $frequencyType daily|weekly|monthly|custom
	 * @param int $frequencyValue Recurrence interval in the frequency unit
	 * @param string $recurrenceMode Task::RECURRENCE_ONCE|RECURRENCE_RECURRING|RECURRENCE_BOUNDED
	 * @param string $schedulingMode dynamic|static
	 * @param string|null $fixedScheduleDays JSON array of weekday names (static+weekly)
	 * @param \DateTime|null $activeUntil Don't project past this date
	 * @param \DateTimeZone|null $timeZone House timezone for the day buckets
	 *                                     (CS-PLAN-057); null falls back to the server timezone. Returned
	 *                                     occurrences are normalized to UTC (storage convention).
	 * @return list<\DateTime> Occurrence dates within [from, to], ascending
	 */
	public function projectOccurrences(
		\DateTimeInterface $from,
		\DateTimeInterface $to,
		string $frequencyType,
		int $frequencyValue,
		string $recurrenceMode = 'recurring',
		string $schedulingMode = 'dynamic',
		?string $fixedScheduleDays = null,
		?\DateTime $activeUntil = null,
		?\DateTimeZone $timeZone = null,
	): array {
		if ($this->nextDue === null) {
			return [];
		}

		if ($timeZone !== null) {
			$anchor = \DateTime::createFromInterface($this->nextDue)->setTimezone($timeZone)->setTime(0, 0, 0);
			$fromDay = \DateTime::createFromInterface($from)->setTimezone($timeZone)->setTime(0, 0, 0);
			$toDay = \DateTime::createFromInterface($to)->setTimezone($timeZone)->setTime(23, 59, 59);
		} else {
			$anchor = \DateTime::createFromInterface($this->nextDue)->setTime(0, 0, 0);
			$fromDay = \DateTime::createFromInterface($from)->setTime(0, 0, 0);
			$toDay = \DateTime::createFromInterface($to)->setTime(23, 59, 59);
		}

		// One-time task: a single occurrence at nextDue if it lands in the window.
		if ($recurrenceMode === Task::RECURRENCE_ONCE) {
			if ($anchor >= $fromDay && $anchor <= $toDay) {
				return [$this->normalizeToStorage($anchor)];
			}
			return [];
		}

		$value = max(1, $frequencyValue);
		$cap = self::MAX_PROJECTION_OCCURRENCES;

		// Static weekly on named weekdays: enumerate matching weekdays week-by-week.
		$staticWeekdays = [];
		if ($schedulingMode === 'static' && $frequencyType === 'weekly' && $fixedScheduleDays) {
			$decoded = json_decode($fixedScheduleDays, true);
			if (is_array($decoded)) {
				$staticWeekdays = array_values(array_filter($decoded, fn ($d) => is_string($d)));
			}
		}
		if (!empty($staticWeekdays)) {
			return array_map(fn (\DateTime $occ) => $this->normalizeToStorage($occ),
				$this->projectWeeklyWeekdays($anchor, $fromDay, $toDay, $value, $staticWeekdays, $activeUntil, $cap));
		}

		// Arithmetic recurrence: daily / weekly-dynamic / monthly / custom fallback.
		$step = match ($frequencyType) {
			'daily' => new \DateInterval("P{$value}D"),
			'weekly' => new \DateInterval("P{$value}W"),
			'monthly' => new \DateInterval("P{$value}M"),
			default => new \DateInterval('P1W'),
		};

		$occurrences = [];
		$current = clone $anchor;

		// Fast-forward the anchor into the window without collecting (bounded).
		$advances = 0;
		while ($current < $fromDay && $advances < $cap) {
			$next = (clone $current)->add($step);
			if ($next <= $current) {
				break; // non-advancing recurrence guard
			}
			$current = $next;
			$advances++;
		}

		// Collect occurrences inside [from, to].
		while ($current <= $toDay && count($occurrences) < $cap) {
			if ($activeUntil !== null && $current > $activeUntil) {
				break;
			}
			if ($current >= $fromDay) {
				$occurrences[] = clone $current;
			}
			$next = (clone $current)->add($step);
			if ($next <= $current) {
				break; // non-advancing recurrence guard
			}
			$current = $next;
		}

		return array_map(fn (\DateTime $occ) => $this->normalizeToStorage($occ), $occurrences);
	}

	/**
	 * Normalize a projected occurrence back to the storage timezone
	 * (UTC wall-clock convention) so callers can bind/serialize it like
	 * any stored timestamp.
	 */
	private function normalizeToStorage(\DateTime $occurrence): \DateTime {
		return \DateTime::createFromInterface($occurrence)
			->setTimezone(new \DateTimeZone(date_default_timezone_get()));
	}

	/**
	 * Enumerate static-weekly occurrences (named weekdays recurring every N weeks).
	 *
	 * Anchors the cadence on the Monday of nextDue's ISO week and steps forward
	 * in whole-week intervals, emitting each configured weekday that falls in
	 * [from, to] and on/after the stored nextDue.
	 *
	 * @param \DateTime $anchor The stored nextDue (time-normalised to midnight)
	 * @param \DateTime $fromDay Inclusive window start (midnight)
	 * @param \DateTime $toDay Inclusive window end (23:59:59)
	 * @param int $weeksInterval Recurrence interval in weeks (e.g. 1 = every week, 2 = biweekly)
	 * @param array<string> $weekdayNames Lowercase weekday names to emit (e.g. ['monday', 'friday'])
	 * @param \DateTime|null $activeUntil Don't project past this date
	 * @param int $cap Hard iteration guard
	 * @return list<\DateTime>
	 */
	private function projectWeeklyWeekdays(
		\DateTime $anchor,
		\DateTime $fromDay,
		\DateTime $toDay,
		int $weeksInterval,
		array $weekdayNames,
		?\DateTime $activeUntil,
		int $cap,
	): array {
		$targetSet = [];
		foreach ($weekdayNames as $name) {
			$key = strtolower(trim((string)$name));
			if (isset(self::WEEKDAY_MAP[$key])) {
				$targetSet[self::WEEKDAY_MAP[$key]] = true;
			}
		}
		if (empty($targetSet)) {
			return [];
		}
		$targetDays = array_keys($targetSet);
		sort($targetDays);

		// Monday of the anchor's ISO week is the recurrence cadence origin.
		$weekOrigin = clone $anchor;
		$dow = (int)$weekOrigin->format('N'); // 1=Mon..7=Sun
		$weekOrigin->sub(new \DateInterval('P' . ($dow - 1) . 'D'));
		$weekOrigin->setTime(0, 0, 0);

		$occurrences = [];
		$week = 0;
		while (count($occurrences) < $cap && $week <= $cap) {
			$weekStart = (clone $weekOrigin)->modify('+' . ($week * $weeksInterval) . ' weeks');
			if ($weekStart > $toDay) {
				break;
			}
			foreach ($targetDays as $dayNum) {
				$occ = (clone $weekStart)->add(new \DateInterval('P' . ($dayNum - 1) . 'D'));
				if ($occ < $anchor) {
					continue; // never before the stored nextDue
				}
				if ($occ < $fromDay || $occ > $toDay) {
					continue;
				}
				if ($activeUntil !== null && $occ > $activeUntil) {
					continue;
				}
				$occurrences[] = $occ;
			}
			$week++;
		}

		usort($occurrences, fn (\DateTime $a, \DateTime $b) => $a->getTimestamp() <=> $b->getTimestamp());
		return $occurrences;
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
