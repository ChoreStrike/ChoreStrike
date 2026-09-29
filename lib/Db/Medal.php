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
 * @method int getId()
 * @method void setId(int $id)
 * @method int getUserId()
 * @method void setUserId(int $userId)
 * @method string getMedalType()
 * @method void setMedalType(string $medalType)
 * @method string getWeekStart()
 * @method void setWeekStart(string $weekStart)
 * @method int getValue()
 * @method void setValue(int $value)
 * @method \DateTime getAwardedAt()
 * @method void setAwardedAt(\DateTime $awardedAt)
 */
class Medal extends Entity implements JsonSerializable {
	/** @var int */
	protected $userId;

	/** @var string */
	protected $medalType;

	/** @var string */
	protected $weekStart;

	/** @var int */
	protected $value = 0;

	/** @var \DateTime */
	protected $awardedAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('userId', 'integer');
		$this->addType('medalType', 'string');
		$this->addType('weekStart', 'string');
		$this->addType('value', 'integer');
		$this->addType('awardedAt', 'datetime');
	}

	/**
	 * Convert medal to JSON representation
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'medalType' => $this->medalType,
			'medalLabel' => $this->getMedalLabel(),
			'weekStart' => $this->weekStart,
			'value' => $this->value,
			'awardedAt' => $this->awardedAt?->format('c'),
		];
	}

	/**
	 * Get human-readable medal label
	 *
	 * The retired activity/combo medal types (team_anchor, flow_keeper,
	 * room_guardian, team_player, streak_keeper) dropped with the WP4
	 * label cutover (CS-PLAN-070) — only the retained rhythm tiers and
	 * the weekly milestone exist.
	 */
	public function getMedalLabel(): string {
		return ucfirst(str_replace('_', ' ', $this->medalType));
	}

}
