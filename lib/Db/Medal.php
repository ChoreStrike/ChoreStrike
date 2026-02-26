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
	 */
	public function getMedalLabel(): string {
		return match($this->medalType) {
			'team_anchor' => 'Team Anchor',
			'flow_keeper' => 'Flow Keeper',
			'room_guardian' => 'Room Guardian',
			'team_player' => 'Team Player',
			'streak_keeper' => 'Streak Keeper',
			default => ucfirst(str_replace('_', ' ', $this->medalType))
		};
	}

	/**
	 * Get medal description
	 */
	public function getDescription(): string {
		return match($this->medalType) {
			'team_anchor' => 'Most tasks completed this week',
			'flow_keeper' => 'Consistent daily activity',
			'room_guardian' => 'Most room improvements',
			'team_player' => 'Most collaborative tasks',
			'streak_keeper' => 'Longest activity streak',
			default => 'Special achievement'
		};
	}

	/**
	 * Get rarity based on medal type
	 */
	public function getRarity(): string {
		return match($this->medalType) {
			'team_anchor' => 'high',
			'flow_keeper' => 'high',
			'room_guardian' => 'medium',
			'team_player' => 'medium',
			'streak_keeper' => 'high',
			default => 'low'
		};
	}
}
