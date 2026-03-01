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
 * @method int getTaskCount()
 * @method void setTaskCount(int $count)
 * @method \DateTime getStartedAt()
 * @method void setStartedAt(\DateTime $date)
 * @method \DateTime getLastTaskAt()
 * @method void setLastTaskAt(\DateTime $date)
 * @method int getComboBonusPoints()
 * @method void setComboBonusPoints(int $points)
 * @method bool getIsActive()
 * @method void setIsActive(bool $active)
 */
class Combo extends Entity implements JsonSerializable {

	/** @var int */
	protected $houseId;

	/** @var string */
	protected $userId;

	/** @var int */
	protected $taskCount = 0;

	/** @var \DateTime */
	protected $startedAt;

	/** @var \DateTime */
	protected $lastTaskAt;

	/** @var int */
	protected $comboBonusPoints = 0;

	/** @var bool */
	protected $isActive = true;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('houseId', 'integer');
		$this->addType('userId', 'string');
		$this->addType('taskCount', 'integer');
		$this->addType('startedAt', 'datetime');
		$this->addType('lastTaskAt', 'datetime');
		$this->addType('comboBonusPoints', 'integer');
		$this->addType('isActive', 'boolean');
	}

	/**
	 * Convert combo to JSON representation
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'houseId' => $this->houseId,
			'userId' => $this->userId,
			'taskCount' => $this->taskCount,
			'startedAt' => $this->startedAt?->format('c'),
			'lastTaskAt' => $this->lastTaskAt?->format('c'),
			'comboBonusPoints' => $this->comboBonusPoints,
			'isActive' => $this->isActive,
		];
	}

	/**
	 * Add task to combo
	 */
	public function addTask(int $points = 10): void {
		$this->taskCount++;
		$this->comboBonusPoints += $points;
		$this->lastTaskAt = new \DateTime();
	}

	/**
	 * Check if combo has timed out (24 hours)
	 */
	public function hasTimedOut(): bool {
		if (!$this->lastTaskAt) {
			return false;
		}

		$now = new \DateTime();
		$diff = $now->diff($this->lastTaskAt);
		
		return $diff->days >= 1 || ($diff->days === 0 && $diff->h >= 24);
	}

	/**
	 * End combo
	 */
	public function end(): void {
		$this->isActive = false;
	}

	/**
	 * Get combo multiplier based on task count
	 */
	public function getMultiplier(): float {
		return 1 + ($this->taskCount * 0.1);
	}
}
