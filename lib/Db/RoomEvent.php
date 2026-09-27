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
 * Room history journal event (CS-PLAN-070 WP3 §3.5).
 *
 * Every room-state mutation records ONE event with the REQUESTED effect
 * (not an absolute value, not the observed clamped delta):
 *
 * - `completion`: the frozen per-completion room effect
 *   (`deltaCleanliness = totalEnergy * 10`), independent of the number of
 *   actual participants; undo invalidates the effect (the completion's
 *   `undone_at`), attribution never changes it.
 * - `manual_delta`: a requested cleanliness delta
 *   ({@see RoomService::updateRoomCleanliness}).
 * - `reset`: an absolute reset (cleanliness 100, monsters 0,
 *   last_cleaned cleared).
 * - `baseline`: the creation/setup seed baseline of the room.
 *
 * Replay order is `(effective_at, id)`; correction execution time must
 * never become `last_cleaned` (the completion event freezes its own
 * timestamp behavior — only completion-kind events touch `last_cleaned`).
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getRoomId()
 * @method void setRoomId(int $roomId)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method \DateTime getEffectiveAt()
 * @method void setEffectiveAt(\DateTime $effectiveAt)
 * @method string getStableId()
 * @method void setStableId(string $stableId)
 * @method int|null getSourceCompletionId()
 * @method void setSourceCompletionId(?int $sourceCompletionId)
 * @method int|null getDeltaCleanliness()
 * @method void setDeltaCleanliness(?int $deltaCleanliness)
 * @method int|null getBaselineCleanliness()
 * @method void setBaselineCleanliness(?int $baselineCleanliness)
 * @method int|null getBaselineMonsters()
 * @method void setBaselineMonsters(?int $baselineMonsters)
 * @method bool getClearsLastCleaned()
 * @method void setClearsLastCleaned(bool $clearsLastCleaned)
 * @method string|null getCommandUuid()
 * @method void setCommandUuid(?string $commandUuid)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class RoomEvent extends Entity implements JsonSerializable {
	public const KIND_BASELINE = 'baseline';
	public const KIND_COMPLETION = 'completion';
	public const KIND_MANUAL_DELTA = 'manual_delta';
	public const KIND_RESET = 'reset';

	public const KINDS = [
		self::KIND_BASELINE,
		self::KIND_COMPLETION,
		self::KIND_MANUAL_DELTA,
		self::KIND_RESET,
	];

	/** @var int */
	protected $roomId;

	/** @var string self::KIND_* */
	protected $kind;

	/** @var \DateTime The work/request instant the effect belongs to. */
	protected $effectiveAt;

	/** @var string House-unique identity of the source (e.g. `completion:17`, `reset:<uuid>`). */
	protected $stableId;

	/** @var int|null */
	protected $sourceCompletionId;

	/** @var int|null Requested delta (completion/manual_delta). */
	protected $deltaCleanliness;

	/** @var int|null Absolute baseline/reset value. */
	protected $baselineCleanliness;

	/** @var int|null Absolute baseline/reset value. */
	protected $baselineMonsters;

	/** @var bool Reset semantics: last_cleaned returns to NULL. */
	protected $clearsLastCleaned = false;

	/** @var string|null */
	protected $commandUuid;

	/** @var \DateTime */
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('roomId', 'integer');
		$this->addType('kind', 'string');
		$this->addType('effectiveAt', 'datetime');
		$this->addType('stableId', 'string');
		$this->addType('sourceCompletionId', 'integer');
		$this->addType('deltaCleanliness', 'integer');
		$this->addType('baselineCleanliness', 'integer');
		$this->addType('baselineMonsters', 'integer');
		$this->addType('clearsLastCleaned', 'boolean');
		$this->addType('commandUuid', 'string');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'roomId' => $this->roomId,
			'kind' => $this->kind,
			'effectiveAt' => $this->effectiveAt?->format('c'),
			'stableId' => $this->stableId,
			'sourceCompletionId' => $this->sourceCompletionId,
			'deltaCleanliness' => $this->deltaCleanliness,
			'baselineCleanliness' => $this->baselineCleanliness,
			'baselineMonsters' => $this->baselineMonsters,
			'clearsLastCleaned' => $this->clearsLastCleaned,
			'commandUuid' => $this->commandUuid,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}
}
