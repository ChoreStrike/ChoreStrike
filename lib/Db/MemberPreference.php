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
 * Per-member preference row (CS-PLAN-070 WP4, Q-075-01 Option A).
 *
 * WP4 scope: the RANKING-CONSENT slice only. WP5 amends this table in
 * place for the digest / quiet-hours / sound preference columns; nothing
 * here is a ranking authority on its own — RankingService interprets the
 * generation facts against the house flag.
 *
 * Consent lifecycle: the row EXISTS exactly while one consent generation
 * is live. Withdrawing, leaving the house, account erasure or disabling
 * the house ranking flag deletes it (consent belongs to the active
 * membership); a re-consent creates a fresh row with the next generation
 * effective the following Monday (never retroactive).
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getHouseId()
 * @method void setHouseId(int $houseId)
 * @method int getHouseUserId()
 * @method void setHouseUserId(int $houseUserId)
 * @method int getVersion()
 * @method void setVersion(int $version)
 * @method int getRankingConsentGeneration()
 * @method void setRankingConsentGeneration(int $generation)
 * @method \DateTime getRankingEffectiveMonday()
 * @method void setRankingEffectiveMonday(\DateTime $monday)
 * @method string getRankingSavedZone()
 * @method void setRankingSavedZone(string $zone)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class MemberPreference extends Entity implements JsonSerializable {
	/** @var int */
	protected $houseId;

	/** @var int */
	protected $houseUserId;

	/**
	 * Optimistic mutation counter (parent §3.2 pattern) — bumped by the
	 * owning mutation under the house lock. Neutral default: the Nextcloud
	 * Entity setter only marks a field updated when the value CHANGES, so
	 * defaults must never equal a realistic persisted value.
	 *
	 * @var int
	 */
	protected $version = 0;

	/**
	 * Monotonic consent-generation identity. Never reused: a withdrawn
	 * generation stays invalidated even if the row is re-created later.
	 * Neutral default 0 — real generations start at 1.
	 *
	 * @var int
	 */
	protected $rankingConsentGeneration = 0;

	/** @var \DateTime The saved next-Monday activation date (date part). */
	protected $rankingEffectiveMonday;

	/**
	 * Saved IANA zone of the activation boundary — the generation activates
	 * at this zone's Monday 00:00 even when the house zone changes later.
	 * Neutral default '' — never a valid IANA id, so the explicit setter
	 * always marks the field for the INSERT.
	 *
	 * @var string
	 */
	protected $rankingSavedZone = '';

	/** @var \DateTime */
	protected $createdAt;

	/** @var \DateTime */
	protected $updatedAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('houseId', 'integer');
		$this->addType('houseUserId', 'integer');
		$this->addType('version', 'integer');
		$this->addType('rankingConsentGeneration', 'integer');
		$this->addType('rankingEffectiveMonday', 'datetime');
		$this->addType('rankingSavedZone', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	/**
	 * Whether the consent generation is effective at `$now` (the saved
	 * Monday 00:00 in the saved zone has passed).
	 */
	public function isEffectiveAt(\DateTime $now): bool {
		$monday = $this->getRankingEffectiveMonday();
		if ($monday === null) {
			return false;
		}
		$effective = $this->effectiveInstant();
		return $now->getTimestamp() >= $effective->getTimestamp();
	}

	/**
	 * The activation instant: saved-zone Monday 00:00 rendered as a UTC
	 * instant.
	 */
	public function effectiveInstant(): \DateTimeImmutable {
		$zone = new \DateTimeZone($this->getRankingSavedZone());
		$local = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->getRankingEffectiveMonday()->format('Y-m-d'), $zone);
		return $local->setTime(0, 0, 0);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'houseId' => $this->houseId,
			'houseUserId' => $this->houseUserId,
			'version' => $this->version,
			'rankingConsentGeneration' => $this->rankingConsentGeneration,
			'rankingEffectiveMonday' => $this->rankingEffectiveMonday?->format('Y-m-d'),
			'rankingSavedZone' => $this->rankingSavedZone,
		];
	}
}
