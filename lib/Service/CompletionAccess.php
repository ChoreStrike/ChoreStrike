<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * How the completion commands reach their target (ADR-014, ADR-021
 * decision 9): the house-scoped resolution of a completion and its room
 * task, the authorization of a correction from live rows, and the one
 * skeleton every correction starts with under the house lock — lock the
 * room task, re-read the completion, lock the room, authorize, compare the
 * version. A foreign or missing id is a clean 404.
 */
class CompletionAccess {
	/** The full re-date/delete right: the owner, or the recorder of the task's latest valid entry. */
	public const RIGHTS_LATEST = 'latest';
	/** The right without its state part (still the latest entry): the gate before a command and on its replay. */
	public const RIGHTS_ENTRY = 'entry';
	/** Membership and visibility only: the read after a committed command, whose effect no longer decides the right. */
	public const RIGHTS_NONE = 'none';
	/** Changing who is credited: the owner's alone. */
	public const RIGHTS_ATTRIBUTE = 'attribute';

	public function __construct(
		private CompletedTaskMapper $completedTaskMapper,
		private RoomTaskMapper $roomTaskMapper,
		private TaskMapper $taskMapper,
		private RoomMapper $roomMapper,
		private HouseUserMapper $houseUserMapper,
		private TaskAssigneeMapper $taskAssigneeMapper,
		private TaskAccessPolicy $taskAccessPolicy,
		private CompletionAuthority $authority,
		private IL10N $l10n,
	) {
	}

	/** @throws NotFoundException a foreign, missing or deleted room task */
	public function roomTask(int $roomTaskId, int $houseId): RoomTask {
		$roomTask = $this->roomTaskMapper->findByIdsScopedToHouse([$roomTaskId], $houseId)[0] ?? null;
		// A deleted room task has no row to act on (as RoomTaskAccess decides).
		if ($roomTask === null || $roomTask->getDeletedAt() !== null) {
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}
		return $roomTask;
	}

	/**
	 * The membership of a user, read live. With `$actorId` it must still be
	 * the membership a command started with: one that left and came back
	 * is somebody else.
	 *
	 * @throws ForbiddenException with `$refusal`
	 */
	public function member(int $houseId, string $userId, ?int $actorId, string $refusal): HouseUser {
		$member = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
		if ($member === null || ($actorId !== null && (int)$member->getId() !== $actorId)) {
			throw new ForbiddenException($refusal);
		}
		return $member;
	}

	/**
	 * The definition of a room task the member may see (ADR-010).
	 *
	 * @param string[]|null $assigneeUserIds The room task's assignees, when the caller read them already
	 * @throws NotFoundException
	 */
	public function visibleTask(RoomTask $roomTask, HouseUser $member, ?array $assigneeUserIds = null): Task {
		$task = $this->task($roomTask->getTaskId());
		$this->taskAccessPolicy->assertCanView($task, $member, $assigneeUserIds ?? $this->taskAssigneeMapper->findUserIdsByRoomTask((int)$roomTask->getId()));
		return $task;
	}

	/**
	 * A completion of the house with its room task: the house scope is
	 * resolved through the room task, so both come from the one read.
	 *
	 * @return array{0: CompletedTask, 1: RoomTask}
	 * @throws NotFoundException a foreign or missing completion
	 */
	public function target(int $completionId, int $houseId): array {
		try {
			$completion = $this->completedTaskMapper->find($completionId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Completion not found'));
		}
		$roomTask = $this->roomTaskMapper->findByIdsScopedToHouse([$completion->getRoomTaskId()], $houseId)[0] ?? null;
		if ($roomTask === null) {
			throw new NotFoundException($this->l10n->t('Completion not found'));
		}
		return [$completion, $roomTask];
	}

	/** @throws NotFoundException */
	public function task(int $taskId): Task {
		try {
			return $this->taskMapper->find($taskId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Task not found'));
		}
	}

	/** @throws NotFoundException */
	public function room(int $roomId): Room {
		try {
			return $this->roomMapper->find($roomId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Room not found'));
		}
	}

	/**
	 * Authorize a correction from live rows: for the gate before a command,
	 * the house-locked aggregate and a receipt replay. The actor entity a
	 * caller read before the lock never decides the current role.
	 *
	 * @param string $rights self::RIGHTS_* — how much of the right to check
	 * @return Task The definition the visibility was decided on
	 * @throws ForbiddenException no membership, or no right to correct
	 * @throws NotFoundException a task the actor may not see
	 */
	public function authorize(int $houseId, HouseUser $actor, CompletedTask $completion, RoomTask $roomTask, string $rights): Task {
		$liveActor = $this->member($houseId, $actor->getNextcloudUserId(), (int)$actor->getId(), $this->l10n->t('Not a member of this house'));
		$task = $this->visibleTask($roomTask, $liveActor);
		match ($rights) {
			self::RIGHTS_ATTRIBUTE => $this->authority->assertCanAttribute($liveActor),
			self::RIGHTS_LATEST => $this->authority->assertCanCorrect($liveActor, $completion),
			self::RIGHTS_ENTRY => $this->authority->assertMayCorrectEntry($liveActor, $completion),
			default => null,
		};
		return $task;
	}

	/**
	 * The start of every correction aggregate, inside the command
	 * transaction. The room-task lock is the serialization point: the
	 * completion is re-read UNDER it, so a concurrently committed
	 * correction is visible here; the room row follows (documented order:
	 * rooms before memberships and wallets).
	 *
	 * @param string $rights self::RIGHTS_*
	 */
	public function lockTarget(int $houseId, HouseUser $actor, int $completionId, string $rights): CorrectionTarget {
		[$probe] = $this->target($completionId, $houseId);
		$roomTask = $this->roomTaskMapper->lockForUpdate($probe->getRoomTaskId());
		// The house scope is decided: an entry never changes its room task.
		$completion = $this->completedTaskMapper->find($completionId);
		$room = $this->roomMapper->lockForUpdate($roomTask->getRoomId());
		$task = $this->authorize($houseId, $actor, $completion, $roomTask, $rights);
		return new CorrectionTarget($roomTask, $completion, $room, $task);
	}

	/** @throws ConflictException the completion changed since the client showed it */
	public function assertVersion(CompletedTask $completion, int $expectedVersion): void {
		if ($completion->getCompletionVersion() !== $expectedVersion) {
			throw new ConflictException($this->l10n->t('The completion changed since it was displayed'));
		}
	}
}
