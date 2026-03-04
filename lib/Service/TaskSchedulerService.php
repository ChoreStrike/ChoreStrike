<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\AvailabilitySettings;
use OCA\ChoreStrike\Db\AvailabilitySettingsMapper;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\UserStatsMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Service\Scheduling\TaskPriorityScorer;
use OCA\ChoreStrike\Service\Scheduling\AvailabilityCalculator;
use OCA\ChoreStrike\Service\Scheduling\TaskAssignmentAlgorithm;
use OCA\ChoreStrike\Service\Scheduling\ScheduleOptimizer;
use OCA\ChoreStrike\Service\Scheduling\TaskReassignmentSuggester;

/**
 * Service for intelligent task scheduling
 */
class TaskSchedulerService
{
    public function __construct(
        private AvailabilitySettingsMapper $availabilityMapper,
        private FloorMapper $floorMapper,
        private RoomTaskMapper $roomTaskMapper,
        private UserStatsMapper $userStatsMapper,
        private TaskMapper $taskMapper,
        private RoomMapper $roomMapper,
        private TaskPriorityScorer $priorityScorer,
        private AvailabilityCalculator $availabilityCalculator,
        private TaskAssignmentAlgorithm $assignmentAlgorithm,
        private ScheduleOptimizer $scheduleOptimizer,
        private TaskReassignmentSuggester $reassignmentSuggester,
    ) {
    }

    /**
     * Generate weekly schedule for house
     */
    public function generateWeeklySchedule(int $houseId, \DateTime $weekStart): array
    {
        // Collect data
        $roomTasks = $this->getActiveRoomTasks($houseId);
        $userSettings = $this->availabilityMapper->findByHouse($houseId);

        // Calculate priority scores and sort
        $scoredTasks = $this->priorityScorer->calculateScores($roomTasks);
        $scoredTasks = $this->priorityScorer->sortByPriority($scoredTasks);

        // Generate schedule using assignment algorithm
        $schedule = $this->assignmentAlgorithm->distributeTasks($scoredTasks, $userSettings, $weekStart);

        return [
            'week_start' => $weekStart->format('Y-m-d'),
            'tasks' => $schedule,
            'total_tasks' => count($schedule),
            'users_count' => count($userSettings),
        ];
    }

    /**
     * Get active room tasks for house
     *
     * @return RoomTask[]
     */
    private function getActiveRoomTasks(int $houseId): array
    {
        $floors = $this->floorMapper->findByHouseId($houseId);
        $allTasks = [];

        foreach ($floors as $floor) {
            $rooms = $this->roomMapper->findByFloorId($floor->getId());
            foreach ($rooms as $room) {
                $tasks = $this->roomTaskMapper->findByRoomId($room->getId(), true);
                foreach ($tasks as $task) {
                    if ($this->shouldScheduleTask($task)) {
                        $allTasks[] = $task;
                    }
                }
            }
        }

        return $allTasks;
    }

    /**
     * Check if task should be scheduled
     */
    private function shouldScheduleTask(RoomTask $task): bool
    {
        // Skip completed or inactive tasks
        if (!$task->getIsActive()) {
            return false;
        }

        $nextDue = $task->getNextDue();
        if (!$nextDue) {
            return false;
        }

        $now = new \DateTime();
        $daysUntilDue = (int)$now->diff($nextDue)->days;

        // Schedule if overdue or due within 7 days
        return $daysUntilDue <= 7 || $nextDue < $now;
    }






    /**
     * Optimize existing schedule
     */
    public function optimizeSchedule(array $schedule): array
    {
        // Get user settings for optimization
        $houseId = $this->extractHouseIdFromSchedule($schedule);
        if ($houseId === null) {
            return $schedule;
        }

        $userSettings = $this->availabilityMapper->findByHouse($houseId);

        // Use the schedule optimizer
        $result = $this->scheduleOptimizer->optimizeSchedule($schedule, $userSettings);

        return $result['schedule'];
    }

    /**
     * Get suggested task reassignment
     */
    public function suggestReassignment(int $roomTaskId): array
    {
        return $this->reassignmentSuggester->suggestReassignment($roomTaskId);
    }

    /**
     * Extract house ID from schedule (helper method)
     */
    private function extractHouseIdFromSchedule(array $schedule): ?int
    {
        if (empty($schedule)) {
            return null;
        }

        // Get first task and find its room task to determine house
        $firstTask = $schedule[0];
        $roomTaskId = $firstTask['room_task_id'] ?? null;

        if ($roomTaskId) {
            $roomTask = $this->roomTaskMapper->find($roomTaskId);
            if ($roomTask) {
                $room = $this->roomMapper->find($roomTask->getRoomId());
                if ($room) {
                    $floor = $this->floorMapper->find($room->getFloorId());
                    return $floor?->getHouseId();
                }
            }
        }

        return null;
    }

    /**
     * Get scheduling statistics
     */
    public function getScheduleStats(int $houseId, \DateTime $weekStart): array
    {
        $schedule = $this->generateWeeklySchedule($houseId, $weekStart);

        $stats = [
            'total_tasks' => count($schedule['tasks']),
            'by_day' => [],
            'by_user' => [],
            'total_energy' => 0,
        ];

        foreach ($schedule['tasks'] as $task) {
            $day = $task['scheduled_day'];
            $user = $task['assigned_to'];

            $stats['by_day'][$day] = ($stats['by_day'][$day] ?? 0) + 1;
            $stats['by_user'][$user] = ($stats['by_user'][$user] ?? 0) + 1;
            $stats['total_energy'] += $task['energy_cost'];
        }

        return $stats;
    }

    /**
     * Get assignment statistics using the algorithm
     */
    public function getAssignmentStats(int $houseId, \DateTime $weekStart): array
    {
        $roomTasks = $this->getActiveRoomTasks($houseId);
        $userSettings = $this->availabilityMapper->findByHouse($houseId);

        $scoredTasks = $this->priorityScorer->calculateScores($roomTasks);
        $scoredTasks = $this->priorityScorer->sortByPriority($scoredTasks);

        $schedule = $this->assignmentAlgorithm->distributeTasks($scoredTasks, $userSettings, $weekStart);

        return $this->assignmentAlgorithm->getAssignmentStats($schedule);
    }

    /**
     * Get overdue tasks for a house
     */
    public function getOverdueTasks(int $houseId): array
    {
        $roomTasks = $this->getActiveRoomTasks($houseId);
        $scoredTasks = $this->priorityScorer->calculateScores($roomTasks);

        return $this->priorityScorer->getOverdueTasks($scoredTasks);
    }

    /**
     * Get tasks due soon (within 3 days)
     */
    public function getTasksDueSoon(int $houseId): array
    {
        $roomTasks = $this->getActiveRoomTasks($houseId);
        $scoredTasks = $this->priorityScorer->calculateScores($roomTasks);

        return $this->priorityScorer->getTasksDueSoon($scoredTasks);
    }

    /**
     * Get high priority tasks for a house
     */
    public function getHighPriorityTasks(int $houseId, int $threshold = 50): array
    {
        $roomTasks = $this->getActiveRoomTasks($houseId);
        $scoredTasks = $this->priorityScorer->calculateScores($roomTasks);

        return $this->priorityScorer->getHighPriorityTasks($scoredTasks, $threshold);
    }
}
