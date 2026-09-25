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
 * One durable occurrence agreement (CS-PLAN-072 WP1, parent §3.2): the
 * recurring chore's concrete obligation unit with its saved time basis.
 *
 * Public identity is the canonical `<scheduleRevisionId>:<ordinal>` key
 * (see {@see toPublicKey()}/{@see parsePublicKey()}) — NEVER this row's
 * bigint surrogate. A row that was merely materialized (by the timeline
 * job or a completion's virtual-key resolution) keeps `version` 0; domain
 * mutation advances it. Every instant column is UTC; the saved zone is
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
class Occurrence extends Entity implements JsonSerializable {
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

	/** Upper bound for ordinal values accepted in public keys. */
	public const MAX_ORDINAL = 1000000;

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

	/** @var int Frozen agreed total energy. */
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

	/**
	 * The canonical public occurrence key `<scheduleRevisionId>:<ordinal>`.
	 *
	 * Canonical numeric spelling: plain base-10 integers, no leading zeros,
	 * no signs, no whitespace — the exact string parsePublicKey() accepts.
	 */
	public function toPublicKey(): string {
		return $this->scheduleRevId . ':' . $this->ordinal;
	}

	/**
	 * Parse and validate a public occurrence key. Rejects non-canonical
	 * spelling (leading zeros, signs, whitespace), non-integers and
	 * out-of-bounds parts.
	 *
	 * @return array{0: int, 1: int} [scheduleRevisionId, ordinal]
	 * @throws \InvalidArgumentException on any malformed key
	 */
	public static function parsePublicKey(string $key): array {
		if (!preg_match('/^(0|[1-9][0-9]*):(0|[1-9][0-9]*)$/', $key)) {
			throw new \InvalidArgumentException('Invalid occurrenceId format, expected <scheduleRevisionId>:<ordinal>');
		}
		[$revId, $ordinal] = explode(':', $key);
		$revId = (int)$revId;
		$ordinal = (int)$ordinal;
		if ($revId < 1 || $ordinal < 1 || $ordinal > self::MAX_ORDINAL) {
			throw new \InvalidArgumentException('Invalid occurrenceId bounds');
		}
		return [$revId, $ordinal];
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->toPublicKey(),
			'kind' => $this->kind,
			'plannedDate' => $this->plannedDate?->format('Y-m-d'),
			'weekStart' => $this->weekStart?->format('Y-m-d'),
			'timezone' => $this->timezone,
			'dayStart' => $this->dayStart?->format('c'),
			'windowStart' => $this->windowStart?->format('c'),
			'windowEnd' => $this->windowEnd?->format('c'),
			'energy' => $this->energy,
			'isPublic' => $this->isPublic,
			'version' => $this->version,
			'fulfilled' => $this->completionId !== null,
			'covered' => $this->coveredByCompletionId !== null,
			'suspended' => $this->suspensionState === self::SUSPENSION_SUSPENDED,
		];
	}

	/**
	 * The WP2 neutral suspension facts: state plus the recognized pause
	 * provenance (CS-PLAN-073 §3.4).
	 *
	 * @return array{suspended: bool, evidence: list<array{pauseGroupId: int, revision: int}>}
	 */
	public function suspensionFacts(): array {
		$evidence = [];
		foreach (is_array($this->suspensionEvidence) ? $this->suspensionEvidence : [] as $entry) {
			if (is_array($entry) && isset($entry['pauseGroupId'], $entry['revision'])) {
				$evidence[] = [
					'pauseGroupId' => (int)$entry['pauseGroupId'],
					'revision' => (int)$entry['revision'],
				];
			}
		}
		return [
			'suspended' => $this->suspensionState === self::SUSPENSION_SUSPENDED,
			'evidence' => $evidence,
		];
	}
}
