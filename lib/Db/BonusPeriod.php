<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A bonus period of a house (ADR-021 decision 11): one to three
 * house-local days on which every member's coins count × 1.5. A cancelled
 * period keeps its row; completions recorded while it ran keep the percent
 * frozen on them.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getHouseId()
 * @method void setHouseId(int $houseId)
 * @method \DateTime getStartsOn()
 * @method void setStartsOn(\DateTime $startsOn)
 * @method \DateTime getEndsOn()
 * @method void setEndsOn(\DateTime $endsOn)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime|null getCancelledAt()
 * @method void setCancelledAt(?\DateTime $cancelledAt)
 */
class BonusPeriod extends Entity {
	/** What a share's coins count on a day of a period, in percent. */
	public const PERCENT = 150;
	/** A day outside every period. */
	public const PERCENT_NONE = 100;

	public const MIN_DAYS = 1;
	public const MAX_DAYS = 3;

	/** @var int */
	protected $houseId;

	/** @var \DateTime First day (house-local, inclusive). */
	protected $startsOn;

	/** @var \DateTime Last day (house-local, inclusive). */
	protected $endsOn;

	/** @var \DateTime */
	protected $createdAt;

	/** @var \DateTime|null */
	protected $cancelledAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('houseId', 'integer');
		$this->addType('startsOn', 'date');
		$this->addType('endsOn', 'date');
		$this->addType('createdAt', 'datetime');
		$this->addType('cancelledAt', 'datetime');
	}

	/**
	 * The shape `GET /house` and the owner commands answer with.
	 *
	 * @return array{id: int, startsOn: string, endsOn: string}
	 */
	public function toPayload(): array {
		return [
			'id' => (int)$this->id,
			'startsOn' => $this->startsOn->format('Y-m-d'),
			'endsOn' => $this->endsOn->format('Y-m-d'),
		];
	}
}
