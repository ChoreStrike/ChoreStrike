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
	 * Increment streak
	 */
	public function incrementStreak(): void {
		$this->currentStreak++;
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
