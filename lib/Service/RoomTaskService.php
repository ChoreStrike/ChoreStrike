<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssignee;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;

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
	private ?AppLogger $appLogger;

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
		?AppLogger $appLogger = null,
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
		$this->appLogger = $appLogger;
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
			throw new NotFoundException('Room task not found');
		}

		if ($floor->getHouseId() !== $houseId) {
			$this->appLogger?->securityWarning('Room task ownership validation failed', [
				'service' => 'RoomTaskService',
				'roomTaskId' => $roomTaskId,
				'houseId' => $houseId,
				'actualHouseId' => $floor->getHouseId(),
			]);
			throw new NotFoundException('Room task not found');
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
	 * Get all room tasks for a user
	 *
	 * @param string $userId The user ID
	 * @param bool $activeOnly Whether to return only active tasks
	 * @return RoomTask[]
	 */
	public function getUserTasks(string $userId, bool $activeOnly = true): array {
		return $this->roomTaskMapper->findByUserId($userId, $activeOnly);
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
		return $this->roomTaskMapper->findDueTodayByHouse($houseId, $roomId);
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
			throw new NotFoundException('Room not found');
		}

		if ($floor->getHouseId() !== $houseId) {
			$this->appLogger?->securityWarning('Cross-house room task creation denied', [
				'service' => 'RoomTaskService',
				'roomId' => $roomId,
				'houseId' => $houseId,
				'actualHouseId' => $floor->getHouseId(),
			]);
			throw new NotFoundException('Room not found');
		}

		// CR-SVC-01: a stamped assignee must be a member of the caller's house.
		if ($assignedToUserId !== null && $assignedToUserId !== '') {
			if ($this->houseUserMapper->findByHouseAndUser($houseId, $assignedToUserId) === null) {
				throw new \InvalidArgumentException(
					'Assigned user is not a member of this house: ' . $assignedToUserId
				);
			}
		}

		// Verify task exists and get flexDays
		$task = $this->taskMapper->find($taskId);
		$flexDays = $task->getFlexDays();

		// Check if room task already exists
		if ($this->roomTaskMapper->exists($roomId, $taskId)) {
			throw new \InvalidArgumentException("Task {$taskId} already exists in room {$roomId}");
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
				throw new \InvalidArgumentException('User is not a member of this house: ' . $userId);
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
			throw new \InvalidArgumentException('User is already assigned to this task');
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
	 */
	public function reactivateTask(int $roomTaskId): RoomTask {
		$this->roomTaskMapper->find($roomTaskId);
		$this->roomTaskMapper->reactivate($roomTaskId);

		// Return updated room task
		return $this->roomTaskMapper->find($roomTaskId);
	}

	/**
	 * Update room task due date
	 *
	 * @param int $roomTaskId The room task ID
	 * @param \DateTime $nextDue The new due date
	 */
	public function updateDueDate(int $roomTaskId, \DateTime $nextDue): RoomTask {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);
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
	 * Delete room task
	 *
	 * @param int $roomTaskId The room task ID
	 */
	public function deleteRoomTask(int $roomTaskId): void {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);
		$this->roomTaskMapper->delete($roomTask);
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

		return [
			'roomTask' => $roomTask,
			'task' => $task,
			'room' => $room,
			'status' => $roomTask->getStatus(),
			'isOverdue' => $roomTask->isOverdue(),
			'isDueToday' => $roomTask->isDueToday(),
			'daysUntilDue' => $roomTask->getDaysUntilDue()
		];
	}

	/**
	 * Get task statistics for a room
	 *
	 * @param int $roomId The room ID
	 */
	public function getRoomTaskStats(int $roomId): array {
		$allTasks = $this->roomTaskMapper->findByRoomId($roomId, false);
		$activeTasks = array_filter($allTasks, fn ($task) => $task->getIsActive());
		$overdueTasks = array_filter($activeTasks, fn ($task) => $task->isOverdue());
		$dueTodayTasks = array_filter($activeTasks, fn ($task) => $task->isDueToday());
		$dueSoonTasks = array_filter($activeTasks, fn ($task) => $task->isDueWithinDays(3));

		return [
			'totalTasks' => count($allTasks),
			'activeTasks' => count($activeTasks),
			'overdueTasks' => count($overdueTasks),
			'dueTodayTasks' => count($dueTodayTasks),
			'dueSoonTasks' => count($dueSoonTasks),
			'completionRate' => count($allTasks) > 0 ? round((count($allTasks) - count($activeTasks)) / count($allTasks) * 100, 2) : 100
		];
	}

	/**
	 * Get task statistics for a user
	 *
	 * @param string $userId The user ID
	 */
	public function getUserTaskStats(string $userId): array {
		$allTasks = $this->roomTaskMapper->findByUserId($userId, false);
		$activeTasks = array_filter($allTasks, fn ($task) => $task->getIsActive());
		$overdueTasks = array_filter($activeTasks, fn ($task) => $task->isOverdue());
		$dueTodayTasks = array_filter($activeTasks, fn ($task) => $task->isDueToday());

		return [
			'totalTasks' => count($allTasks),
			'activeTasks' => count($activeTasks),
			'overdueTasks' => count($overdueTasks),
			'dueTodayTasks' => count($dueTodayTasks),
			'completionRate' => count($allTasks) > 0 ? round((count($allTasks) - count($activeTasks)) / count($allTasks) * 100, 2) : 100
		];
	}

	/**
	 * Bulk assign tasks to user
	 *
	 * Each item is routed through {@see assignTask()} so ownership and
	 * membership validation (CR-SVC-09) apply per task; failing items are
	 * skipped and logged without the raw target userId (privacy).
	 *
	 * @param array $roomTaskIds Array of room task IDs
	 * @param string $userId The user ID (must be a house member)
	 * @param int $houseId The caller's house ID (scopes the room tasks)
	 * @return RoomTask[] The successfully assigned room tasks
	 */
	public function bulkAssignTasks(array $roomTaskIds, string $userId, int $houseId): array {
		$assignedTasks = [];

		foreach ($roomTaskIds as $roomTaskId) {
			try {
				$assignedTasks[] = $this->assignTask($roomTaskId, $userId, $houseId);
			} catch (\Exception $e) {
				$this->appLogger?->warning('Bulk room task assignment skipped failed item', [
					'service' => 'RoomTaskService',
					'roomTaskId' => $roomTaskId,
					'houseId' => $houseId,
					'exception' => get_class($e),
				]);
				continue;
			}
		}

		return $assignedTasks;
	}

	/**
	 * Get upcoming tasks for user (next 7 days)
	 *
	 * @param string $userId The user ID
	 */
	public function getUpcomingTasksForUser(string $userId): array {
		$allTasks = $this->getUserTasks($userId);
		$upcomingTasks = [];

		foreach ($allTasks as $task) {
			if ($task->isDueWithinDays(7)) {
				$upcomingTasks[] = $task;
			}
		}

		// Sort by due date
		usort($upcomingTasks, function ($a, $b) {
			return $a->getNextDue() <=> $b->getNextDue();
		});

		return $upcomingTasks;
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
	 * Get tasks assigned to user
	 *
	 * @param int $houseId The house ID
	 * @param string $userId The user ID
	 * @return RoomTask[]
	 */
	public function getTasksAssignedToUser(int $houseId, string $userId): array {
		return $this->roomTaskMapper->findByHouseAndAssignedUser($houseId, $userId);
	}

	/**
	 * Get all unassigned tasks in house
	 *
	 * @param int $houseId The house ID
	 * @return RoomTask[]
	 */
	public function getUnassignedTasks(int $houseId): array {
		return $this->roomTaskMapper->findUnassignedByHouse($houseId);
	}

	/**
	 * Get today's tasks for a house
	 *
	 * @param int $houseId The house ID
	 * @param DateTime $date The date to get tasks for
	 * @return RoomTask[]
	 */
	public function getDailyTasks(int $houseId, \DateTime $date): array {
		return $this->roomTaskMapper->findDailyTasks($houseId, $date);
	}

	/**
	 * Get today's tasks for a house including task and room details.
	 *
	 * Uses per-request caching for task and room entities to avoid repeated
	 * lookups when multiple daily room tasks reference the same task or room.
	 *
	 * @param int $houseId The house ID
	 * @param \DateTime $date The date to get tasks for
	 * @return array<int, array{roomTask: RoomTask, task: Task, room: Room}>
	 */
	public function getDailyTasksWithDetails(int $houseId, \DateTime $date): array {
		$dailyTasks = $this->getDailyTasks($houseId, $date);
		$taskCache = [];
		$roomCache = [];
		$details = [];

		foreach ($dailyTasks as $roomTask) {
			$taskId = $roomTask->getTaskId();
			if (!isset($taskCache[$taskId])) {
				$taskCache[$taskId] = $this->taskMapper->find($taskId);
			}

			$roomId = $roomTask->getRoomId();
			if (!isset($roomCache[$roomId])) {
				$roomCache[$roomId] = $this->roomMapper->find($roomId);
			}

			$details[] = [
				'roomTask' => $roomTask,
				'task' => $taskCache[$taskId],
				'room' => $roomCache[$roomId],
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
		// Base set: every active task that could recur into the window (nextDue <= to).
		$roomTasks = $this->roomTaskMapper->findActiveDueUpToByHouse($houseId, $to);
		$completions = $this->completedTaskMapper->findCompletionsBetweenByHouse($houseId, $from, $to);

		$taskIds = [];
		$roomIds = [];
		foreach ($roomTasks as $rt) {
			$taskIds[$rt->getTaskId()] = true;
			$roomIds[$rt->getRoomId()] = true;
		}

		$taskCache = [];
		foreach (array_keys($taskIds) as $taskId) {
			$taskCache[$taskId] = $this->taskMapper->find($taskId);
		}

		$roomCache = [];
		foreach (array_keys($roomIds) as $roomId) {
			$roomCache[$roomId] = $this->roomMapper->find($roomId);
		}

		$scheduled = [];
		foreach ($roomTasks as $rt) {
			$task = $taskCache[$rt->getTaskId()];

			$occurrenceDates = $rt->projectOccurrences(
				$from,
				$to,
				$task->getFrequencyType(),
				$task->getFrequencyValue(),
				$task->getRecurrenceMode(),
				$task->getSchedulingMode(),
				$task->getFixedScheduleDays(),
				$task->getActiveUntil(),
			);

			// Skip tasks that produce no occurrence inside the window.
			if (empty($occurrenceDates)) {
				continue;
			}

			$flexWindow = $rt->getFlexWindowBounds($task->getFlexDays());

			$scheduled[] = [
				'roomTask' => $rt->jsonSerialize(),
				'task' => $task->jsonSerialize(),
				'room' => $roomCache[$rt->getRoomId()]->jsonSerialize(),
				'flexWindow' => [
					'earliest' => $flexWindow['earliest']?->format('Y-m-d'),
					'ideal' => $flexWindow['ideal']?->format('Y-m-d'),
					'latest' => $flexWindow['latest']?->format('Y-m-d'),
				],
				'occurrences' => array_map(
					fn (\DateTime $date) => $date->format('Y-m-d'),
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
	 * Complete a room task (simple version)
	 *
	 * @param int $roomTaskId The room task ID
	 * @return RoomTask The updated room task
	 */
	public function completeTaskSimple(int $roomTaskId): RoomTask {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);
		$roomTask->setLastCompleted(new \DateTime());
		return $this->roomTaskMapper->update($roomTask);
	}

	/**
	 * Uncomplete a room task
	 *
	 * @param int $roomTaskId The room task ID
	 * @return RoomTask The updated room task
	 */
	public function uncompleteTask(int $roomTaskId): RoomTask {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);
		$roomTask->setLastCompleted(null);
		return $this->roomTaskMapper->update($roomTask);
	}

	/**
	 * Skip a room task
	 *
	 * @param int $roomTaskId The room task ID
	 * @return RoomTask The updated room task
	 */
	public function skipTask(int $roomTaskId): RoomTask {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);
		$nextDue = $roomTask->getNextDue();
		if ($nextDue === null) {
			throw new \InvalidArgumentException('Cannot skip a room task without a due date');
		}
		$nextDue->modify('+1 day');
		$roomTask->setNextDue($nextDue);
		return $this->roomTaskMapper->update($roomTask);
	}

	/**
	 * Reschedule a room task
	 *
	 * @param int $roomTaskId The room task ID
	 * @param DateTime $newDate The new due date
	 * @return RoomTask The updated room task
	 */
	public function rescheduleTask(int $roomTaskId, \DateTime $newDate): RoomTask {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);
		$roomTask->setNextDue($newDate);
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

		// Get current day name (lowercase)
		$currentDay = strtolower($date->format('l'));

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
