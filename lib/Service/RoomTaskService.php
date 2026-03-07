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
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\FloorMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\NotFoundException;

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
    private ?AppLogger $appLogger;

    public function __construct(
        RoomTaskMapper $roomTaskMapper,
        TaskMapper $taskMapper,
        RoomMapper $roomMapper,
        FloorMapper $floorMapper,
        RoomService $roomService,
        ?AppLogger $appLogger = null
    ) {
        $this->roomTaskMapper = $roomTaskMapper;
        $this->taskMapper = $taskMapper;
        $this->roomMapper = $roomMapper;
        $this->floorMapper = $floorMapper;
        $this->roomService = $roomService;
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
        $roomTask = $this->roomTaskMapper->find($roomTaskId);
        $room = $this->roomMapper->find($roomTask->getRoomId());
        $floor = $this->floorMapper->find($room->getFloorId());

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
     */
    public function createRoomTask(
        int $roomId,
        int $taskId,
        ?\DateTime $nextDue = null,
        ?int $scopeId = null,
        ?string $assignedToUserId = null
    ): RoomTask {
        // Verify room exists
        $this->roomMapper->find($roomId);

        // Verify task exists
        $this->taskMapper->find($taskId);

        // Check if room task already exists
        if ($this->roomTaskMapper->exists($roomId, $taskId)) {
            throw new \InvalidArgumentException("Task {$taskId} already exists in room {$roomId}");
        }

        // Default to tomorrow if no due date provided
        if ($nextDue === null) {
            $nextDue = new \DateTime();
            $nextDue->add(new \DateInterval('P1D'));
        }

        return $this->roomTaskMapper->create($roomId, $taskId, $nextDue, $assignedToUserId, $scopeId);
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
     * Join a task as a participant (teamwork)
     *
     * @param int $roomTaskId The room task ID
     * @param string $userId The user ID joining
     * @return array Participant info
     */
    public function joinTask(int $roomTaskId, string $userId): array
    {
        $roomTask = $this->roomTaskMapper->find($roomTaskId);
        $task = $this->taskMapper->find($roomTask->getTaskId());

        // Check if teamwork is allowed for this task
        if (!$task->getAllowTeamwork()) {
            throw new \InvalidArgumentException('This task does not allow teamwork');
        }

        // Check max participants
        $maxParticipants = $task->getMaxParticipants();
        if ($maxParticipants !== null) {
            // Count existing participants (would need TaskParticipantMapper here)
            // For now, just return success
        }

        return [
            'success' => true,
            'roomTaskId' => $roomTaskId,
            'userId' => $userId,
            'joinedAt' => (new \DateTime())->format('c')
        ];
    }

    /**
     * Leave a task as a participant
     *
     * @param int $roomTaskId The room task ID
     * @param string $userId The user ID leaving
     * @return array Result info
     */
    public function leaveTask(int $roomTaskId, string $userId): array
    {
        $this->roomTaskMapper->find($roomTaskId);

        return [
            'success' => true,
            'roomTaskId' => $roomTaskId,
            'userId' => $userId,
            'leftAt' => (new \DateTime())->format('c')
        ];
    }

    /**
     * Get participants for a task
     *
     * @param int $roomTaskId The room task ID
     * @return array Participant list
     */
    public function getTaskParticipants(int $roomTaskId): array
    {
        $this->roomTaskMapper->find($roomTaskId);

        // Would use TaskParticipantMapper in full implementation
        return [];
    }

    /**
     * Claim an overdue task from another user
     *
     * @param int $roomTaskId The room task ID
     * @param string $userId The user claiming the task
     * @return array Claim result with bonus sparks
     */
    public function claimOverdueTask(int $roomTaskId, string $userId): array
    {
        $roomTask = $this->roomTaskMapper->find($roomTaskId);

        // Verify task is overdue
        if (!$roomTask->isOverdue()) {
            throw new \InvalidArgumentException('Task is not overdue and cannot be claimed');
        }

        // Calculate days overdue
        $daysOverdue = abs($roomTask->getDaysUntilDue());

        // Store original assignee
        $originalAssignee = $roomTask->getAssignedToUserId();

        // Assign to new user
        $this->roomTaskMapper->assignToUser($roomTaskId, $userId);

        // Mark as claimed
        $roomTask->setWasClaimed(true);
        $roomTask->setOriginalAssignee($originalAssignee);
        $roomTask->setClaimedAt(new \DateTime());

        // Calculate bonus sparks (5 sparks per day overdue, max 50)
        $bonusSparks = min($daysOverdue * 5, 50);

        return [
            'success' => true,
            'roomTask' => $roomTask->jsonSerialize(),
            'daysOverdue' => $daysOverdue,
            'originalAssignee' => $originalAssignee,
            'claimedBy' => $userId,
            'bonusSparks' => $bonusSparks
        ];
    }

    /**
     * Get overdue tasks that can be claimed
     *
     * @param int $houseId The house ID
     * @return RoomTask[]
     */
    public function getClaimableOverdueTasks(int $houseId): array
    {
        // Get all overdue tasks for the house
        $overdueTasks = $this->roomTaskMapper->findOverdue();

        // Filter to only include tasks that are assigned to someone else
        $claimableTasks = array_filter($overdueTasks, function ($task) {
            return $task->getAssignedToUserId() !== null &&
                   $task->getAssignedToUserId() !== '';
        });

        return array_values($claimableTasks);
    }

    /**
     * Get claim history for a task
     *
     * @param int $roomTaskId The room task ID
     * @return array Claim history
     */
    public function getClaimHistory(int $roomTaskId): array
    {
        $this->roomTaskMapper->find($roomTaskId);

        // Would use TaskClaimMapper in full implementation
        return [];
    }

    /**
     * Get claim statistics for a user
     *
     * @param string $userId The user ID
     * @return array Claim stats
     */
    public function getUserClaimStats(string $userId): array
    {
        // Would use TaskClaimMapper in full implementation
        return [
            'totalClaims' => 0,
            'completedClaims' => 0,
            'totalBonusSparks' => 0,
            'claims' => []
        ];
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
}
