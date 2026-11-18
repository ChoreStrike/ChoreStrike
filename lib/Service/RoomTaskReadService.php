<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskChecklistItemMapper;
use OCA\ChoreStrike\Db\TaskMapper;

/**
 * Focused owner of the room-task READ cluster (CS-PLAN-067 §3.1): batched
 * visibility filtering, task/room enrichment, canonical assignee sets and
 * the house/room/scope/daily execution-row assembly.
 *
 * Every enrichment is a bounded batch (TaskMapper/RoomMapper::findByIds,
 * TaskAssigneeMapper::findUserIdsByRoomTasks) — no per-row lookup, including
 * duplicate enrichment inside the policy filter. Empty ID sets issue no
 * enrichment query (mapper batch methods short-circuit). Visibility always
 * runs through {@see TaskAccessPolicy}; this service never duplicates the
 * matrix itself.
 */
class RoomTaskReadService {
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private FloorMapper $floorMapper;
	private HouseMapper $houseMapper;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private TaskAccessPolicy $taskAccessPolicy;
	private CalendarService $calendarService;
	private WindowService $windows;
	private AppLogger $logger;
	private TaskChecklistItemMapper $checklistItemMapper;

	public function __construct(
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		RoomMapper $roomMapper,
		FloorMapper $floorMapper,
		HouseMapper $houseMapper,
		TaskAssigneeMapper $taskAssigneeMapper,
		TaskAccessPolicy $taskAccessPolicy,
		CalendarService $calendarService,
		WindowService $windows,
		AppLogger $logger,
		TaskChecklistItemMapper $checklistItemMapper,
	) {
		$this->roomTaskMapper = $roomTaskMapper;
		$this->taskMapper = $taskMapper;
		$this->roomMapper = $roomMapper;
		$this->floorMapper = $floorMapper;
		$this->houseMapper = $houseMapper;
		$this->taskAssigneeMapper = $taskAssigneeMapper;
		$this->taskAccessPolicy = $taskAccessPolicy;
		$this->calendarService = $calendarService;
		$this->windows = $windows;
		$this->logger = $logger;
		$this->checklistItemMapper = $checklistItemMapper;
	}

	/**
	 * The current cycle of each room task from ONE batched derivation
	 * (CS-PLAN-091 WP-B1, ADR-021): two completion reads and at most one
	 * definition read, whatever the row count; write-free.
	 *
	 * @param RoomTask[] $roomTasks
	 * @param array<int, Task>|null $tasksById Definitions the caller already loaded
	 * @param \DateTimeInterface|null $now The instant to derive for (default: now)
	 * @return array<int, array{cycle: ?CurrentCycle, block: ?array<string, mixed>}> By room task id
	 */
	public function cycleFacts(int $houseId, array $roomTasks, ?array $tasksById = null, ?\DateTimeInterface $now = null): array {
		if ($roomTasks === []) {
			return [];
		}
		$now ??= new \DateTime();
		$tz = $this->timeZoneForHouse($houseId);
		$today = WindowService::todayKey($now, $tz);
		$tasksById ??= $this->tasksByIdForRoomTasks($roomTasks);
		$histories = $this->windows->historiesFor(array_map(static fn (RoomTask $roomTask): int => (int)$roomTask->getId(), $roomTasks));

		$facts = [];
		foreach ($roomTasks as $roomTask) {
			$id = (int)$roomTask->getId();
			$task = $tasksById[$roomTask->getTaskId()] ?? null;
			$cycle = $task === null ? null : $this->windows->currentCycle($roomTask, $task, $histories[$id] ?? null, $tz, $now);
			$facts[$id] = [
				'cycle' => $cycle,
				'block' => $task === null ? null : $this->windows->block($roomTask, $task, $cycle, $today),
			];
		}
		return $facts;
	}

	/**
	 * `cycle` block enrichment: attaches the completion target and window
	 * of each row (API reference, Room Tasks). A task without a cycle
	 * keeps `cycle: null`.
	 *
	 * @param list<array<string, mixed>> $rows Rows carrying `roomTask` and `task`
	 * @return list<array<string, mixed>> Rows with a `cycle` key
	 */
	public function withCycleBlocks(array $rows, int $houseId, ?\DateTimeInterface $now = null): array {
		$roomTasks = [];
		$tasksById = [];
		foreach ($rows as $row) {
			$roomTasks[] = $row['roomTask'];
			$tasksById[$row['roomTask']->getTaskId()] = $row['task'];
		}
		$facts = $this->cycleFacts($houseId, $roomTasks, $tasksById, $now);
		foreach ($rows as $index => $row) {
			$rows[$index]['cycle'] = $facts[(int)$row['roomTask']->getId()]['block'] ?? null;
		}
		return $rows;
	}

	/**
	 * Display status under derived windows (API reference): overdue once
	 * the window has ended, due today while today lies inside it — never
	 * overdue before that (CS-PLAN-084 D-084-08). A task without a window
	 * keeps the entity's rule, which only knows inactive from active.
	 *
	 * @param array<string, mixed>|null $block The row's `cycle` block
	 */
	public function statusFor(RoomTask $roomTask, ?array $block): string {
		if (!$roomTask->getIsActive()) {
			return 'inactive';
		}
		return match ($block['state'] ?? null) {
			WindowService::STATE_OVERDUE => 'overdue',
			WindowService::STATE_OPEN => 'due_today',
			WindowService::STATE_UPCOMING => $roomTask->isDueWithinDays(3) ? 'due_soon' : 'upcoming',
			default => 'upcoming',
		};
	}

	/**
	 * The serialized room task whose `status` follows {@see statusFor}
	 * (CR-084-03): every response that updates a task row — list, execution,
	 * filtered-daily, create, completion, undo and attribution — reports
	 * the same status for the same cycle.
	 *
	 * @param array<string, mixed>|null $block The row's `cycle` block
	 * @return array<string, mixed>
	 */
	public function roomTaskPayload(RoomTask $roomTask, \DateTimeZone $tz, ?array $block): array {
		$data = $roomTask->jsonSerialize($tz);
		$data['status'] = $this->statusFor($roomTask, $block);
		return $data;
	}

	/**
	 * {@see roomTaskPayload} for a single row whose cycle is not loaded
	 * yet (create and correction responses).
	 *
	 * @return array<string, mixed>
	 */
	public function selectedRoomTaskPayload(int $houseId, RoomTask $roomTask, \DateTimeZone $tz): array {
		$block = $this->cycleFacts($houseId, [$roomTask])[(int)$roomTask->getId()]['block'] ?? null;
		return $this->roomTaskPayload($roomTask, $tz, $block);
	}

	/**
	 * {@see selectedRoomTaskPayload} for a row whose write has already
	 * committed (create, completion replay, attribution): a failing status
	 * read degrades to the entity rule instead of an error that would
	 * invite the client to repeat persisted work.
	 *
	 * @param string $action Stable name of the calling action for the log
	 * @return array<string, mixed>
	 */
	public function committedRoomTaskPayload(int $houseId, RoomTask $roomTask, \DateTimeZone $tz, string $action): array {
		try {
			return $this->selectedRoomTaskPayload($houseId, $roomTask, $tz);
		} catch (\Throwable $e) {
			$this->logger->warning('Room task status read failed after a committed write', [
				'action' => $action,
				'roomTaskId' => $roomTask->getId(),
				'exception' => get_class($e),
			]);
			return $roomTask->jsonSerialize($tz);
		}
	}

	/**
	 * Resolve the house timezone for day-boundary bucketing (CS-PLAN-057).
	 */
	public function timeZoneForHouse(int $houseId): \DateTimeZone {
		return $this->calendarService->getTimeZone($this->houseMapper->find($houseId));
	}

	/**
	 * Resolve the owning house ID behind a room (room → floor → house).
	 *
	 * Lock-order primitive (CS-PLAN-071 WP0): room-task writers resolve
	 * the aggregate's house from the row's room so the house-first
	 * transaction locks the correct house row.
	 */
	public function houseIdForRoom(int $roomId): int {
		$room = $this->roomMapper->find($roomId);
		$floor = $this->floorMapper->find($room->getFloorId());
		return $floor->getHouseId();
	}

	/**
	 * Resolve the house timezone behind a room (room → floor → house).
	 */
	public function timeZoneForRoom(int $roomId): \DateTimeZone {
		$room = $this->roomMapper->find($roomId);
		return $this->timeZoneForRoomEntity($room);
	}

	/**
	 * Resolve the house timezone behind an already-fetched room entity.
	 */
	private function timeZoneForRoomEntity(Room $room): \DateTimeZone {
		$floor = $this->floorMapper->find($room->getFloorId());
		return $this->timeZoneForHouse($floor->getHouseId());
	}

	/**
	 * Fetch task definitions for a batch of room tasks in one query.
	 *
	 * @param RoomTask[] $roomTasks
	 * @return array<int, Task> Map of task ID → Task
	 */
	public function tasksByIdForRoomTasks(array $roomTasks): array {
		$taskIds = [];
		foreach ($roomTasks as $roomTask) {
			$taskIds[$roomTask->getTaskId()] = true;
		}
		return $this->taskMapper->findByIds(array_keys($taskIds));
	}

	/**
	 * Batch visibility filter for a room-task set (CS-PLAN-061 §3.3): ONE
	 * definition fetch plus ONE assignee fetch for the whole batch, the
	 * decision delegated to {@see TaskAccessPolicy::filterVisibleRoomTasks()}.
	 * An empty set short-circuits — no enrichment query at all.
	 *
	 * @param RoomTask[] $roomTasks
	 * @return RoomTask[] Visible room tasks, input order
	 */
	public function visibleRoomTasks(array $roomTasks, HouseUser $viewer): array {
		if ($roomTasks === []) {
			return [];
		}

		return $this->taskAccessPolicy->filterVisibleRoomTasks(
			$roomTasks,
			$this->tasksByIdForRoomTasks($roomTasks),
			$viewer
		);
	}

	/**
	 * Assemble visible room tasks into `{roomTask, task, room}` rows with
	 * two batch queries (TaskMapper/RoomMapper::findByIds, CS-PLAN-036).
	 * A missing task or room row (map absence) skips the room task; an
	 * empty set short-circuits — no enrichment query at all.
	 *
	 * @param RoomTask[] $roomTasks Room tasks to filter and enrich
	 * @return array<int, array{roomTask: RoomTask, task: Task, room: Room}>
	 */
	public function rowsForRoomTasks(array $roomTasks, HouseUser $viewer): array {
		if ($roomTasks === []) {
			return [];
		}

		$tasksById = $this->taskMapper->findByIds(array_keys($this->taskIdsOf($roomTasks)));
		$visibleTasks = $this->taskAccessPolicy->filterVisibleRoomTasks($roomTasks, $tasksById, $viewer);
		return $this->assembleRows($visibleTasks, $tasksById, $this->roomsByIdOf($roomTasks));
	}

	/**
	 * Assemble an ALREADY visibility-filtered room-task set into rows
	 * (RF-058-10): the due-today/overdue row paths pre-filter through
	 * {@see visibleRoomTasks()}, so re-running the policy (plus its
	 * definition/assignee enrichment) would duplicate the batch reads.
	 *
	 * @param RoomTask[] $visibleRoomTasks Viewer-visible room tasks
	 * @return array<int, array{roomTask: RoomTask, task: Task, room: Room}>
	 */
	private function rowsForVisibleRoomTasks(array $visibleRoomTasks): array {
		if ($visibleRoomTasks === []) {
			return [];
		}

		$tasksById = $this->taskMapper->findByIds(array_keys($this->taskIdsOf($visibleRoomTasks)));
		return $this->assembleRows($visibleRoomTasks, $tasksById, $this->roomsByIdOf($visibleRoomTasks));
	}

	/**
	 * @param RoomTask[] $roomTasks
	 * @return array<int, bool>
	 */
	private function taskIdsOf(array $roomTasks): array {
		$taskIds = [];
		foreach ($roomTasks as $roomTask) {
			$taskIds[$roomTask->getTaskId()] = true;
		}
		return $taskIds;
	}

	/**
	 * @param RoomTask[] $roomTasks
	 * @return array<int, Room>
	 */
	private function roomsByIdOf(array $roomTasks): array {
		$roomIds = [];
		foreach ($roomTasks as $roomTask) {
			$roomIds[$roomTask->getRoomId()] = true;
		}
		return $this->roomMapper->findByIds(array_keys($roomIds));
	}

	/**
	 * @param RoomTask[] $roomTasks
	 * @param array<int, Task> $tasksById
	 * @param array<int, Room> $roomsById
	 * @return array<int, array{roomTask: RoomTask, task: Task, room: Room}>
	 */
	private function assembleRows(array $roomTasks, array $tasksById, array $roomsById): array {
		$rows = [];
		foreach ($roomTasks as $roomTask) {
			$task = $tasksById[$roomTask->getTaskId()] ?? null;
			$room = $roomsById[$roomTask->getRoomId()] ?? null;
			if ($task === null || $room === null) {
				continue;
			}

			$rows[] = [
				'roomTask' => $roomTask,
				'task' => $task,
				'room' => $room,
			];
		}

		return $rows;
	}

	/**
	 * Canonical assignee sets for a batch of room tasks (one query).
	 *
	 * @param RoomTask[] $roomTasks
	 * @return array<int, string[]> Map of room task ID => unique Nextcloud user IDs
	 */
	public function assigneesByRoomTasks(array $roomTasks): array {
		$roomTaskIds = array_map(static fn (RoomTask $rt): int => $rt->getId(), $roomTasks);
		return $this->taskAssigneeMapper->findUserIdsByRoomTasks($roomTaskIds);
	}

	/**
	 * Every room task of a house the viewer may see (CS-PLAN-067 D1) — the
	 * one batched read that replaces the per-room request loop of
	 * TaskManager and SchedulingView. Inactive rows are only included when
	 * explicitly requested.
	 *
	 * @return RoomTask[] Visible room tasks, deterministic `next_due, created_at, id` order
	 */
	public function getHouseRoomTasks(int $houseId, HouseUser $viewer, bool $activeOnly = true): array {
		$roomTasks = $this->roomTaskMapper->findByHouse($houseId, $activeOnly);
		return $this->visibleRoomTasks($roomTasks, $viewer);
	}

	/**
	 * Get the room tasks of one room the viewer may see (visibility matrix,
	 * CS-PLAN-061 §3.3).
	 *
	 * @return RoomTask[] Visible room tasks
	 */
	public function getRoomTasks(int $roomId, HouseUser $viewer, bool $activeOnly = true): array {
		$roomTasks = $this->roomTaskMapper->findByRoomId($roomId, $activeOnly);
		return $this->visibleRoomTasks($roomTasks, $viewer);
	}

	/**
	 * Get room tasks for a scope, filtered to one house and the viewer's
	 * visibility (CR-053-02, CS-PLAN-061 §3.3).
	 *
	 * The scopeId is caller-supplied and scopes are not house-bound, so the
	 * unscoped fetch is intersected with the house-scoped batch fetch
	 * (skip-on-missing semantics): cross-house entries are silently absent
	 * instead of leaking assignee user ids to another house.
	 *
	 * @return RoomTask[] Scope tasks belonging to the house and visible to the viewer (original order)
	 */
	public function getScopeTasksScopedToHouse(int $scopeId, int $houseId, HouseUser $viewer, bool $activeOnly = true): array {
		$roomTasks = $this->roomTaskMapper->findByScopeId($scopeId, $activeOnly);
		if ($roomTasks === []) {
			return [];
		}

		$scoped = $this->roomTaskMapper->findByIdsScopedToHouse(
			array_map(static fn (RoomTask $roomTask): int => $roomTask->getId(), $roomTasks),
			$houseId
		);

		$scopedIds = array_flip(array_map(static fn (RoomTask $roomTask): int => $roomTask->getId(), $scoped));

		$inHouse = array_values(array_filter(
			$roomTasks,
			static fn (RoomTask $roomTask): bool => isset($scopedIds[$roomTask->getId()])
		));

		return $this->visibleRoomTasks($inHouse, $viewer);
	}

	/**
	 * Overdue execution rows (CS-PLAN-066 §3.1 D1): every viewer-visible
	 * active room task whose current window has ended — one red entry per
	 * task, whatever the number of cycles that passed (ADR-021 decision 1).
	 *
	 * @return array<int, array{roomTask: RoomTask, task: Task, room: Room}>
	 */
	public function getOverdueTaskRows(int $houseId, HouseUser $viewer, ?int $roomId = null): array {
		return $this->withChecklistCounts($this->rowsInState($houseId, $viewer, $roomId, WindowService::STATE_OVERDUE));
	}

	/**
	 * Due-today execution rows (CS-PLAN-066 §3.1 D1): every viewer-visible
	 * active room task whose window is open today.
	 *
	 * @return array<int, array{roomTask: RoomTask, task: Task, room: Room}>
	 */
	public function getDueTodayTaskRows(int $houseId, HouseUser $viewer, ?int $roomId = null): array {
		return $this->withChecklistCounts($this->rowsInState($houseId, $viewer, $roomId, WindowService::STATE_OPEN));
	}

	/**
	 * Viewer-visible active rows whose cycle is in the given state, in the
	 * mapper's canonical `next_due, created_at, id` order. Visibility
	 * first: the one cycle derivation covers only rows the viewer may see.
	 *
	 * @return list<array<string, mixed>> Rows with their `cycle` block
	 */
	private function rowsInState(int $houseId, HouseUser $viewer, ?int $roomId, string $state, ?\DateTimeInterface $now = null): array {
		$candidates = array_values(array_filter(
			$this->roomTaskMapper->findByHouse($houseId, true),
			static fn (RoomTask $roomTask): bool => $roomId === null || $roomTask->getRoomId() === $roomId
		));
		$rows = $this->withCycleBlocks($this->rowsForVisibleRoomTasks($this->visibleRoomTasks($candidates, $viewer)), $houseId, $now);
		return array_values(array_filter(
			$rows,
			static fn (array $row): bool => ($row['cycle']['state'] ?? null) === $state
		));
	}

	/**
	 * The daily rows of a user: every visible room task whose window is
	 * open on the given day, restricted for a child by eligibility,
	 * availability and the daily limit.
	 *
	 * A row planned for another day inside its flex window carries
	 * `openWindow: true`; My Day lists those in their own group
	 * (BUG-UX-005), and they never consume a child's slot.
	 *
	 * CS-PLAN-065 (decision D4): for child users the assignment eligibility
	 * (assigned to the viewer OR unassigned — the canonical My Day "mine"
	 * semantics) is evaluated BEFORE the availability gate and the daily
	 * limit, so an ineligible task can never consume one of the child's
	 * slots. Non-child users receive all visible daily tasks.
	 *
	 * RF-058-11: every returned row carries its canonical `assignees` set
	 * from ONE batched read (shared with the child eligibility filter), so
	 * the consuming endpoint never issues a second assignee query.
	 *
	 * @return array<int, array{roomTask: RoomTask, task: Task, room: Room, assignees: string[]}> Filtered tasks with details
	 */
	public function getFilteredDailyTasksForUser(
		int $houseId,
		HouseUser $viewer,
		\DateTime $date,
	): array {
		$dayKey = WindowService::todayKey($date, $this->timeZoneForHouse($houseId));
		$dueRows = [];
		$openWindow = [];
		foreach ($this->rowsInState($houseId, $viewer, null, WindowService::STATE_OPEN, $date) as $row) {
			if ($row['cycle']['centreOn'] === $dayKey) {
				$dueRows[] = $row;
			} else {
				$openWindow[] = $row + ['openWindow' => true];
			}
		}

		// Non-child users see all (visible) tasks.
		if (!$viewer->isChild()) {
			return $this->withChecklistCounts($this->withAssignees([...$dueRows, ...$openWindow]));
		}
		return $this->childDailyRows($houseId, $viewer, $date, $dueRows, $openWindow);
	}

	/**
	 * A child's My Day rows: eligibility first (D4: assigned to the viewer
	 * OR unassigned), then the weekday availability gate and the owner-set
	 * daily limit (D5, {@see withinChildLimit()}); open-window rows follow
	 * the limit and never consume a slot (BUG-UX-005). The single batched
	 * assignee read serves eligibility AND the returned rows (RF-058-11).
	 *
	 * @param list<array<string, mixed>> $dueRows The day's visible rows
	 * @param list<array<string, mixed>> $openWindow The open-window rows
	 * @return list<array<string, mixed>>
	 */
	private function childDailyRows(int $houseId, HouseUser $viewer, \DateTime $date, array $dueRows, array $openWindow): array {
		$assigneesByRoomTask = $this->taskAssigneeMapper->findUserIdsByRoomTasks(
			array_map(static fn (array $item): int => $item['roomTask']->getId(), [...$dueRows, ...$openWindow])
		);
		$isEligible = static fn (array $item): bool => self::isChildEligible($item, $viewer, $assigneesByRoomTask);
		$eligible = array_values(array_filter($dueRows, $isEligible));

		$preferences = $viewer->getSchedulingPreferences();
		// Current day name (lowercase, house TZ — CS-PLAN-057).
		$currentDay = strtolower(\DateTime::createFromInterface($date)->setTimezone($this->timeZoneForHouse($houseId))->format('l'));
		if (!($preferences['weekdayAvailability'][$currentDay] ?? true)) {
			return [];
		}
		// A missing or non-finite limit means unlimited.
		$maxDailyTasks = $preferences['maxDailyTasks'] ?? null;
		if (is_int($maxDailyTasks)) {
			$eligible = self::withinChildLimit($eligible, $viewer, $assigneesByRoomTask, $maxDailyTasks);
		}
		$eligible = [...$eligible, ...array_values(array_filter($openWindow, $isEligible))];

		return $this->withChecklistCounts($this->withAssignees($eligible, $assigneesByRoomTask));
	}

	/**
	 * The daily limit caps what My Day offers, never what the child owes
	 * (C2, BUG-UX-013): every own task stays visible, even above the limit,
	 * and unassigned rows only fill the slots that remain. A hidden own
	 * task would turn into a silent miss.
	 *
	 * @param list<array<string, mixed>> $eligible Eligible rows in canonical order
	 * @param array<int, string[]> $assigneesByRoomTask
	 * @return list<array<string, mixed>>
	 */
	private static function withinChildLimit(array $eligible, HouseUser $viewer, array $assigneesByRoomTask, int $maxDailyTasks): array {
		$isOwn = static fn (array $item): bool => self::isOwn($item, $viewer, $assigneesByRoomTask);
		$openSlots = max(0, $maxDailyTasks - count(array_filter($eligible, $isOwn)));

		return array_values(array_filter($eligible, static function (array $item) use ($isOwn, &$openSlots): bool {
			return $isOwn($item) || $openSlots-- > 0;
		}));
	}

	/**
	 * Whether a row is the child's: assigned to the child or unassigned.
	 *
	 * @param array<string, mixed> $item
	 * @param array<int, string[]> $assigneesByRoomTask
	 */
	private static function isChildEligible(array $item, HouseUser $viewer, array $assigneesByRoomTask): bool {
		return self::isOwn($item, $viewer, $assigneesByRoomTask)
			|| ($assigneesByRoomTask[$item['roomTask']->getId()] ?? []) === [];
	}

	/**
	 * Whether the row is assigned to the child. The canonical assignees
	 * decide; there is no per-cycle handover (ADR-021 decision 13).
	 *
	 * @param array<string, mixed> $item
	 * @param array<int, string[]> $assigneesByRoomTask
	 */
	private static function isOwn(array $item, HouseUser $viewer, array $assigneesByRoomTask): bool {
		return in_array($viewer->getNextcloudUserId(), $assigneesByRoomTask[$item['roomTask']->getId()] ?? [], true);
	}

	/**
	 * Decorate execution rows with their canonical assignee sets (one
	 * batched read when no map is supplied — RF-058-11).
	 *
	 * @param array<int, array{roomTask: RoomTask, task: Task, room: Room}> $rows
	 * @param array<int, string[]>|null $assigneesByRoomTask Pre-fetched map (reuse without a second query)
	 * @return array<int, array{roomTask: RoomTask, task: Task, room: Room, assignees: string[]}>
	 */
	private function withAssignees(array $rows, ?array $assigneesByRoomTask = null): array {
		if ($rows === []) {
			return [];
		}
		if ($assigneesByRoomTask === null) {
			$assigneesByRoomTask = $this->taskAssigneeMapper->findUserIdsByRoomTasks(
				array_map(static fn (array $item): int => $item['roomTask']->getId(), $rows)
			);
		}
		foreach ($rows as $index => $item) {
			$rows[$index]['assignees'] = $assigneesByRoomTask[$item['roomTask']->getId()] ?? [];
		}
		return $rows;
	}

	/**
	 * The definition's checklist item count per execution row, from one
	 * grouped query (BUG-UX-003): My Day renders the checklist toggle only
	 * for rows with items, without a per-row request.
	 *
	 * @param list<array<string, mixed>> $rows Rows carrying `task`
	 * @return list<array<string, mixed>> Rows with a `checklistItemCount` key
	 */
	private function withChecklistCounts(array $rows): array {
		if ($rows === []) {
			return [];
		}
		$counts = $this->checklistItemMapper->countByTaskIds(
			array_map(static fn (array $row): int => (int)$row['task']->getId(), $rows)
		);
		foreach ($rows as $index => $row) {
			$rows[$index]['checklistItemCount'] = $counts[(int)$row['task']->getId()] ?? 0;
		}
		return $rows;
	}
}
