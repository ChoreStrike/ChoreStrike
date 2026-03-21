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
 * TaskParticipant - tracks users who participated in a task completion
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getCompletedTaskId()
 * @method void setCompletedTaskId(int $completedTaskId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getCoinsEarned()
 * @method void setCoinsEarned(int $coinsEarned)
 * @method string getParticipationType()
 * @method void setParticipationType(string $participationType)
 * @method \DateTime getJoinedAt()
 * @method void setJoinedAt(\DateTime $joinedAt)
 */
class TaskParticipant extends Entity implements JsonSerializable
{
    /** @var int */
    protected $completedTaskId;

    /** @var string */
    protected $userId;

    /** @var int */
    protected $coinsEarned = 0;

    /** @var string */
    protected $participationType = 'helper';

    /** @var \DateTime */
    protected $joinedAt;

    public function __construct()
    {
        $this->addType('id', 'integer');
        $this->addType('completedTaskId', 'integer');
        $this->addType('userId', 'string');
        $this->addType('coinsEarned', 'integer');
        $this->addType('participationType', 'string');
        $this->addType('joinedAt', 'datetime');
    }

    /**
     * Convert participant to JSON representation
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'completedTaskId' => $this->completedTaskId,
            'userId' => $this->userId,
            'coinsEarned' => $this->coinsEarned,
            'participationType' => $this->participationType,
            'joinedAt' => $this->joinedAt?->format('c'),
        ];
    }

    /**
     * Check if this participant is the primary (claimer)
     */
    public function isPrimary(): bool
    {
        return $this->participationType === 'primary';
    }

    /**
     * Check if this participant is a helper
     */
    public function isHelper(): bool
    {
        return $this->participationType === 'helper';
    }
}
