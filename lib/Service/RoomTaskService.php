<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Service for room task business logic: ownership validation, lifecycle
 * mutations and the room-scoped aggregates (stats, calendar range). The
 * batched READ/enrichment cluster lives in {@see RoomTaskReadService}
 * (CS-PLAN-067 §3.1); this service delegates to it instead of duplicating
 * the assembly. The one form's save ({@see RoomTaskEditService}) owns the
 * edits of a room task; the window itself is derived and never edited here
 * (ADR-021).
 */
class RoomTaskService {
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private HouseMapper $houseMapper;
	private RoomTaskReadService $readService;
	private RoomService $roomService;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private TransactionExecutor $transactions;
	private IL10N $l10n;
	private AppLogger $appLogger;
	private TaskAccessPolicy $taskAccessPolicy;
	private AuthorizationService $authorization;
	private WindowService $windows;

	public function __construct(
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		RoomMapper $roomMapper,
		HouseMapper $houseMapper,
		RoomTaskReadService $readService,
		RoomService $roomService,
		TaskAssigneeMapper $taskAssigneeMapper,
		CompletedTaskMapper $completedTaskMapper,
		TransactionExecutor $transactions,
		IL10N $l10n,
		AppLogger $appLogger,
		TaskAccessPolicy $taskAccessPolicy,
		AuthorizationService $authorization,
		WindowService $windows,
	) {
		$this->roomTaskMapper = $roomTaskMapper;
		$this->taskMapper = $taskMapper;
		$this->roomMapper = $roomMapper;
		$this->houseMapper = $houseMapper;
		$this->readService = $readService;
		$this->roomService = $roomService;
		$this->taskAssigneeMapper = $taskAssigneeMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->transactions = $transactions;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
		$this->taskAccessPolicy = $taskAccessPolicy;
		$this->authorization = $authorization;
		$this->windows = $windows;
	}

	/**
	 * Validate that a room task belongs to the specified house
	 *
	 * The room→floor→house walk is owned by {@see RoomService::validateRoomOwnership()}
	 * (one canonical implementation, CS-PLAN-063 §3.4 — CR-SVC-41 dedup);
	 * this wrapper resolves the room task's room and translates any miss
	 * back into the room-task 404 so callers keep one denial shape.
	 *
	 * @param int $roomTaskId The room task ID
	 * @param int $houseId The house ID to validate against
	 * @throws NotFoundException if room task doesn't belong to house
	 */
	public function validateRoomTaskOwnership(int $roomTaskId, int $houseId): void {
		try {
			$roomTask = $this->roomTaskMapper->find($roomTaskId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}

		// Q-70-01 (CS-PLAN-071 WP0): a tombstoned room task is gone from
		// active planning — mutation/validation surfaces treat it exactly
		// like a missing id (404), never as a writable target.
		if ($roomTask->getDeletedAt() !== null) {
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}

		try {
			$this->roomService->validateRoomOwnership($roomTask->getRoomId(), $houseId);
		} catch (NotFoundException $e) {
			$this->appLogger->securityWarning('Room task ownership validation failed', [
				'service' => 'RoomTaskService',
				'roomTaskId' => $roomTaskId,
				'houseId' => $houseId,
			]);
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}
	}

	/**
	 * Validate that a room belongs to the specified house.
	 *
	 * Delegates to RoomService: a cross-house roomId is rejected before
	 * anything is read or written for it.
	 *
	 * @param int $roomId The room ID
	 * @param int $houseId The house ID to validate against
	 * @throws NotFoundException if the room doesn't belong to the house
	 */
	public function validateRoomOwnership(int $roomId, int $houseId): void {
		$this->roomService->validateRoomOwnership($roomId, $houseId);
	}

	/**
	 * Get room task by ID
	 *
	 * @param int $roomTaskId The room task ID
	 * @throws NotFoundException if not found
	 */
	public function getRoomTask(int $roomTaskId): RoomTask {
		return $this->findRoomTaskOrNotFound($roomTaskId);
	}

	/**
	 * Fetch a room task or translate the mapper's DoesNotExistException into
	 * the app NotFoundException (404, CS-PLAN-061 §3.5) — a raw framework
	 * exception would surface as a 500.
	 *
	 * @throws NotFoundException if not found
	 */
	private function findRoomTaskOrNotFound(int $roomTaskId): RoomTask {
		try {
			return $this->roomTaskMapper->find($roomTaskId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}
	}

	// CS-PLAN-067 §3.1: the batched READ cluster (house/room/scope/daily
	// fetches, visibility filtering, task/room enrichment, filtered-daily
	// assembly, cycle blocks, execution-row aggregation) lives
	// in RoomTaskReadService — callers consume it directly, this service
	// keeps only the writer/mutation aggregate and the room-scoped stats and
	// calendar projections.

	// CS-PLAN-063 (CR-WF-03): the former createRoomTask/createFromTemplate
	// pair — sequential auto-commit writes that could strand an orphan task
	// definition — was replaced by the atomic TaskCreationService aggregate
	// (lib/Service/TaskCreationService.php). The one create route goes through
	// it; setup keeps its own internal aggregate (CS-PLAN-063 D9).

	/**
	 * Assert the room task's underlying definition is visible to the actor
	 * (404 anti-enumeration, decision D3).
	 *
	 * @throws NotFoundException when the matrix denies the actor
	 */
	private function assertRoomTaskVisible(int $roomTaskId, HouseUser $actor): void {
		$roomTask = $this->findRoomTaskOrNotFound($roomTaskId);
		if ($roomTask->getDeletedAt() !== null) {
			// Tombstoned = gone from active planning (Q-70-01): 404, the
			// same denial shape as an invisible id.
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}
		$task = $this->findTaskOrNotFound($roomTask->getTaskId());
		$this->taskAccessPolicy->assertCanView($task, $actor, $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTaskId));
	}

	/**
	 * Fetch a task definition or translate the mapper's DoesNotExistException
	 * into the app NotFoundException (404, CS-PLAN-061 §3.5).
	 *
	 * @throws NotFoundException if not found
	 */
	private function findTaskOrNotFound(int $taskId): Task {
		try {
			return $this->taskMapper->find($taskId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Task not found'));
		}
	}

	/**
	 * Tombstone a room task (CS-PLAN-071 WP0, Q-070-01 / RF-070-16).
	 *
	 * Structural deletion keeps the evidence: inside one house-first
	 * transaction the row is tombstoned (`deleted_at` set, `deleted_token
	 * = id`, `is_active = 0`), its `task_assignees` and checklist-progress
	 * rows are deleted (active-planning state), and its `completed_tasks`
	 * rows and closed cycles are RETAINED (minimum history, existing
	 * visibility, valid earned effects). No production path hard-deletes structural rows outside the
	 * test-mode reset (decision D4).
	 *
	 * Service-level gates (CS-PLAN-061 §3.4): the acting user must be able
	 * to see the room task's definition (404 for invisible tasks) and may
	 * not be a child (403). Tombstone deletion stays owner/managers-only
	 * via the same actor policy as before.
	 *
	 * @param int $roomTaskId The room task ID
	 * @param HouseUser $actor The acting user's house membership
	 * @throws NotFoundException if the room task or its definition is invisible to the actor
	 * @throws ForbiddenException if the actor is a child
	 */
	public function deleteRoomTask(int $roomTaskId, HouseUser $actor): void {
		$this->assertRoomTaskVisible($roomTaskId, $actor);
		$this->authorization->assertCanManageTasks($actor);
		$roomTask = $this->findRoomTaskOrNotFound($roomTaskId);
		$owningHouseId = $this->readService->houseIdForRoom($roomTask->getRoomId());

		$this->transactions->run(function () use ($roomTaskId, $owningHouseId): void {
			// FIRST lock of the documented order: the house row.
			$this->houseMapper->findForUpdate($owningHouseId);

			$locked = $this->roomTaskMapper->lockForUpdate($roomTaskId);
			if ($locked->getDeletedAt() !== null) {
				// Authoritative tombstone gate (Q-70-01): a concurrent
				// delete winner makes this invocation a clean 404 with
				// zero writes — the original tombstone evidence survives.
				throw new NotFoundException($this->l10n->t('Room task not found'));
			}
			$this->roomService->deleteRoomTaskPlanningState([$roomTaskId]);
			$locked->tombstone(new \DateTime());
			$this->roomTaskMapper->update($locked);
		});
	}

	/**
	 * Get task statistics for a room, restricted to the viewer's visibility
	 * (CS-PLAN-061 review fix): counts are computed over the visibility-
	 * filtered room-task set, so the stats of a room with invisible private
	 * tasks neither include them nor confirm that they exist.
	 *
	 * CR-SVC-31 root fix (CS-PLAN-040): completionRate counts REAL
	 * completions — the share of the room's current tasks with at least
	 * one completion row — instead of `(total − active)/total`, which
	 * counted deactivated/skipped tasks as completed. An empty room
	 * returns 0 (not 100).
	 *
	 * Overdue/due-today counts follow the list rows (CS-PLAN-084 RF-084-02):
	 * one batched cycle derivation and `statusFor`, so a task inside an
	 * open window counts as due, not overdue.
	 *
	 * @param int $roomId The room ID
	 * @param HouseUser $viewer The acting user's house membership
	 */
	public function getRoomTaskStats(int $roomId, HouseUser $viewer): array {
		$allTasks = $this->roomTaskMapper->findByRoomId($roomId, false);
		$visibleTasks = $this->readService->visibleRoomTasks($allTasks, $viewer)->roomTasks;
		$activeTasks = array_filter($visibleTasks, fn ($task) => $task->getIsActive());
		$statuses = $this->rowStatuses($roomId, array_values($activeTasks));
		$overdueTasks = array_filter($statuses, static fn (string $status): bool => $status === 'overdue');
		$dueTodayTasks = array_filter($statuses, static fn (string $status): bool => $status === 'due_today');
		$dueSoonTasks = array_filter($activeTasks, fn ($task) => $task->isDueWithinDays(3));

		$visibleIds = array_map(static fn (RoomTask $roomTask): int => $roomTask->getId(), $visibleTasks);
		$completedCount = $this->completedTaskMapper->countDistinctCompletedRoomTasksInSet($visibleIds);

		return [
			'totalTasks' => count($visibleTasks),
			'activeTasks' => count($activeTasks),
			'overdueTasks' => count($overdueTasks),
			'dueTodayTasks' => count($dueTodayTasks),
			'dueSoonTasks' => count($dueSoonTasks),
			'completionRate' => count($visibleTasks) > 0
				? round(min($completedCount, count($visibleTasks)) / count($visibleTasks) * 100, 2)
				: 0
		];
	}

	/**
	 * The list-row status of each active room task (RF-084-02).
	 *
	 * @param RoomTask[] $activeTasks
	 * @return array<int, string> status by room-task id
	 */
	private function rowStatuses(int $roomId, array $activeTasks): array {
		if ($activeTasks === []) {
			return [];
		}
		$facts = $this->readService->cycleFacts($this->readService->houseIdForRoom($roomId), $activeTasks);
		$statuses = [];
		foreach ($activeTasks as $roomTask) {
			$statuses[$roomTask->getId()] = $this->readService->statusFor($roomTask, $facts[$roomTask->getId()]['block'] ?? null);
		}
		return $statuses;
	}

	/**
	 * Get scheduled and completed tasks for a house within a date range.
	 *
	 * Derived-window projection (CS-PLAN-091 WP-B1, ADR-021): each visible
	 * active room task carries the cycles planned inside the range — its
	 * current cycle and the ones its rule yields after it, write-free.
	 * Only the current cycle is a completion target (`cycleKey`); it is
	 * `late` once its window has ended, which is the calendar's overdue
	 * mark (CR-084-01). Later cycles are projections.
	 *
	 * @param int $houseId The house ID
	 * @param \DateTime $from Inclusive range start
	 * @param \DateTime $to Inclusive range end
	 * @param HouseUser $viewer The acting user's house membership
	 * @return array{scheduled: array<int, array{roomTask: array, task: array, room: array, assignees: list<string>, cycles: list<array{date: string, cycleKey: ?string, windowStartOn: string, windowEndOn: string, current: bool, late: bool}>}>, completed: array<int, array>}
	 */
	public function getRangeTasks(int $houseId, \DateTime $from, \DateTime $to, HouseUser $viewer): array {
		// CS-PLAN-057: reinterpret the controller's Y-m-d keys as house-local
		// calendar bounds (UTC-normalized instants for the SQL comparison).
		// CS-PLAN-060 §3.6: the completion window is half-open [start, end)
		// — the end bound is the NEXT day's 00:00:00 (exclusive), matching
		// the window-query convention.
		$tz = $this->readService->timeZoneForHouse($houseId);
		$fromKey = $from->format('Y-m-d');
		$toKey = $to->format('Y-m-d');
		$fromBound = (new \DateTime($fromKey . ' 00:00:00', $tz))
			->setTimezone(new \DateTimeZone('UTC'));
		$toBoundExclusive = (new \DateTime($toKey . ' 00:00:00', $tz))
			->modify('+1 day')
			->setTimezone(new \DateTimeZone('UTC'));

		// Base set: every active task of the house (tombstoned and
		// inactive rows have no cycle).
		$roomTasks = $this->roomTaskMapper->findByHouse($houseId, true);
		$completions = $this->completedTaskMapper->findCompletionsBetweenByHouse($houseId, $fromBound, $toBoundExclusive);
		$completions = $this->redactInvisibleCompletionRows($completions, $viewer);

		$tasksById = $this->readService->tasksByIdForRoomTasks($roomTasks);
		$roomsById = $this->roomMapper->findByIds(array_values(array_unique(array_map(
			static fn (RoomTask $rt): int => $rt->getRoomId(),
			$roomTasks
		))));

		// Visibility filter (CS-PLAN-061 §3.3): private/admin-only base rows
		// must not reach the projection — their full task definition is
		// serialized into the response.
		$roomTasks = $this->taskAccessPolicy->filterVisibleRoomTasks($roomTasks, $tasksById, $viewer);
		$roomTaskIds = array_map(static fn (RoomTask $rt): int => (int)$rt->getId(), $roomTasks);
		$histories = $this->windows->historiesFor($roomTaskIds);
		$assigneesByRoomTask = $this->taskAssigneeMapper->findUserIdsByRoomTasks($roomTaskIds);
		$now = new \DateTime();

		$scheduled = [];
		foreach ($roomTasks as $rt) {
			$task = $tasksById[$rt->getTaskId()] ?? null;
			$room = $roomsById[$rt->getRoomId()] ?? null;
			if ($task === null || $room === null) {
				// Orphaned row (task/room definition gone) — skip it.
				continue;
			}
			$cycles = $this->rangeCycles(
				$this->windows->cyclesThrough($rt, $task, $histories[$rt->getId()] ?? null, $tz, $now, $toKey, $fromKey),
				$fromKey,
				$toKey,
				WindowService::todayKey($now, $tz)
			);
			// Skip tasks without a cycle planned inside the range.
			if ($cycles === []) {
				continue;
			}
			$scheduled[] = [
				'roomTask' => $rt->jsonSerialize($tz),
				'task' => $task->jsonSerialize(),
				'room' => $room->jsonSerialize(),
				'assignees' => $assigneesByRoomTask[$rt->getId()] ?? [],
				'cycles' => $cycles,
			];
		}

		return [
			'scheduled' => $scheduled,
			'completed' => $completions,
		];
	}

	/**
	 * The calendar entries of one room task: its cycles planned on a
	 * house-local day inside the range (CS-PLAN-057 day keys).
	 *
	 * @param list<CurrentCycle> $cycles The current cycle first
	 * @return list<array{date: string, cycleKey: ?string, windowStartOn: string, windowEndOn: string, current: bool, late: bool}>
	 */
	private function rangeCycles(array $cycles, string $fromKey, string $toKey, string $todayKey): array {
		$entries = [];
		foreach ($cycles as $index => $cycle) {
			$date = $cycle->plannedOn();
			if ($date < $fromKey || $date > $toKey) {
				continue;
			}
			$entries[] = [
				'date' => $date,
				'cycleKey' => $index === 0 ? $cycle->key : null,
				'windowStartOn' => $cycle->startOn,
				'windowEndOn' => $cycle->endOn,
				'current' => $index === 0,
				'late' => $index === 0 && $cycle->isOverdue($todayKey),
			];
		}
		return $entries;
	}

	/**
	 * Redact the task content of completion-history rows the viewer may not
	 * see (CS-PLAN-061 review fix): rows stay house-scoped (decision D9 —
	 * aggregate calendar history), but the task NAME of an invisible
	 * private/admin-only task is nulled instead of leaking into the
	 * response. One batched task fetch + one batched assignee fetch (no
	 * N+1).
	 *
	 * @param array<int, array<string, mixed>> $completions Flat completion rows
	 * @param HouseUser $viewer The acting user's house membership
	 * @return array<int, array<string, mixed>> Rows with redacted task names
	 */
	private function redactInvisibleCompletionRows(array $completions, HouseUser $viewer): array {
		if ($completions === []) {
			return [];
		}

		$taskIdSet = [];
		foreach ($completions as $row) {
			$taskIdSet[(int)$row['taskId']] = true;
		}
		$taskIds = array_keys($taskIdSet);

		$tasksById = $this->taskMapper->findByIds($taskIds);
		$assigneesByTask = $this->taskAssigneeMapper->findUserIdsByTaskIds($taskIds);

		foreach ($completions as &$row) {
			$taskId = (int)$row['taskId'];
			$task = $tasksById[$taskId] ?? null;
			$visible = $task !== null
				&& $this->taskAccessPolicy->canView($task, $viewer, $assigneesByTask[$taskId] ?? []);
			if (!$visible) {
				$row['taskName'] = null;
			}
		}
		unset($row);

		return $completions;
	}
}
