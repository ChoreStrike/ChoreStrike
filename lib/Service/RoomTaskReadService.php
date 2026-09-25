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
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

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
	private OccurrenceService $occurrenceService;
	private PauseProjectionService $pauseProjection;
	private IL10N $l10n;

	public function __construct(
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		RoomMapper $roomMapper,
		FloorMapper $floorMapper,
		HouseMapper $houseMapper,
		TaskAssigneeMapper $taskAssigneeMapper,
		TaskAccessPolicy $taskAccessPolicy,
		CalendarService $calendarService,
		OccurrenceService $occurrenceService,
		PauseProjectionService $pauseProjection,
		IL10N $l10n,
	) {
		$this->roomTaskMapper = $roomTaskMapper;
		$this->taskMapper = $taskMapper;
		$this->roomMapper = $roomMapper;
		$this->floorMapper = $floorMapper;
		$this->houseMapper = $houseMapper;
		$this->taskAssigneeMapper = $taskAssigneeMapper;
		$this->taskAccessPolicy = $taskAccessPolicy;
		$this->calendarService = $calendarService;
		$this->occurrenceService = $occurrenceService;
		$this->pauseProjection = $pauseProjection;
		$this->l10n = $l10n;
	}

	/**
	 * Occurrence DTO block enrichment (CS-PLAN-072 WP1): attaches the
	 * canonical completion target (`occurrenceId`, `expectedVersion`,
	 * `selectionToken`, classification, saved window/zone) to execution
	 * rows — batched over the house, write-free. Tasks without a target
	 * (all fulfilled) keep `occurrence: null`.
	 *
	 * @param list<array<string, mixed>> $rows Rows carrying `roomTask`
	 * @param int $houseId House scope for the batched derivation
	 * @return list<array<string, mixed>> Rows with an `occurrence` key
	 */
	public function withOccurrenceBlocks(array $rows, int $houseId): array {
		$roomTasks = [];
		foreach ($rows as $row) {
			if (isset($row['roomTask']) && $row['roomTask'] instanceof RoomTask) {
				$roomTasks[] = $row['roomTask'];
			}
		}
		if ($roomTasks === []) {
			return $rows;
		}

		$selections = $this->occurrenceSelectionsForHouse($houseId, $roomTasks);
		$pauseFingerprint = $this->pauseFingerprintForHouse($houseId);
		foreach ($rows as &$row) {
			$roomTask = $row['roomTask'] ?? null;
			if ($roomTask instanceof RoomTask) {
				$selection = $selections[$roomTask->getId()] ?? null;
				$row['occurrence'] = $selection !== null
					? $this->occurrenceBlockFor($selection['occurrence'], $selection['revision'], $selection['classification'], $houseId, $pauseFingerprint, $selection['workAppointment'] ?? null)
					: null;
			}
		}
		unset($row);
		return $rows;
	}

	/**
	 * Batched canonical selection map for a house's room tasks (thin
	 * delegate over the occurrence authority, write-free).
	 *
	 * @param RoomTask[] $roomTasks
	 * @return array<int, array{occurrence: \OCA\ChoreStrike\Db\Occurrence, revision: \OCA\ChoreStrike\Db\ScheduleRevision, classification: string, workAppointment?: array{date: ?string, zone: string}}|null>
	 */
	public function occurrenceSelectionsForHouse(int $houseId, array $roomTasks): array {
		if ($roomTasks === []) {
			return [];
		}
		return $this->occurrenceService->selectionsForHouseTasks($houseId, $roomTasks, new \DateTime());
	}

	/**
	 * The occurrence DTO block for a selection entry (thin delegate); the
	 * house context keeps the WP2 token fingerprint consistent between
	 * the read that shows a target and the completion that re-validates
	 * it. `$selection` carries the derived work appointment.
	 *
	 * @param array{date: ?string, zone: string}|null $workAppointment
	 * @return array<string, mixed>
	 */
	public function occurrenceBlockFor(\OCA\ChoreStrike\Db\Occurrence $occurrence, \OCA\ChoreStrike\Db\ScheduleRevision $revision, string $classification, int $houseId = 0, ?string $pauseFingerprint = null, ?array $workAppointment = null): array {
		return $this->occurrenceService->occurrenceBlock($occurrence, $revision, $classification, $houseId, $pauseFingerprint, $workAppointment);
	}

	/**
	 * The house pause-revision fingerprint (WP2 token input, one query).
	 */
	public function pauseFingerprintForHouse(int $houseId): string {
		return $this->pauseProjection->housePauseFingerprint($houseId);
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
	 * Occurrence-authority overdue set (CS-PLAN-072 WP1): every active
	 * room task whose canonical selection is a MISSED occurrence (window
	 * ended, unfulfilled, uncovered) — exactly one catch-up card per
	 * chore; completing current work later covers the card instead of
	 * fulfilling the historical miss.
	 *
	 * @return RoomTask[]
	 */
	public function getOverdueTasks(int $houseId, HouseUser $viewer, ?int $roomId = null): array {
		return $this->overdueSelectionMap($houseId, $viewer, $roomId)['tasks'];
	}

	/**
	 * RF-072-WP1-10: ONE bounded selection derivation shared by the
	 * overdue filter pass and the row enrichment — the rows endpoint never
	 * re-runs the whole-house selection.
	 *
	 * @return array{tasks: RoomTask[], selections: array<int, array{occurrence: \OCA\ChoreStrike\Db\Occurrence, revision: \OCA\ChoreStrike\Db\ScheduleRevision, classification: string}|null>}
	 */
	private function overdueSelectionMap(int $houseId, HouseUser $viewer, ?int $roomId): array {
		$now = new \DateTime();
		$candidates = array_values(array_filter(
			$this->roomTaskMapper->findByHouse($houseId, true),
			fn (RoomTask $roomTask): bool => $roomId === null || $roomTask->getRoomId() === $roomId
		));
		$selections = $this->occurrenceService->selectionsForHouseTasks($houseId, $candidates, $now);

		$result = [];
		foreach ($candidates as $roomTask) {
			$selection = $selections[$roomTask->getId()] ?? null;
			if ($selection !== null && $selection['classification'] === 'late') {
				$result[] = $roomTask;
			}
		}
		return ['tasks' => $this->visibleRoomTasks($result, $viewer), 'selections' => $selections];
	}

	/**
	 * Occurrence-authority due-today set: viewer-visible active tasks with
	 * an AGREED occurrence planned on the current house-local day.
	 *
	 * @return RoomTask[]
	 */
	public function getDueTodayTasks(int $houseId, HouseUser $viewer, ?int $roomId = null): array {
		$result = [];
		foreach ($this->getDailyTasks($houseId, new \DateTime()) as $roomTask) {
			if ($roomId === null || $roomTask->getRoomId() === $roomId) {
				$result[] = $roomTask;
			}
		}
		return $this->visibleRoomTasks($result, $viewer);
	}

	/**
	 * Due-today execution rows with task/room details (CS-PLAN-066 §3.1 D1):
	 * the same viewer-visible set as {@see getDueTodayTasks()}, assembled
	 * into the canonical `{roomTask, task, room}` row shape the enriched
	 * daily endpoint returns — one contract for every execution row consumer.
	 * RF-072-WP1-10: the enrichment selection runs over the (small) visible
	 * due set only, never over the whole house again.
	 *
	 * @return array<int, array{roomTask: RoomTask, task: Task, room: Room}>
	 */
	public function getDueTodayTaskRows(int $houseId, HouseUser $viewer, ?int $roomId = null): array {
		$now = new \DateTime();
		$dayKey = $this->calendarService->dayKey($now, $this->timeZoneForHouse($houseId));
		$candidates = $this->roomTaskMapper->findByHouse($houseId, true);
		$agreedByTask = $this->occurrenceService->agreedOccurrencesForHouseTasks($houseId, $candidates, $dayKey, $dayKey);

		$dueTasks = [];
		foreach ($candidates as $roomTask) {
			if (($agreedByTask[$roomTask->getId()] ?? []) !== []
				&& ($roomId === null || $roomTask->getRoomId() === $roomId)) {
				$dueTasks[] = $roomTask;
			}
		}
		$visible = $this->visibleRoomTasks($dueTasks, $viewer);

		$rows = $this->rowsForVisibleRoomTasks($visible);
		$selections = $this->occurrenceService->selectionsForHouseTasks($houseId, $visible, $now);
		$pauseFingerprint = $this->pauseFingerprintForHouse($houseId);
		foreach ($rows as $index => $row) {
			$roomTask = $row['roomTask'];
			$selection = $selections[$roomTask->getId()] ?? null;
			$rows[$index]['occurrence'] = $selection !== null
				? $this->occurrenceBlockFor($selection['occurrence'], $selection['revision'], $selection['classification'], $houseId, $pauseFingerprint, $selection['workAppointment'] ?? null)
				: null;
		}
		return $rows;
	}

	/**
	 * Overdue execution rows with task/room details (CS-PLAN-066 §3.1 D1):
	 * the same viewer-visible set as {@see getOverdueTasks()}, assembled
	 * into the canonical `{roomTask, task, room}` row shape. Each row
	 * carries its occurrence block — the catch-up card (CS-PLAN-072 WP1).
	 * RF-072-WP1-10: the filter pass and the enrichment share ONE
	 * selection map.
	 *
	 * @return array<int, array{roomTask: RoomTask, task: Task, room: Room}>
	 */
	public function getOverdueTaskRows(int $houseId, HouseUser $viewer, ?int $roomId = null): array {
		$map = $this->overdueSelectionMap($houseId, $viewer, $roomId);
		$rows = $this->rowsForVisibleRoomTasks($map['tasks']);
		$pauseFingerprint = $this->pauseFingerprintForHouse($houseId);
		foreach ($rows as $index => $row) {
			$roomTask = $row['roomTask'];
			$selection = $map['selections'][$roomTask->getId()] ?? null;
			$rows[$index]['occurrence'] = $selection !== null
				? $this->occurrenceBlockFor($selection['occurrence'], $selection['revision'], $selection['classification'], $houseId, $pauseFingerprint, $selection['workAppointment'] ?? null)
				: null;
		}
		return $rows;
	}

	/**
	 * Get tasks due within the next N days for a house, filtered to the
	 * viewer's visibility.
	 *
	 * @return RoomTask[]
	 */
	public function getDueWithinDaysTasks(int $houseId, int $days, HouseUser $viewer, ?int $roomId = null): array {
		$roomTasks = $this->roomTaskMapper->findDueWithinDaysByHouse($houseId, $days, $roomId);
		return $this->visibleRoomTasks($roomTasks, $viewer);
	}

	/**
	 * Occurrence-authority claimable set: overdue (a missed, uncovered
	 * occurrence exists) + assigned to at least one member via the
	 * canonical join table, filtered to the viewer's visibility.
	 *
	 * @return RoomTask[]
	 */
	public function getClaimableOverdueTasks(int $houseId, HouseUser $viewer): array {
		$overdueTasks = $this->getOverdueTasks($houseId, $viewer);
		if ($overdueTasks === []) {
			return [];
		}

		$assigneesByTask = $this->taskAssigneeMapper->findUserIdsByRoomTasks(
			array_map(static fn (RoomTask $task) => $task->getId(), $overdueTasks)
		);

		$claimableTasks = array_filter(
			$overdueTasks,
			static fn (RoomTask $task) => ($assigneesByTask[$task->getId()] ?? []) !== []
		);

		$claimable = array_values($claimableTasks);
		return $this->visibleRoomTasks($claimable, $viewer);
	}

	/**
	 * Occurrence-authority daily set (CS-PLAN-072 WP1): every ACTIVE room
	 * task of the house with an AGREED occurrence planned on the given
	 * house-local day — materialized rows plus virtual keys the frontier
	 * already covers (a stopped cron never changes the answer, and reads
	 * cause no DB writes; virtual and persisted representations share the
	 * same public `<scheduleRevisionId>:<ordinal>` key).
	 *
	 * @return RoomTask[] Deterministic `next_due, created_at, id` order
	 */
	public function getDailyTasks(int $houseId, \DateTime $date): array {
		$dayKey = $this->calendarService->dayKey($date, $this->timeZoneForHouse($houseId));
		$candidates = $this->roomTaskMapper->findByHouse($houseId, true);
		$agreedByTask = $this->occurrenceService->agreedOccurrencesForHouseTasks($houseId, $candidates, $dayKey, $dayKey);

		$result = [];
		foreach ($candidates as $roomTask) {
			if (($agreedByTask[$roomTask->getId()] ?? []) !== []) {
				$result[] = $roomTask;
			}
		}
		return $result;
	}

	/**
	 * Get the day's tasks including task and room details, filtered to the
	 * viewer's visibility (CS-PLAN-061 §3.3) — the shared assembly behind
	 * the daily and filtered-daily reads.
	 *
	 * @return array<int, array{roomTask: RoomTask, task: Task, room: Room}>
	 */
	public function getDailyTasksWithDetails(int $houseId, \DateTime $date, HouseUser $viewer): array {
		$dailyTasks = $this->getDailyTasks($houseId, $date);
		return $this->rowsForRoomTasks($dailyTasks, $viewer);
	}

	/**
	 * Get filtered daily tasks for a user based on their role and scheduling
	 * preferences, restricted to the viewer's visibility matrix.
	 *
	 * CS-PLAN-065 (decision D4): for child users the assignment eligibility
	 * (assigned to the viewer OR unassigned — the canonical My Day "mine"
	 * semantics) is evaluated BEFORE the availability gate and the daily
	 * limit, so an ineligible task can never consume one of the child's
	 * slots. Ordering is the canonical `nextDue, createdAt, id` (mapper
	 * guarantee), and non-child users receive all visible daily tasks.
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
		// Daily tasks, already filtered through the visibility matrix and in
		// the deterministic `nextDue, createdAt, id` order — each row
		// enriched with the canonical occurrence target (WP1).
		$allTasks = $this->withOccurrenceBlocks(
			$this->getDailyTasksWithDetails($houseId, $date, $viewer),
			$houseId
		);

		// Non-child users see all (visible) tasks.
		if (!$viewer->isChild()) {
			return $this->withAssignees($allTasks);
		}

		// Eligibility first (D4): assigned to the viewer OR unassigned.
		// The single batched assignee read serves eligibility AND the
		// returned rows (RF-058-11 — no duplicate assignee query).
		$assigneesByRoomTask = $this->taskAssigneeMapper->findUserIdsByRoomTasks(
			array_map(static fn (array $item): int => $item['roomTask']->getId(), $allTasks)
		);
		$eligible = array_values(array_filter(
			$allTasks,
			function (array $item) use ($assigneesByRoomTask, $viewer): bool {
				$assignees = $assigneesByRoomTask[$item['roomTask']->getId()] ?? [];
				return $assignees === [] || in_array($viewer->getNextcloudUserId(), $assignees, true);
			}
		));

		// Get user's scheduling preferences
		$preferences = $viewer->getSchedulingPreferences();
		$weekdayAvailability = $preferences['weekdayAvailability'] ?? [];
		$maxDailyTasks = $preferences['maxDailyTasks'] ?? null;

		// Get current day name (lowercase, house TZ — CS-PLAN-057)
		$tz = $this->timeZoneForHouse($houseId);
		$currentDay = strtolower(\DateTime::createFromInterface($date)->setTimezone($tz)->format('l'));

		// If not available today, return empty array
		$isAvailableToday = $weekdayAvailability[$currentDay] ?? true;
		if (!$isAvailableToday) {
			return [];
		}

		// A finite owner-set limit truncates AFTER eligibility (decision
		// D5); a missing or non-finite value means unlimited.
		if (is_int($maxDailyTasks)) {
			$eligible = array_slice($eligible, 0, $maxDailyTasks);
		}

		return $this->withAssignees($eligible, $assigneesByRoomTask);
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
	 * Get a room task with full details (execution surface extras) —
	 * including the canonical occurrence target block (CS-PLAN-072 WP1).
	 *
	 * @return array{roomTask: RoomTask, task: Task, room: Room, status: string, isOverdue: bool, isDueToday: bool, daysUntilDue: int, occurrence: ?array}
	 * @throws NotFoundException if the room task, its definition or room is gone
	 */
	public function getRoomTaskWithDetails(int $roomTaskId): array {
		try {
			$roomTask = $this->roomTaskMapper->find($roomTaskId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}

		try {
			$task = $this->taskMapper->find($roomTask->getTaskId());
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Task not found'));
		}

		try {
			$room = $this->roomMapper->find($roomTask->getRoomId());
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Room not found'));
		}

		$tz = $this->timeZoneForRoomEntity($room);
		$houseId = $this->houseIdForRoom($room->getId());
		$selection = $this->occurrenceService->selectionFor($roomTask, new \DateTime(), $houseId);
		$pauseFingerprint = $this->pauseFingerprintForHouse($houseId);

		return [
			'roomTask' => $roomTask,
			'task' => $task,
			'room' => $room,
			'status' => $roomTask->getStatus($tz),
			'isOverdue' => $roomTask->isOverdue(),
			'isDueToday' => $roomTask->isDueToday($tz),
			'daysUntilDue' => $roomTask->getDaysUntilDue($tz),
			'occurrence' => $selection !== null
				? $this->occurrenceService->occurrenceBlock($selection['occurrence'], $selection['revision'], $selection['classification'], $houseId, $pauseFingerprint, $selection['workAppointment'] ?? null)
				: null,
		];
	}
}
