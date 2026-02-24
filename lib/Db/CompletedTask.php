<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getRoomTaskId()
 * @method void setRoomTaskId(int $roomTaskId)
 * @method int getUserId()
 * @method void setUserId(int $userId)
 * @method \DateTime getCompletedAt()
 * @method void setCompletedAt(\DateTime $completedAt)
 * @method int getSparksEarned()
 * @method void setSparksEarned(int $sparksEarned)
 * @method bool getWasCombo()
 * @method void setWasCombo(bool $wasCombo)
 * @method int|null getComboWithUserId()
 * @method void setComboWithUserId(?int $comboWithUserId)
 * @method int|null getNextcloudTaskId()
 * @method void setNextcloudTaskId(?int $nextcloudTaskId)
 * @method bool getSyncedToNextcloud()
 * @method void setSyncedToNextcloud(bool $syncedToNextcloud)
 */
class CompletedTask extends Entity implements JsonSerializable {

	/** @var int */
	protected $roomTaskId;

	/** @var int */
	protected $userId;

	/** @var \DateTime */
	protected $completedAt;

	/** @var int */
	protected $sparksEarned = 0;

	/** @var bool */
	protected $wasCombo = false;

	/** @var int|null */
	protected $comboWithUserId;

	/** @var int|null */
	protected $nextcloudTaskId;

	/** @var bool */
	protected $syncedToNextcloud = false;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('roomTaskId', 'integer');
		$this->addType('userId', 'integer');
		$this->addType('completedAt', 'datetime');
		$this->addType('sparksEarned', 'integer');
		$this->addType('wasCombo', 'boolean');
		$this->addType('comboWithUserId', 'integer');
		$this->addType('nextcloudTaskId', 'integer');
		$this->addType('syncedToNextcloud', 'boolean');
	}

	/**
	 * Convert completed task to JSON representation
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'roomTaskId' => $this->roomTaskId,
			'userId' => $this->userId,
			'completedAt' => $this->completedAt?->format('c'),
			'sparksEarned' => $this->sparksEarned,
			'wasCombo' => $this->wasCombo,
			'comboWithUserId' => $this->comboWithUserId,
			'nextcloudTaskId' => $this->nextcloudTaskId,
			'syncedToNextcloud' => $this->syncedToNextcloud,
		];
	}

	/**
	 * Check if completion was recent (within last N minutes)
	 *
	 * @param int $minutes Number of minutes
	 */
	public function isRecent(int $minutes = 5): bool {
		$cutoff = new \DateTime("-{$minutes} minutes");
		return $this->completedAt >= $cutoff;
	}
}
