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
 * Per-member preference row (CS-PLAN-070 WP4, Q-075-01 Option A; WP5
 * CS-PLAN-076 delivery extension, Q-076-03).
 *
 * ONE shared row per active membership carries the RANKING-CONSENT block
 * and the C5h DELIVERY block (digest/quiet-hours/sound/optional-area).
 * Nothing here is a ranking authority on its own — RankingService
 * interprets the generation facts against the house flag; PreferenceService
 * owns the delivery fields. Every write bumps the shared `version` under
 * the house lock (stale drafts answer 409).
 *
 * Consent lifecycle (Q-076-03 A): ranking consent/re-consent updates the
 * row in place (or inserts it when absent); withdraw and house ranking
 * disable RESET ONLY the consent columns (generation 0, Monday/zone
 * cleared) — delivery preferences survive both. The row is deleted only
 * on membership removal and account erasure; a rejoining account starts
 * quiet/unconsented. A row with generation 0 (and NULL Monday/zone) is
 * ranking-inactive: ranking reads filter generation > 0 before using the
 * effective-date/zone facts.
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
 * @method \DateTime|null getRankingEffectiveMonday()
 * @method void setRankingEffectiveMonday(?\DateTime $monday)
 * @method string|null getRankingSavedZone()
 * @method void setRankingSavedZone(?string $zone)
 * @method bool getTaskDigestEnabled()
 * @method void setTaskDigestEnabled(bool $enabled)
 * @method bool getOrgDigestEnabled()
 * @method void setOrgDigestEnabled(bool $enabled)
 * @method string getDigestTime()
 * @method void setDigestTime(string $digestTime)
 * @method string|null getQuietStart()
 * @method void setQuietStart(?string $quietStart)
 * @method string|null getQuietEnd()
 * @method void setQuietEnd(?string $quietEnd)
 * @method bool getSoundEnabled()
 * @method void setSoundEnabled(bool $enabled)
 * @method bool getAnimationEnabled()
 * @method void setAnimationEnabled(bool $enabled)
 * @method bool getArcadeVisible()
 * @method void setArcadeVisible(bool $visible)
 * @method bool getCatalogVisible()
 * @method void setCatalogVisible(bool $visible)
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
	 * Shared optimistic mutation counter — bumped by EVERY
	 * preference/consent mutation under the house lock. Neutral default:
	 * the Nextcloud Entity setter only marks a field updated when the
	 * value CHANGES, so defaults must never equal a realistic persisted
	 * value.
	 *
	 * @var int
	 */
	protected $version = 0;

	/**
	 * Monotonic consent-generation identity. Never reused: a withdrawn
	 * generation stays invalidated even after later rows. 0 = no live
	 * consent (ranking-inactive row); real generations start at 1.
	 *
	 * @var int
	 */
	protected $rankingConsentGeneration = 0;

	/** @var \DateTime|null The saved next-Monday activation date (date part); null while ranking-inactive. */
	protected $rankingEffectiveMonday = null;

	/**
	 * Saved IANA zone of the activation boundary — the generation activates
	 * at this zone's Monday 00:00 even when the house zone changes later.
	 * Neutral default '' — never a valid IANA id, so the explicit setter
	 * always marks the field for the INSERT.
	 *
	 * @var string|null
	 */
	protected $rankingSavedZone = '';

	/** @var bool C5h: opt-in to the daily task summary. */
	protected $taskDigestEnabled = false;

	/** @var bool C5e: opt-in to organizational updates in the summary. */
	protected $orgDigestEnabled = false;

	/**
	 * Daily summary time, `HH:mm` house-local. Neutral default '' — the
	 * DB column default '18:00' applies to rows inserted without an
	 * explicit value.
	 *
	 * @var string
	 */
	protected $digestTime = '';

	/** @var string|null Personal quiet-hours start (`HH:mm`), may cross midnight. */
	protected $quietStart = null;

	/** @var string|null Personal quiet-hours end (`HH:mm`). */
	protected $quietEnd = null;

	/** @var bool Persistence only until WP6 (sound.js consumer). */
	protected $soundEnabled = false;

	/** @var bool Persistence only until WP6 (confetti.js consumer). */
	protected $animationEnabled = false;

	/** @var bool Optional-area persistence; presentation arrives in WP6. */
	protected $arcadeVisible = true;

	/** @var bool Optional-area persistence; presentation arrives in WP6. */
	protected $catalogVisible = true;

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
		$this->addType('taskDigestEnabled', 'boolean');
		$this->addType('orgDigestEnabled', 'boolean');
		$this->addType('digestTime', 'string');
		$this->addType('quietStart', 'string');
		$this->addType('quietEnd', 'string');
		$this->addType('soundEnabled', 'boolean');
		$this->addType('animationEnabled', 'boolean');
		$this->addType('arcadeVisible', 'boolean');
		$this->addType('catalogVisible', 'boolean');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	/**
	 * Whether a live consent generation is effective at `$now` (the saved
	 * Monday 00:00 in the saved zone has passed). Ranking-inactive rows
	 * (generation 0 / NULL facts) are never effective.
	 */
	public function isEffectiveAt(\DateTime $now): bool {
		$monday = $this->getRankingEffectiveMonday();
		if ($this->getRankingConsentGeneration() <= 0 || $monday === null || $this->getRankingSavedZone() === null) {
			return false;
		}
		$effective = $this->effectiveInstant();
		return $now->getTimestamp() >= $effective->getTimestamp();
	}

	/**
	 * The activation instant: saved-zone Monday 00:00 rendered as a UTC
	 * instant. Only valid on a consented row (generation > 0 with the
	 * saved facts present).
	 */
	public function effectiveInstant(): \DateTimeImmutable {
		$zone = new \DateTimeZone((string)$this->getRankingSavedZone());
		$monday = $this->getRankingEffectiveMonday();
		$local = \DateTimeImmutable::createFromFormat('!Y-m-d', $monday !== null ? $monday->format('Y-m-d') : '', $zone);
		return $local->setTime(0, 0, 0);
	}

	/**
	 * Reset ONLY the ranking-consent columns (withdraw / house ranking
	 * disable): delivery preferences survive (Q-076-03 A). The caller
	 * bumps the shared version.
	 */
	public function clearRankingConsent(): void {
		$this->setRankingConsentGeneration(0);
		$this->setRankingEffectiveMonday(null);
		$this->setRankingSavedZone(null);
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
			'taskDigestEnabled' => $this->taskDigestEnabled,
			'orgDigestEnabled' => $this->orgDigestEnabled,
			'digestTime' => $this->digestTime,
			'quietStart' => $this->quietStart,
			'quietEnd' => $this->quietEnd,
			'soundEnabled' => $this->soundEnabled,
			'animationEnabled' => $this->animationEnabled,
			'arcadeVisible' => $this->arcadeVisible,
			'catalogVisible' => $this->catalogVisible,
		];
	}
}
