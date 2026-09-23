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
 * Immutable rule revision of one room task (CS-PLAN-072 WP1, parent
 * CS-PLAN-070 §3.2): the complete generation inputs for a period of the
 * task's timeline. Definition inputs (cadence, anchor, bounds, zone,
 * energy, participant defaults) are IMMUTABLE after insert; only the
 * agreement frontier (`agreedThrough`) and closing metadata (`closedAt`)
 * advance. Edits close the old revision and append a new one.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getRoomTaskId()
 * @method void setRoomTaskId(int $roomTaskId)
 * @method int getRevision()
 * @method void setRevision(int $revision)
 * @method \DateTime getEffectiveFrom()
 * @method void setEffectiveFrom(\DateTime $effectiveFrom)
 * @method \DateTime getAgreedThrough()
 * @method void setAgreedThrough(\DateTime $agreedThrough)
 * @method \DateTime|null getClosedAt()
 * @method void setClosedAt(?\DateTime $closedAt)
 * @method string getCadenceMode()
 * @method void setCadenceMode(string $cadenceMode)
 * @method string getCadenceUnit()
 * @method void setCadenceUnit(string $cadenceUnit)
 * @method int getCadenceInterval()
 * @method void setCadenceInterval(int $cadenceInterval)
 * @method \DateTime getAnchorDate()
 * @method void setAnchorDate(\DateTime $anchorDate)
 * @method string|null getWeekdays()
 * @method void setWeekdays(?string $weekdays)
 * @method string getLifetime()
 * @method void setLifetime(string $lifetime)
 * @method \DateTime|null getActiveFrom()
 * @method void setActiveFrom(?\DateTime $activeFrom)
 * @method \DateTime|null getActiveUntil()
 * @method void setActiveUntil(?\DateTime $activeUntil)
 * @method int getEarlyWindowDays()
 * @method void setEarlyWindowDays(int $earlyWindowDays)
 * @method int getLateWindowDays()
 * @method void setLateWindowDays(int $lateWindowDays)
 * @method string getTimezone()
 * @method void setTimezone(string $timezone)
 * @method int getEnergy()
 * @method void setEnergy(int $energy)
 * @method bool getIsPublic()
 * @method void setIsPublic(bool $isPublic)
 * @method bool getIsVoluntary()
 * @method void setIsVoluntary(bool $isVoluntary)
 * @method array|null getDefaultMembers()
 * @method void setDefaultMembers(?array $defaultMembers)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class ScheduleRevision extends Entity implements JsonSerializable {
	public const CADENCE_FIXED = 'fixed';
	public const CADENCE_INTERVAL = 'interval';

	public const CADENCE_MODES = [
		self::CADENCE_FIXED,
		self::CADENCE_INTERVAL,
	];

	public const UNIT_DAY = 'day';
	public const UNIT_WEEK = 'week';
	public const UNIT_MONTH = 'month';

	public const CADENCE_UNITS = [
		self::UNIT_DAY,
		self::UNIT_WEEK,
		self::UNIT_MONTH,
	];

	/** @var int */
	protected $roomTaskId;

	/** @var int */
	protected $revision = 1;

	/** @var \DateTime */
	protected $effectiveFrom;

	/** @var \DateTime Inclusive local planned-date frontier (a `Y-m-d` at 00:00). */
	protected $agreedThrough;

	/** @var \DateTime|null NULL = current (not superseded) revision. */
	protected $closedAt;

	/** @var string */
	protected $cadenceMode;

	/** @var string */
	protected $cadenceUnit;

	/** @var int */
	protected $cadenceInterval = 1;

	/** @var \DateTime Original anchor local date. */
	protected $anchorDate;

	/** @var string|null JSON array of weekday names (fixed weekly rules). */
	protected $weekdays;

	/** @var string Task::RECURRENCE_* */
	protected $lifetime;

	/** @var \DateTime|null Inclusive active bound. */
	protected $activeFrom;

	/** @var \DateTime|null Inclusive active bound. */
	protected $activeUntil;

	/** @var int */
	protected $earlyWindowDays = 0;

	/** @var int */
	protected $lateWindowDays = 0;

	/** @var string Saved IANA zone. */
	protected $timezone;

	/** @var int Frozen agreed total energy. */
	protected $energy = 1;

	/** @var bool Public-at-agreement classification. */
	protected $isPublic = true;

	/** @var bool */
	protected $isVoluntary = false;

	/**
	 * Default responsibility snapshot at agreement time (bounded JSON):
	 * [{walletId, userId, role, obligated, sharePct}]. Stored facts, never
	 * authorization.
	 *
	 * @var array|null
	 */
	protected $defaultMembers;

	/** @var \DateTime */
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('roomTaskId', 'integer');
		$this->addType('revision', 'integer');
		$this->addType('effectiveFrom', 'datetime');
		$this->addType('agreedThrough', 'date');
		$this->addType('closedAt', 'datetime');
		$this->addType('cadenceMode', 'string');
		$this->addType('cadenceUnit', 'string');
		$this->addType('cadenceInterval', 'integer');
		$this->addType('anchorDate', 'date');
		$this->addType('weekdays', 'string');
		$this->addType('lifetime', 'string');
		$this->addType('activeFrom', 'date');
		$this->addType('activeUntil', 'date');
		$this->addType('earlyWindowDays', 'integer');
		$this->addType('lateWindowDays', 'integer');
		$this->addType('timezone', 'string');
		$this->addType('energy', 'integer');
		$this->addType('isPublic', 'boolean');
		$this->addType('isVoluntary', 'boolean');
		$this->addType('defaultMembers', 'json');
		$this->addType('createdAt', 'datetime');
	}

	/**
	 * Decoded weekday names of a fixed weekly rule (canonical order).
	 *
	 * @return list<string>
	 */
	public function weekdayList(): array {
		if (!is_string($this->weekdays) || $this->weekdays === '') {
			return [];
		}
		$decoded = json_decode($this->weekdays, true);
		if (!is_array($decoded)) {
			return [];
		}
		$names = [];
		foreach ($decoded as $day) {
			if (is_string($day) && $day !== '') {
				$names[] = strtolower(trim($day));
			}
		}
		return array_values(array_unique($names));
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'roomTaskId' => $this->roomTaskId,
			'revision' => $this->revision,
			'effectiveFrom' => $this->effectiveFrom?->format('c'),
			'agreedThrough' => $this->agreedThrough?->format('Y-m-d'),
			'closedAt' => $this->closedAt?->format('c'),
			'cadenceMode' => $this->cadenceMode,
			'cadenceUnit' => $this->cadenceUnit,
			'cadenceInterval' => $this->cadenceInterval,
			'anchorDate' => $this->anchorDate?->format('Y-m-d'),
			'weekdays' => $this->weekdayList(),
			'lifetime' => $this->lifetime,
			'activeFrom' => $this->activeFrom?->format('Y-m-d'),
			'activeUntil' => $this->activeUntil?->format('Y-m-d'),
			'earlyWindowDays' => $this->earlyWindowDays,
			'lateWindowDays' => $this->lateWindowDays,
			'timezone' => $this->timezone,
			'energy' => $this->energy,
			'isPublic' => $this->isPublic,
			'isVoluntary' => $this->isVoluntary,
		];
	}
}
