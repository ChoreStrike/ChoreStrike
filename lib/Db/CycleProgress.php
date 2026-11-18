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
 * One sub-task tick of one cycle (ADR-021 decision 3, D-091-17). Progress
 * belongs to its cycle and never leaks into the next one; partly ticked
 * work stays saved while the task is open (D-089-02). Ticks from before
 * the derived model stay in `chorestrike_chk_progress` as history.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getRoomTaskId()
 * @method void setRoomTaskId(int $roomTaskId)
 * @method string getCycleKey()
 * @method void setCycleKey(string $cycleKey)
 * @method int getChecklistItemId()
 * @method void setChecklistItemId(int $checklistItemId)
 * @method int getRevision()
 * @method void setRevision(int $revision)
 * @method bool getIsChecked()
 * @method void setIsChecked(bool $isChecked)
 * @method \DateTime|null getCheckedAt()
 * @method void setCheckedAt(?\DateTime $checkedAt)
 * @method int|null getCheckedByUserId()
 * @method void setCheckedByUserId(?int $checkedByUserId)
 */
class CycleProgress extends Entity implements JsonSerializable {
	/** @var int */
	protected $roomTaskId;

	/** @var string */
	protected $cycleKey;

	/** @var int */
	protected $checklistItemId;

	/** @var int Progress revision for stale-mutation detection. */
	protected $revision = 0;

	/** @var bool */
	protected $isChecked = false;

	/** @var \DateTime|null */
	protected $checkedAt;

	/** @var int|null */
	protected $checkedByUserId;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('roomTaskId', 'integer');
		$this->addType('cycleKey', 'string');
		$this->addType('checklistItemId', 'integer');
		$this->addType('revision', 'integer');
		$this->addType('isChecked', 'boolean');
		$this->addType('checkedAt', 'datetime');
		$this->addType('checkedByUserId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'roomTaskId' => $this->roomTaskId,
			'cycleKey' => $this->cycleKey,
			'revision' => $this->revision,
			'checklistItemId' => $this->checklistItemId,
			'isChecked' => $this->isChecked,
			'checkedAt' => $this->checkedAt?->format('c'),
			'checkedByUserId' => $this->checkedByUserId,
		];
	}
}
