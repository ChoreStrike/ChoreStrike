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
 * Immutable work allocation evidence (CS-PLAN-070 WP3 §3.1).
 *
 * One row per participant share of one allocation revision of one
 * completion. Rows are never mutated: a correction inserts a NEW revision
 * and the completion's `allocation_revision` pointer selects the counted
 * one; old revisions remain evidence and are never counted again.
 *
 * Exact integer hundredths: `energyMinor = totalEnergy * sharePct`,
 * `coinMinor = 10 * totalEnergy * sharePct` (frozen rate 10 Coins per
 * energy). A valid completion's selected shares total exactly
 * `100 * totalEnergy` energy minor and `1000 * totalEnergy` coin minor.
 *
 * `walletId` is nullable: an erased subject keeps only the non-identifying
 * shared work facts.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getCompletionId()
 * @method void setCompletionId(int $completionId)
 * @method int getAllocationRevision()
 * @method void setAllocationRevision(int $allocationRevision)
 * @method int|null getWalletId()
 * @method void setWalletId(?int $walletId)
 * @method int getSharePct()
 * @method void setSharePct(int $sharePct)
 * @method int getEnergyMinor()
 * @method void setEnergyMinor(int $energyMinor)
 * @method int getCoinMinor()
 * @method void setCoinMinor(int $coinMinor)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class WorkShare extends Entity implements JsonSerializable {
	/** @var int */
	protected $completionId;

	/**
	 * NOTE: no property defaults here — the OCP Entity setter skips
	 * marking a field updated when the value equals the property default,
	 * which silently drops the column from the INSERT (a raw default
	 * collides with the always-written revision 1 / share 100).
	 * @var int
	 */
	protected $allocationRevision;

	/** @var int|null */
	protected $walletId;

	/** @var int Positive whole percent (1-100). */
	protected $sharePct;

	/** @var int Exact hundredths of energy. */
	protected $energyMinor = 0;

	/** @var int Exact hundredths of Coins. */
	protected $coinMinor = 0;

	/** @var \DateTime */
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('completionId', 'integer');
		$this->addType('allocationRevision', 'integer');
		$this->addType('walletId', 'integer');
		$this->addType('sharePct', 'integer');
		$this->addType('energyMinor', 'integer');
		$this->addType('coinMinor', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'completionId' => $this->completionId,
			'allocationRevision' => $this->allocationRevision,
			'walletId' => $this->walletId,
			'sharePct' => $this->sharePct,
			'energyMinor' => $this->energyMinor,
			'coinMinor' => $this->coinMinor,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}
}
