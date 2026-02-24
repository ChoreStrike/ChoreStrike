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
 * @method string getType()
 * @method void setType(string $type)
 * @method int|null getRoomId()
 * @method void setRoomId(?int $roomId)
 * @method string getName()
 * @method void setName(string $name)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class TaskScope extends Entity implements JsonSerializable {

	/** @var int */
	protected $houseId;

	/** @var string */
	protected $type = 'room';

	/** @var int|null */
	protected $roomId;

	/** @var string */
	protected $name;

	/** @var \DateTime */
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('houseId', 'integer');
		$this->addType('type', 'string');
		$this->addType('roomId', 'integer');
		$this->addType('name', 'string');
		$this->addType('createdAt', 'datetime');
	}

	/**
	 * Convert scope to JSON representation.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'houseId' => $this->houseId,
			'type' => $this->type,
			'roomId' => $this->roomId,
			'name' => $this->name,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}
}
