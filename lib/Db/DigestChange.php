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
 * One queued organizational change for a member's digest (CS-PLAN-076
 * §3.3, C5m): identity/receipt rows ONLY — object type/id/revision,
 * category, source time, target slot, disposition. No rendered payloads
 * and no free text ever enter this table.
 *
 * The unique (house_user_id, object_type, object_id, target_slot) key
 * coalesces per object per slot: a repeated write for the same identity
 * updates the row instead of duplicating it; unchanged objects are never
 * repeated into a later slot. Rows carry only recipients authorized at
 * write time; rights and relevance are rechecked at dispatch.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getHouseId()
 * @method void setHouseId(int $houseId)
 * @method int getHouseUserId()
 * @method void setHouseUserId(int $houseUserId)
 * @method string getObjectType()
 * @method void setObjectType(string $objectType)
 * @method string getObjectId()
 * @method void setObjectId(string $objectId)
 * @method int getObjectRevision()
 * @method void setObjectRevision(int $objectRevision)
 * @method string getCategory()
 * @method void setCategory(string $category)
 * @method \DateTime getChangeTime()
 * @method void setChangeTime(\DateTime $changeTime)
 * @method string getTargetSlot()
 * @method void setTargetSlot(string $targetSlot)
 * @method string getDisposition()
 * @method void setDisposition(string $disposition)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class DigestChange extends Entity implements JsonSerializable {
	public const CATEGORY_ORG = 'org';
	/** Reserved — task relevance is dispatch-computed, never queued. */
	public const CATEGORY_TASK = 'task';

	public const DISPOSITION_QUEUED = 'queued';
	public const DISPOSITION_CONSUMED = 'consumed';
	public const DISPOSITION_EXPIRED = 'expired';

	/** @var int */
	protected $houseId;

	/** @var int Recipient membership ID (authorized at write time). */
	protected $houseUserId;

	/** @var string Bounded organizational object type (e.g. takeover/agreement/pause). */
	protected $objectType = '';

	/** @var string Immutable object identity (never a task name). */
	protected $objectId = '';

	/** @var int Source object revision at write time. */
	protected $objectRevision = 0;

	/** @var string org (task is reserved). */
	protected $category = self::CATEGORY_ORG;

	/** @var \DateTime Source action time (UTC). */
	protected $changeTime;

	/** @var string House-local `Y-m-d` slot the change belongs to. */
	protected $targetSlot = '';

	/** @var string queued | consumed | expired */
	protected $disposition = self::DISPOSITION_QUEUED;

	/** @var \DateTime */
	protected $createdAt;

	/** @var \DateTime */
	protected $updatedAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('houseId', 'integer');
		$this->addType('houseUserId', 'integer');
		$this->addType('objectType', 'string');
		$this->addType('objectId', 'string');
		$this->addType('objectRevision', 'integer');
		$this->addType('category', 'string');
		$this->addType('changeTime', 'datetime');
		$this->addType('targetSlot', 'string');
		$this->addType('disposition', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'houseId' => $this->houseId,
			'houseUserId' => $this->houseUserId,
			'objectType' => $this->objectType,
			'objectId' => $this->objectId,
			'objectRevision' => $this->objectRevision,
			'category' => $this->category,
			'changeTime' => $this->changeTime?->format('c'),
			'targetSlot' => $this->targetSlot,
			'disposition' => $this->disposition,
		];
	}
}
