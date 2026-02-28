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
 * @method int getRoomTaskId()
 * @method void setRoomTaskId(int $roomTaskId)
 * @method int getChecklistItemId()
 * @method void setChecklistItemId(int $checklistItemId)
 * @method bool getIsChecked()
 * @method void setIsChecked(bool $isChecked)
 * @method \DateTime|null getCheckedAt()
 * @method void setCheckedAt(?\DateTime $checkedAt)
 * @method int|null getCheckedByUserId()
 * @method void setCheckedByUserId(?int $checkedByUserId)
 */
class RoomTaskChecklistProgress extends Entity implements JsonSerializable {

	/** @var int */
	protected $roomTaskId;

	/** @var int */
	protected $checklistItemId;

	/** @var bool */
	protected $isChecked = false;

	/** @var \DateTime|null */
	protected $checkedAt;

	/** @var int|null */
	protected $checkedByUserId;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('roomTaskId', 'integer');
		$this->addType('checklistItemId', 'integer');
		$this->addType('isChecked', 'boolean');
		$this->addType('checkedAt', 'datetime');
		$this->addType('checkedByUserId', 'integer');
	}

	/**
	 * Convert checklist progress to JSON representation
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'roomTaskId' => $this->roomTaskId,
			'checklistItemId' => $this->checklistItemId,
			'isChecked' => $this->isChecked,
			'checkedAt' => $this->checkedAt?->format('c'),
			'checkedByUserId' => $this->checkedByUserId,
		];
	}

	/**
	 * Check if this progress item was checked recently
	 */
	public function wasCheckedRecently(int $minutes = 5): bool {
		if (!$this->isChecked || $this->checkedAt === null) {
			return false;
		}
		
		$cutoff = new \DateTime("-{$minutes} minutes");
		return $this->checkedAt >= $cutoff;
	}
}
