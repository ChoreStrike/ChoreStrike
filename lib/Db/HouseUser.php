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
 * @method int getTeamSparks()
 * @method void setTeamSparks(int $teamSparks)
 * @method int getCurrentSparks()
 * @method void setCurrentSparks(int $currentSparks)
 * @method int getTotalSparksEarned()
 * @method void setTotalSparksEarned(int $totalSparksEarned)
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
 */
class HouseUser extends Entity implements JsonSerializable {

	/** @var int */
	protected $houseId;

	/** @var string */
	protected $nextcloudUserId;

	/** @var string */
	protected $displayName;

	/** @var int */
	protected $contributionScore = 0;

	/** @var int */
	protected $teamSparks = 0;

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
	protected $currentSparks = 0;

	/** @var int */
	protected $totalSparksEarned = 0;

	/** @var int */
	protected $longestStreak = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('houseId', 'integer');
		$this->addType('nextcloudUserId', 'string');
		$this->addType('displayName', 'string');
		$this->addType('contributionScore', 'integer');
		$this->addType('teamSparks', 'integer');
		$this->addType('currentSparks', 'integer');
		$this->addType('totalSparksEarned', 'integer');
		$this->addType('currentStreak', 'integer');
		$this->addType('longestStreak', 'integer');
		$this->addType('lastActivity', 'datetime');
		$this->addType('role', 'string');
		$this->addType('joinedAt', 'datetime');
		$this->addType('schedulingPreferences', 'json');
	}

	/**
	 * Convert house user to JSON representation
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'houseId' => $this->houseId,
			'nextcloudUserId' => $this->nextcloudUserId,
			'displayName' => $this->displayName,
			'contributionScore' => $this->contributionScore,
			'teamSparks' => $this->teamSparks,
			'currentSparks' => $this->currentSparks,
			'totalSparksEarned' => $this->totalSparksEarned,
			'currentStreak' => $this->currentStreak,
			'longestStreak' => $this->longestStreak,
			'lastActivity' => $this->lastActivity?->format('c'),
			'role' => $this->role,
			'joinedAt' => $this->joinedAt?->format('c'),
			'schedulingPreferences' => $this->schedulingPreferences,
		];
	}

	/**
	 * Add contribution score
	 *
	 * @param int $points Points to add
	 */
	public function addContributionScore(int $points): void {
		$this->setContributionScore($this->contributionScore + $points);
	}

	/**
	 * Add team sparks
	 *
	 * @param int $sparks Sparks to add
	 */
	public function addTeamSparks(int $sparks): void {
		$this->setTeamSparks($this->teamSparks + $sparks);
	}

	/**
	 * Calculate streak with respect to absence days
	 *
	 * @param array $weekdayAvailability Weekday availability from scheduling preferences
	 * @return int New streak value
	 */
	public function calculateStreakWithAbsence(array $weekdayAvailability): int {
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
	public function updateStreakWithAbsence(array $weekdayAvailability): void {
		$this->setCurrentStreak($this->calculateStreakWithAbsence($weekdayAvailability));
	}

	/**
	 * Reset streak
	 */
	public function resetStreak(): void {
		$this->setCurrentStreak(0);
	}

	/**
	 * Update last activity timestamp
	 */
	public function touch(): void {
		$this->setLastActivity(new \DateTime());
	}

	/**
	 * Check if user is owner
	 */
	public function isOwner(): bool {
		return $this->role === 'owner';
	}

	/**
	 * Check if user is child
	 */
	public function isChild(): bool {
		return $this->role === 'child';
	}

	/**
	 * Get role label (English only - i18n should be handled in frontend)
	 */
	public function getRoleLabel(): string {
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
	public function getRank(): string {
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
	 * Add sparks to current and total
	 */
	public function addSparks(int $sparks): void {
		$this->setCurrentSparks($this->currentSparks + $sparks);
		$this->setTotalSparksEarned($this->totalSparksEarned + $sparks);
	}

	/**
	 * Spend sparks
	 */
	public function spendSparks(int $sparks): void {
		$this->setCurrentSparks(max(0, $this->currentSparks - $sparks));
	}

	/**
	 * Increment streak
	 */
	public function incrementStreak(): void {
		$newStreak = $this->currentStreak + 1;
		$this->setCurrentStreak($newStreak);
		if ($newStreak > $this->longestStreak) {
			$this->setLongestStreak($newStreak);
		}
	}
}
