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
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getTotalPoints()
 * @method void setTotalPoints(int $points)
 * @method int getCurrentStreak()
 * @method void setCurrentStreak(int $streak)
 * @method int getLongestStreak()
 * @method void setLongestStreak(int $streak)
 * @method int getTasksCompleted()
 * @method void setTasksCompleted(int $count)
 * @method \DateTime|null getLastCompletionDate()
 * @method void setLastCompletionDate(?\DateTime $date)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $date)
 */
class UserStats extends Entity implements JsonSerializable {

	/** @var int */
	protected $houseId;

	/** @var string */
	protected $userId;

	/** @var int */
	protected $totalPoints = 0;

	/** @var int */
	protected $currentStreak = 0;

	/** @var int */
	protected $longestStreak = 0;

	/** @var int */
	protected $tasksCompleted = 0;

	/** @var \DateTime|null */
	protected $lastCompletionDate;

	/** @var \DateTime */
	protected $updatedAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('houseId', 'integer');
		$this->addType('userId', 'string');
		$this->addType('totalPoints', 'integer');
		$this->addType('currentStreak', 'integer');
		$this->addType('longestStreak', 'integer');
		$this->addType('tasksCompleted', 'integer');
		$this->addType('lastCompletionDate', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	/**
	 * Convert user stats to JSON representation
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'houseId' => $this->houseId,
			'userId' => $this->userId,
			'totalPoints' => $this->totalPoints,
			'currentStreak' => $this->currentStreak,
			'longestStreak' => $this->longestStreak,
			'tasksCompleted' => $this->tasksCompleted,
			'lastCompletionDate' => $this->lastCompletionDate?->format('c'),
			'updatedAt' => $this->updatedAt?->format('c'),
		];
	}

	/**
	 * Add points to total
	 */
	public function addPoints(int $points): void {
		$this->setTotalPoints($this->totalPoints + $points);
		$this->setUpdatedAt(new \DateTime());
	}

	/**
	 * Increment tasks completed
	 */
	public function incrementTasksCompleted(): void {
		$this->setTasksCompleted($this->tasksCompleted + 1);
		$this->setLastCompletionDate(new \DateTime());
		$this->setUpdatedAt(new \DateTime());
	}

	/**
	 * Update streak
	 */
	public function updateStreak(int $newStreak): void {
		$this->setCurrentStreak($newStreak);
		if ($newStreak > $this->longestStreak) {
			$this->setLongestStreak($newStreak);
		}
		$this->setUpdatedAt(new \DateTime());
	}

	/**
	 * Reset current streak
	 */
	public function resetStreak(): void {
		$this->setCurrentStreak(0);
		$this->setUpdatedAt(new \DateTime());
	}

	/**
	 * Increment current streak
	 */
	public function incrementStreak(): void {
		$newStreak = $this->currentStreak + 1;
		$this->updateStreak($newStreak);
	}
}
