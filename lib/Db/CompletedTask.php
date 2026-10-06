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
 * Completion evidence row = one fulfilled occurrence = one undo unit
 * (CS-PLAN-070 WP3 §3.1; formerly the ADR-011 effect-record ledger).
 *
 * WP3 cutover: the row is the immutable WORK evidence, no longer the
 * monetary authority and no longer a before-state restore source. It
 * freezes the occurrence key, the server work instant/date/zone, the audit
 * completer, the total effort and the frozen rate, plus the versioning
 * pair (`completionVersion`, `allocationRevision`) that selects the
 * counted `work_shares` revision. Money lives in wallets/wallet_entries;
 * room history lives in room_events; correction history lives in
 * completion_revs. `undoneAt` is NULL while the occurrence is valid; an
 * undone row is not a completion anywhere in the domain (decision D11).
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getRoomTaskId()
 * @method void setRoomTaskId(int $roomTaskId)
 * @method int|null getUserId()
 * @method void setUserId(?int $userId)
 * @method \DateTime getCompletedAt()
 * @method void setCompletedAt(\DateTime $completedAt)
 * @method int getTotalEnergy()
 * @method void setTotalEnergy(int $totalEnergy)
 * @method int getRateMinor()
 * @method void setRateMinor(int $rateMinor)
 * @method int getCompletionVersion()
 * @method void setCompletionVersion(int $completionVersion)
 * @method int getAllocationRevision()
 * @method void setAllocationRevision(int $allocationRevision)
 * @method string getCommandUuid()
 * @method void setCommandUuid(string $commandUuid)
 * @method \DateTime|null getOccurrenceDue()
 * @method void setOccurrenceDue(?\DateTime $occurrenceDue)
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
	 * Audit completer's house_user id. Nullable since CS-PLAN-071
	 * (decision D3): account erasure nulls the actor and retains the
	 * anonymous shared work facts. Q-074-02: the audit actor stays
	 * immutable across attribution corrections.
	 *
	 * @var int|null
	 */
	protected $userId;

	/** @var \DateTime */
	protected $completedAt;

	/**
	 * NOTE: no property defaults on the writer-set columns — the OCP
	 * Entity setter skips marking a field updated when the value equals
	 * the property default, silently dropping the column from the INSERT
	 * (a 1-energy completion IS the documented minimum; the frozen rate
	 * IS 1000; the initial version/revision ARE 0/1). The schema carries
	 * matching DB defaults as the safety net; the writer always sets
	 * every field explicitly.
	 *
	 * @var int Frozen task effort 1-5 at completion.
	 */
	protected $totalEnergy;

	/** @var int Frozen Coin minor per whole energy unit (10 Coins). */
	protected $rateMinor;

	/** @var int Initial 0; attribution and undo increment. */
	protected $completionVersion;

	/** @var int The selected work_shares revision (initial 1). */
	protected $allocationRevision;

	/** @var string Creating command receipt UUID. */
	protected $commandUuid;

	/** @var \DateTime|null House-local due date of the completed occurrence (ADR-009). */
	protected $occurrenceDue;

	/** @var \DateTime|null NULL = valid completion. */
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
		$this->addType('totalEnergy', 'integer');
		$this->addType('rateMinor', 'integer');
		$this->addType('completionVersion', 'integer');
		$this->addType('allocationRevision', 'integer');
		$this->addType('commandUuid', 'string');
		$this->addType('occurrenceDue', 'date');
		$this->addType('undoneAt', 'datetime');
		$this->addType('scheduleRevId', 'integer');
		$this->addType('occurrenceOrdinal', 'integer');
		$this->addType('workDate', 'date');
		$this->addType('workZone', 'string');
		$this->addType('completionClass', 'string');
	}

	/**
	 * Whether this completion is a VALID CLASS_AGREED on-time completion
	 * inside the given occurrence window (half-open: `>= start`, `< end`,
	 * a null bound is unbounded). The single shared on-time predicate of
	 * the rhythm, weekly-goal and ranking evaluators (C1f semantics —
	 * RF-075-04: extracted at the rule of three).
	 */
	public function isAgreedOnTime(?\DateTime $windowStart, ?\DateTime $windowEnd): bool {
		return $this->completionClass === self::CLASS_AGREED
			&& ($windowStart === null || $this->completedAt >= $windowStart)
			&& ($windowEnd === null || $this->completedAt < $windowEnd);
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
			'totalEnergy' => $this->totalEnergy,
			'completionVersion' => $this->completionVersion,
			'allocationRevision' => $this->allocationRevision,
			'undoneAt' => $this->undoneAt?->format('c'),
			'occurrenceId' => $this->scheduleRevId !== null && $this->occurrenceOrdinal !== null
				? $this->scheduleRevId . ':' . $this->occurrenceOrdinal
				: null,
			'workDate' => $this->workDate?->format('Y-m-d'),
			'completionClass' => $this->completionClass,
		];
	}
}
