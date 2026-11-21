<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One durable occurrence agreement (CS-PLAN-072 WP1, parent §3.2): the
 * recurring chore's concrete obligation unit with its saved time basis.
 *
 * Read only since ADR-021: windows are derived, nothing writes these rows
 * any more, and the recognitions still read the ones from before the
 * cutover. Public identity was the canonical
 * `<scheduleRevisionId>:<ordinal>` key — NEVER this row's
 * bigint surrogate. A row that was merely materialized (by the timeline
 * job or a completion's virtual-key resolution) kept `version` 0; domain
 * mutation advanced it. Every instant column is UTC; the saved zone is
 * carried separately so later house-zone edits cannot rewrite history.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getHouseId()
 * @method void setHouseId(int $houseId)
 * @method int getRoomTaskId()
 * @method void setRoomTaskId(int $roomTaskId)
 * @method int getScheduleRevId()
 * @method void setScheduleRevId(int $scheduleRevId)
 * @method int getOrdinal()
 * @method void setOrdinal(int $ordinal)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method \DateTime getPlannedDate()
 * @method void setPlannedDate(\DateTime $plannedDate)
 * @method \DateTime getWeekStart()
 * @method void setWeekStart(\DateTime $weekStart)
 * @method string getTimezone()
 * @method void setTimezone(string $timezone)
 * @method \DateTime getDayStart()
 * @method void setDayStart(\DateTime $dayStart)
 * @method \DateTime getWindowStart()
 * @method void setWindowStart(\DateTime $windowStart)
 * @method \DateTime getWindowEnd()
 * @method void setWindowEnd(\DateTime $windowEnd)
 * @method int getEnergy()
 * @method void setEnergy(int $energy)
 * @method bool getIsPublic()
 * @method void setIsPublic(bool $isPublic)
 * @method int getVersion()
 * @method void setVersion(int $version)
 * @method int|null getCoveredByCompletionId()
 * @method void setCoveredByCompletionId(?int $coveredByCompletionId)
 * @method int|null getSourceCompletionId()
 * @method void setSourceCompletionId(?int $sourceCompletionId)
 * @method int|null getCompletionId()
 * @method void setCompletionId(?int $completionId)
 * @method string getSuspensionState()
 * @method void setSuspensionState(string $suspensionState)
 * @method array|null getSuspensionEvidence()
 * @method void setSuspensionEvidence(?array $suspensionEvidence)
 * @method int|null getRestartOfId()
 * @method void setRestartOfId(?int $restartOfId)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Occurrence extends Entity {
	public const KIND_SCHEDULED = 'scheduled';
	public const KIND_VOLUNTARY = 'voluntary';

	public const KINDS = [
		self::KIND_SCHEDULED,
		self::KIND_VOLUNTARY,
	];

	/** WP2 neutral suspension states. */
	public const SUSPENSION_NONE = 'none';
	public const SUSPENSION_SUSPENDED = 'suspended';

	public const SUSPENSION_STATES = [
		self::SUSPENSION_NONE,
		self::SUSPENSION_SUSPENDED,
	];

	/** @var int */
	protected $houseId;

	/** @var int */
	protected $roomTaskId;

	/** @var int */
	protected $scheduleRevId;

	/** @var int Stable position in the revision's anchored sequence. */
	protected $ordinal;

	/** @var string self::KIND_* */
	protected $kind = self::KIND_SCHEDULED;

	/** @var \DateTime Saved local planned date (`Y-m-d` at 00:00). */
	protected $plannedDate;

	/** @var \DateTime Monday of the planned date's week in the saved zone. */
	protected $weekStart;

	/** @var string Saved IANA zone of this agreement. */
	protected $timezone;

	/** @var \DateTime UTC instant of the saved planned-day local midnight. */
	protected $dayStart;

	/** @var \DateTime UTC instant, inclusive window start. */
	protected $windowStart;

	/** @var \DateTime UTC instant, EXCLUSIVE window end. */
	protected $windowEnd;

	/** @var int Total energy: current while open, frozen once completed or covered. */
	protected $energy = 1;

	/** @var bool Public-at-agreement classification. */
	protected $isPublic = true;

	/** @var int 0 while merely materialized; domain mutation advances it. */
	protected $version = 0;

	/** @var int|null Catch-up coverage provenance (expired miss covered). */
	protected $coveredByCompletionId;

	/** @var int|null Interval provenance: anchoring completion. */
	protected $sourceCompletionId;

	/** @var int|null Current valid completion (NULL = unfulfilled). */
	protected $completionId;

	/** @var string self::SUSPENSION_* (WP2 neutral suspension). */
	protected $suspensionState = self::SUSPENSION_NONE;

	/** @var array|null [{pauseGroupId, revision}] neutral-suspension provenance. */
	protected $suspensionEvidence;

	/** @var int|null Linked interval restart predecessor (WP2). */
	protected $restartOfId;

	/** @var \DateTime */
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('houseId', 'integer');
		$this->addType('roomTaskId', 'integer');
		$this->addType('scheduleRevId', 'integer');
		$this->addType('ordinal', 'integer');
		$this->addType('kind', 'string');
		$this->addType('plannedDate', 'date');
		$this->addType('weekStart', 'date');
		$this->addType('timezone', 'string');
		$this->addType('dayStart', 'datetime');
		$this->addType('windowStart', 'datetime');
		$this->addType('windowEnd', 'datetime');
		$this->addType('energy', 'integer');
		$this->addType('isPublic', 'boolean');
		$this->addType('version', 'integer');
		$this->addType('coveredByCompletionId', 'integer');
		$this->addType('sourceCompletionId', 'integer');
		$this->addType('completionId', 'integer');
		$this->addType('suspensionState', 'string');
		$this->addType('suspensionEvidence', 'json');
		$this->addType('restartOfId', 'integer');
		$this->addType('createdAt', 'datetime');
	}
}
