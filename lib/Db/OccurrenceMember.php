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
 * Participant snapshot of one occurrence (CS-PLAN-072 WP1, parent §3.2).
 *
 * References the persistent WP0 wallet identity (survives departure), NOT
 * an active membership: stored facts only, never authorization. Erasure
 * purges these rows; departure retains them without access.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOccurrenceId()
 * @method void setOccurrenceId(int $occurrenceId)
 * @method int getWalletId()
 * @method void setWalletId(int $walletId)
 * @method string getRole()
 * @method void setRole(string $role)
 * @method bool getObligated()
 * @method void setObligated(bool $obligated)
 * @method int getSharePct()
 * @method void setSharePct(int $sharePct)
 * @method \DateTime|null getWorkDate()
 * @method void setWorkDate(?\DateTime $workDate)
 * @method string|null getWorkZone()
 * @method void setWorkZone(?string $workZone)
 * @method string getDisposition()
 * @method void setDisposition(string $disposition)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class OccurrenceMember extends Entity implements JsonSerializable {
	public const ROLE_RESPONSIBLE = 'responsible';
	public const ROLE_JOINT = 'joint';
	public const ROLE_HELPER = 'helper';

	public const ROLES = [
		self::ROLE_RESPONSIBLE,
		self::ROLE_JOINT,
		self::ROLE_HELPER,
	];

	public const DISPOSITION_ACTIVE = 'active';
	public const DISPOSITION_RELEASED = 'released';
	public const DISPOSITION_EXCUSED = 'excused';

	public const DISPOSITIONS = [
		self::DISPOSITION_ACTIVE,
		self::DISPOSITION_RELEASED,
		self::DISPOSITION_EXCUSED,
	];

	/** @var int */
	protected $occurrenceId;

	/** @var int Persistent wallet identity. */
	protected $walletId;

	/** @var string self::ROLE_* */
	protected $role = self::ROLE_RESPONSIBLE;

	/** @var bool */
	protected $obligated = true;

	/** @var int Agreed positive share percent. */
	protected $sharePct = 100;

	/** @var \DateTime|null Agreed work date when it differs from planned. */
	protected $workDate;

	/** @var string|null */
	protected $workZone;

	/** @var string self::DISPOSITION_* */
	protected $disposition = self::DISPOSITION_ACTIVE;

	/** @var \DateTime */
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('occurrenceId', 'integer');
		$this->addType('walletId', 'integer');
		$this->addType('role', 'string');
		$this->addType('obligated', 'boolean');
		$this->addType('sharePct', 'integer');
		$this->addType('workDate', 'date');
		$this->addType('workZone', 'string');
		$this->addType('disposition', 'string');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'walletId' => $this->walletId,
			'role' => $this->role,
			'obligated' => $this->obligated,
			'sharePct' => $this->sharePct,
			'workDate' => $this->workDate?->format('Y-m-d'),
			'workZone' => $this->workZone,
			'disposition' => $this->disposition,
		];
	}
}
