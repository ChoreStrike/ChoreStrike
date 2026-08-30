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
 * @method int getRoomTaskId()
 * @method void setRoomTaskId(int $roomTaskId)
 * @method int getUserId()
 * @method void setUserId(int $userId)
 * @method \DateTime getCompletedAt()
 * @method void setCompletedAt(\DateTime $completedAt)
 * @method int getCoinsEarned()
 * @method void setCoinsEarned(int $coinsEarned)
 * @method bool getWasCombo()
 * @method void setWasCombo(bool $wasCombo)
 * @method int|null getComboWithUserId()
 * @method void setComboWithUserId(?int $comboWithUserId)
 * @method int|null getNextcloudTaskId()
 * @method void setNextcloudTaskId(?int $nextcloudTaskId)
 * @method bool getSyncedToNextcloud()
 * @method void setSyncedToNextcloud(bool $syncedToNextcloud)
 * @method bool getIsTeamCompletion()
 * @method void setIsTeamCompletion(bool $isTeamCompletion)
 * @method int getParticipantCount()
 * @method void setParticipantCount(int $participantCount)
 * @method int getTeamSparkBonus()
 * @method void setTeamSparkBonus(int $teamSparkBonus)
 * @method bool getWasClaimedCompletion()
 * @method void setWasClaimedCompletion(bool $wasClaimedCompletion)
 * @method int getClaimBonusSparks()
 * @method void setClaimBonusSparks(int $claimBonusSparks)
 * @method int|null getDaysOverdueWhenClaimed()
 * @method void setDaysOverdueWhenClaimed(?int $daysOverdueWhenClaimed)
 */
class CompletedTask extends Entity implements JsonSerializable {
	/** @var int */
	protected $roomTaskId;

	/** @var int */
	protected $userId;

	/** @var \DateTime */
	protected $completedAt;

	/** @var int */
	protected $coinsEarned = 0;

	/** @var bool */
	protected $wasCombo = false;

	/** @var int|null */
	protected $comboWithUserId;

	/** @var int|null */
	protected $nextcloudTaskId;

	/** @var bool */
	protected $syncedToNextcloud = false;

	/** @var bool */
	protected $isTeamCompletion = false;

	/** @var int */
	protected $participantCount = 1;

	/** @var int */
	protected $teamSparkBonus = 0;

	/** @var bool */
	protected $wasClaimedCompletion = false;

	/** @var int */
	protected $claimBonusSparks = 0;

	/** @var int|null */
	protected $daysOverdueWhenClaimed;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('roomTaskId', 'integer');
		$this->addType('userId', 'integer');
		$this->addType('completedAt', 'datetime');
		$this->addType('coinsEarned', 'integer');
		$this->addType('wasCombo', 'boolean');
		$this->addType('comboWithUserId', 'integer');
		$this->addType('nextcloudTaskId', 'integer');
		$this->addType('syncedToNextcloud', 'boolean');
		$this->addType('isTeamCompletion', 'boolean');
		$this->addType('participantCount', 'integer');
		$this->addType('teamSparkBonus', 'integer');
		$this->addType('wasClaimedCompletion', 'boolean');
		$this->addType('claimBonusSparks', 'integer');
		$this->addType('daysOverdueWhenClaimed', 'integer');
	}

	/**
	 * Convert completed task to JSON representation
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'roomTaskId' => $this->roomTaskId,
			'userId' => $this->userId,
			'completedAt' => $this->completedAt?->format('c'),
			'coinsEarned' => $this->coinsEarned,
			'wasCombo' => $this->wasCombo,
			'comboWithUserId' => $this->comboWithUserId,
			'nextcloudTaskId' => $this->nextcloudTaskId,
			'syncedToNextcloud' => $this->syncedToNextcloud,
			'isTeamCompletion' => $this->isTeamCompletion,
			'participantCount' => $this->participantCount,
			'teamSparkBonus' => $this->teamSparkBonus,
			'wasClaimedCompletion' => $this->wasClaimedCompletion,
			'claimBonusSparks' => $this->claimBonusSparks,
			'daysOverdueWhenClaimed' => $this->daysOverdueWhenClaimed,
		];
	}

}
