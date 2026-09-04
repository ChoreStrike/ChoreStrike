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
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
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
	private TransactionExecutor $transactions;
	private IL10N $l10n;
	private AppLogger $appLogger;
	private HouseMapper $houseMapper;
	private CalendarService $calendarService;
	private TaskAccessPolicy $taskAccessPolicy;
	private AuthorizationService $authorization;

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
		TransactionExecutor $transactions,
		IL10N $l10n,
		AppLogger $appLogger,
		HouseMapper $houseMapper,
		CalendarService $calendarService,
		TaskAccessPolicy $taskAccessPolicy,
		AuthorizationService $authorization,
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
		$this->transactions = $transactions;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
		$this->houseMapper = $houseMapper;
		$this->calendarService = $calendarService;
		$this->taskAccessPolicy = $taskAccessPolicy;
		$this->authorization = $authorization;
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

	/**
	 * Fetch task definitions for a batch of room tasks in one query.
	 *
	 * @param RoomTask[] $roomTasks
	 * @return array<int, Task> Map of task ID → Task
	 */
	private function tasksByIdForRoomTasks(array $roomTasks): array {
		$taskIds = [];
		foreach ($roomTasks as $roomTask) {
			$taskIds[$roomTask->getTaskId()] = true;
		}
		return $this->taskMapper->findByIds(array_keys($taskIds));
	}

	/**
	 * Get the room tasks of one room the viewer may see (visibility matrix,
	 * CS-PLAN-061 §3.3).
	 *
	 * @param int $roomId The room ID
	 * @param HouseUser $viewer The acting user's house membership
	 * @param bool $activeOnly Whether to return only active tasks
	 * @return RoomTask[]
	 */
	public function getRoomTasks(int $roomId, HouseUser $viewer, bool $activeOnly = true): array {
		$roomTasks = $this->roomTaskMapper->findByRoomId($roomId, $activeOnly);
		return $this->taskAccessPolicy->filterVisibleRoomTasks($roomTasks, $this->tasksByIdForRoomTasks($roomTasks), $viewer);
	}

	/**
	 * Get overdue tasks for a house, filtered to the viewer's visibility.
	 *
	 * @param int $houseId The house ID (scopes the query to one house)
	 * @param HouseUser $viewer The acting user's house membership
	 * @param int|null $roomId Optional room ID to filter by
	 * @return RoomTask[]
	 */
	public function getOverdueTasks(int $houseId, HouseUser $viewer, ?int $roomId = null): array {
		$roomTasks = $this->roomTaskMapper->findOverdueByHouse($houseId, $roomId);
		return $this->taskAccessPolicy->filterVisibleRoomTasks($roomTasks, $this->tasksByIdForRoomTasks($roomTasks), $viewer);
	}

	/**
	 * Get tasks due today for a house, filtered to the viewer's visibility.
	 *
	 * @param int $houseId The house ID (scopes the query to one house)
	 * @param HouseUser $viewer The acting user's house membership
	 * @param int|null $roomId Optional room ID to filter by
	 * @return RoomTask[]
	 */
	public function getDueTodayTasks(int $houseId, HouseUser $viewer, ?int $roomId = null): array {
		$tz = $this->getTimeZoneForHouse($houseId);
		[$start, $end] = $this->calendarService->dayBounds(new \DateTime(), $tz);
		$roomTasks = $this->roomTaskMapper->findDueTodayByHouse($houseId, $start, $end, $roomId);
		return $this->taskAccessPolicy->filterVisibleRoomTasks($roomTasks, $this->tasksByIdForRoomTasks($roomTasks), $viewer);
	}

	/**
	 * Get tasks due within the next N days for a house, filtered to the
	 * viewer's visibility.
	 *
	 * @param int $houseId The house ID (scopes the query to one house)
	 * @param int $days Number of days
	 * @param HouseUser $viewer The acting user's house membership
	 * @param int|null $roomId Optional room ID to filter by
	 * @return RoomTask[]
	 */
	public function getDueWithinDaysTasks(int $houseId, int $days, HouseUser $viewer, ?int $roomId = null): array {
		$roomTasks = $this->roomTaskMapper->findDueWithinDaysByHouse($houseId, $days, $roomId);
		return $this->taskAccessPolicy->filterVisibleRoomTasks($roomTasks, $this->tasksByIdForRoomTasks($roomTasks), $viewer);
	}

	/**
	 * Create a new room task
	 *
	 * Authorization is enforced inside the service (CR-SVC-01, CS-PLAN-061
	 * §3.4): the target room is resolved through the floor chain
	 * (room → floor → house) and must belong to the caller's `$houseId`, the
	 * acting user must be owner or member (children execute only), the task
	 * definition must be visible to the actor (a private/admin-only
	 * definition cannot be materialized from an id the actor cannot see),
	 * and any `$assignedToUserId` must be a member of that house. No caller
	 * can bypass this preamble.
	 *
	 * @param int $houseId The caller's house ID (scopes the target room)
	 * @param int $roomId The room ID
	 * @param int $taskId The task ID
	 * @param HouseUser $actor The acting user's house membership
	 * @param \DateTime|null $nextDue Optional next due date (defaults to tomorrow)
	 * @param int|null $scopeId Optional scope ID
	 * @param string|null $assignedToUserId Optional assigned user ID (must be a house member)
	 * @param array $weekdayAvailability Optional weekday availability for flex scheduling
	 * @param array $energyBudget Optional energy budget for flex scheduling
	 * @throws NotFoundException if the room/task does not exist or belongs to another house
	 * @throws ForbiddenException if the actor is a child or cannot see the task definition
	 * @throws \InvalidArgumentException if the assignee is not a member of the house
	 */
	public function createRoomTask(
		int $houseId,
		int $roomId,
		int $taskId,
		HouseUser $actor,
		?\DateTime $nextDue = null,
		?int $scopeId = null,
		?string $assignedToUserId = null,
		array $weekdayAvailability = [],
		array $energyBudget = [],
	): RoomTask {
		// Actor policy (D5): children execute tasks, they do not create them.
		$this->authorization->assertCanManageTasks($actor);

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

		// Verify task exists and get flexDays.
		try {
			$task = $this->taskMapper->find($taskId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Task not found'));
		}

		// Visibility gate (D2/D3): the definition must be visible to the
		// actor — an invisible private/admin-only id is a 404, not a hint
		// that the definition exists.
		$this->taskAccessPolicy->assertCanViewTask($task, $actor);

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

		// Initial assignment writes the canonical join-table rows after
		// insert (D2). Sequential calls preserve today's atomicity level;
		// wrapping creation+assignment into one aggregate transaction is
		// WP4 scope.
		$roomTask = $this->roomTaskMapper->create($roomId, $taskId, $nextDue, $scopeId, $idealDueDate);
		if ($assignedToUserId !== null && $assignedToUserId !== '') {
			$this->taskAssigneeMapper->addAssignee($roomTask->getId(), $assignedToUserId);
		}

		return $roomTask;
	}

	/**
	 * Create a room task from a catalog template.
	 *
	 * Materializes the template at the given cleaning level (default
	 * putzteufel), applies whitelisted overrides, creates the Task, and links
	 * it to the room with an optional assignee. Authorization (room belongs to
	 * the caller's house, assignee is a member, actor may manage tasks) is
	 * enforced by {@see createRoomTask()}.
	 *
	 * @param int $houseId The caller's house ID (scopes the target room)
	 * @param int $roomId The room ID (resolved + validated server-side)
	 * @param string $templateId The catalog template ID
	 * @param string $level One of TemplateService::LEVELS (default putzteufel)
	 * @param array $overrides Optional materialization overrides
	 * @param string|null $assignedToUserId Optional assignee (must be a house member)
	 * @param HouseUser $actor The acting user's house membership
	 * @return RoomTask The created room task
	 *
	 * @throws NotFoundException if the room does not exist or belongs to another house
	 * @throws ForbiddenException if the actor is a child
	 * @throws \InvalidArgumentException if the template/level is invalid or the assignee is not a member
	 */
	public function createFromTemplate(
		int $houseId,
		int $roomId,
		string $templateId,
		string $level = TemplateService::LEVEL_PUTZTEUFEL,
		array $overrides = [],
		?string $assignedToUserId = null,
		?HouseUser $actor = null,
	): RoomTask {
		if ($actor === null) {
			throw new \InvalidArgumentException('Acting house user is required');
		}
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

		return $this->createRoomTask($houseId, $roomId, $task->getId(), $actor, null, null, $assignedToUserId);
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
	 * @param int $scopeId The scope ID
	 * @param int $houseId The caller's house ID
	 * @param HouseUser $viewer The acting user's house membership
	 * @param bool $activeOnly Whether to return only active tasks
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

		return $this->taskAccessPolicy->filterVisibleRoomTasks($inHouse, $this->tasksByIdForRoomTasks($inHouse), $viewer);
	}

	/**
	 * Write the canonical single-assignee state: the assignee set becomes
	 * exactly [$userId] (empty string = unassign) and a stale claim marker
	 * is cleared — the same effect the former legacy-column UPDATE had.
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string $userId The assignee (empty = clear)
	 */
	private function replaceSingleAssignee(int $roomTaskId, string $userId): void {
		$userIds = $userId === '' ? [] : [$userId];
		$this->taskAssigneeMapper->replaceAssignees($roomTaskId, $userIds);

		$roomTask = $this->roomTaskMapper->find($roomTaskId);
		if ($roomTask->getClaimedByUser() !== null) {
			$roomTask->setClaimedByUser(null);
			$this->roomTaskMapper->update($roomTask);
		}
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
	 * Get the Nextcloud user IDs assigned to a room task (canonical join
	 * table, CS-PLAN-060 WP1 D2).
	 *
	 * Visibility-gated (CS-PLAN-061 review fix): the assignee list of a task
	 * the actor cannot see is a 404 — reading the assignees of an invisible
	 * private task would leak its assignment graph.
	 *
	 * @param int $roomTaskId The room task ID
	 * @param HouseUser $actor The acting user's house membership
	 * @return string[] Unique Nextcloud user IDs
	 * @throws NotFoundException if the room task or its definition is invisible to the actor
	 */
	public function getAssigneeUserIds(int $roomTaskId, HouseUser $actor): array {
		$this->assertRoomTaskVisible($roomTaskId, $actor);
		return $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTaskId);
	}

	/**
	 * Get the Nextcloud user IDs assigned to multiple room tasks (one query).
	 *
	 * @param RoomTask[] $roomTasks The room tasks
	 * @return array<int, string[]> Map of room task ID => unique Nextcloud user IDs
	 */
	public function getAssigneeUserIdsByRoomTasks(array $roomTasks): array {
		$roomTaskIds = array_map(fn ($rt) => $rt->getId(), $roomTasks);
		return $this->taskAssigneeMapper->findUserIdsByRoomTasks($roomTaskIds);
	}

	/**
	 * Replace the full set of assignees for a room task.
	 *
	 * Service-level gates (ADR-006, CS-PLAN-061 §3.4): the room task must
	 * belong to the caller's house (floor-chain ownership, defense-in-depth
	 * against a forgotten controller preamble), be visible to the acting
	 * user (404 anti-enumeration for invisible tasks), and the actor must be
	 * owner or member (children → 403). Every target user ID must belong to
	 * the given house (membership is validated server-side; client input is
	 * never trusted).
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string[] $userIds Nextcloud user IDs to assign
	 * @param int $houseId The house ID for membership validation
	 * @param HouseUser $actor The acting user's house membership
	 * @return TaskAssignee[] The resulting assignee list
	 * @throws NotFoundException if the room task does not exist, is foreign, or is invisible to the actor
	 * @throws ForbiddenException if the actor is a child
	 */
	public function setAssignees(int $roomTaskId, array $userIds, int $houseId, HouseUser $actor): array {
		$this->validateRoomTaskOwnership($roomTaskId, $houseId);
		$this->assertRoomTaskVisible($roomTaskId, $actor);
		$this->authorization->assertCanManageTasks($actor);
		$this->assertHouseMembers($userIds, $houseId);

		$this->taskAssigneeMapper->replaceAssignees($roomTaskId, $userIds, $actor->getNextcloudUserId());
		return $this->taskAssigneeMapper->findByRoomTask($roomTaskId);
	}

	/**
	 * Assert the room task's underlying definition is visible to the actor
	 * (404 anti-enumeration, decision D3).
	 *
	 * @throws NotFoundException when the matrix denies the actor
	 */
	private function assertRoomTaskVisible(int $roomTaskId, HouseUser $actor): void {
		$roomTask = $this->findRoomTaskOrNotFound($roomTaskId);
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
	 * Reactivate room task
	 *
	 * @param int $roomTaskId The room task ID
	 * @param HouseUser $houseUser The acting user's house membership
	 */
	public function reactivateTask(int $roomTaskId, HouseUser $houseUser): RoomTask {
		$roomTask = $this->findRoomTaskOrNotFound($roomTaskId);
		$this->assertLifecycleVisibility($roomTask, $this->findTaskOrNotFound($roomTask->getTaskId()), $houseUser);
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
	 */
	public function updateDueDate(int $roomTaskId, \DateTime $nextDue, HouseUser $houseUser): RoomTask {
		$roomTask = $this->findRoomTaskOrNotFound($roomTaskId);
		$this->assertLifecycleVisibility($roomTask, $this->findTaskOrNotFound($roomTask->getTaskId()), $houseUser);
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
	 * Service-level gates (CS-PLAN-061 §3.4): the acting user must be able
	 * to see the room task's definition (404 for invisible tasks) and may
	 * not be a child (403).
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

		$this->transactions->run(function () use ($roomTaskId, $roomTask) {
			$this->roomService->deleteRoomTaskDependents([$roomTaskId]);
			$this->roomTaskMapper->delete($roomTask);
		});
	}

	/**
	 * Get room task with full details
	 *
	 * @param int $roomTaskId The room task ID
	 */
	public function getRoomTaskWithDetails(int $roomTaskId): array {
		$roomTask = $this->findRoomTaskOrNotFound($roomTaskId);
		$task = $this->findTaskOrNotFound($roomTask->getTaskId());
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
	 * @param int $roomId The room ID
	 * @param HouseUser $viewer The acting user's house membership
	 */
	public function getRoomTaskStats(int $roomId, HouseUser $viewer): array {
		$allTasks = $this->roomTaskMapper->findByRoomId($roomId, false);
		$visibleTasks = $this->taskAccessPolicy->filterVisibleRoomTasks(
			$allTasks,
			$this->tasksByIdForRoomTasks($allTasks),
			$viewer
		);
		$tz = $this->getTimeZoneForRoom($roomId);
		$activeTasks = array_filter($visibleTasks, fn ($task) => $task->getIsActive());
		$overdueTasks = array_filter($activeTasks, fn ($task) => $task->isOverdue());
		$dueTodayTasks = array_filter($activeTasks, fn ($task) => $task->isDueToday($tz));
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
	 * assignment write never aborts the batch.
	 *
	 * Actor policy + visibility (CS-PLAN-061 §3.4): the acting user must be
	 * owner or member (child → 403), and every requested id that IS scoped
	 * to the house but invisible to the actor fails the whole request up
	 * front with a 404 (anti-enumeration — "first assertCanView"). Items
	 * missing from the house keep their skip isolation.
	 *
	 * @param array $roomTaskIds Array of room task IDs
	 * @param string $userId The user ID (must be a house member)
	 * @param int $houseId The caller's house ID (scopes the room tasks)
	 * @param HouseUser $actor The acting user's house membership
	 * @return RoomTask[] The successfully assigned room tasks, in input order
	 * @throws NotFoundException if a house-scoped id is invisible to the actor
	 * @throws ForbiddenException if the actor is a child
	 */
	public function bulkAssignTasks(array $roomTaskIds, string $userId, int $houseId, HouseUser $actor): array {
		$this->authorization->assertCanManageTasks($actor);
		$this->assertHouseMembers([$userId], $houseId);

		$scopedById = [];
		foreach ($this->roomTaskMapper->findByIdsScopedToHouse($roomTaskIds, $houseId) as $scopedRoomTask) {
			$scopedById[$scopedRoomTask->getId()] = $scopedRoomTask;
		}

		// Visibility pre-check for everything that IS in the house: one
		// batched assignee fetch (no N+1), 404 on the first invisible task.
		if ($scopedById !== []) {
			$tasksById = $this->tasksByIdForRoomTasks(array_values($scopedById));
			$assigneesByRoomTask = $this->taskAssigneeMapper->findUserIdsByRoomTasks(array_keys($scopedById));
			foreach ($scopedById as $scopedId => $scopedRoomTask) {
				$task = $tasksById[$scopedRoomTask->getTaskId()] ?? null;
				if ($task === null
					|| !$this->taskAccessPolicy->canView($task, $actor, $assigneesByRoomTask[$scopedId] ?? [])) {
					throw new NotFoundException($this->l10n->t('Room task not found'));
				}
			}
		}

		$scopedIds = array_fill_keys(array_keys($scopedById), true);

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
				$this->replaceSingleAssignee((int)$roomTaskId, $userId);
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
	 * Get overdue tasks that can be claimed (overdue + assigned to at least
	 * one member via the canonical join table), filtered to the viewer's
	 * visibility.
	 *
	 * @param int $houseId The house ID
	 * @param HouseUser $viewer The acting user's house membership
	 * @return RoomTask[]
	 */
	public function getClaimableOverdueTasks(int $houseId, HouseUser $viewer): array {
		$overdueTasks = $this->roomTaskMapper->findOverdueByHouse($houseId);
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
		return $this->taskAccessPolicy->filterVisibleRoomTasks($claimable, $this->tasksByIdForRoomTasks($claimable), $viewer);
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
	 * Get today's tasks for a house including task and room details, filtered
	 * to the viewer's visibility (CS-PLAN-061 §3.3).
	 *
	 * Task and room entities are fetched in two batch queries
	 * (TaskMapper/RoomMapper::findByIds, CS-PLAN-036) instead of per-id
	 * lookups — superseding the old per-request id cache. A missing task
	 * or room row (map absence) skips the room task.
	 *
	 * @param int $houseId The house ID
	 * @param \DateTime $date The date to get tasks for
	 * @param HouseUser $viewer The acting user's house membership
	 * @return array<int, array{roomTask: RoomTask, task: Task, room: Room}>
	 */
	public function getDailyTasksWithDetails(int $houseId, \DateTime $date, HouseUser $viewer): array {
		$dailyTasks = $this->getDailyTasks($houseId, $date);

		$taskIds = [];
		$roomIds = [];
		foreach ($dailyTasks as $roomTask) {
			$taskIds[$roomTask->getTaskId()] = true;
			$roomIds[$roomTask->getRoomId()] = true;
		}

		$tasksById = $this->taskMapper->findByIds(array_keys($taskIds));
		$roomsById = $this->roomMapper->findByIds(array_keys($roomIds));

		$visibleTasks = $this->taskAccessPolicy->filterVisibleRoomTasks($dailyTasks, $tasksById, $viewer);

		$details = [];
		foreach ($visibleTasks as $roomTask) {
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
	 * @param HouseUser $viewer The acting user's house membership
	 * @return array{scheduled: array<int, array{roomTask: array, task: array, room: array, flexWindow: array, occurrences: array<int, string>}>, completed: array<int, array>}
	 */
	public function getRangeTasks(int $houseId, \DateTime $from, \DateTime $to, HouseUser $viewer): array {
		// CS-PLAN-057: reinterpret the controller's Y-m-d keys as house-local
		// calendar bounds (UTC-normalized instants for the SQL comparison).
		// CS-PLAN-060 §3.6: the completion window is half-open [start, end)
		// — the end bound is the NEXT day's 00:00:00 (exclusive), matching
		// the window-query convention. The projection base-set bound keeps
		// its inclusive end-of-day semantics.
		$tz = $this->getTimeZoneForHouse($houseId);
		$fromBound = (new \DateTime($from->format('Y-m-d') . ' 00:00:00', $tz))
			->setTimezone(new \DateTimeZone('UTC'));
		$toBoundInclusive = (new \DateTime($to->format('Y-m-d') . ' 23:59:59', $tz))
			->setTimezone(new \DateTimeZone('UTC'));
		$toBoundExclusive = (new \DateTime($to->format('Y-m-d') . ' 00:00:00', $tz))
			->modify('+1 day')
			->setTimezone(new \DateTimeZone('UTC'));

		// Base set: every active task that could recur into the window (nextDue <= to).
		$roomTasks = $this->roomTaskMapper->findActiveDueUpToByHouse($houseId, $toBoundInclusive);
		$completions = $this->completedTaskMapper->findCompletionsBetweenByHouse($houseId, $fromBound, $toBoundExclusive);
		$completions = $this->redactInvisibleCompletionRows($completions, $viewer);

		$taskIds = [];
		$roomIds = [];
		foreach ($roomTasks as $rt) {
			$taskIds[$rt->getTaskId()] = true;
			$roomIds[$rt->getRoomId()] = true;
		}

		$tasksById = $this->taskMapper->findByIds(array_keys($taskIds));
		$roomsById = $this->roomMapper->findByIds(array_keys($roomIds));

		// Visibility filter (CS-PLAN-061 §3.3): private/admin-only base rows
		// must not reach the projection — their full task definition is
		// serialized into the response.
		$roomTasks = $this->taskAccessPolicy->filterVisibleRoomTasks($roomTasks, $tasksById, $viewer);

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
				$toBoundInclusive,
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

	/**
	 * Enforce the task visibility matrix for lifecycle mutations (CS-PLAN-056,
	 * CR-053-06; unified via {@see TaskAccessPolicy}, CS-PLAN-061 §3.2).
	 *
	 * One matrix everywhere (decision D2):
	 * - public tasks: any house member
	 * - private tasks: creator ∪ explicit assignees (canonical join table)
	 * - admin-only tasks: house owners
	 *
	 * A non-viewer is denied with the app NotFoundException — the 404
	 * anti-enumeration standard (decision D3): the lifecycle surface must not
	 * confirm that an invisible room-task id exists.
	 *
	 * @param RoomTask $roomTask The room task being mutated
	 * @param Task $task The task definition (visibility source)
	 * @param HouseUser $houseUser The acting user's house membership
	 * @throws NotFoundException if the matrix denies the actor
	 */
	public function assertLifecycleVisibility(RoomTask $roomTask, Task $task, HouseUser $houseUser): void {
		$assigneeUserIds = $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTask->getId());
		$this->taskAccessPolicy->assertCanView($task, $houseUser, $assigneeUserIds);
	}

	/**
	 * Uncomplete a room task
	 *
	 * @param int $roomTaskId The room task ID
	 * @param HouseUser $houseUser The acting user's house membership
	 * @return RoomTask The updated room task
	 */
	public function uncompleteTask(int $roomTaskId, HouseUser $houseUser): RoomTask {
		$roomTask = $this->findRoomTaskOrNotFound($roomTaskId);
		$this->assertLifecycleVisibility($roomTask, $this->findTaskOrNotFound($roomTask->getTaskId()), $houseUser);
		$roomTask->setLastCompleted(null);
		return $this->roomTaskMapper->update($roomTask);
	}

	/**
	 * Get filtered daily tasks for a user based on their role and scheduling
	 * preferences, restricted to the viewer's visibility matrix.
	 *
	 * For child users, this filters tasks based on:
	 * - weekdayAvailability: Only shows tasks due on days the user is available
	 * - maxDailyTasks: Limits the number of tasks shown
	 *
	 * Non-child users receive all daily tasks (still visibility-filtered).
	 *
	 * @param int $houseId The house ID
	 * @param HouseUser $viewer The acting user's house membership (role + preferences source)
	 * @param \DateTime $date The date to get tasks for
	 * @return array<int, array{roomTask: RoomTask, task: Task, room: Room}> Filtered tasks with details
	 */
	public function getFilteredDailyTasksForUser(
		int $houseId,
		HouseUser $viewer,
		\DateTime $date,
	): array {
		// Daily tasks, already filtered through the visibility matrix.
		$allTasks = $this->getDailyTasksWithDetails($houseId, $date, $viewer);

		// Non-child users see all (visible) tasks
		if (!$viewer->isChild()) {
			return $allTasks;
		}

		// Get user's scheduling preferences
		$preferences = $viewer->getSchedulingPreferences();
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
