<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CycleProgressMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskChecklistItem;
use OCA\ChoreStrike\Db\TaskChecklistItemMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\IL10N;

/**
 * The one form's save on an existing task (CS-PLAN-091 WP-B6,
 * FEAT-UX-040): the definition, its sub-tasks, the room, the assignees,
 * the first date and the sleep switch change in one atomic write, so a
 * refusal never leaves the form half applied (BUG-UX-011).
 *
 * A definition that several rooms share changes only with a scope: *all
 * rooms* edits it where it is, *this room* splits off a copy for this room
 * task (FEAT-UX-043). A member who cannot see every room task of the
 * definition changes this room's only (D-091-110). A move changes the room
 * of the room task; its completions and closed cycles belong to the room
 * task, and the room journal keeps every entry in the room where it
 * happened. Adults and the owner edit; children do not
 * ({@see RoomTaskCommand}). A committed save is written to the audit log.
 */
class RoomTaskEditService {
	public const ACTION = 'room_task.edit';

	public function __construct(
		private RoomTaskCommand $command,
		private TaskService $definitions,
		private SubTaskWriter $subTasks,
		private SleepService $sleep,
		private CycleService $cycleService,
		private CycleProjection $projection,
		private CycleProgressMapper $progress,
		private RoomTaskMapper $roomTaskMapper,
		private TaskMapper $taskMapper,
		private TaskAssigneeMapper $assignees,
		private TaskChecklistItemMapper $checklistItems,
		private HouseMapper $houseMapper,
		private RoomService $rooms,
		private AuthorizationService $authorization,
		private TaskAccessPolicy $accessPolicy,
		private CalendarService $calendar,
		private IL10N $l10n,
	) {
	}

	/**
	 * @return array{roomTask: RoomTask, task: Task, assignees: string[], checklistItems: TaskChecklistItem[], cycle: ?array<string, mixed>, timeZone: \DateTimeZone, replay: bool}
	 * @throws NotFoundException a foreign, deleted or invisible room task, or a foreign room
	 * @throws ForbiddenException a child, a member who left, a visibility the member may not change, or a save that would take the member's own sight of the task
	 * @throws ConflictException `STALE_TARGET`, `SCOPE_REQUIRED`, an inactive task, a first date after the first completion, or a room that holds the definition
	 * @throws \InvalidArgumentException a value the form may not save (400)
	 */
	public function edit(int $houseId, HouseUser $actor, int $roomTaskId, TaskEdit $edit): array {
		$result = $this->command->run(
			$houseId,
			$actor,
			$roomTaskId,
			$edit->commandId,
			self::ACTION,
			$edit->identity(),
			fn (HouseUser $live): array => $this->aggregate($houseId, $live, $roomTaskId, $edit),
			true,
			true,
		);
		return [...$result, 'checklistItems' => $this->checklistItems->findByTaskId((int)$result['task']->getId())];
	}

	/**
	 * The save UNDER LOCK. The window changes once: a changed first date
	 * wins over a changed rule, which wins over changed assignees. Waking
	 * comes before that change, so a first date set with it holds; going to
	 * sleep comes last (D-091-92).
	 *
	 * @return array<string, mixed> The stored receipt identity
	 */
	private function aggregate(int $houseId, HouseUser $live, int $roomTaskId, TaskEdit $edit): array {
		$form = $edit->form;
		$roomTask = $this->command->lockTarget($roomTaskId, $edit->expectedVersion);
		$house = $this->houseMapper->find($houseId);
		$timeZone = $this->calendar->getTimeZone($house);
		$now = new \DateTime();
		$before = $this->taskMapper->lockForUpdate((int)$roomTask->getTaskId());
		// The completion inputs of the room task: read once, and only by a save that derives its window.
		$read = null;
		$histories = function () use (&$read, $roomTaskId): array {
			return $read ??= $this->cycleService->historiesFor([$roomTaskId]);
		};

		$prepared = $this->definitions->prepareUpdate($before, $form->definition ?? [], $live);
		$this->assertKeepsSight($before, $prepared, $form->assigneeUserIds, $live);

		[$task, $split] = $this->writeDefinition($houseId, $live, $roomTask, clone $before, $prepared, $edit, $timeZone, $now, $histories);
		$moved = $this->moveUnderLock($houseId, $roomTask, $task, $form->roomId);
		$reassigned = $this->replaceAssigneesUnderLock($houseId, $roomTask, $form->assigneeUserIds, $live);
		// Compared with the stored first date before a wake moves the anchor.
		$firstDate = $this->changedFirstDate($roomTask, $task, $form, $histories, $this->calendar->dayKey($now, $timeZone));

		if ($form->sleeping === false) {
			$this->sleep->applyUnderLock($house, $roomTask, $task, false, null, $timeZone, $now, $read);
		}
		if ($firstDate !== null) {
			$this->cycleService->startUnderLock($roomTask, $task, $firstDate, $timeZone, $now, $histories());
		} elseif (TaskService::ruleRelevantInputsChanged($before, $task)) {
			$this->cycleService->ruleChangedUnderLock($roomTask, $before, $task, $timeZone, $now, $histories());
		} elseif ($reassigned) {
			// The assignees are part of the current cycle: a client that shows the old set is stale.
			$this->projection->changedUnderLock($roomTask, $task, $timeZone, $now, $histories());
		} elseif ($split || $moved) {
			$this->roomTaskMapper->update($roomTask);
		}
		if ($form->sleeping === true) {
			$this->sleep->applyUnderLock($house, $roomTask, $task, true, $form->wakeOn, $timeZone, $now, $read);
		}
		return ['roomTaskId' => $roomTaskId, 'taskId' => (int)$task->getId()];
	}

	/**
	 * A save never takes the acting member's own sight of the task
	 * (D-091-108): whoever is neither the owner of a private task nor, after
	 * the save, one of its assignees is refused before anything is written.
	 * Without a change of the assignees the guard of the command has
	 * already seen the member among them.
	 *
	 * @param array<string, mixed> $prepared From {@see TaskService::prepareUpdate()}
	 * @param string[]|null $assigneeUserIds The assignees the save sets; null leaves them
	 * @throws ForbiddenException
	 */
	private function assertKeepsSight(Task $task, array $prepared, ?array $assigneeUserIds, HouseUser $live): void {
		$private = ($prepared['visibilityType'] ?? $task->getVisibilityType()) === Task::VISIBILITY_PRIVATE;
		$owner = array_key_exists('ownerUserId', $prepared) ? $prepared['ownerUserId'] : $task->getOwnerUserId();
		$userId = $live->getNextcloudUserId();
		if ($private && $assigneeUserIds !== null && $owner !== $userId && !in_array($userId, $assigneeUserIds, true)) {
			throw new ForbiddenException($this->l10n->t('You cannot remove yourself from a private task that is not yours'));
		}
	}

	/**
	 * Write the definition and its sub-tasks UNDER LOCK: where they are, or
	 * — for a definition several rooms share and the scope *this room* —
	 * into a copy that only this room task uses from now on. A member who
	 * cannot see every room task of the definition never chooses: the save
	 * changes this room's only, and nothing tells of the others (D-091-110).
	 *
	 * @param array<string, mixed> $prepared From {@see TaskService::prepareUpdate()}
	 * @param callable(): array<int, CycleHistory> $histories The completion inputs of the room task
	 * @return array{0: Task, 1: bool} The definition the room task uses now, and whether it split off
	 * @throws ConflictException `SCOPE_REQUIRED`
	 */
	private function writeDefinition(int $houseId, HouseUser $live, RoomTask $roomTask, Task $task, array $prepared, TaskEdit $edit, \DateTimeZone $timeZone, \DateTime $now, callable $histories): array {
		$taskId = (int)$task->getId();
		$updates = $this->definitions->changedFields($task, $prepared);
		$subTasks = $edit->form->subTasks;
		$items = $subTasks === null ? null : $this->subTasks->itemsOf($taskId, $subTasks);
		$subTasksChange = $subTasks !== null && $this->subTasks->differs($items, $subTasks);
		if ($updates === [] && !$subTasksChange) {
			return [$task, false];
		}
		$mounted = $this->roomTaskMapper->findByTaskIdScopedToHouse($taskId, $houseId);
		if (count($mounted) > 1) {
			$seesEvery = $this->seesEvery($mounted, $task, $live);
			if ($seesEvery && $edit->scope === null) {
				throw new ConflictException($this->l10n->t('Several rooms share this task. Choose whether the change applies to this room or to all rooms.'), ConflictException::REASON_SCOPE_REQUIRED);
			}
			if (!$seesEvery || $edit->scope !== TaskEdit::SCOPE_ALL_ROOMS) {
				$items ??= $this->subTasks->itemsOf($taskId, null);
				return [$this->splitUnderLock($roomTask, $task, $updates, $items, $subTasksChange ? $subTasks : null, $timeZone, $now, $histories), true];
			}
		}
		$shared = clone $task;
		if ($updates !== []) {
			$task = $this->definitions->applyUnderLock($task, $shared, $updates);
			$this->definitions->reviseMountedUnderLock($shared, $task, $houseId, $timeZone, $now, [(int)$roomTask->getId()], $mounted);
		}
		if ($subTasksChange) {
			$this->subTasks->syncUnderLock($taskId, $items, $subTasks);
		}
		return [$task, false];
	}

	/**
	 * Whether the member may see every room task of the definition
	 * (ADR-010: a private task per room task, by its own assignees).
	 *
	 * @param RoomTask[] $mounted
	 */
	private function seesEvery(array $mounted, Task $task, HouseUser $live): bool {
		if ($task->getVisibilityType() !== Task::VISIBILITY_PRIVATE) {
			// Public and admin-only visibility do not depend on the assignees.
			return true;
		}
		$assignees = $this->assignees->findUserIdsByRoomTasks(array_map(static fn (RoomTask $roomTask): int => (int)$roomTask->getId(), $mounted));
		foreach ($mounted as $roomTask) {
			if (!$this->accessPolicy->canView($task, $live, $assignees[(int)$roomTask->getId()] ?? [])) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Split this room task off a shared definition UNDER LOCK (FEAT-UX-043):
	 * a copy takes the edit and the sub-tasks, the ticks of the current
	 * cycle follow their sub-tasks, and the other rooms keep the definition
	 * as it was. Completions, closed cycles and the room journal belong to
	 * the room task and stay as recorded.
	 *
	 * @param array<string, mixed> $updates The changed fields
	 * @param array<int, TaskChecklistItem> $items The shared definition's sub-tasks
	 * @param callable(): array<int, CycleHistory> $histories The completion inputs of the room task
	 */
	private function splitUnderLock(RoomTask $roomTask, Task $shared, array $updates, array $items, ?SubTaskList $subTasks, \DateTimeZone $timeZone, \DateTime $now, callable $histories): Task {
		$copy = $this->taskMapper->insertCopy($shared);
		if ($updates !== []) {
			$copy = $this->definitions->applyUnderLock($copy, $shared, $updates);
		}
		$itemMap = $this->subTasks->copyUnderLock($items, (int)$copy->getId(), $subTasks);
		// Only a task with a cycle has ticks to carry over.
		$cycle = $itemMap !== [] && CycleProjection::hasCycles($roomTask, $shared)
			? $this->projection->currentFor($roomTask, $shared, $timeZone, $now, $histories())
			: null;
		if ($cycle !== null) {
			$this->progress->moveToItems((int)$roomTask->getId(), $cycle->key, $itemMap);
		}
		$roomTask->setTaskId((int)$copy->getId());
		return $copy;
	}

	/**
	 * Move the room task to another room of the house UNDER LOCK.
	 *
	 * @throws NotFoundException a room outside the house (404)
	 * @throws ConflictException the room already holds this definition
	 */
	private function moveUnderLock(int $houseId, RoomTask $roomTask, Task $task, ?int $roomId): bool {
		if ($roomId === null || $roomId === (int)$roomTask->getRoomId()) {
			return false;
		}
		$this->rooms->validateRoomOwnership($roomId, $houseId);
		if ($this->roomTaskMapper->exists($roomId, (int)$task->getId())) {
			throw new ConflictException($this->l10n->t('The room already has this task'));
		}
		$roomTask->setRoomId($roomId);
		return true;
	}

	/**
	 * Replace the assignee set UNDER LOCK; every member is checked under the
	 * house lock, so one who left meanwhile rolls the save back (400).
	 *
	 * @param string[]|null $userIds Null: the assignees stay
	 * @throws \InvalidArgumentException
	 */
	private function replaceAssigneesUnderLock(int $houseId, RoomTask $roomTask, ?array $userIds, HouseUser $live): bool {
		if ($userIds === null) {
			return false;
		}
		$roomTaskId = (int)$roomTask->getId();
		$current = $this->assignees->findUserIdsByRoomTask($roomTaskId);
		if (array_diff($current, $userIds) === [] && array_diff($userIds, $current) === []) {
			return false;
		}
		$this->authorization->assertMembersUnderLock($houseId, $userIds);
		$this->assignees->deleteByRoomTask($roomTaskId);
		foreach ($userIds as $userId) {
			$this->assignees->addAssignee($roomTaskId, $userId, $live->getNextcloudUserId());
		}
		return true;
	}

	/**
	 * The first date the save sets, or null when it leaves the stored one
	 * (D-091-19): it moves the window only until the first completion;
	 * afterwards the date correction of the latest entry does. A bonus
	 * task has no window to anchor. Only a new choice is held to its range
	 * (D-091-45): the form sends the stored date of a task that was never
	 * done with every save.
	 *
	 * @param callable(): array<int, CycleHistory> $histories The completion inputs of the room task
	 * @throws ConflictException a changed first date after the first completion
	 * @throws \InvalidArgumentException a new first date outside its range (400)
	 */
	private function changedFirstDate(RoomTask $roomTask, Task $task, TaskFormPayload $form, callable $histories, string $todayKey): ?string {
		$firstDate = $form->firstDate;
		if ($firstDate === null || $task->getIsVoluntary() || $roomTask->getAnchorOn()?->format('Y-m-d') === $firstDate) {
			return null;
		}
		$form->assertFirstDateInRange($this->l10n, $todayKey);
		if (($histories()[(int)$roomTask->getId()] ?? null)?->hasCompletion()) {
			throw new ConflictException($this->l10n->t('The first date only changes until the task is done for the first time'));
		}
		return $firstDate;
	}
}
