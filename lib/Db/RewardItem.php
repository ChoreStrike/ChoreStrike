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
 * One owner-administered catalog wish (CS-PLAN-070 WP7, CS-PLAN-078 §3.1).
 *
 * Title/description are family-authored CONTENT (never translation keys —
 * the presentation layer escapes them). `priceMinor` is an exact integer
 * hundredths amount (1250 = 12.50 Coins); positive, service-validated.
 * Promised terms are archived instead of deleted once a request exists.
 * The nullable creator references are actor-wallet provenance for erasure.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getHouseId()
 * @method void setHouseId(int $houseId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method int getPriceMinor()
 * @method void setPriceMinor(int $priceMinor)
 * @method bool getArchived()
 * @method void setArchived(bool $archived)
 * @method int getVersion()
 * @method void setVersion(int $version)
 * @method int|null getCreatedByHouseUserId()
 * @method void setCreatedByHouseUserId(?int $createdByHouseUserId)
 * @method int|null getCreatedByWalletId()
 * @method void setCreatedByWalletId(?int $createdByWalletId)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class RewardItem extends Entity implements JsonSerializable {
	/** @var int */
	protected $houseId;

	/** @var string */
	protected $title;

	/** @var string|null */
	protected $description;

	/** @var int Positive minor units (hundredths); set explicitly on insert. */
	protected $priceMinor;

	/** @var bool Archived items keep their promised request terms alive. */
	protected $archived;

	/** @var int Optimistic-concurrency version (starts at 0). */
	protected $version;

	/** @var int|null */
	protected $createdByHouseUserId;

	/** @var int|null */
	protected $createdByWalletId;

	/** @var \DateTime */
	protected $createdAt;

	/** @var \DateTime */
	protected $updatedAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('houseId', 'integer');
		$this->addType('title', 'string');
		$this->addType('description', 'string');
		$this->addType('priceMinor', 'integer');
		$this->addType('archived', 'boolean');
		$this->addType('version', 'integer');
		$this->addType('createdByHouseUserId', 'integer');
		$this->addType('createdByWalletId', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	/**
	 * The viewer-safe catalog DTO: item ID, terms, price, archive state
	 * and version — no actor references.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => (int)$this->id,
			'title' => $this->title,
			'description' => $this->description,
			'priceMinor' => $this->priceMinor,
			'archived' => $this->archived,
			'version' => $this->version,
		];
	}
}
