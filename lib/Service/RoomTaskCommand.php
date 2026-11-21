<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\IL10N;

/**
 * The skeleton of a receipt-protected command on one room task (ADR-011,
 * ADR-021): postpone, sleep and the one form's save (RF-091-10). The
 * aggregate runs under the house lock with its receipt. On execution and
 * on replay, before any answer, a guard decides with the member as they are
 * now: whoever left the house, lost sight of the task or — for a command
 * of adults — is a child learns nothing about it. The answer is the room
 * task read fresh, with its `cycle` block. Every aggregate starts with
 * {@see lockTarget()}.
 */
class RoomTaskCommand {
	public function __construct(
		private CommandService $commandService,
		private RoomTaskAccess $access,
		private AuthorizationService $authorization,
		private RoomTaskReadService $roomTaskReads,
		private RoomTaskMapper $roomTaskMapper,
		private CycleService $cycleService,
		private IL10N $l10n,
	) {
	}

	/**
	 * The start of every aggregate UNDER the house LOCK: the room task row
	 * locked, not deleted, active and — for a command that names the room
	 * task alone — at the version the client showed. Postpone checks its
	 * version with its cycle key ({@see CycleService::postponeUnderLock()}).
	 *
	 * @param int|null $expectedVersion Null: the aggregate checks the version with its key
	 * @throws NotFoundException a deleted room task
	 * @throws ConflictException an inactive task, or `STALE_TARGET`
	 */
	public function lockTarget(int $roomTaskId, ?int $expectedVersion): RoomTask {
		$roomTask = $this->roomTaskMapper->lockForUpdate($roomTaskId);
		if ($roomTask->getDeletedAt() !== null) {
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}
		if (!$roomTask->getIsActive()) {
			throw new ConflictException($this->l10n->t('Task is not active'));
		}
		if ($expectedVersion !== null) {
			$this->cycleService->assertTarget(null, $roomTask, null, $expectedVersion);
		}
		return $roomTask;
	}

	/**
	 * @param array<string, mixed> $identity What makes two commands on the room task the same
	 * @param callable(HouseUser): array<string, mixed> $aggregate Runs under the house lock with the
	 *                                                             member as the guard read them; returns the receipt identity
	 * @param bool $adultsOnly Whether a child is refused (403)
	 * @param bool $audited Whether the first commit is written to the audit log (a save of definitions)
	 * @return array{roomTask: RoomTask, task: Task, assignees: string[], cycle: ?array<string, mixed>, timeZone: \DateTimeZone, replay: bool}
	 * @throws NotFoundException a foreign, deleted or invisible room task
	 * @throws ForbiddenException a member who left, or a child for a command of adults
	 */
	public function run(int $houseId, HouseUser $actor, int $roomTaskId, string $commandId, string $action, array $identity, callable $aggregate, bool $adultsOnly, bool $audited = false): array {
		$live = $actor;
		$command = $this->commandService->runCommand(
			$houseId,
			(int)$actor->getId(),
			$commandId,
			$action,
			['roomTaskId' => $roomTaskId, ...$identity],
			// The guard runs first under the lock, so the aggregate acts as the member is now.
			function () use ($aggregate, &$live): array {
				return $aggregate($live);
			},
			function () use ($houseId, $actor, $roomTaskId, $adultsOnly, &$live): void {
				$live = $this->access->liveMember($houseId, $actor);
				$this->visibleTo($houseId, $live, $roomTaskId, $adultsOnly);
			},
			$audited,
		);
		$fresh = $this->visibleTo($houseId, $this->access->liveMember($houseId, $actor), $roomTaskId, $adultsOnly);
		$timeZone = $this->roomTaskReads->timeZoneForHouse($houseId);
		$cycle = $this->roomTaskReads->cycleBlockOf($houseId, $fresh['roomTask'], $fresh['task'], $timeZone);
		return [...$fresh, 'cycle' => $cycle, 'timeZone' => $timeZone, 'replay' => $command['replay']];
	}

	/**
	 * The room task for a member who may act on it, read fresh. `$live` is
	 * the membership as read now: the entity a caller resolved before a
	 * lock never decides the current role.
	 *
	 * @return array{roomTask: RoomTask, task: Task, assignees: string[]}
	 * @throws NotFoundException
	 * @throws ForbiddenException
	 */
	private function visibleTo(int $houseId, HouseUser $live, int $roomTaskId, bool $adultsOnly): array {
		if ($adultsOnly) {
			$this->authorization->assertCanManageTasks($live);
		}
		return $this->access->visible($houseId, $live, $roomTaskId);
	}
}
