<?php

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

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('nextcloudInstanceId', 'string');
		$this->addType('name', 'string');
		$this->addType('houseHealth', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	/**
	 * Convert house to JSON representation
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
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
		$this->updatedAt = new \DateTime();
	}
}
