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
 * Append-only completion audit row (CS-PLAN-070 WP3 §3.1).
 *
 * Every mutation of a completion (initial completion, attribution
 * correction, undo) records one immutable revision. The audit revision
 * matches the completion version (initial 0); the allocation revision
 * points at the selected `work_shares` revision (NULL on undo — the undo
 * invalidates the selected allocation through the completion's
 * `undone_at` and writes no new shares).
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getCompletionId()
 * @method void setCompletionId(int $completionId)
 * @method int getRevision()
 * @method void setRevision(int $revision)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method int|null getAllocationRevision()
 * @method void setAllocationRevision(?int $allocationRevision)
 * @method int|null getActorHouseUserId()
 * @method void setActorHouseUserId(?int $actorHouseUserId)
 * @method string|null getReasonCode()
 * @method void setReasonCode(?string $reasonCode)
 * @method string getCommandUuid()
 * @method void setCommandUuid(string $commandUuid)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class CompletionRevision extends Entity implements JsonSerializable {
	public const ACTION_COMPLETE = 'complete';
	public const ACTION_ATTRIBUTION = 'attribution';
	public const ACTION_UNDO = 'undo';

	public const ACTIONS = [
		self::ACTION_COMPLETE,
		self::ACTION_ATTRIBUTION,
		self::ACTION_UNDO,
	];

	/** @var int */
	protected $completionId;

	/**
	 * NOTE: no property default — the OCP Entity setter skips marking a
	 * field updated when the value equals the property default, silently
	 * dropping the column from the INSERT (the initial revision IS 0).
	 * @var int
	 */
	protected $revision;

	/** @var string self::ACTION_* */
	protected $action;

	/** @var int|null */
	protected $allocationRevision;

	/** @var int|null Nullable: erased actors lose the reference. */
	protected $actorHouseUserId;

	/** @var string|null Bounded caller-supplied reason code. */
	protected $reasonCode;

	/** @var string */
	protected $commandUuid;

	/** @var \DateTime */
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('completionId', 'integer');
		$this->addType('revision', 'integer');
		$this->addType('action', 'string');
		$this->addType('allocationRevision', 'integer');
		$this->addType('actorHouseUserId', 'integer');
		$this->addType('reasonCode', 'string');
		$this->addType('commandUuid', 'string');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'completionId' => $this->completionId,
			'revision' => $this->revision,
			'action' => $this->action,
			'allocationRevision' => $this->allocationRevision,
			'actorHouseUserId' => $this->actorHouseUserId,
			'reasonCode' => $this->reasonCode,
			'commandUuid' => $this->commandUuid,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}
}
