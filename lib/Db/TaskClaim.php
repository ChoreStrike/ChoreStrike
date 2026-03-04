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
 * TaskClaim - tracks when users claim overdue tasks from others
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getRoomTaskId()
 * @method void setRoomTaskId(int $roomTaskId)
 * @method string getClaimedByUser()
 * @method void setClaimedByUser(string $claimedByUser)
 * @method string|null getOriginalAssignee()
 * @method void setOriginalAssignee(?string $originalAssignee)
 * @method \DateTime getClaimedAt()
 * @method void setClaimedAt(\DateTime $claimedAt)
 * @method int getDaysOverdue()
 * @method void setDaysOverdue(int $daysOverdue)
 * @method bool getWasCompleted()
 * @method void setWasCompleted(bool $wasCompleted)
 * @method \DateTime|null getCompletedAt()
 * @method void setCompletedAt(?\DateTime $completedAt)
 * @method int getBonusSparks()
 * @method void setBonusSparks(int $bonusSparks)
 */
class TaskClaim extends Entity implements JsonSerializable
{
    /** @var int */
    protected $roomTaskId;

    /** @var string */
    protected $claimedByUser;

    /** @var string|null */
    protected $originalAssignee;

    /** @var \DateTime */
    protected $claimedAt;

    /** @var int */
    protected $daysOverdue;

    /** @var bool */
    protected $wasCompleted = false;

    /** @var \DateTime|null */
    protected $completedAt;

    /** @var int */
    protected $bonusSparks = 0;

    public function __construct()
    {
        $this->addType('id', 'integer');
        $this->addType('roomTaskId', 'integer');
        $this->addType('claimedByUser', 'string');
        $this->addType('originalAssignee', 'string');
        $this->addType('claimedAt', 'datetime');
        $this->addType('daysOverdue', 'integer');
        $this->addType('wasCompleted', 'boolean');
        $this->addType('completedAt', 'datetime');
        $this->addType('bonusSparks', 'integer');
    }

    /**
     * Convert claim to JSON representation
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'roomTaskId' => $this->roomTaskId,
            'claimedByUser' => $this->claimedByUser,
            'originalAssignee' => $this->originalAssignee,
            'claimedAt' => $this->claimedAt?->format('c'),
            'daysOverdue' => $this->daysOverdue,
            'wasCompleted' => $this->wasCompleted,
            'completedAt' => $this->completedAt?->format('c'),
            'bonusSparks' => $this->bonusSparks,
        ];
    }
}
