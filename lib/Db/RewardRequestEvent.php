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
 * One append-only audit event of a reward request (CS-PLAN-070 WP7,
 * CS-PLAN-078 §3.1). Exactly one event per (request, revision); creation
 * is revision 0, every subsequent effect bumps the request version and
 * appends the matching revision. Retained until authorized privacy
 * erasure — never rewritten.
 *
 * Actor references are the nullable membership/wallet pair (actor-wallet
 * provenance keeps erasure working after the membership ended). Reason
 * codes are bounded closed-set values, never personal free text.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getRequestId()
 * @method void setRequestId(int $requestId)
 * @method int getRevision()
 * @method void setRevision(int $revision)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method string|null getOldState()
 * @method void setOldState(?string $oldState)
 * @method string getNewState()
 * @method void setNewState(string $newState)
 * @method int|null getActorHouseUserId()
 * @method void setActorHouseUserId(?int $actorHouseUserId)
 * @method int|null getActorWalletId()
 * @method void setActorWalletId(?int $actorWalletId)
 * @method int|null getOldRoleRevision()
 * @method void setOldRoleRevision(?int $oldRoleRevision)
 * @method int|null getNewRoleRevision()
 * @method void setNewRoleRevision(?int $newRoleRevision)
 * @method string|null getReasonCode()
 * @method void setReasonCode(?string $reasonCode)
 * @method int|null getFinancialEntryId()
 * @method void setFinancialEntryId(?int $financialEntryId)
 * @method string|null getSourceCommandUuid()
 * @method void setSourceCommandUuid(?string $sourceCommandUuid)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class RewardRequestEvent extends Entity implements JsonSerializable {
	public const ACTION_CREATE = 'create';
	public const ACTION_APPROVE = 'approve';
	public const ACTION_REJECT = 'reject';
	public const ACTION_CANCEL = 'cancel';
	public const ACTION_FULFILL = 'fulfill';
	public const ACTION_CORRECT = 'correct';
	/** Requester role changed; approved requests rebase to pending when the requester became a child. */
	public const ACTION_ROLE_CHANGE = 'role_change';
	/** Owner removal aggregate cancelled the unfulfilled request before access ended. */
	public const ACTION_DEPARTURE_CANCEL = 'departure_cancel';

	public const ACTIONS = [
		self::ACTION_CREATE,
		self::ACTION_APPROVE,
		self::ACTION_REJECT,
		self::ACTION_CANCEL,
		self::ACTION_FULFILL,
		self::ACTION_CORRECT,
		self::ACTION_ROLE_CHANGE,
		self::ACTION_DEPARTURE_CANCEL,
	];

	/** @var int */
	protected $requestId;

	/** @var int Matches the request version after the effect; set explicitly on insert. */
	protected $revision;

	/** @var string */
	protected $action;

	/** @var string|null */
	protected $oldState;

	/** @var string */
	protected $newState;

	/** @var int|null */
	protected $actorHouseUserId;

	/** @var int|null */
	protected $actorWalletId;

	/** @var int|null */
	protected $oldRoleRevision;

	/** @var int|null */
	protected $newRoleRevision;

	/** @var string|null Closed-set reason code. */
	protected $reasonCode;

	/** @var int|null Settlement/refund ledger entry produced by this event. */
	protected $financialEntryId;

	/** @var string|null */
	protected $sourceCommandUuid;

	/** @var \DateTime */
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('requestId', 'integer');
		$this->addType('revision', 'integer');
		$this->addType('action', 'string');
		$this->addType('oldState', 'string');
		$this->addType('newState', 'string');
		$this->addType('actorHouseUserId', 'integer');
		$this->addType('actorWalletId', 'integer');
		$this->addType('oldRoleRevision', 'integer');
		$this->addType('newRoleRevision', 'integer');
		$this->addType('reasonCode', 'string');
		$this->addType('financialEntryId', 'integer');
		$this->addType('sourceCommandUuid', 'string');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'id' => (int)$this->id,
			'requestId' => (int)$this->requestId,
			'revision' => $this->revision,
			'action' => $this->action,
			'oldState' => $this->oldState,
			'newState' => $this->newState,
			'actorHouseUserId' => $this->actorHouseUserId,
			'oldRoleRevision' => $this->oldRoleRevision,
			'newRoleRevision' => $this->newRoleRevision,
			'reasonCode' => $this->reasonCode,
			'financialEntryId' => $this->financialEntryId,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}
}
