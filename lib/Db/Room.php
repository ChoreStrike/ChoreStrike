<?php

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
 * @method \DateTime getLastCleaned()
 * @method void setLastCleaned(\DateTime $lastCleaned)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
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

	/** @var \DateTime|null */
	protected $lastCleaned;

	/** @var \DateTime */
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('floorId', 'integer');
		$this->addType('name', 'string');
		$this->addType('cleanliness', 'integer');
		$this->addType('monsterCount', 'integer');
		$this->addType('lastCleaned', 'datetime');
		$this->addType('createdAt', 'datetime');
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
			'lastCleaned' => $this->lastCleaned?->format('c'),
			'createdAt' => $this->createdAt?->format('c'),
		];
	}

	/**
	 * Update cleanliness and monster count based on task completion
	 *
	 * @param int $cleanlinessChange The change in cleanliness (-100 to 100)
	 */
	public function updateCleanliness(int $cleanlinessChange): void {
		$this->cleanliness = max(0, min(100, $this->cleanliness + $cleanlinessChange));
		
		// Update monster count based on cleanliness
		if ($this->cleanliness >= 80) {
			$this->monsterCount = 0;
		} elseif ($this->cleanliness >= 60) {
			$this->monsterCount = 1;
		} elseif ($this->cleanliness >= 40) {
			$this->monsterCount = 2;
		} elseif ($this->cleanliness >= 20) {
			$this->monsterCount = 3;
		} else {
			$this->monsterCount = 4;
		}

		$this->lastCleaned = new \DateTime();
	}

	/**
	 * Check if room needs cleaning
	 */
	public function needsCleaning(): bool {
		return $this->cleanliness < 60 || $this->monsterCount > 1;
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
