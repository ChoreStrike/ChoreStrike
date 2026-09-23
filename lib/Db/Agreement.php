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
 * Agreement evidence row of one occurrence (CS-PLAN-072 WP1, parent §3.2;
 * moved into WP1 by Q-072-01 Option A). Traceable facts, never a second
 * current-state authority: the occurrence row remains the state, this log
 * records how an agreement changed (date/window/handover/share/catch-up/
 * restart/pause) with old/new snapshots.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOccurrenceId()
 * @method void setOccurrenceId(int $occurrenceId)
 * @method int getRevision()
 * @method void setRevision(int $revision)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method int|null getActorHouseUserId()
 * @method void setActorHouseUserId(?int $actorHouseUserId)
 * @method array|null getAffectedWalletIds()
 * @method void setAffectedWalletIds(?array $affectedWalletIds)
 * @method array|null getOldState()
 * @method void setOldState(?array $oldState)
 * @method array|null getNewState()
 * @method void setNewState(?array $newState)
 * @method int|null getPauseRef()
 * @method void setPauseRef(?int $pauseRef)
 * @method array|null getConsent()
 * @method void setConsent(?array $consent)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Agreement extends Entity implements JsonSerializable {
	public const KIND_DATE = 'date';
	public const KIND_WINDOW = 'window';
	public const KIND_HANDOVER = 'handover';
	public const KIND_SHARE = 'share';
	public const KIND_CATCHUP = 'catchup';
	public const KIND_RESTART = 'restart';
	public const KIND_PAUSE = 'pause';

	public const KINDS = [
		self::KIND_DATE,
		self::KIND_WINDOW,
		self::KIND_HANDOVER,
		self::KIND_SHARE,
		self::KIND_CATCHUP,
		self::KIND_RESTART,
		self::KIND_PAUSE,
	];

	/** @var int */
	protected $occurrenceId;

	/** @var int Monotonic per-occurrence revision, starting at 1. */
	protected $revision = 1;

	/** @var string self::KIND_* */
	protected $kind;

	/** @var int|null Acting membership; NULL after erasure (facts stay). */
	protected $actorHouseUserId;

	/** @var array|null Bounded wallet-id list of affected people. */
	protected $affectedWalletIds;

	/** @var array|null Bounded old-state snapshot. */
	protected $oldState;

	/** @var array|null Bounded new-state snapshot. */
	protected $newState;

	/** @var int|null Referenced pause group (WP2). */
	protected $pauseRef;

	/** @var array|null Recorded consent facts. */
	protected $consent;

	/** @var \DateTime */
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('occurrenceId', 'integer');
		$this->addType('revision', 'integer');
		$this->addType('kind', 'string');
		$this->addType('actorHouseUserId', 'integer');
		$this->addType('affectedWalletIds', 'json');
		$this->addType('oldState', 'json');
		$this->addType('newState', 'json');
		$this->addType('pauseRef', 'integer');
		$this->addType('consent', 'json');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'occurrenceId' => $this->occurrenceId,
			'revision' => $this->revision,
			'kind' => $this->kind,
			'affectedWalletIds' => $this->affectedWalletIds ?? [],
			'createdAt' => $this->createdAt?->format('c'),
		];
	}
}
