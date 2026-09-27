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
 * Arcade score row (CS-PLAN-070 WP3 §3.7: scores only). Never touches the
 * family wallet, contribution or any reward — the former boost metadata
 * and Coin awarding are retired with the no-Coin arcade cutover.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getHouseId()
 * @method void setHouseId(int $houseId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getGameId()
 * @method void setGameId(string $gameId)
 * @method int getScore()
 * @method void setScore(int $score)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class MinigameScore extends Entity implements JsonSerializable {
	/** @var int */
	protected $houseId;

	/** @var string */
	protected $userId;

	/** @var string */
	protected $gameId = 'snake';

	/** @var int */
	protected $score = 0;

	/** @var \DateTime */
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('houseId', 'integer');
		$this->addType('userId', 'string');
		$this->addType('gameId', 'string');
		$this->addType('score', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	/**
	 * Convert minigame score to JSON representation
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'houseId' => $this->houseId,
			'userId' => $this->userId,
			'gameId' => $this->gameId,
			'score' => $this->score,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}
}
