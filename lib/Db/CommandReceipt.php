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
 * Command receipt (CS-PLAN-071 WP0, parent §3.2/§3.5): the idempotency
 * record of one house-scoped mutating command.
 *
 * One row per (house, actor, command UUID). An identical normalized payload
 * replays the stored result identity with a replay marker; a changed
 * payload is a 409 conflict. The row commits only together with all domain
 * facts of the command. Never stores tokens or raw request bodies — only
 * the payload hash and the bounded result identity.
 *
 * No production endpoint adopts `commandId` in WP0 (first consumers: WP2
 * scheduling apply, WP3 completion — parent §3.4); T0 exercises the
 * {@see \OCA\ChoreStrike\Service\CommandService} directly (decision D6).
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getHouseId()
 * @method void setHouseId(int $houseId)
 * @method int|null getActorHouseUserId()
 * @method void setActorHouseUserId(?int $actorHouseUserId)
 * @method string getCommandUuid()
 * @method void setCommandUuid(string $commandUuid)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method string getPayloadHash()
 * @method void setPayloadHash(string $payloadHash)
 * @method array|null getResultRefs()
 * @method void setResultRefs(?array $resultRefs)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class CommandReceipt extends Entity implements JsonSerializable {
	public const STATUS_COMMITTED = 'committed';

	/** @var int */
	protected $houseId;

	/** @var int|null Nulled by account erasure; the receipt row survives. */
	protected $actorHouseUserId;

	/** @var string */
	protected $commandUuid;

	/** @var string */
	protected $action;

	/** @var string SHA-256 hex digest of the normalized payload. */
	protected $payloadHash;

	/** @var array|null Bounded JSON result identity. */
	protected $resultRefs;

	/** @var string */
	protected $status = self::STATUS_COMMITTED;

	/** @var \DateTime */
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('houseId', 'integer');
		$this->addType('actorHouseUserId', 'integer');
		$this->addType('commandUuid', 'string');
		$this->addType('action', 'string');
		$this->addType('payloadHash', 'string');
		$this->addType('resultRefs', 'json');
		$this->addType('status', 'string');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'houseId' => $this->houseId,
			'actorHouseUserId' => $this->actorHouseUserId,
			'commandUuid' => $this->commandUuid,
			'action' => $this->action,
			'status' => $this->status,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}
}
