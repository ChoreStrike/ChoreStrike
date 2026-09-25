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
 * One revision of the pause lifecycle (CS-PLAN-073 WP2, parent §3.2/§3.4).
 *
 * A logical pause is a stable `pause_group_id`; every mutation appends a
 * new revision (unique per group) — the latest revision is the current
 * projection, the revisions are the traceable evidence. Personal pauses
 * name a subject wallet (persistent WP0 identity, survives departure);
 * house pauses have no subject. Reason is a bounded code, never free text.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getHouseId()
 * @method void setHouseId(int $houseId)
 * @method int getPauseGroupId()
 * @method void setPauseGroupId(int $pauseGroupId)
 * @method int getRevision()
 * @method void setRevision(int $revision)
 * @method string getScope()
 * @method void setScope(string $scope)
 * @method int|null getSubjectWalletId()
 * @method void setSubjectWalletId(?int $subjectWalletId)
 * @method int|null getRequesterHouseUserId()
 * @method void setRequesterHouseUserId(?int $requesterHouseUserId)
 * @method int|null getDeciderHouseUserId()
 * @method void setDeciderHouseUserId(?int $deciderHouseUserId)
 * @method string getState()
 * @method void setState(string $state)
 * @method string getReasonCode()
 * @method void setReasonCode(string $reasonCode)
 * @method bool getIsRetrospectiveIllness()
 * @method void setIsRetrospectiveIllness(bool $isRetrospectiveIllness)
 * @method \DateTime getStartDate()
 * @method void setStartDate(\DateTime $startDate)
 * @method \DateTime getEndDate()
 * @method void setEndDate(\DateTime $endDate)
 * @method string getTimezone()
 * @method void setTimezone(string $timezone)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class Pause extends Entity implements JsonSerializable {
	public const SCOPE_PERSONAL = 'personal';
	public const SCOPE_HOUSE = 'house';

	public const SCOPES = [
		self::SCOPE_PERSONAL,
		self::SCOPE_HOUSE,
	];

	public const STATE_REQUESTED = 'requested';
	public const STATE_ACKNOWLEDGED = 'acknowledged';
	public const STATE_REJECTED = 'rejected';
	public const STATE_CANCELLED = 'cancelled';

	public const STATES = [
		self::STATE_REQUESTED,
		self::STATE_ACKNOWLEDGED,
		self::STATE_REJECTED,
		self::STATE_CANCELLED,
	];

	/** Bounded reason codes (plan §3.1: no medical free text). */
	public const REASON_ILLNESS = 'illness';
	public const REASON_TRAVEL = 'travel';
	public const REASON_OVERLOAD = 'overload';
	public const REASON_OTHER = 'other';

	public const REASON_CODES = [
		self::REASON_ILLNESS,
		self::REASON_TRAVEL,
		self::REASON_OVERLOAD,
		self::REASON_OTHER,
	];

	/** @var int */
	protected $houseId;

	/** @var int */
	protected $pauseGroupId;

	/** @var int Monotonic per group, starting at 1. */
	protected $revision = 1;

	/** @var string self::SCOPE_* */
	protected $scope = self::SCOPE_PERSONAL;

	/** @var int|null NULL = house scope. */
	protected $subjectWalletId;

	/** @var int|null Acting membership; NULL after erasure. */
	protected $requesterHouseUserId;

	/** @var int|null Acknowledging/rejecting membership; NULL after erasure. */
	protected $deciderHouseUserId;

	/** @var string self::STATE_* */
	protected $state = self::STATE_REQUESTED;

	/** @var string self::REASON_* */
	protected $reasonCode = self::REASON_OTHER;

	/** @var bool Explicit retrospective-illness audit marker. */
	protected $isRetrospectiveIllness = false;

	/** @var \DateTime Inclusive saved-zone start date. */
	protected $startDate;

	/** @var \DateTime Inclusive saved-zone end date. */
	protected $endDate;

	/** @var string Saved IANA zone of the date range. */
	protected $timezone;

	/** @var \DateTime */
	protected $createdAt;

	/** @var \DateTime */
	protected $updatedAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('houseId', 'integer');
		$this->addType('pauseGroupId', 'integer');
		$this->addType('revision', 'integer');
		$this->addType('scope', 'string');
		$this->addType('subjectWalletId', 'integer');
		$this->addType('requesterHouseUserId', 'integer');
		$this->addType('deciderHouseUserId', 'integer');
		$this->addType('state', 'string');
		$this->addType('reasonCode', 'string');
		$this->addType('isRetrospectiveIllness', 'boolean');
		$this->addType('startDate', 'date');
		$this->addType('endDate', 'date');
		$this->addType('timezone', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	/**
	 * Whether this revision's inclusive saved-date range covers the given
	 * civil date label (Q-073-02 rule 4: no zone translation).
	 */
	public function coversDate(string $dateKey): bool {
		$start = $this->getStartDate()?->format('Y-m-d');
		$end = $this->getEndDate()?->format('Y-m-d');
		if ($start === null || $end === null) {
			return false;
		}
		return $dateKey >= $start && $dateKey <= $end;
	}

	public function isRecognized(): bool {
		return $this->getState() === self::STATE_ACKNOWLEDGED;
	}

	/**
	 * The decision fact of this revision (CS-PLAN-073 R0/R2/R3): the
	 * instant it became what it is. For an acknowledged revision this is
	 * the acknowledgment/approval time — `updated_at` is stamped at
	 * insertion and re-stamped ONLY by an explicit owner decision on a
	 * pending row (or a pending cancellation), never by a later revision,
	 * a house-zone edit or a job replay. A child's earlier request time
	 * is `created_at` and is deliberately NOT the decision.
	 */
	public function decisionTime(): ?\DateTime {
		return $this->getUpdatedAt() ?? $this->getCreatedAt();
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'pauseGroupId' => $this->pauseGroupId,
			'revision' => $this->revision,
			'scope' => $this->scope,
			'subjectWalletId' => $this->subjectWalletId,
			'state' => $this->state,
			'reasonCode' => $this->reasonCode,
			'isRetrospectiveIllness' => $this->isRetrospectiveIllness,
			'startDate' => $this->startDate?->format('Y-m-d'),
			'endDate' => $this->endDate?->format('Y-m-d'),
			'timezone' => $this->timezone,
			'createdAt' => $this->createdAt?->format('c'),
			'updatedAt' => $this->updatedAt?->format('c'),
		];
	}
}
