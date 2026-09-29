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
 * @method int getHouseId()
 * @method void setHouseId(int $houseId)
 * @method string getNextcloudUserId()
 * @method void setNextcloudUserId(string $nextcloudUserId)
 * @method string getDisplayName()
 * @method void setDisplayName(string $displayName)
 * @method int getContributionMinor()
 * @method void setContributionMinor(int $contributionMinor)
 * @method int getCurrentStreak()
 * @method void setCurrentStreak(int $currentStreak)
 * @method int getLongestStreak()
 * @method void setLongestStreak(int $longestStreak)
 * @method \DateTime|null getLastActivity()
 * @method void setLastActivity(?\DateTime $lastActivity)
 * @method string getRole()
 * @method void setRole(string $role)
 * @method \DateTime getJoinedAt()
 * @method void setJoinedAt(\DateTime $joinedAt)
 * @method array|null getSchedulingPreferences()
 * @method void setSchedulingPreferences(array $preferences)
 * @method array getUnlockedTitles()
 * @method void setUnlockedTitles(array $unlockedTitles)
 * @method int|null getWalletId()
 * @method void setWalletId(?int $walletId)
 */
class HouseUser extends Entity implements JsonSerializable {
	/** @var int */
	protected $houseId;

	/** @var string */
	protected $nextcloudUserId;

	/** @var string */
	protected $displayName;

	/**
	 * Exact contribution in energy minor units (CS-PLAN-070 WP3 §3.1) —
	 * derived from the member's valid selected work shares. Membership is
	 * never a financial authority: a rejoining member's contribution is
	 * re-derived from the retained work.
	 *
	 * @var int
	 */
	protected $contributionMinor = 0;

	/** @var int */
	protected $currentStreak = 0;

	/** @var \DateTime|null */
	protected $lastActivity;

	/** @var string */
	protected $role = 'member';

	/** @var \DateTime */
	protected $joinedAt;

	/** @var array */
	protected $schedulingPreferences = [];

	/** @var int */
	protected $longestStreak = 0;

	/**
	 * Single-series unlocked-tier marker (CS-PLAN-070 WP4, Q-075-02 A):
	 * cache of `{pack}.{tier}` strings the user has EVER unlocked — the
	 * first-earned celebration evidence (C3g no-repeat). Recomputable from
	 * stats, so safe to clear on a house reset.
	 *
	 * @var array
	 */
	protected $unlockedTitles = [];

	/**
	 * Persistent wallet binding (CS-PLAN-071 WP0). The wallet — not this
	 * row — is the monetary authority; contribution_minor is a derived
	 * cache. Deliberately NOT exposed through jsonSerialize (financial
	 * identity grants no access and leaks nothing).
	 *
	 * @var int|null
	 */
	protected $walletId = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('houseId', 'integer');
		$this->addType('nextcloudUserId', 'string');
		$this->addType('displayName', 'string');
		$this->addType('contributionMinor', 'integer');
		$this->addType('currentStreak', 'integer');
		$this->addType('longestStreak', 'integer');
		$this->addType('lastActivity', 'datetime');
		$this->addType('role', 'string');
		$this->addType('joinedAt', 'datetime');
		$this->addType('schedulingPreferences', 'json');
		$this->addType('unlockedTitles', 'json');
		$this->addType('walletId', 'integer');
	}

	/**
	 * Convert house user to JSON representation
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'houseId' => $this->houseId,
			'nextcloudUserId' => $this->nextcloudUserId,
			'displayName' => $this->displayName,
			'contributionMinor' => $this->contributionMinor,
			'currentStreak' => $this->currentStreak,
			'longestStreak' => $this->longestStreak,
			'lastActivity' => $this->lastActivity?->format('c'),
			'role' => $this->role,
			'joinedAt' => $this->joinedAt?->format('c'),
			'schedulingPreferences' => $this->schedulingPreferences,
		];
	}

	/**
	 * Add contribution in minor units
	 */
	public function addContributionMinor(int $minor): void {
		$this->setContributionMinor($this->contributionMinor + $minor);
	}

	/**
	 * Reset streak
	 */
	public function resetStreak(): void {
		$this->setCurrentStreak(0);
	}

	/**
	 * Update last activity timestamp
	 */
	public function touch(): void {
		$this->setLastActivity(new \DateTime());
	}

	/**
	 * Check if user is owner
	 */
	public function isOwner(): bool {
		return $this->role === 'owner';
	}

	/**
	 * Check if user is child
	 */
	public function isChild(): bool {
		return $this->role === 'child';
	}

	/**
	 * Append a `{pack}.{tier}` title id to the unlocked-titles cache.
	 *
	 * Idempotent: returns true only when the title was newly added. The
	 * cache is a denormalized mirror of the stat-derived unlock set, so a
	 * house reset clears it without data loss.
	 *
	 * @param string $tierId Canonical `{pack}.{tier}` identifier
	 * @return bool True when the title was newly cached
	 */
	public function addUnlockedTitle(string $tierId): bool {
		$current = $this->unlockedTitles ?? [];
		if (in_array($tierId, $current, true)) {
			return false;
		}
		$current[] = $tierId;
		$this->setUnlockedTitles($current);
		return true;
	}
}
