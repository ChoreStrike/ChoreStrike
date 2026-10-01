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
 * One family reward request under the approved C4 lifecycle (CS-PLAN-070
 * parent §3.2; CS-PLAN-078 §3.1/§3.2). The frozen title/description/price
 * snapshot is immutable once created — live catalog changes never reprice
 * an existing request. The requester WALLET is the stable actor provenance
 * that survives departure; no raw UID/display-name snapshot is stored.
 *
 * Funding hold is NOT stored state: it is derived per wallet from
 * `bookedMinor < reservedMinor` (orthogonal to the state machine). A
 * pending/approved request reserves exactly its immutable positive price
 * (mirrored in `wallets.reserved_minor` in the same transaction).
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getHouseId()
 * @method void setHouseId(int $houseId)
 * @method int getWalletId()
 * @method void setWalletId(int $walletId)
 * @method int getItemId()
 * @method void setItemId(int $itemId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method int getPriceMinor()
 * @method void setPriceMinor(int $priceMinor)
 * @method string getState()
 * @method void setState(string $state)
 * @method int getVersion()
 * @method void setVersion(int $version)
 * @method int|null getApprovedRoleRevision()
 * @method void setApprovedRoleRevision(?int $approvedRoleRevision)
 * @method int|null getApprovedByHouseUserId()
 * @method void setApprovedByHouseUserId(?int $approvedByHouseUserId)
 * @method int|null getApprovedByWalletId()
 * @method void setApprovedByWalletId(?int $approvedByWalletId)
 * @method \DateTime|null getApprovedAt()
 * @method void setApprovedAt(?\DateTime $approvedAt)
 * @method int|null getSettlementEntryId()
 * @method void setSettlementEntryId(?int $settlementEntryId)
 * @method int|null getRefundEntryId()
 * @method void setRefundEntryId(?int $refundEntryId)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class RewardRequest extends Entity implements JsonSerializable {
	/** Awaiting owner approval (child request) — reservation held. */
	public const STATE_PENDING = 'pending';
	/** Approved — reservation held, no debit yet. */
	public const STATE_APPROVED = 'approved';
	/** Terminal: settled exactly once (original debit stored). */
	public const STATE_FULFILLED = 'fulfilled';
	/** Terminal: rejected by the owner — reservation released. */
	public const STATE_REJECTED = 'rejected';
	/** Terminal: cancelled by requester/owner — reservation released. */
	public const STATE_CANCELLED = 'cancelled';
	/** Terminal: owner-attested false fulfillment refunded exactly once. */
	public const STATE_CORRECTED = 'corrected';

	public const STATES_UNFULFILLED = [self::STATE_PENDING, self::STATE_APPROVED];
	public const STATES_TERMINAL = [self::STATE_FULFILLED, self::STATE_REJECTED, self::STATE_CANCELLED, self::STATE_CORRECTED];

	/** @var int */
	protected $houseId;

	/** @var int Requester wallet (stable identity, survives departure). */
	protected $walletId;

	/** @var int */
	protected $itemId;

	/** @var string */
	protected $title;

	/** @var string|null */
	protected $description;

	/** @var int Frozen positive minor units; set explicitly on insert. */
	protected $priceMinor;

	/** @var string */
	protected $state;

	/** @var int Audit/effect revision (creation event = revision 0). */
	protected $version;

	/** @var int|null Requester's role revision at approval time. */
	protected $approvedRoleRevision;

	/** @var int|null */
	protected $approvedByHouseUserId;

	/** @var int|null */
	protected $approvedByWalletId;

	/** @var \DateTime|null */
	protected $approvedAt;

	/** @var int|null */
	protected $settlementEntryId;

	/** @var int|null */
	protected $refundEntryId;

	/** @var \DateTime */
	protected $createdAt;

	/** @var \DateTime */
	protected $updatedAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('houseId', 'integer');
		$this->addType('walletId', 'integer');
		$this->addType('itemId', 'integer');
		$this->addType('title', 'string');
		$this->addType('description', 'string');
		$this->addType('priceMinor', 'integer');
		$this->addType('state', 'string');
		$this->addType('version', 'integer');
		$this->addType('approvedRoleRevision', 'integer');
		$this->addType('approvedByHouseUserId', 'integer');
		$this->addType('approvedByWalletId', 'integer');
		$this->addType('approvedAt', 'datetime');
		$this->addType('settlementEntryId', 'integer');
		$this->addType('refundEntryId', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	public function isUnfulfilled(): bool {
		return in_array($this->state, self::STATES_UNFULFILLED, true);
	}

	public function isTerminal(): bool {
		return in_array($this->state, self::STATES_TERMINAL, true);
	}

	/**
	 * The administrative core of the request DTO (viewer-specific fields
	 * such as allowedActions/wallet are added by the service). The stored
	 * settlement/refund ledger references ride back so a correction can
	 * verify against the SERVER's entry.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => (int)$this->id,
			'itemId' => (int)$this->itemId,
			'title' => $this->title,
			'description' => $this->description,
			'priceMinor' => $this->priceMinor,
			'state' => $this->state,
			'version' => $this->version,
			'settlementEntryId' => $this->settlementEntryId,
			'refundEntryId' => $this->refundEntryId,
			'createdAt' => $this->createdAt?->format('c'),
			'updatedAt' => $this->updatedAt?->format('c'),
		];
	}
}
