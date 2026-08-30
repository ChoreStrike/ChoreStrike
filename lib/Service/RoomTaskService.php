<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssignee;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Service for room task-related business logic
 */
class RoomTaskService {
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private FloorMapper $floorMapper;
	private RoomService $roomService;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private HouseUserMapper $houseUserMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private TemplateService $templateService;
	private IDBConnection $db;
	private IL10N $l10n;
	private AppLogger $appLogger;
	private HouseMapper $houseMapper;
	private CalendarService $calendarService;

	public function __construct(
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		RoomMapper $roomMapper,
		FloorMapper $floorMapper,
		RoomService $roomService,
		TaskAssigneeMapper $taskAssigneeMapper,
		HouseUserMapper $houseUserMapper,
		CompletedTaskMapper $completedTaskMapper,
		TemplateService $templateService,
		IDBConnection $db,
		IL10N $l10n,
		AppLogger $appLogger,
		HouseMapper $houseMapper,
		CalendarService $calendarService,
	) {
		$this->roomTaskMapper = $roomTaskMapper;
		$this->taskMapper = $taskMapper;
		$this->roomMapper = $roomMapper;
		$this->floorMapper = $floorMapper;
		$this->roomService = $roomService;
		$this->taskAssigneeMapper = $taskAssigneeMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->templateService = $templateService;
		$this->db = $db;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
		$this->houseMapper = $houseMapper;
		$this->calendarService = $calendarService;
	}

	/**
	 * Resolve the house timezone for day-boundary bucketing (CS-PLAN-057).
	 */
	private function getTimeZoneForHouse(int $houseId): \DateTimeZone {
		return $this->calendarService->getTimeZone($this->houseMapper->find($houseId));
	}

	/**
	 * Resolve the house timezone behind a room (room → floor → house).
	 */
	private function getTimeZoneForRoom(int $roomId): \DateTimeZone {
		$room = $this->roomMapper->find($roomId);
		return $this->getTimeZoneForRoomEntity($room);
	}

	/**
	 * Resolve the house timezone behind an already-fetched room entity.
	 */
	private function getTimeZoneForRoomEntity(Room $room): \DateTimeZone {
		$floor = $this->floorMapper->find($room->getFloorId());
		return $this->getTimeZoneForHouse($floor->getHouseId());
	}

	/**
	 * Validate that a room task belongs to the specified house
	 *
	 * @param int $roomTaskId The room task ID
	 * @param int $houseId The house ID to validate against
	 * @throws NotFoundException if room task doesn't belong to house
	 */
	public function validateRoomTaskOwnership(int $roomTaskId, int $houseId): void {
		try {
			$roomTask = $this->roomTaskMapper->find($roomTaskId);
			$room = $this->roomMapper->find($roomTask->getRoomId());
			$floor = $this->floorMapper->find($room->getFloorId());
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}

		if ($floor->getHouseId() !== $houseId) {
			$this->appLogger->securityWarning('Room task ownership validation failed', [
				'service' => 'RoomTaskService',
				'roomTaskId' => $roomTaskId,
				'houseId' => $houseId,
				'actualHouseId' => $floor->getHouseId(),
			]);
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}
	}

	/**
	 * Validate that a room belongs to the specified house.
	 *
	 * Delegates to RoomService so the from-template endpoint can reject a
	 * cross-house roomId before materializing/creating any task.
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
	 * @throws DoesNotExistException if not found
	 */
	public function getRoomTask(int $roomTaskId): RoomTask {
		return $this->roomTaskMapper->find($roomTaskId);
	}

	/**
	 * Get all room tasks for a room
	 *
	 * @param int $roomId The room ID
	 * @param bool $activeOnly Whether to return only active tasks
	 * @return RoomTask[]
	 */
	public function getRoomTasks(int $roomId, bool $activeOnly = true): array {
		return $this->roomTaskMapper->findByRoomId($roomId, $activeOnly);
	}

	/**
	 * Get overdue tasks for a house
	 *
	 * @param int $houseId The house ID (scopes the query to one house)
	 * @param int|null $roomId Optional room ID to filter by
	 * @return RoomTask[]
	 */
	public function getOverdueTasks(int $houseId, ?int $roomId = null): array {
		return $this->roomTaskMapper->findOverdueByHouse($houseId, $roomId);
	}

	/**
	 * Get tasks due today for a house
	 *
	 * @param int $houseId The house ID (scopes the query to one house)
	 * @param int|null $roomId Optional room ID to filter by
	 * @return RoomTask[]
	 */
	public function getDueTodayTasks(int $houseId, ?int $roomId = null): array {
		$tz = $this->getTimeZoneForHouse($houseId);
		[$start, $end] = $this->calendarService->dayBounds(new \DateTime(), $tz);
		return $this->roomTaskMapper->findDueTodayByHouse($houseId, $start, $end, $roomId);
	}

	/**
	 * Get tasks due within the next N days for a house
	 *
	 * @param int $houseId The house ID (scopes the query to one house)
	 * @param int $days Number of days
	 * @param int|null $roomId Optional room ID to filter by
	 * @return RoomTask[]
	 */
	public function getDueWithinDaysTasks(int $houseId, int $days, ?int $roomId = null): array {
		return $this->roomTaskMapper->findDueWithinDaysByHouse($houseId, $days, $roomId);
	}

	/**
	 * Create a new room task
	 *
	 * Authorization is enforced inside the service (CR-SVC-01): the target
	 * room is resolved through the floor chain (room → floor → house) and must
	 * belong to the caller's `$houseId`, and any `$assignedToUserId` must be a
	 * member of that house. No caller can bypass this preamble.
	 *
	 * @param int $houseId The caller's house ID (scopes the target room)
	 * @param int $roomId The room ID
	 * @param int $taskId The task ID
	 * @param \DateTime|null $nextDue Optional next due date (defaults to tomorrow)
	 * @param int|null $scopeId Optional scope ID
	 * @param string|null $assignedToUserId Optional assigned user ID (must be a house member)
	 * @param array $weekdayAvailability Optional weekday availability for flex scheduling
	 * @param array $energyBudget Optional energy budget for flex scheduling
	 * @throws NotFoundException if the room does not exist or belongs to another house
	 * @throws \InvalidArgumentException if the assignee is not a member of the house
	 */
	public function createRoomTask(
		int $houseId,
		int $roomId,
		int $taskId,
		?\DateTime $nextDue = null,
		?int $scopeId = null,
		?string $assignedToUserId = null,
		array $weekdayAvailability = [],
		array $energyBudget = [],
	): RoomTask {
		// CR-SVC-01: resolve the room's house via the floor chain and reject a
		// cross-house write before any persistence. A miss returns
		// NotFoundException so the existence/house boundary is not leaked.
		try {
			$room = $this->roomMapper->find($roomId);
			$floor = $this->floorMapper->find($room->getFloorId());
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Room not found'));
		}

		if ($floor->getHouseId() !== $houseId) {
			$this->appLogger->securityWarning('Cross-house room task creation denied', [
				'service' => 'RoomTaskService',
				'roomId' => $roomId,
				'houseId' => $houseId,
				'actualHouseId' => $floor->getHouseId(),
			]);
			throw new NotFoundException($this->l10n->t('Room not found'));
		}

		// CR-SVC-01: a stamped assignee must be a member of the caller's house.
		if ($assignedToUserId !== null && $assignedToUserId !== '') {
			if ($this->houseUserMapper->findByHouseAndUser($houseId, $assignedToUserId) === null) {
				throw new \InvalidArgumentException(
					$this->l10n->t('Assigned user is not a member of this house: %1$s', [$assignedToUserId])
				);
			}
		}

		// Verify task exists and get flexDays
		$task = $this->taskMapper->find($taskId);
		$flexDays = $task->getFlexDays();

		// Check if room task already exists
		if ($this->roomTaskMapper->exists($roomId, $taskId)) {
			throw new \InvalidArgumentException($this->l10n->t('Task %1$d already exists in room %2$d', [$taskId, $roomId]));
		}

		// Default to tomorrow if no due date provided
		if ($nextDue === null) {
			$nextDue = new \DateTime();
			$nextDue->add(new \DateInterval('P1D'));
		}

		// Calculate ideal due date within flex window
		$idealDueDate = null;
		if ($flexDays > 0) {
			$roomTask = new RoomTask(); // Temporary instance for calculation
			$idealDueDate = $roomTask->calculateIdealDueDate($nextDue, $flexDays, $weekdayAvailability, $energyBudget);
		}

		return $this->roomTaskMapper->create($roomId, $taskId, $nextDue, $assignedToUserId, $scopeId, $idealDueDate);
	}

	/**
	 * Create a room task from a catalog template.
	 *
	 * Materializes the template at the given cleaning level (default
	 * putzteufel), applies whitelisted overrides, creates the Task, and links
	 * it to the room with an optional assignee. Authorization (room belongs to
	 * the caller's house, assignee is a member) is enforced by
	 * {@see createRoomTask()}.
	 *
	 * @param int $houseId The caller's house ID (scopes the target room)
	 * @param int $roomId The room ID (resolved + validated server-side)
	 * @param string $templateId The catalog template ID
	 * @param string $level One of TemplateService::LEVELS (default putzteufel)
	 * @param array $overrides Optional materialization overrides
	 * @param string|null $assignedToUserId Optional assignee (must be a house member)
	 * @return RoomTask The created room task
	 *
	 * @throws NotFoundException if the room does not exist or belongs to another house
	 * @throws \InvalidArgumentException if the template/level is invalid or the assignee is not a member
	 */
	public function createFromTemplate(
		int $houseId,
		int $roomId,
		string $templateId,
		string $level = TemplateService::LEVEL_PUTZTEUFEL,
		array $overrides = [],
		?string $assignedToUserId = null,
	): RoomTask {
		$materialized = $this->templateService->materializeTemplate($templateId, $level, $overrides);

		$task = $this->taskMapper->create(
			name: $materialized['name'],
			description: $materialized['description'],
			energyCost: $materialized['energyCost'],
			priority: $materialized['priority'],
			frequencyType: $materialized['frequencyType'],
			frequencyValue: $materialized['frequencyValue'],
			estimatedMinutes: $materialized['estimatedMinutes'],
			category: $materialized['category'],
			scopeType: $materialized['scopeType'],
			schedulingMode: $materialized['schedulingMode'],
			instructions: $materialized['instructions'],
		);

		return $this->createRoomTask($houseId, $roomId, $task->getId(), null, null, $assignedToUserId);
	}

	/**
	 * Get room tasks for a scope
	 *
	 * @param int $scopeId The scope ID
	 * @param bool $activeOnly Whether to return only active tasks
	 * @return RoomTask[]
	 */
	public function getScopeTasks(int $scopeId, bool $activeOnly = true): array {
		return $this->roomTaskMapper->findByScopeId($scopeId, $activeOnly);
	}

	/**
	 * Get room tasks for a scope, filtered to one house (CR-053-02).
	 *
	 * The scopeId is caller-supplied and scopes are not house-bound, so the
	 * unscoped fetch is intersected with the house-scoped batch fetch
	 * (skip-on-missing semantics): cross-house entries are silently absent
	 * instead of leaking assignee user ids to another house.
	 *
	 * @param int $scopeId The scope ID
	 * @param int $houseId The caller's house ID
	 * @param bool $activeOnly Whether to return only active tasks
	 * @return RoomTask[] Scope tasks belonging to the house (original order)
	 */
	public function getScopeTasksScopedToHouse(int $scopeId, int $houseId, bool $activeOnly = true): array {
		$roomTasks = $this->roomTaskMapper->findByScopeId($scopeId, $activeOnly);
		if ($roomTasks === []) {
			return [];
		}

		$scoped = $this->roomTaskMapper->findByIdsScopedToHouse(
			array_map(static fn (RoomTask $roomTask): int => $roomTask->getId(), $roomTasks),
			$houseId
		);

		$scopedIds = array_flip(array_map(static fn (RoomTask $roomTask): int => $roomTask->getId(), $scoped));

		return array_values(array_filter(
			$roomTasks,
			static fn (RoomTask $roomTask): bool => isset($scopedIds[$roomTask->getId()])
		));
	}

	/**
	 * Assign room task to user
	 *
	 * Authorization is enforced inside the service (CR-SVC-09): the room
	 * task must belong to `$houseId` (floor-chain ownership) and the target
	 * user must be a member of that house — no caller can bypass the
	 * checks by forgetting a controller preamble.
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string $userId The user ID (must be a house member)
	 * @param int $houseId The caller's house ID (scopes the room task)
	 * @throws NotFoundException if the room task does not exist or belongs to another house
	 * @throws \InvalidArgumentException if the user is not a member of the house
	 */
	public function assignTask(int $roomTaskId, string $userId, int $houseId): RoomTask {
		$this->validateRoomTaskOwnership($roomTaskId, $houseId);
		$this->assertHouseMembers([$userId], $houseId);
		$this->roomTaskMapper->find($roomTaskId);
		$this->roomTaskMapper->assignToUser($roomTaskId, $userId);

		// Return updated room task
		return $this->roomTaskMapper->find($roomTaskId);
	}

	/**
	 * Unassign room task from user
	 *
	 * @param int $roomTaskId The room task ID
	 */
	public function unassignTask(int $roomTaskId): RoomTask {
		$this->roomTaskMapper->find($roomTaskId);
		$this->roomTaskMapper->assignToUser($roomTaskId, '');

		// Return updated room task
		return $this->roomTaskMapper->find($roomTaskId);
	}

	/**
	 * Validate that every Nextcloud user ID is a member of the given house.
	 *
	 * @param string[] $userIds The Nextcloud user IDs to validate
	 * @param int $houseId The house ID to validate against
	 * @throws \InvalidArgumentException if any user is not a house member
	 */
	private function assertHouseMembers(array $userIds, int $houseId): void {
		$members = $this->houseUserMapper->findByHouseId($houseId);
		$memberIds = array_map(fn ($user) => $user->getNextcloudUserId(), $members);

		foreach ($userIds as $userId) {
			if (!is_string($userId) || trim($userId) === '') {
				continue;
			}
			if (!in_array($userId, $memberIds, true)) {
				throw new \InvalidArgumentException($this->l10n->t('User is not a member of this house: %1$s', [$userId]));
			}
		}
	}

	/**
	 * Get the Nextcloud user IDs assigned to a room task, including the legacy
	 * single assignee column.
	 *
	 * @param int $roomTaskId The room task ID
	 * @return string[] Unique Nextcloud user IDs
	 */
	public function getAssigneeUserIds(int $roomTaskId): array {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);
		$userIds = $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTaskId);

		return TaskAssigneeMapper::mergeLegacyAssignee($userIds, $roomTask->getAssignedToUserId());
	}

	/**
	 * Get the Nextcloud user IDs assigned to multiple room tasks (one query),
	 * each merged with the legacy single assignee column.
	 *
	 * @param RoomTask[] $roomTasks The room tasks
	 * @return array<int, string[]> Map of room task ID => unique Nextcloud user IDs
	 */
	public function getAssigneeUserIdsByRoomTasks(array $roomTasks): array {
		$roomTaskIds = array_map(fn ($rt) => $rt->getId(), $roomTasks);
		$byTask = $this->taskAssigneeMapper->findUserIdsByRoomTasks($roomTaskIds);

		foreach ($roomTasks as $roomTask) {
			$ids = $byTask[$roomTask->getId()] ?? [];
			$byTask[$roomTask->getId()] = TaskAssigneeMapper::mergeLegacyAssignee(
				$ids,
				$roomTask->getAssignedToUserId()
			);
		}

		return $byTask;
	}

	/**
	 * Replace the full set of assignees for a room task.
	 *
	 * Every user ID must belong to the given house (membership is validated
	 * server-side; client input is never trusted).
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string[] $userIds Nextcloud user IDs to assign
	 * @param int $houseId The house ID for membership validation
	 * @param string $assignedBy The Nextcloud user ID performing the assignment
	 * @return TaskAssignee[] The resulting assignee list
	 */
	public function setAssignees(int $roomTaskId, array $userIds, int $houseId, string $assignedBy): array {
		$this->roomTaskMapper->find($roomTaskId);
		$this->assertHouseMembers($userIds, $houseId);

		$this->taskAssigneeMapper->replaceAssignees($roomTaskId, $userIds, $assignedBy);
		return $this->taskAssigneeMapper->findByRoomTask($roomTaskId);
	}

	/**
	 * Add a single assignee to a room task
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string $userId The Nextcloud user ID to assign
	 * @param int $houseId The house ID for membership validation
	 * @param string $assignedBy The Nextcloud user ID performing the assignment
	 * @throws \InvalidArgumentException if the user is already an assignee or not a member
	 */
	public function addAssignee(int $roomTaskId, string $userId, int $houseId, string $assignedBy): TaskAssignee {
		$this->roomTaskMapper->find($roomTaskId);
		$this->assertHouseMembers([$userId], $houseId);

		if ($this->taskAssigneeMapper->isAssignee($roomTaskId, $userId)) {
			throw new \InvalidArgumentException($this->l10n->t('User is already assigned to this task'));
		}

		return $this->taskAssigneeMapper->addAssignee($roomTaskId, $userId, $assignedBy);
	}

	/**
	 * Remove an assignee from a room task
	 *
	 * Mirrors addAssignee's guard (CR-SVC-22): the target user must be a
	 * member of the house — client-supplied user IDs are never trusted.
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string $userId The Nextcloud user ID to remove
	 * @param int $houseId The house ID for membership validation
	 * @throws \InvalidArgumentException if the user is not a house member
	 */
	public function removeAssignee(int $roomTaskId, string $userId, int $houseId): void {
		$this->roomTaskMapper->find($roomTaskId);
		$this->assertHouseMembers([$userId], $houseId);
		$this->taskAssigneeMapper->removeAssignee($roomTaskId, $userId);
	}

	/**
	 * Deactivate room task
	 *
	 * @param int $roomTaskId The room task ID
	 */
	public function deactivateTask(int $roomTaskId): RoomTask {
		$this->roomTaskMapper->find($roomTaskId);
		$this->roomTaskMapper->deactivate($roomTaskId);

		// Return updated room task
		return $this->roomTaskMapper->find($roomTaskId);
	}

	/**
	 * Reactivate room task
	 *
	 * @param int $roomTaskId The room task ID
	 * @param HouseUser $houseUser The acting user's house membership
	 * @param string $userId The acting user's Nextcloud user ID
	 */
	public function reactivateTask(int $roomTaskId, HouseUser $houseUser, string $userId): RoomTask {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);
		$this->assertLifecycleVisibility($roomTask, $this->taskMapper->find($roomTask->getTaskId()), $houseUser, $userId);
		$this->roomTaskMapper->reactivate($roomTaskId);

		// Return updated room task
		return $this->roomTaskMapper->find($roomTaskId);
	}

	/**
	 * Update room task due date
	 *
	 * @param int $roomTaskId The room task ID
	 * @param \DateTime $nextDue The new due date
	 * @param HouseUser $houseUser The acting user's house membership
	 * @param string $userId The acting user's Nextcloud user ID
	 */
	public function updateDueDate(int $roomTaskId, \DateTime $nextDue, HouseUser $houseUser, string $userId): RoomTask {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);
		$this->assertLifecycleVisibility($roomTask, $this->taskMapper->find($roomTask->getTaskId()), $houseUser, $userId);
		$this->roomTaskMapper->updateNextDue($roomTaskId, $nextDue);

		// Return updated room task
		return $this->roomTaskMapper->find($roomTaskId);
	}

	/**
	 * Complete room task
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string $userId The user who completed the task
	 */
	public function completeTask(int $roomTaskId, string $userId): array {
		return $this->roomService->completeRoomTask($roomTaskId, $userId);
	}

	/**
	 * Delete room task and everything that depends on it (CS-PLAN-037, D1/D2).
	 *
	 * Explicit, transactional cascade for a single id — before this, the
	 * row's completions, assignees, and checklist progress were left
	 * orphaned (no FK constraints in the schema).
	 *
	 * @param int $roomTaskId The room task ID
	 */
	public function deleteRoomTask(int $roomTaskId): void {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);

		$this->db->beginTransaction();
		try {
			$this->roomService->deleteRoomTaskDependents([$roomTaskId]);
			$this->roomTaskMapper->delete($roomTask);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Get room task with full details
	 *
	 * @param int $roomTaskId The room task ID
	 */
	public function getRoomTaskWithDetails(int $roomTaskId): array {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);
		$task = $this->taskMapper->find($roomTask->getTaskId());
		$room = $this->roomMapper->find($roomTask->getRoomId());
		$tz = $this->getTimeZoneForRoomEntity($room);

		return [
			'roomTask' => $roomTask,
			'task' => $task,
			'room' => $room,
			'status' => $roomTask->getStatus($tz),
			'isOverdue' => $roomTask->isOverdue(),
			'isDueToday' => $roomTask->isDueToday($tz),
			'daysUntilDue' => $roomTask->getDaysUntilDue($tz)
		];
	}

	/**
	 * Get task statistics for a room
	 *
	 * CR-SVC-31 root fix (CS-PLAN-040): completionRate counts REAL
	 * completions — the share of the room's current tasks with at least
	 * one completion row — instead of `(total − active)/total`, which
	 * counted deactivated/skipped tasks as completed. An empty room
	 * returns 0 (not 100).
	 *
	 * Dangling completion rows (single-task deletion does not cascade) are
	 * excluded by the mapper's room-scoped JOIN, so they can never inflate
	 * the numerator.
	 *
	 * @param int $roomId The room ID
	 */
	public function getRoomTaskStats(int $roomId): array {
		$allTasks = $this->roomTaskMapper->findByRoomId($roomId, false);
		$tz = $this->getTimeZoneForRoom($roomId);
		$activeTasks = array_filter($allTasks, fn ($task) => $task->getIsActive());
		$overdueTasks = array_filter($activeTasks, fn ($task) => $task->isOverdue());
		$dueTodayTasks = array_filter($activeTasks, fn ($task) => $task->isDueToday($tz));
		$dueSoonTasks = array_filter($activeTasks, fn ($task) => $task->isDueWithinDays(3));

		$completedCount = $this->completedTaskMapper->countDistinctCompletedRoomTasksByRoom($roomId);

		return [
			'totalTasks' => count($allTasks),
			'activeTasks' => count($activeTasks),
			'overdueTasks' => count($overdueTasks),
			'dueTodayTasks' => count($dueTodayTasks),
			'dueSoonTasks' => count($dueSoonTasks),
			'completionRate' => count($allTasks) > 0
				? round(min($completedCount, count($allTasks)) / count($allTasks) * 100, 2)
				: 0
		];
	}

	/**
	 * Bulk assign tasks to user
	 *
	 * Batch pre-validation (CS-PLAN-037, CR-SVC-60): one house-scoped
	 * fetch ({@see RoomTaskMapper::findByIdsScopedToHouse}) replaces the
	 * per-item 3-query ownership chain, and one assertHouseMembers call
	 * replaces the per-item membership fetch (≈7N → 3 + N queries). The
	 * target user is a request-level parameter, so a non-member fails
	 * the whole call up front; per-ITEM isolation is preserved — ids
	 * that are missing or belong to another house are skipped and logged
	 * without the raw target userId (privacy), and a failing
	 * assignToUser write never aborts the batch.
	 *
	 * @param array $roomTaskIds Array of room task IDs
	 * @param string $userId The user ID (must be a house member)
	 * @param int $houseId The caller's house ID (scopes the room tasks)
	 * @return RoomTask[] The successfully assigned room tasks, in input order
	 */
	public function bulkAssignTasks(array $roomTaskIds, string $userId, int $houseId): array {
		$this->assertHouseMembers([$userId], $houseId);

		$scopedIds = [];
		foreach ($this->roomTaskMapper->findByIdsScopedToHouse($roomTaskIds, $houseId) as $scopedRoomTask) {
			$scopedIds[$scopedRoomTask->getId()] = true;
		}

		$assignedIds = [];
		foreach ($roomTaskIds as $roomTaskId) {
			if (!isset($scopedIds[(int)$roomTaskId])) {
				$this->appLogger->warning('Bulk room task assignment skipped failed item', [
					'service' => 'RoomTaskService',
					'roomTaskId' => $roomTaskId,
					'houseId' => $houseId,
					'reason' => 'not_found_in_house',
				]);
				continue;
			}

			try {
				$this->roomTaskMapper->assignToUser((int)$roomTaskId, $userId);
				$assignedIds[] = (int)$roomTaskId;
			} catch (\Exception $e) {
				$this->appLogger->warning('Bulk room task assignment skipped failed item', [
					'service' => 'RoomTaskService',
					'roomTaskId' => $roomTaskId,
					'houseId' => $houseId,
					'exception' => get_class($e),
				]);
				continue;
			}
		}

		if ($assignedIds === []) {
			return [];
		}

		// One re-fetch of the successfully-assigned ids builds the return
		// payload with the persisted assignment state, in input order.
		$freshById = [];
		foreach ($this->roomTaskMapper->findByIdsScopedToHouse($assignedIds, $houseId) as $freshRoomTask) {
			$freshById[$freshRoomTask->getId()] = $freshRoomTask;
		}

		$assignedTasks = [];
		foreach ($assignedIds as $assignedId) {
			if (isset($freshById[$assignedId])) {
				$assignedTasks[] = $freshById[$assignedId];
			}
		}

		return $assignedTasks;
	}

	/**
	 * Get overdue tasks that can be claimed
	 *
	 * @param int $houseId The house ID
	 * @return RoomTask[]
	 */
	public function getClaimableOverdueTasks(int $houseId): array {
		$overdueTasks = $this->roomTaskMapper->findOverdueByHouse($houseId);

		$claimableTasks = array_filter($overdueTasks, function ($task) {
			return $task->getAssignedToUserId() !== null
				   && $task->getAssignedToUserId() !== '';
		});

		return array_values($claimableTasks);
	}

	/**
	 * Get today's tasks for a house
	 *
	 * @param int $houseId The house ID
	 * @param DateTime $date The date to get tasks for
	 * @return RoomTask[]
	 */
	public function getDailyTasks(int $houseId, \DateTime $date): array {
		$tz = $this->getTimeZoneForHouse($houseId);
		[$start, $end] = $this->calendarService->dayBounds($date, $tz);
		return $this->roomTaskMapper->findDailyTasks($houseId, $start, $end);
	}

	/**
	 * Get today's tasks for a house including task and room details.
	 *
	 * Task and room entities are fetched in two batch queries
	 * (TaskMapper/RoomMapper::findByIds, CS-PLAN-036) instead of per-id
	 * lookups — superseding the old per-request id cache. A missing task
	 * or room row (map absence) skips the room task.
	 *
	 * @param int $houseId The house ID
	 * @param \DateTime $date The date to get tasks for
	 * @return array<int, array{roomTask: RoomTask, task: Task, room: Room}>
	 */
	public function getDailyTasksWithDetails(int $houseId, \DateTime $date): array {
		$dailyTasks = $this->getDailyTasks($houseId, $date);

		$taskIds = [];
		$roomIds = [];
		foreach ($dailyTasks as $roomTask) {
			$taskIds[$roomTask->getTaskId()] = true;
			$roomIds[$roomTask->getRoomId()] = true;
		}

		$tasksById = $this->taskMapper->findByIds(array_keys($taskIds));
		$roomsById = $this->roomMapper->findByIds(array_keys($roomIds));

		$details = [];
		foreach ($dailyTasks as $roomTask) {
			$task = $tasksById[$roomTask->getTaskId()] ?? null;
			$room = $roomsById[$roomTask->getRoomId()] ?? null;
			if ($task === null || $room === null) {
				continue;
			}

			$details[] = [
				'roomTask' => $roomTask,
				'task' => $task,
				'room' => $room,
			];
		}

		return $details;
	}

	/**
	 * Get scheduled and completed tasks for a house within a date range.
	 *
	 * Batch-fetches task and room entities by unique-ID sets to avoid N+1
	 * queries over a multi-day range. Returns enriched scheduled items with
	 * flex window bounds and projected completion rows.
	 *
	 * Recurrence projection (D2): each recurring task is expanded into every
	 * occurrence it would have within [from, to] via RoomTask::projectOccurrences,
	 * reusing the same recurrence rules as task completion so the calendar and
	 * the scheduler never disagree. The base set is every active task due on or
	 * before `to` (findActiveDueUpToByHouse); tasks that produce no in-window
	 * occurrence are omitted. Occurrence dates are returned as a compact
	 * `occurrences` list of Y-m-d strings; the top-level `flexWindow` stays
	 * anchored on the stored nextDue for response-shape stability.
	 *
	 * @param int $houseId The house ID
	 * @param \DateTime $from Inclusive range start
	 * @param \DateTime $to Inclusive range end
	 * @return array{scheduled: array<int, array{roomTask: array, task: array, room: array, flexWindow: array, occurrences: array<int, string>}>, completed: array<int, array>}
	 */
	public function getRangeTasks(int $houseId, \DateTime $from, \DateTime $to): array {
		// CS-PLAN-057: reinterpret the controller's Y-m-d keys as house-local
		// calendar bounds (UTC-normalized instants for the SQL comparison).
		$tz = $this->getTimeZoneForHouse($houseId);
		$fromBound = (new \DateTime($from->format('Y-m-d') . ' 00:00:00', $tz))
			->setTimezone(new \DateTimeZone('UTC'));
		$toBound = (new \DateTime($to->format('Y-m-d') . ' 23:59:59', $tz))
			->setTimezone(new \DateTimeZone('UTC'));

		// Base set: every active task that could recur into the window (nextDue <= to).
		$roomTasks = $this->roomTaskMapper->findActiveDueUpToByHouse($houseId, $toBound);
		$completions = $this->completedTaskMapper->findCompletionsBetweenByHouse($houseId, $fromBound, $toBound);

		$taskIds = [];
		$roomIds = [];
		foreach ($roomTasks as $rt) {
			$taskIds[$rt->getTaskId()] = true;
			$roomIds[$rt->getRoomId()] = true;
		}

		$tasksById = $this->taskMapper->findByIds(array_keys($taskIds));
		$roomsById = $this->roomMapper->findByIds(array_keys($roomIds));

		$scheduled = [];
		foreach ($roomTasks as $rt) {
			$task = $tasksById[$rt->getTaskId()] ?? null;
			$room = $roomsById[$rt->getRoomId()] ?? null;
			if ($task === null || $room === null) {
				// Orphaned row (task/room definition gone) — skip it.
				continue;
			}

			$occurrenceDates = $rt->projectOccurrences(
				$fromBound,
				$toBound,
				$task->getFrequencyType(),
				$task->getFrequencyValue(),
				$task->getRecurrenceMode(),
				$task->getSchedulingMode(),
				$task->getFixedScheduleDays(),
				$task->getActiveUntil(),
				$tz,
			);

			// Skip tasks that produce no occurrence inside the window.
			if (empty($occurrenceDates)) {
				continue;
			}

			$flexWindow = $rt->getFlexWindowBounds($task->getFlexDays());

			$scheduled[] = [
				'roomTask' => $rt->jsonSerialize($tz),
				'task' => $task->jsonSerialize(),
				'room' => $room->jsonSerialize(),
				'flexWindow' => [
					'earliest' => $flexWindow['earliest']?->format('Y-m-d'),
					'ideal' => $flexWindow['ideal']?->format('Y-m-d'),
					'latest' => $flexWindow['latest']?->format('Y-m-d'),
				],
				// House-local Y-m-d day keys (CS-PLAN-057) — the calendar
				// bucketing convention shared with the frontend.
				'occurrences' => array_map(
					fn (\DateTime $date) => \DateTime::createFromInterface($date)->setTimezone($tz)->format('Y-m-d'),
					$occurrenceDates,
				),
			];
		}

		return [
			'scheduled' => $scheduled,
			'completed' => $completions,
		];
	}

	/**
	 * Enforce the task visibility matrix for lifecycle mutations (CS-PLAN-056, CR-053-06).
	 *
	 * Mirrors the completion gate in RoomService::completeRoomTask:
	 * - private tasks: only assigned users (join table + legacy assignee column)
	 * - admin-only tasks: only house owners
	 * - public tasks: any house member
	 *
	 * @param RoomTask $roomTask The room task being mutated
	 * @param Task $task The task definition (visibility source)
	 * @param HouseUser $houseUser The acting user's house membership
	 * @param string $userId The acting user's Nextcloud user ID
	 * @throws ForbiddenException if the matrix forbids the mutation
	 */
	public function assertLifecycleVisibility(RoomTask $roomTask, Task $task, HouseUser $houseUser, string $userId): void {
		$assigneeUserIds = TaskAssigneeMapper::mergeLegacyAssignee(
			$this->taskAssigneeMapper->findUserIdsByRoomTask($roomTask->getId()),
			$roomTask->getAssignedToUserId()
		);

		if ($task->isPrivate() && !in_array($userId, $assigneeUserIds, true)) {
			throw new ForbiddenException($this->l10n->t('Private tasks may only be modified by assigned users'));
		}

		if ($task->getVisibilityType() === Task::VISIBILITY_ADMIN_ONLY && !$houseUser->isOwner()) {
			throw new ForbiddenException($this->l10n->t('Admin-only tasks may only be modified by house owners'));
		}
	}

	/**
	 * Uncomplete a room task
	 *
	 * @param int $roomTaskId The room task ID
	 * @param HouseUser $houseUser The acting user's house membership
	 * @param string $userId The acting user's Nextcloud user ID
	 * @return RoomTask The updated room task
	 */
	public function uncompleteTask(int $roomTaskId, HouseUser $houseUser, string $userId): RoomTask {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);
		$this->assertLifecycleVisibility($roomTask, $this->taskMapper->find($roomTask->getTaskId()), $houseUser, $userId);
		$roomTask->setLastCompleted(null);
		return $this->roomTaskMapper->update($roomTask);
	}

	/**
	 * Get filtered daily tasks for a user based on their role and scheduling preferences.
	 *
	 * For child users, this filters tasks based on:
	 * - weekdayAvailability: Only shows tasks due on days the user is available
	 * - maxDailyTasks: Limits the number of tasks shown
	 *
	 * Non-child users receive all daily tasks without filtering.
	 *
	 * @param int $houseId The house ID
	 * @param string $nextcloudUserId The Nextcloud user ID
	 * @param \DateTime $date The date to get tasks for
	 * @param HouseUserMapper $houseUserMapper Mapper to get user preferences
	 * @return array<int, array{roomTask: RoomTask, task: Task, room: Room}> Filtered tasks with details
	 */
	public function getFilteredDailyTasksForUser(
		int $houseId,
		string $nextcloudUserId,
		\DateTime $date,
		\OCA\ChoreStrike\Db\HouseUserMapper $houseUserMapper,
	): array {
		// Get all daily tasks for the house
		$allTasks = $this->getDailyTasksWithDetails($houseId, $date);

		// Get user to check role and preferences
		$user = $houseUserMapper->findByNextcloudUserId($houseId, $nextcloudUserId);

		// Non-child users see all tasks
		if (!$user->isChild()) {
			return $allTasks;
		}

		// Get user's scheduling preferences
		$preferences = $user->getSchedulingPreferences();
		$weekdayAvailability = $preferences['weekdayAvailability'] ?? [];
		$maxDailyTasks = $preferences['maxDailyTasks'] ?? PHP_INT_MAX;

		// Get current day name (lowercase, house TZ — CS-PLAN-057)
		$tz = $this->getTimeZoneForHouse($houseId);
		$currentDay = strtolower(\DateTime::createFromInterface($date)->setTimezone($tz)->format('l'));

		// Check if user is available today
		$isAvailableToday = $weekdayAvailability[$currentDay] ?? true;

		// If not available today, return empty array
		if (!$isAvailableToday) {
			return [];
		}

		// Limit tasks to maxDailyTasks
		return array_slice($allTasks, 0, (int)$maxDailyTasks);
	}
}
