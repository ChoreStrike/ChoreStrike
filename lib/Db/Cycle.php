<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A closed cycle of a room task (ADR-021 decisions 3 to 5, D-091-17):
 * done by a valid completion, or a fixed cycle that was missed. An open
 * cycle is derived on read and has no row. The row of a done cycle only
 * projects the cycle facts frozen on its completion, so an undo deletes
 * it; a missed row is written once by the timeline job.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getHouseId()
 * @method void setHouseId(int $houseId)
 * @method int getRoomTaskId()
 * @method void setRoomTaskId(int $roomTaskId)
 * @method string getCycleKey()
 * @method void setCycleKey(string $cycleKey)
 * @method \DateTime getWindowStartOn()
 * @method void setWindowStartOn(\DateTime $windowStartOn)
 * @method \DateTime getWindowEndOn()
 * @method void setWindowEndOn(\DateTime $windowEndOn)
 * @method \DateTime getWeekStart()
 * @method void setWeekStart(\DateTime $weekStart)
 * @method string getTimezone()
 * @method void setTimezone(string $timezone)
 * @method string getOutcome()
 * @method void setOutcome(string $outcome)
 * @method int|null getCompletionId()
 * @method void setCompletionId(?int $completionId)
 * @method string|null getCompletionClass()
 * @method void setCompletionClass(?string $completionClass)
 * @method int getEnergy()
 * @method void setEnergy(int $energy)
 * @method array|null getResponsible()
 * @method void setResponsible(?array $responsible)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Cycle extends Entity {
	public const OUTCOME_DONE = 'done';
	public const OUTCOME_MISSED = 'missed';

	/** @var int */
	protected $houseId;

	/** @var int */
	protected $roomTaskId;

	/** @var string */
	protected $cycleKey;

	/** @var \DateTime First day of the final window (house-local). */
	protected $windowStartOn;

	/** @var \DateTime Last day of the final window (house-local). */
	protected $windowEndOn;

	/** @var \DateTime House-local Monday of the week the window ends in. */
	protected $weekStart;

	/** @var string */
	protected $timezone;

	/** @var string self::OUTCOME_* */
	protected $outcome;

	/** @var int|null */
	protected $completionId;

	/** @var string|null CompletedTask::CLASS_* of a done cycle. */
	protected $completionClass;

	/**
	 * No property default: the OCP Entity setter would drop a value equal
	 * to it from the INSERT; the schema default is the safety net.
	 *
	 * @var int
	 */
	protected $energy;

	/** @var array|null Wallet ids of the room task's assignees when the cycle closed. */
	protected $responsible;

	/** @var \DateTime */
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('houseId', 'integer');
		$this->addType('roomTaskId', 'integer');
		$this->addType('cycleKey', 'string');
		$this->addType('windowStartOn', 'date');
		$this->addType('windowEndOn', 'date');
		$this->addType('weekStart', 'date');
		$this->addType('timezone', 'string');
		$this->addType('outcome', 'string');
		$this->addType('completionId', 'integer');
		$this->addType('completionClass', 'string');
		$this->addType('energy', 'integer');
		$this->addType('responsible', 'json');
		$this->addType('createdAt', 'datetime');
	}

	/**
	 * @return list<int> Wallet ids the cycle counts for; empty for an unassigned cycle
	 */
	public function responsibleWalletIds(): array {
		return array_values(array_map('intval', $this->responsible ?? []));
	}
}
