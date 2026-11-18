<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\IL10N;

/**
 * The one resolution of a room task for a member who acts on it or reads
 * its history (ADR-010): scoped to the house, not deleted, and visible to
 * that member under the {@see TaskAccessPolicy} matrix. Whatever fails,
 * the answer is the same 404, so an id tells nothing about a task the
 * member may not see.
 */
class RoomTaskAccess {
	public function __construct(
		private RoomTaskMapper $roomTaskMapper,
		private TaskMapper $taskMapper,
		private TaskAssigneeMapper $taskAssigneeMapper,
		private HouseUserMapper $houseUserMapper,
		private TaskAccessPolicy $taskAccessPolicy,
		private IL10N $l10n,
	) {
	}

	/**
	 * The acting membership, read fresh: the entity a caller resolved
	 * before a lock never decides the current role.
	 *
	 * @throws ForbiddenException the member left the house
	 */
	public function liveMember(int $houseId, HouseUser $actor): HouseUser {
		$live = $this->houseUserMapper->findByHouseAndUser($houseId, $actor->getNextcloudUserId());
		if ($live === null || (int)$live->getId() !== (int)$actor->getId()) {
			throw new ForbiddenException($this->l10n->t('Not a member of this house'));
		}
		return $live;
	}

	/**
	 * @return array{roomTask: RoomTask, task: Task, assignees: string[]}
	 * @throws NotFoundException a foreign, deleted or invisible room task
	 */
	public function visible(int $houseId, HouseUser $viewer, int $roomTaskId): array {
		$roomTask = $this->roomTaskMapper->findByIdsScopedToHouse([$roomTaskId], $houseId)[0] ?? null;
		// A deleted room task has no row to act on or to open a history from.
		$task = $roomTask === null || $roomTask->getDeletedAt() !== null
			? null
			: ($this->taskMapper->findByIds([$roomTask->getTaskId()])[$roomTask->getTaskId()] ?? null);
		if ($task === null) {
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}
		$assignees = $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTaskId);
		$this->taskAccessPolicy->assertCanView($task, $viewer, $assignees);
		return ['roomTask' => $roomTask, 'task' => $task, 'assignees' => $assignees];
	}
}
