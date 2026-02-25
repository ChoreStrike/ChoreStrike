<?php

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
 * @method int getCurrentStreak()
 * @method void setCurrentStreak(int $currentStreak)
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

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('houseId', 'integer');
		$this->addType('nextcloudUserId', 'string');
		$this->addType('displayName', 'string');
		$this->addType('contributionScore', 'integer');
		$this->addType('teamSparks', 'integer');
		$this->addType('currentStreak', 'integer');
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
			'currentStreak' => $this->currentStreak,
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
		$this->contributionScore += $points;
	}

	/**
	 * Add team sparks
	 *
	 * @param int $sparks Sparks to add
	 */
	public function addTeamSparks(int $sparks): void {
		$this->teamSparks += $sparks;
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

		if ($daysSinceLastActivity === 0) {
			return $this->currentStreak + 1;
		}

		// Check each day between last activity and today
		$checkDate = clone $lastActivityDate;
		$checkDate->modify('+1 day');

		while ($checkDate <= $today) {
			$dayName = strtolower($checkDate->format('l'));
			$isAvailable = $weekdayAvailability[$dayName] ?? true;

			// If user was available but didn't complete a task, reset streak
			if ($isAvailable && $checkDate->format('Y-m-d') !== $today->format('Y-m-d')) {
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
		$this->currentStreak = $this->calculateStreakWithAbsence($weekdayAvailability);
	}

	/**
	 * Reset streak
	 */
	public function resetStreak(): void {
		$this->currentStreak = 0;
	}

	/**
	 * Update last activity timestamp
	 */
	public function touch(): void {
		$this->lastActivity = new \DateTime();
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
	 * Get role label
	 */
	public function getRoleLabel(): string {
		return match($this->role) {
			'owner' => 'Owner',
			'member' => 'Member',
			'child' => 'Child',
			default => 'Member'
		};
	}
}
