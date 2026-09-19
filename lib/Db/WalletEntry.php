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
 * Immutable wallet ledger entry (CS-PLAN-071 WP0 schema, parent §3.2).
 *
 * No runtime writer exists before WP3's single economy cutover (decision
 * D1); the table and its unique (wallet, source-kind, source-id, revision)
 * idempotency key are frozen by WP0.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getWalletId()
 * @method void setWalletId(int $walletId)
 * @method string getSourceKind()
 * @method void setSourceKind(string $sourceKind)
 * @method int getSourceId()
 * @method void setSourceId(int $sourceId)
 * @method int getSourceRevision()
 * @method void setSourceRevision(int $sourceRevision)
 * @method int getDeltaMinor()
 * @method void setDeltaMinor(int $deltaMinor)
 * @method string getReasonCode()
 * @method void setReasonCode(string $reasonCode)
 * @method int|null getActorHouseUserId()
 * @method void setActorHouseUserId(?int $actorHouseUserId)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class WalletEntry extends Entity implements JsonSerializable {
	/** @var int */
	protected $walletId;

	/** @var string */
	protected $sourceKind;

	/** @var int */
	protected $sourceId;

	/** @var int */
	protected $sourceRevision = 0;

	/** @var int Signed minor-unit delta. */
	protected $deltaMinor = 0;

	/** @var string */
	protected $reasonCode;

	/** @var int|null */
	protected $actorHouseUserId;

	/** @var \DateTime */
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('walletId', 'integer');
		$this->addType('sourceKind', 'string');
		$this->addType('sourceId', 'integer');
		$this->addType('sourceRevision', 'integer');
		$this->addType('deltaMinor', 'integer');
		$this->addType('reasonCode', 'string');
		$this->addType('actorHouseUserId', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'walletId' => $this->walletId,
			'sourceKind' => $this->sourceKind,
			'sourceId' => $this->sourceId,
			'sourceRevision' => $this->sourceRevision,
			'deltaMinor' => $this->deltaMinor,
			'reasonCode' => $this->reasonCode,
			'actorHouseUserId' => $this->actorHouseUserId,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}
}
