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
 * @method int getId()
 * @method void setId(int $id)
 * @method int getHouseId()
 * @method void setHouseId(int $houseId)
 * @method string getName()
 * @method void setName(string $name)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Floor extends Entity implements JsonSerializable
{
    /** @var int */
    protected $houseId;

    /** @var string */
    protected $name;

    /** @var int */
    protected $sortOrder = 0;

    /** @var \DateTime */
    protected $createdAt;

    public function __construct()
    {
        $this->addType('id', 'integer');
        $this->addType('houseId', 'integer');
        $this->addType('name', 'string');
        $this->addType('sortOrder', 'integer');
        $this->addType('createdAt', 'datetime');
    }

    /**
     * Convert floor to JSON representation
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'houseId' => $this->houseId,
            'name' => $this->name,
            'sortOrder' => $this->sortOrder,
            'createdAt' => $this->createdAt?->format('c'),
        ];
    }
}
