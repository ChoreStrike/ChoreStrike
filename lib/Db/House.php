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
 * @method string getNextcloudInstanceId()
 * @method void setNextcloudInstanceId(string $nextcloudInstanceId)
 * @method string getName()
 * @method void setName(string $name)
 * @method int getHouseHealth()
 * @method void setHouseHealth(int $houseHealth)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 * @method string|null getWeeklyGoalNotifiedWeek()
 * @method void setWeeklyGoalNotifiedWeek(?string $weeklyGoalNotifiedWeek)
 */
class House extends Entity implements JsonSerializable {
	/** @var string */
	protected $nextcloudInstanceId;

	/** @var string */
	protected $name;

	/** @var int */
	protected $houseHealth = 100;

	/** @var \DateTime */
	protected $createdAt;

	/** @var \DateTime */
	protected $updatedAt;

	/**
	 * ISO week-start (Y-m-d, Monday) for which the weekly-goal-reached
	 * notification has already been dispatched to this household. NULL or a
	 * different week means a fresh transition can still claim dispatch.
	 * Backed by Version1023 migration.
	 *
	 * @var string|null
	 */
	protected $weeklyGoalNotifiedWeek;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('nextcloudInstanceId', 'string');
		$this->addType('name', 'string');
		$this->addType('houseHealth', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
		$this->addType('weeklyGoalNotifiedWeek', 'string');
	}

	/**
	 * Convert house to JSON representation
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'nextcloudInstanceId' => $this->nextcloudInstanceId,
			'name' => $this->name,
			'houseHealth' => $this->houseHealth,
			'createdAt' => $this->createdAt?->format('c'),
			'updatedAt' => $this->updatedAt?->format('c'),
		];
	}

	/**
	 * Update the updated_at timestamp
	 */
	public function touch(): void {
		$this->setUpdatedAt(new \DateTime());
	}
}
