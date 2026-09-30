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
 * One digest delivery slot (CS-PLAN-076 §3.2, C5a/b/c): the daily-budget
 * identity — at most one summary per member and house-local day,
 * independent of the opt-in toggles so retained history enforces the
 * 24-elapsed-hour zone-change spacing (C5k) and an off/on toggle never
 * creates a new allowance.
 *
 * `scheduled_utc` is the member's `digest_time` resolved in the SAVED
 * house zone (DST gap → first valid later instant, fold → first
 * occurrence); `window_end_utc` is the earlier of scheduled + 60 elapsed
 * minutes and local day-end (C5c) — a missed slot expires permanently,
 * never catching up the next day.
 *
 * At-most-once (parent §3.6): the claim is the conditional
 * pending → dispatching status write under the house lock; a slot that
 * ends `uncertain` or `dispatching` after a crash is consumed and NEVER
 * resent.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getHouseId()
 * @method void setHouseId(int $houseId)
 * @method int getHouseUserId()
 * @method void setHouseUserId(int $houseUserId)
 * @method string getLocalDay()
 * @method void setLocalDay(string $localDay)
 * @method string getZone()
 * @method void setZone(string $zone)
 * @method int getPrefRevision()
 * @method void setPrefRevision(int $prefRevision)
 * @method \DateTime getScheduledUtc()
 * @method void setScheduledUtc(\DateTime $scheduledUtc)
 * @method \DateTime getWindowEndUtc()
 * @method void setWindowEndUtc(\DateTime $windowEndUtc)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getDispatchToken()
 * @method void setDispatchToken(?string $dispatchToken)
 * @method \DateTime|null getDispatchedAt()
 * @method void setDispatchedAt(?\DateTime $dispatchedAt)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class DigestSlot extends Entity implements JsonSerializable {
	public const STATUS_PENDING = 'pending';
	public const STATUS_DISPATCHING = 'dispatching';
	public const STATUS_SENT = 'sent';
	public const STATUS_SKIPPED = 'skipped';
	public const STATUS_UNCERTAIN = 'uncertain';

	/** @var int */
	protected $houseId;

	/** @var int */
	protected $houseUserId;

	/** @var string House-local ISO day (`Y-m-d`) — the daily budget key. */
	protected $localDay = '';

	/** @var string IANA zone the schedule was resolved in. */
	protected $zone = '';

	/** @var int The member_prefs `version` the slot was resolved with. */
	protected $prefRevision = 0;

	/** @var \DateTime Resolved schedule instant (UTC-normalized). */
	protected $scheduledUtc;

	/** @var \DateTime Dispatch window end (UTC-normalized). */
	protected $windowEndUtc;

	/** @var string pending | dispatching | sent | skipped | uncertain */
	protected $status = self::STATUS_PENDING;

	/** @var string|null Claim token of the dispatching worker. */
	protected $dispatchToken = null;

	/** @var \DateTime|null When the dispatch attempt happened. */
	protected $dispatchedAt = null;

	/** @var \DateTime */
	protected $createdAt;

	/** @var \DateTime */
	protected $updatedAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('houseId', 'integer');
		$this->addType('houseUserId', 'integer');
		$this->addType('localDay', 'string');
		$this->addType('zone', 'string');
		$this->addType('prefRevision', 'integer');
		$this->addType('scheduledUtc', 'datetime');
		$this->addType('windowEndUtc', 'datetime');
		$this->addType('status', 'string');
		$this->addType('dispatchToken', 'string');
		$this->addType('dispatchedAt', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	public function isPending(): bool {
		return $this->getStatus() === self::STATUS_PENDING;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'houseId' => $this->houseId,
			'houseUserId' => $this->houseUserId,
			'localDay' => $this->localDay,
			'zone' => $this->zone,
			'prefRevision' => $this->prefRevision,
			'scheduledUtc' => $this->scheduledUtc?->format('c'),
			'windowEndUtc' => $this->windowEndUtc?->format('c'),
			'status' => $this->status,
		];
	}
}
