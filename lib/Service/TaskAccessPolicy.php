<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\IL10N;

/**
 * Single source of the task-visibility matrix (CS-PLAN-061 §3.2, decision D2).
 *
 * Exactly one matrix everywhere:
 *   - public:     every house member
 *   - private:    the creator (ownerUserId) UNION the explicit assignees
 *                 (canonical task_assignees join table)
 *   - admin-only: house owners only
 *
 * Owners do NOT implicitly see private tasks (D2). Visibility denials throw
 * the app {@see NotFoundException} — the 404 anti-enumeration standard
 * (decision D3, TaskController precedent) — while role denials (a visible
 * task, forbidden actor) stay ForbiddenException/403 elsewhere.
 */
class TaskAccessPolicy {
	private TaskAssigneeMapper $taskAssigneeMapper;
	private IL10N $l10n;

	public function __construct(
		TaskAssigneeMapper $taskAssigneeMapper,
		IL10N $l10n,
	) {
		$this->taskAssigneeMapper = $taskAssigneeMapper;
		$this->l10n = $l10n;
	}

	/**
	 * Whether the viewer may see the task definition and its room tasks.
	 *
	 * @param Task $task The task definition (visibility source)
	 * @param HouseUser $viewer The acting user's house membership
	 * @param string[] $assigneeUserIds Nextcloud user IDs assigned to the
	 *                                  room task(s) of this task (empty when
	 *                                  the task has no room tasks yet)
	 */
	public function canView(Task $task, HouseUser $viewer, array $assigneeUserIds = []): bool {
		$visibility = $task->getVisibilityType();

		if ($visibility === Task::VISIBILITY_PUBLIC) {
			return true;
		}

		if ($visibility === Task::VISIBILITY_ADMIN_ONLY) {
			return $viewer->isOwner();
		}

		// Private: creator ∪ explicit assignees (D2).
		return $task->getOwnerUserId() === $viewer->getNextcloudUserId()
			|| in_array($viewer->getNextcloudUserId(), $assigneeUserIds, true);
	}

	/**
	 * Single-task variant that resolves the definition's assignees itself
	 * (one query via {@see TaskAssigneeMapper::findUserIdsByTaskIds}) — for
	 * id-addressed checks like definition updates and lifecycle gates.
	 */
	public function canViewTask(Task $task, HouseUser $viewer): bool {
		if ($task->getVisibilityType() === Task::VISIBILITY_PUBLIC) {
			return true;
		}

		$assignees = $this->taskAssigneeMapper->findUserIdsByTaskIds([$task->getId()])[$task->getId()] ?? [];
		return $this->canView($task, $viewer, $assignees);
	}

	/**
	 * Assert the single-task visibility check; visibility denials are 404s
	 * (anti-enumeration, decision D3). Assignees are resolved internally.
	 *
	 * @throws NotFoundException when the matrix denies the viewer
	 */
	public function assertCanViewTask(Task $task, HouseUser $viewer): void {
		if (!$this->canViewTask($task, $viewer)) {
			throw new NotFoundException($this->l10n->t('Task not found'));
		}
	}

	/**
	 * Assert the viewer may see the task; visibility denials are 404s
	 * (anti-enumeration, decision D3).
	 *
	 * @throws NotFoundException when the matrix denies the viewer
	 */
	public function assertCanView(Task $task, HouseUser $viewer, array $assigneeUserIds = []): void {
		if (!$this->canView($task, $viewer, $assigneeUserIds)) {
			throw new NotFoundException($this->l10n->t('Task not found'));
		}
	}

	/**
	 * Batch post-fetch filter for room-task read paths (no N+1: ONE assignee
	 * fetch for the whole batch).
	 *
	 * @param RoomTask[] $roomTasks The fetched room tasks
	 * @param Task[] $tasksById Map of task ID → Task for the batch
	 * @param HouseUser $viewer The acting user's house membership
	 * @return RoomTask[] Only the room tasks visible to the viewer, input order
	 */
	public function filterVisibleRoomTasks(array $roomTasks, array $tasksById, HouseUser $viewer): array {
		if ($roomTasks === []) {
			return [];
		}

		$roomTaskIds = array_map(static fn (RoomTask $roomTask): int => $roomTask->getId(), $roomTasks);
		$assigneesByRoomTask = $this->taskAssigneeMapper->findUserIdsByRoomTasks($roomTaskIds);

		// Group room tasks by their task definition so a private task's
		// creator sees ALL its room tasks through one visibility decision
		// per (task, viewer) pair.
		$visible = [];
		foreach ($roomTasks as $roomTask) {
			$task = $tasksById[$roomTask->getTaskId()] ?? null;
			if ($task === null) {
				// Orphaned row (definition gone) — never leak it.
				continue;
			}

			if ($this->canView($task, $viewer, $assigneesByRoomTask[$roomTask->getId()] ?? [])) {
				$visible[] = $roomTask;
			}
		}

		return $visible;
	}
}
