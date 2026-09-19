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
 * @method int getFloorId()
 * @method void setFloorId(int $floorId)
 * @method string getName()
 * @method void setName(string $name)
 * @method int getCleanliness()
 * @method void setCleanliness(int $cleanliness)
 * @method int getMonsterCount()
 * @method void setMonsterCount(int $monsterCount)
 * @method string getIcon()
 * @method void setIcon(string $icon)
 * @method string getRoomType()
 * @method void setRoomType(string $roomType)
 * @method \DateTime getLastCleaned()
 * @method void setLastCleaned(\DateTime $lastCleaned)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime|null getDeletedAt()
 * @method void setDeletedAt(?\DateTime $deletedAt)
 */
class Room extends Entity implements JsonSerializable {
	/** @var int */
	protected $floorId;

	/** @var string */
	protected $name;

	/** @var int */
	protected $cleanliness = 50;

	/** @var int */
	protected $monsterCount = 0;

	/** @var string */
	protected $icon = 'Home';

	/** @var string */
	protected $roomType = 'generic';

	/** @var \DateTime|null */
	protected $lastCleaned;

	/** @var \DateTime */
	protected $createdAt;

	/** @var \DateTime|null Tombstone (Q-070-01): NULL = live row. */
	protected $deletedAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('floorId', 'integer');
		$this->addType('name', 'string');
		$this->addType('cleanliness', 'integer');
		$this->addType('monsterCount', 'integer');
		$this->addType('icon', 'string');
		$this->addType('roomType', 'string');
		$this->addType('lastCleaned', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('deletedAt', 'datetime');
	}

	/**
	 * Convert room to JSON representation
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'floorId' => $this->floorId,
			'name' => $this->name,
			'cleanliness' => $this->cleanliness,
			'monsterCount' => $this->monsterCount,
			'icon' => $this->icon ?? 'Home',
			'roomType' => $this->roomType ?? 'generic',
			'lastCleaned' => $this->lastCleaned?->format('c'),
			'createdAt' => $this->createdAt?->format('c'),
		];
	}

	/**
	 * Apply a cleanliness change (clamped to 0-100) and touch lastCleaned
	 *
	 * @param int $cleanlinessChange The change in cleanliness (-100 to 100)
	 */
	public function updateCleanliness(int $cleanlinessChange): void {
		$this->setCleanliness(max(0, min(100, $this->cleanliness + $cleanlinessChange)));
		$this->setLastCleaned(new \DateTime());
	}

	/**
	 * Get cleanliness label
	 */
	public function getCleanlinessLabel(): string {
		if ($this->cleanliness >= 70) {
			return 'clean';
		} elseif ($this->cleanliness >= 50) {
			return 'moderate';
		} elseif ($this->cleanliness >= 30) {
			return 'dirty';
		} else {
			return 'filthy';
		}
	}

	/**
	 * Check if room needs cleaning
	 */
	public function needsCleaning(): bool {
		return $this->cleanliness < 60;
	}

	/**
	 * Get room status as string
	 */
	public function getStatus(): string {
		if ($this->cleanliness >= 80) {
			return 'excellent';
		} elseif ($this->cleanliness >= 60) {
			return 'good';
		} elseif ($this->cleanliness >= 40) {
			return 'fair';
		} elseif ($this->cleanliness >= 20) {
			return 'poor';
		} else {
			return 'terrible';
		}
	}
}
