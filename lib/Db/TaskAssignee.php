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
 * TaskAssignee - a user assigned to a room task (multi-assign join table).
 *
 * Stores the Nextcloud user ID (consistent with room_tasks.assigned_to_user_id)
 * so completion authorization can compare against the authenticated session user.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getRoomTaskId()
 * @method void setRoomTaskId(int $roomTaskId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method \DateTime|null getAssignedAt()
 * @method void setAssignedAt(?\DateTime $assignedAt)
 * @method string|null getAssignedBy()
 * @method void setAssignedBy(?string $assignedBy)
 */
class TaskAssignee extends Entity implements JsonSerializable {
	/** @var int */
	protected $roomTaskId;

	/** @var string */
	protected $userId;

	/** @var \DateTime|null */
	protected $assignedAt;

	/** @var string|null */
	protected $assignedBy;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('roomTaskId', 'integer');
		$this->addType('userId', 'string');
		$this->addType('assignedAt', 'datetime');
		$this->addType('assignedBy', 'string');
	}

	/**
	 * Convert task assignee to JSON representation
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'roomTaskId' => $this->roomTaskId,
			'userId' => $this->userId,
			'assignedAt' => $this->assignedAt?->format('c'),
			'assignedBy' => $this->assignedBy,
		];
	}
}
