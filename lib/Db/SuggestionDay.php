<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Db;

use OCP\AppFramework\Db\Entity;

/**
 * What the live suggestion placed on one house-local day at the day's
 * first read (ADR-022 decision 3). A cache, not a fact: no actor, no
 * effect, no history value.
 *
 * Each placement is `{r, k, u, p, o}`: the room task, the key of the cycle
 * it placed, the house-user ids it was placed on (the one whose budget
 * carries it first), the cycle's postponement count at that moment and
 * whether it was placed as overload.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getHouseId()
 * @method void setHouseId(int $houseId)
 * @method string getDayKey()
 * @method void setDayKey(string $dayKey)
 * @method mixed getPlacements() The decoded JSON; readers keep only a list of entries
 * @method void setPlacements(array $placements)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class SuggestionDay extends Entity {
	/** @var int */
	protected $houseId;

	/** @var string House-local day (`Y-m-d`). */
	protected $dayKey;

	/** @var array|null */
	protected $placements = [];

	/** @var \DateTime */
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('houseId', 'integer');
		$this->addType('dayKey', 'string');
		$this->addType('placements', 'json');
		$this->addType('createdAt', 'datetime');
	}
}
