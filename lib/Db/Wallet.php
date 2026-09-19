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
 * Persistent house/account financial identity (CS-PLAN-071 WP0, parent
 * §3.2; decision D1). Created with membership, preserved on departure,
 * reattached on re-invitation via (house_id, nextcloud_user_id), deleted
 * only by account erasure.
 *
 * WP0 boundary: identity only. Balances stay dormant zeros, no API exposes
 * the entity and `wallet_entries` has no runtime writer before WP3's single
 * economy cutover; `house_users.coins` and the existing reward writers stay
 * authoritative (decision D1/D2).
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getHouseId()
 * @method void setHouseId(int $houseId)
 * @method string getNextcloudUserId()
 * @method void setNextcloudUserId(string $nextcloudUserId)
 * @method int getBookedMinor()
 * @method void setBookedMinor(int $bookedMinor)
 * @method int getReservedMinor()
 * @method void setReservedMinor(int $reservedMinor)
 * @method int getVersion()
 * @method void setVersion(int $version)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class Wallet extends Entity implements JsonSerializable {
	/** @var int */
	protected $houseId;

	/** @var string */
	protected $nextcloudUserId;

	/** @var int Signed minor units (dormant 0 in WP0). */
	protected $bookedMinor = 0;

	/** @var int Reserved minor units, >= 0 enforced service-side. */
	protected $reservedMinor = 0;

	/** @var int Optimistic-concurrency version. */
	protected $version = 0;

	/** @var \DateTime */
	protected $createdAt;

	/** @var \DateTime */
	protected $updatedAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('houseId', 'integer');
		$this->addType('nextcloudUserId', 'string');
		$this->addType('bookedMinor', 'integer');
		$this->addType('reservedMinor', 'integer');
		$this->addType('version', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	/**
	 * JSON serialization is deliberately unused by any API surface in WP0
	 * (identity must not leak through any endpoint); implemented only for
	 * the JsonSerializable contract and tests.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'houseId' => $this->houseId,
			'nextcloudUserId' => $this->nextcloudUserId,
			'bookedMinor' => $this->bookedMinor,
			'reservedMinor' => $this->reservedMinor,
			'version' => $this->version,
			'createdAt' => $this->createdAt?->format('c'),
			'updatedAt' => $this->updatedAt?->format('c'),
		];
	}
}
