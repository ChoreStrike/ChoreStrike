<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssignee;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\FloorMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\ChoreStrike\Exception\NotFoundException;

/**
 * Service for room task-related business logic
 */
class RoomTaskService
{
    private RoomTaskMapper $roomTaskMapper;
    private TaskMapper $taskMapper;
    private RoomMapper $roomMapper;
    private FloorMapper $floorMapper;
    private RoomService $roomService;
    private TaskAssigneeMapper $taskAssigneeMapper;
    private HouseUserMapper $houseUserMapper;
    private ?AppLogger $appLogger;

    public function __construct(
        RoomTaskMapper $roomTaskMapper,
        TaskMapper $taskMapper,
        RoomMapper $roomMapper,
        FloorMapper $floorMapper,
        RoomService $roomService,
        TaskAssigneeMapper $taskAssigneeMapper,
        HouseUserMapper $houseUserMapper,
        ?AppLogger $appLogger = null
    ) {
        $this->roomTaskMapper = $roomTaskMapper;
        $this->taskMapper = $taskMapper;
        $this->roomMapper = $roomMapper;
        $this->floorMapper = $floorMapper;
        $this->roomService = $roomService;
        $this->taskAssigneeMapper = $taskAssigneeMapper;
        $this->houseUserMapper = $houseUserMapper;
        $this->appLogger = $appLogger;
    }

    /**
     * Validate that a room task belongs to the specified house
     *
     * @param int $roomTaskId The room task ID
     * @param int $houseId The house ID to validate against
     * @throws NotFoundException if room task doesn't belong to house
     */
    public function validateRoomTaskOwnership(int $roomTaskId, int $houseId): void
    {
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
     * Get room task by ID
     *
     * @param int $roomTaskId The room task ID
     * @throws DoesNotExistException if not found
     */
    public function getRoomTask(int $roomTaskId): RoomTask
    {
        return $this->roomTaskMapper->find($roomTaskId);
    }

    /**
     * Get all room tasks for a room
     *
     * @param int $roomId The room ID
     * @param bool $activeOnly Whether to return only active tasks
     * @return RoomTask[]
     */
    public function getRoomTasks(int $roomId, bool $activeOnly = true): array
    {
        return $this->roomTaskMapper->findByRoomId($roomId, $activeOnly);
    }

    /**
     * Get all room tasks for a user
     *
     * @param string $userId The user ID
     * @param bool $activeOnly Whether to return only active tasks
     * @return RoomTask[]
     */
    public function getUserTasks(string $userId, bool $activeOnly = true): array
    {
        return $this->roomTaskMapper->findByUserId($userId, $activeOnly);
    }

    /**
     * Get overdue tasks
     *
     * @param int|null $roomId Optional room ID to filter by
     * @return RoomTask[]
     */
    public function getOverdueTasks(?int $roomId = null): array
    {
        return $this->roomTaskMapper->findOverdue($roomId);
    }

    /**
     * Get tasks due today
     *
     * @param int|null $roomId Optional room ID to filter by
     * @return RoomTask[]
     */
    public function getDueTodayTasks(?int $roomId = null): array
    {
        return $this->roomTaskMapper->findDueToday($roomId);
    }

    /**
     * Get tasks due within the next N days
     *
     * @param int $days Number of days
     * @param int|null $roomId Optional room ID to filter by
     * @return RoomTask[]
     */
    public function getDueWithinDaysTasks(int $days, ?int $roomId = null): array
    {
        return $this->roomTaskMapper->findDueWithinDays($days, $roomId);
    }

    /**
     * Create a new room task
     *
     * @param int $roomId The room ID
     * @param int $taskId The task ID
     * @param \DateTime|null $nextDue Optional next due date (defaults to tomorrow)
     * @param int|null $scopeId Optional scope ID
     * @param string|null $assignedToUserId Optional assigned user ID
     * @param array $weekdayAvailability Optional weekday availability for flex scheduling
     * @param array $energyBudget Optional energy budget for flex scheduling
     */
    public function createRoomTask(
        int $roomId,
        int $taskId,
        ?\DateTime $nextDue = null,
        ?int $scopeId = null,
        ?string $assignedToUserId = null,
        array $weekdayAvailability = [],
        array $energyBudget = []
    ): RoomTask {
        // Verify room exists
        $this->roomMapper->find($roomId);

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
     * Get room tasks for a scope
     *
     * @param int $scopeId The scope ID
     * @param bool $activeOnly Whether to return only active tasks
     * @return RoomTask[]
     */
    public function getScopeTasks(int $scopeId, bool $activeOnly = true): array
    {
        return $this->roomTaskMapper->findByScopeId($scopeId, $activeOnly);
    }

    /**
     * Assign room task to user
     *
     * @param int $roomTaskId The room task ID
     * @param string $userId The user ID
     */
    public function assignTask(int $roomTaskId, string $userId): RoomTask
    {
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
    public function unassignTask(int $roomTaskId): RoomTask
    {
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
    private function assertHouseMembers(array $userIds, int $houseId): void
    {
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
    public function getAssigneeUserIds(int $roomTaskId): array
    {
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
    public function getAssigneeUserIdsByRoomTasks(array $roomTasks): array
    {
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
    public function setAssignees(int $roomTaskId, array $userIds, int $houseId, string $assignedBy): array
    {
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
    public function addAssignee(int $roomTaskId, string $userId, int $houseId, string $assignedBy): TaskAssignee
    {
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
     * @param int $roomTaskId The room task ID
     * @param string $userId The Nextcloud user ID to remove
     */
    public function removeAssignee(int $roomTaskId, string $userId): void
    {
        $this->roomTaskMapper->find($roomTaskId);
        $this->taskAssigneeMapper->removeAssignee($roomTaskId, $userId);
    }

    /**
     * Deactivate room task
     *
     * @param int $roomTaskId The room task ID
     */
    public function deactivateTask(int $roomTaskId): RoomTask
    {
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
    public function reactivateTask(int $roomTaskId): RoomTask
    {
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
    public function updateDueDate(int $roomTaskId, \DateTime $nextDue): RoomTask
    {
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
    public function completeTask(int $roomTaskId, string $userId): array
    {
        return $this->roomService->completeRoomTask($roomTaskId, $userId);
    }

    /**
     * Delete room task
     *
     * @param int $roomTaskId The room task ID
     */
    public function deleteRoomTask(int $roomTaskId): void
    {
        $roomTask = $this->roomTaskMapper->find($roomTaskId);
        $this->roomTaskMapper->delete($roomTask);
    }

    /**
     * Get room task with full details
     *
     * @param int $roomTaskId The room task ID
     */
    public function getRoomTaskWithDetails(int $roomTaskId): array
    {
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
    public function getRoomTaskStats(int $roomId): array
    {
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
    public function getUserTaskStats(string $userId): array
    {
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
     * @param array $roomTaskIds Array of room task IDs
     * @param string $userId The user ID
     */
    public function bulkAssignTasks(array $roomTaskIds, string $userId): array
    {
        $assignedTasks = [];

        foreach ($roomTaskIds as $roomTaskId) {
            try {
                $assignedTasks[] = $this->assignTask($roomTaskId, $userId);
            } catch (\Exception $e) {
                $this->appLogger?->warning('Bulk room task assignment skipped failed item', [
                    'service' => 'RoomTaskService',
                    'roomTaskId' => $roomTaskId,
                    'userId' => $userId,
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
    public function getUpcomingTasksForUser(string $userId): array
    {
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
     * Assign room task to a group
     *
     * @param int $roomTaskId The room task ID
     * @param string $groupId The group ID
     */
    public function assignToGroup(int $roomTaskId, string $groupId): RoomTask
    {
        $this->roomTaskMapper->find($roomTaskId);
        $this->roomTaskMapper->assignToGroup($roomTaskId, $groupId);
        return $this->roomTaskMapper->find($roomTaskId);
    }

    /**
     * Claim a group-assigned task for a user
     *
     * @param int $roomTaskId The room task ID
     * @param string $userId The user ID claiming the task
     */
    public function claimForUser(int $roomTaskId, string $userId): RoomTask
    {
        $roomTask = $this->roomTaskMapper->find($roomTaskId);

        // Only allow claiming if assigned to a group
        if ($roomTask->getAssignmentType() !== 'group') {
            throw new \InvalidArgumentException('Task is not assigned to a group');
        }

        $this->roomTaskMapper->claimForUser($roomTaskId, $userId);
        return $this->roomTaskMapper->find($roomTaskId);
    }

    /**
     * Unclaim a group-assigned task
     *
     * @param int $roomTaskId The room task ID
     */
    public function unclaim(int $roomTaskId): RoomTask
    {
        $this->roomTaskMapper->find($roomTaskId);
        $this->roomTaskMapper->unclaim($roomTaskId);
        return $this->roomTaskMapper->find($roomTaskId);
    }

    /**
     * Get tasks assigned to a group
     *
     * @param string $groupId The group ID
     * @param bool $activeOnly Whether to return only active tasks
     * @return RoomTask[]
     */
    public function getGroupTasks(string $groupId, bool $activeOnly = true): array
    {
        return $this->roomTaskMapper->findByGroupId($groupId, $activeOnly);
    }

    /**
     * Get tasks visible to a user (assigned directly or via group)
     *
     * @param string $userId The user ID
     * @param array $groupIds Array of group IDs the user belongs to
     * @return RoomTask[]
     */
    public function getTasksVisibleToUser(string $userId, array $groupIds): array
    {
        return $this->roomTaskMapper->findVisibleToUser($userId, $groupIds);
    }

    /**
     * Get overdue tasks that can be claimed
     *
     * @param int $houseId The house ID
     * @return RoomTask[]
     */
    public function getClaimableOverdueTasks(int $houseId): array
    {
        $overdueTasks = $this->roomTaskMapper->findOverdueByHouse($houseId);

        $claimableTasks = array_filter($overdueTasks, function ($task) {
            return $task->getAssignedToUserId() !== null &&
                   $task->getAssignedToUserId() !== '';
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
    public function getTasksAssignedToUser(int $houseId, string $userId): array
    {
        return $this->roomTaskMapper->findByHouseAndAssignedUser($houseId, $userId);
    }

    /**
     * Get all unassigned tasks in house
     *
     * @param int $houseId The house ID
     * @return RoomTask[]
     */
    public function getUnassignedTasks(int $houseId): array
    {
        return $this->roomTaskMapper->findUnassignedByHouse($houseId);
    }

    /**
     * Get today's tasks for a house
     *
     * @param int $houseId The house ID
     * @param DateTime $date The date to get tasks for
     * @return RoomTask[]
     */
    public function getDailyTasks(int $houseId, \DateTime $date): array
    {
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
    public function getDailyTasksWithDetails(int $houseId, \DateTime $date): array
    {
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
     * Complete a room task (simple version)
     *
     * @param int $roomTaskId The room task ID
     * @return RoomTask The updated room task
     */
    public function completeTaskSimple(int $roomTaskId): RoomTask
    {
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
    public function uncompleteTask(int $roomTaskId): RoomTask
    {
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
    public function skipTask(int $roomTaskId): RoomTask
    {
        $roomTask = $this->roomTaskMapper->find($roomTaskId);
        // Skip by moving due date forward by 1 day
        $nextDue = $roomTask->getNextDue();
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
    public function rescheduleTask(int $roomTaskId, \DateTime $newDate): RoomTask
    {
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
        \OCA\ChoreStrike\Db\HouseUserMapper $houseUserMapper
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
        return array_slice($allTasks, 0, (int) $maxDailyTasks);
    }
}
