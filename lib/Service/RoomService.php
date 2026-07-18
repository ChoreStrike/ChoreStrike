<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\FloorMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\ForbiddenException;
use OCP\AppFramework\Http\NotFoundException;
use OCP\IDBConnection;

/**
 * Service for room-related business logic
 */
class RoomService
{
    private RoomMapper $roomMapper;
    private RoomTaskMapper $roomTaskMapper;
    private TaskMapper $taskMapper;
    private HouseUserMapper $houseUserMapper;
    private FloorMapper $floorMapper;
    private GameLogicService $gameLogicService;
    private TaskAssigneeMapper $taskAssigneeMapper;
    private CompletedTaskMapper $completedTaskMapper;
    private HouseService $houseService;
    private IDBConnection $db;

    public function __construct(
        RoomMapper $roomMapper,
        RoomTaskMapper $roomTaskMapper,
        TaskMapper $taskMapper,
        HouseUserMapper $houseUserMapper,
        FloorMapper $floorMapper,
        GameLogicService $gameLogicService,
        TaskAssigneeMapper $taskAssigneeMapper,
        CompletedTaskMapper $completedTaskMapper,
        HouseService $houseService,
        IDBConnection $db
    ) {
        $this->roomMapper = $roomMapper;
        $this->roomTaskMapper = $roomTaskMapper;
        $this->taskMapper = $taskMapper;
        $this->houseUserMapper = $houseUserMapper;
        $this->floorMapper = $floorMapper;
        $this->gameLogicService = $gameLogicService;
        $this->taskAssigneeMapper = $taskAssigneeMapper;
        $this->completedTaskMapper = $completedTaskMapper;
        $this->houseService = $houseService;
        $this->db = $db;
    }

    /**
     * Validate that a room belongs to the specified house
     *
     * @param int $roomId The room ID
     * @param int $houseId The house ID to validate against
     * @throws NotFoundException if room doesn't belong to house
     */
    public function validateRoomOwnership(int $roomId, int $houseId): void
    {
        $room = $this->roomMapper->find($roomId);
        $floor = $this->floorMapper->find($room->getFloorId());

        if ($floor->getHouseId() !== $houseId) {
            throw new NotFoundException('Room not found');
        }
    }

    /**
     * Get all rooms for a floor
     *
     * @param int $floorId The floor ID
     * @return Room[]
     */
    public function getRooms(int $floorId): array
    {
        return $this->roomMapper->findByFloorId($floorId);
    }

    /**
     * Get room by ID
     *
     * @param int $roomId The room ID
     * @throws DoesNotExistException if not found
     */
    public function getRoom(int $roomId): Room
    {
        return $this->roomMapper->find($roomId);
    }

    /**
     * Get floor by ID.
     *
     * @param int $floorId The floor ID
     * @throws DoesNotExistException if not found
     */
    public function getFloor(int $floorId): Floor
    {
        return $this->floorMapper->find($floorId);
    }

    /**
     * Create a new room
     *
     * @param int $floorId The floor ID
     * @param string $name The room name
     * @param ?string $icon The configured room icon
     */
    public function createRoom(int $floorId, string $name, ?string $icon = null): Room
    {
        return $this->roomMapper->create($floorId, $name, $icon);
    }

    /**
     * Update room name
     *
     * @param int $roomId The room ID
     * @param string $name The new name
     */
    public function updateRoomName(int $roomId, string $name): Room
    {
        $room = $this->roomMapper->find($roomId);
        $room->setName($name);
        return $this->roomMapper->update($room);
    }

    /**
     * Delete room and all associated tasks
     *
     * @param int $roomId The room ID
     */
    public function deleteRoom(int $roomId): void
    {
        $room = $this->roomMapper->find($roomId);

        // Delete associated room tasks
        $roomTasks = $this->roomTaskMapper->findByRoomId($roomId);
        foreach ($roomTasks as $roomTask) {
            $this->roomTaskMapper->delete($roomTask);
        }

        $this->roomMapper->delete($room);
    }

    /**
     * Update room cleanliness after task completion
     *
     * @param int $roomId The room ID
     * @param int $cleanlinessChange The change in cleanliness (-100 to 100)
     */
    public function updateRoomCleanliness(int $roomId, int $cleanlinessChange): Room
    {
        $room = $this->roomMapper->find($roomId);
        $room->updateCleanliness($cleanlinessChange);
        return $this->roomMapper->update($room);
    }

    /**
     * Get room with tasks
     *
     * @param int $roomId The room ID
     */
    public function getRoomWithTasks(int $roomId): array
    {
        $room = $this->roomMapper->find($roomId);
        $roomTasks = $this->roomTaskMapper->findByRoomId($roomId);

        // Load task details for each room task
        $tasksWithDetails = [];
        foreach ($roomTasks as $roomTask) {
            try {
                $task = $this->taskMapper->find($roomTask->getTaskId());
                $tasksWithDetails[] = [
                    'roomTask' => $roomTask,
                    'task' => $task
                ];
            } catch (DoesNotExistException $e) {
                // Task doesn't exist, skip this room task
                continue;
            }
        }

        return [
            'room' => $room,
            'tasks' => $tasksWithDetails,
            'stats' => $this->calculateRoomStats($room, $tasksWithDetails)
        ];
    }

    /**
     * Calculate room statistics
     *
     * @param Room $room The room
     * @param array $tasksWithDetails Array of tasks with details
     */
    private function calculateRoomStats(Room $room, array $tasksWithDetails): array
    {
        $activeTasks = array_filter($tasksWithDetails, fn ($item) => $item['roomTask']->getIsActive());
        $overdueTasks = array_filter($activeTasks, fn ($item) => $item['roomTask']->isOverdue());
        $dueTodayTasks = array_filter($activeTasks, fn ($item) => $item['roomTask']->isDueToday());

        return [
            'cleanliness' => $room->getCleanliness(),
            'monsterCount' => $room->getMonsterCount(),
            'status' => $room->getStatus(),
            'needsCleaning' => $room->needsCleaning(),
            'activeTasks' => count($activeTasks),
            'overdueTasks' => count($overdueTasks),
            'dueTodayTasks' => count($dueTodayTasks),
            'lastCleaned' => $room->getLastCleaned()?->format('c')
        ];
    }

    /**
     * Get rooms that need cleaning
     *
     * @param int|null $floorId Optional floor ID to filter by
     * @return Room[]
     */
    public function getRoomsNeedingCleaning(?int $floorId = null): array
    {
        return $this->roomMapper->findNeedingCleaning($floorId);
    }

    /**
     * Add task to room
     *
     * @param int $roomId The room ID
     * @param int $taskId The task ID
     * @param \DateTime|null $nextDue Optional next due date (defaults to tomorrow)
     * @param string|null $assignedToUserId Optional assigned user ID
     */
    public function addTaskToRoom(
        int $roomId,
        int $taskId,
        ?\DateTime $nextDue = null,
        ?string $assignedToUserId = null
    ): RoomTask {
        // Check if task already exists in room
        if ($this->roomTaskMapper->exists($roomId, $taskId)) {
            throw new \InvalidArgumentException("Task {$taskId} already exists in room {$roomId}");
        }

        // Default to tomorrow if no due date provided
        if ($nextDue === null) {
            $nextDue = new \DateTime();
            $nextDue->add(new \DateInterval('P1D'));
        }

        return $this->roomTaskMapper->create($roomId, $taskId, $nextDue, $assignedToUserId);
    }

    /**
     * Remove task from room
     *
     * @param int $roomId The room ID
     * @param int $taskId The task ID
     */
    public function removeTaskFromRoom(int $roomId, int $taskId): void
    {
        $roomTasks = $this->roomTaskMapper->findByRoomId($roomId);

        foreach ($roomTasks as $roomTask) {
            if ($roomTask->getTaskId() === $taskId) {
                $this->roomTaskMapper->delete($roomTask);
                return;
            }
        }

        throw new DoesNotExistException("Task {$taskId} not found in room {$roomId}");
    }

    /**
     * Get room task by ID
     *
     * @param int $roomTaskId The room task ID
     */
    public function getRoomTask(int $roomTaskId): array
    {
        $roomTask = $this->roomTaskMapper->find($roomTaskId);
        $task = $this->taskMapper->find($roomTask->getTaskId());
        $room = $this->roomMapper->find($roomTask->getRoomId());

        return [
            'roomTask' => $roomTask,
            'task' => $task,
            'room' => $room
        ];
    }

    /**
     * Complete room task and update room cleanliness
     *
     * @param int $roomTaskId The room task ID
     * @param string $userId The Nextcloud user ID who completed the task
     */
    public function completeRoomTask(int $roomTaskId, string $userId): array
    {
        $roomTaskData = $this->getRoomTask($roomTaskId);
        $roomTask = $roomTaskData['roomTask'];
        $task = $roomTaskData['task'];
        $room = $roomTaskData['room'];

        // Get house user for scheduling preferences
        $floor = $this->floorMapper->find($room->getFloorId());
        $houseUser = $this->houseUserMapper->findByNextcloudUserId($floor->getHouseId(), $userId);

        // The whole completion (lock, gate, guard, mutations, reward insert) is
        // atomic: a failure at any step rolls back every prior write. The early
        // room-task row lock also serializes concurrent completions of the SAME
        // task so the duplicate-completion guard cannot be raced past (TOCTOU).
        $this->db->beginTransaction();
        try {
            $this->roomTaskMapper->lockForUpdate($roomTaskId);

            // Resolve the full set of assigned Nextcloud user IDs for this room task:
            // the multi-assign join table plus the legacy single assignee column.
            $assigneeUserIds = TaskAssigneeMapper::mergeLegacyAssignee(
                $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTaskId),
                $roomTask->getAssignedToUserId()
            );

            // Private-task gate: only assigned users may complete a private task.
            // Non-private tasks remain completable by any house member.
            if ($task->isPrivate() && !in_array($userId, $assigneeUserIds, true)) {
                throw new ForbiddenException('Private tasks may only be completed by assigned users');
            }

            // Duplicate-completion guard: prevent the same user from earning coins
            // twice for the same room task on the same calendar day (defense in
            // depth against double-clicks / API re-submission).
            $todayCount = $this->completedTaskMapper->countByRoomTaskAndUserOnDate(
                $roomTaskId,
                $houseUser->getId(),
                new \DateTime()
            );
            if ($todayCount > 0) {
                throw new ForbiddenException('You already completed this task today');
            }

            // Overdue-takeover: only record a takeover when a NON-assignee picks
            // up an overdue task. A co-assignee (join table or legacy) completing
            // their own shared task is NOT a takeover. The original assignee's
            // streak is unaffected because game logic only updates the completer.
            if ($roomTask->isOverdue() && !in_array($userId, $assigneeUserIds, true)) {
                $originalAssignee = $roomTask->getAssignedToUserId();
                if ($originalAssignee !== null && $originalAssignee !== '') {
                    $roomTask->markClaimed($originalAssignee, $userId);
                }
            }

            // Get user's scheduling preferences for flex scheduling
            $preferences = $houseUser->getSchedulingPreferences();
            $weekdayAvailability = $preferences['weekdayAvailability'] ?? [];
            $energyBudget = $preferences['energyBudget'] ?? [];

            // Mark task as completed and calculate next due date with flex scheduling
            $roomTask->markCompleted(
                new \DateTime(),
                $task->getFrequencyType(),
                $task->getFrequencyValue(),
                $task->getRecurrenceMode(),
                $task->getSchedulingMode(),
                $task->getActiveUntil(),
                $task->getFixedScheduleTime(),
                $task->getFixedScheduleDays(),
                $task->getFlexDays(),
                $weekdayAvailability,
                $energyBudget
            );
            $this->roomTaskMapper->update($roomTask);

            // Update room cleanliness based on task energy cost
            $cleanlinessChange = $task->getEnergyCost() * 10; // 10% per energy point
            $updatedRoom = $this->updateRoomCleanliness($room->getId(), $cleanlinessChange);

            // Trigger game logic for task completion
            $gameResult = $this->gameLogicService->handleTaskCompletion(
                $roomTaskId,
                $houseUser->getId(),
                $task,
                $updatedRoom->getCleanliness()
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Refresh weekly goal progress AFTER commit so reads are consistent
        // across engines (the new completion row + any next_due shift from
        // markCompleted are visible). The frontend detects the
        // not-achieved → achieved transition by comparing its previous store
        // snapshot with this fresh one, so we don't need a pre-read here.
        $weeklyGoal = $this->houseService->getWeeklyGoalProgress($floor->getHouseId());

        return [
            'roomTask' => $roomTask,
            'room' => $updatedRoom,
            'task' => $task,
            'rewards' => [
                'cleanlinessChange' => $cleanlinessChange,
                'energyCost' => $task->getEnergyCost(),
                'priority' => $task->getPriority(),
                'contributionScore' => $gameResult['contributionScore'],
                'coins' => $gameResult['coins'],
                'isCombo' => $gameResult['isCombo'],
                'currentStreak' => $gameResult['currentStreak'],
                'streakFreezeUsed' => $gameResult['streakFreezeUsed'] ?? false,
                'streakFreezesRemaining' => $gameResult['streakFreezesRemaining'] ?? 0,
                'comboBonusFreeze' => $gameResult['comboBonusFreeze'] ?? false,
                'weeklyGoal' => $weeklyGoal,
            ]
        ];
    }
}
