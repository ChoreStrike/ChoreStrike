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
 * Completion-ledger row = one completion occurrence = one undo unit
 * (CS-PLAN-064 WP5, decision D1 / ADR-011).
 *
 * Beyond the reward outputs the row carries the immutable effect record:
 * `occurrenceDue` (the house-local due date of the completed occurrence),
 * `beforeState` (authoritative pre-mutation snapshot) and `deltas` (every
 * applied domain delta). `undoneAt` is NULL while the occurrence is
 * revertible; an undone row is not a completion anywhere in the domain
 * (decision D11).
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getRoomTaskId()
 * @method void setRoomTaskId(int $roomTaskId)
 * @method int|null getUserId()
 * @method void setUserId(?int $userId)
 * @method \DateTime getCompletedAt()
 * @method void setCompletedAt(\DateTime $completedAt)
 * @method int getCoinsEarned()
 * @method void setCoinsEarned(int $coinsEarned)
 * @method bool getWasCombo()
 * @method void setWasCombo(bool $wasCombo)
 * @method \DateTime|null getOccurrenceDue()
 * @method void setOccurrenceDue(?\DateTime $occurrenceDue)
 * @method array getBeforeState()
 * @method void setBeforeState(array $beforeState)
 * @method array getDeltas()
 * @method void setDeltas(array $deltas)
 * @method \DateTime|null getUndoneAt()
 * @method void setUndoneAt(?\DateTime $undoneAt)
 * @method int|null getScheduleRevId()
 * @method void setScheduleRevId(?int $scheduleRevId)
 * @method int|null getOccurrenceOrdinal()
 * @method void setOccurrenceOrdinal(?int $occurrenceOrdinal)
 * @method \DateTime|null getWorkDate()
 * @method void setWorkDate(?\DateTime $workDate)
 * @method string|null getWorkZone()
 * @method void setWorkZone(?string $workZone)
 * @method string|null getCompletionClass()
 * @method void setCompletionClass(?string $completionClass)
 */
class CompletedTask extends Entity implements JsonSerializable {
	/** Completion classified inside its agreed window (CS-PLAN-072 WP1). */
	public const CLASS_AGREED = 'agreed';

	/** Late catch-up work fulfilling the most recent missed occurrence. */
	public const CLASS_LATE = 'late';

	/** Voluntary actionable work (no planned-day obligation). */
	public const CLASS_VOLUNTARY = 'voluntary';

	public const COMPLETION_CLASSES = [
		self::CLASS_AGREED,
		self::CLASS_LATE,
		self::CLASS_VOLUNTARY,
	];
	/** @var int */
	protected $roomTaskId;

	/**
	 * Completer's house_user id. Nullable since CS-PLAN-071 (decision D3):
	 * account erasure nulls the actor and retains the anonymous shared
	 * work facts.
	 *
	 * @var int|null
	 */
	protected $userId;

	/** @var \DateTime */
	protected $completedAt;

	/** @var int */
	protected $coinsEarned = 0;

	/** @var bool */
	protected $wasCombo = false;

	/** @var \DateTime|null House-local due date of the completed occurrence (ADR-009). */
	protected $occurrenceDue;

	/** @var array Authoritative pre-mutation snapshot (JSON). */
	protected $beforeState = [];

	/** @var array Every applied domain delta (JSON). */
	protected $deltas = [];

	/** @var \DateTime|null NULL = revertible occurrence. */
	protected $undoneAt;

	/**
	 * Frozen timeline evidence (CS-PLAN-072 WP1): the public occurrence
	 * key parts this completion fulfilled.
	 *
	 * @var int|null
	 */
	protected $scheduleRevId;

	/** @var int|null */
	protected $occurrenceOrdinal;

	/** @var \DateTime|null Server-owned local work date basis. */
	protected $workDate;

	/** @var string|null Saved zone of the work date. */
	protected $workZone;

	/** @var string|null self::CLASS_* */
	protected $completionClass;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('roomTaskId', 'integer');
		$this->addType('userId', 'integer');
		$this->addType('completedAt', 'datetime');
		$this->addType('coinsEarned', 'integer');
		$this->addType('wasCombo', 'boolean');
		$this->addType('occurrenceDue', 'datetime');
		$this->addType('beforeState', 'json');
		$this->addType('deltas', 'json');
		$this->addType('undoneAt', 'datetime');
		$this->addType('scheduleRevId', 'integer');
		$this->addType('occurrenceOrdinal', 'integer');
		$this->addType('workDate', 'date');
		$this->addType('workZone', 'string');
		$this->addType('completionClass', 'string');
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
			'coinsEarned' => $this->coinsEarned,
			'wasCombo' => $this->wasCombo,
			'occurrenceDue' => $this->occurrenceDue?->format('c'),
			'undoneAt' => $this->undoneAt?->format('c'),
			'occurrenceId' => $this->scheduleRevId !== null && $this->occurrenceOrdinal !== null
				? $this->scheduleRevId . ':' . $this->occurrenceOrdinal
				: null,
			'workDate' => $this->workDate?->format('Y-m-d'),
			'completionClass' => $this->completionClass,
		];
	}

}
