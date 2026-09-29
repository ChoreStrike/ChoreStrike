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
 * @method string getNextcloudInstanceId()
 * @method void setNextcloudInstanceId(string $nextcloudInstanceId)
 * @method string getName()
 * @method void setName(string $name)
 * @method int getHouseHealth()
 * @method void setHouseHealth(int $houseHealth)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 * @method string getTimezone()
 * @method void setTimezone(string $timezone)
 * @method int getStateVersion()
 * @method void setStateVersion(int $stateVersion)
 * @method int getTimezoneVersion()
 * @method void setTimezoneVersion(int $timezoneVersion)
 * @method bool getCatalogEnabled()
 * @method void setCatalogEnabled(bool $catalogEnabled)
 * @method bool getRankingEnabled()
 * @method void setRankingEnabled(bool $rankingEnabled)
 * @method int getRankingGenerationSeq()
 * @method void setRankingGenerationSeq(int $rankingGenerationSeq)
 */
class House extends Entity implements JsonSerializable {
	/** @var string */
	protected $nextcloudInstanceId;

	/** @var string */
	protected $name;

	/** @var int */
	protected $houseHealth = 100;

	/** @var \DateTime */
	protected $createdAt;

	/** @var \DateTime */
	protected $updatedAt;

	/**
	 * IANA timezone id used for all calendar-day bucketing of the household
	 * (CS-PLAN-057). Backed by Version1028 migration.
	 *
	 * @var string
	 */
	protected $timezone = 'UTC';

	/**
	 * Aggregate mutation counter (CS-PLAN-071 WP0, parent §3.2): bumped on
	 * every plan/accounting/preference mutation (adopted by WP2-WP5;
	 * mechanism demonstrated by T0). Frozen at 0 until those consumers land.
	 *
	 * @var int
	 */
	protected $stateVersion = 0;

	/**
	 * Timezone mutation counter (CS-PLAN-071 WP0, decision D7): bumped on
	 * every house-timezone change; occurrence facts keep their saved zone.
	 *
	 * @var int
	 */
	protected $timezoneVersion = 0;

	/** @var bool Reward-catalog flag, frozen default-false for WP7. */
	protected $catalogEnabled = false;

	/** @var bool Ranking flag, frozen default-false for WP4/WP7. */
	protected $rankingEnabled = false;

	/**
	 * Per-house monotonic ranking generation watermark (CS-PLAN-070 WP4,
	 * Q-075-01 A). Allocated under the house lock; survives the deletion of
	 * every consent row so a withdrawn generation is never reused.
	 *
	 * @var int
	 */
	protected $rankingGenerationSeq = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('nextcloudInstanceId', 'string');
		$this->addType('name', 'string');
		$this->addType('houseHealth', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
		$this->addType('timezone', 'string');
		$this->addType('stateVersion', 'integer');
		$this->addType('timezoneVersion', 'integer');
		$this->addType('catalogEnabled', 'boolean');
		$this->addType('rankingEnabled', 'boolean');
		$this->addType('rankingGenerationSeq', 'integer');
	}

	/**
	 * Convert house to JSON representation
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'nextcloudInstanceId' => $this->nextcloudInstanceId,
			'name' => $this->name,
			'houseHealth' => $this->houseHealth,
			// Aggregate mutation counter (WP3 §3.6): the optimistic version
			// the cleanliness/reset commands expect against the displayed
			// house state.
			'stateVersion' => $this->stateVersion,
			'createdAt' => $this->createdAt?->format('c'),
			'updatedAt' => $this->updatedAt?->format('c'),
			'timezone' => $this->timezone,
			// Consent-filtered ranking gate (CS-PLAN-070 WP4, C5i/D10b) —
			// the Leaderboard's opt-in/closed state reads this.
			'rankingEnabled' => $this->rankingEnabled,
		];
	}

	/**
	 * Update the updated_at timestamp
	 */
	public function touch(): void {
		$this->setUpdatedAt(new \DateTime());
	}

	/**
	 * Increment the timezone mutation counter (CS-PLAN-071 D7). Called by
	 * the owning mutation while the house row is locked.
	 */
	public function bumpTimezoneVersion(): void {
		$this->setTimezoneVersion($this->getTimezoneVersion() + 1);
	}
}
