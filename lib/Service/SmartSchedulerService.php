<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\RoomTask;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Smart scheduling compatibility facade.
 */
class SmartSchedulerService
{
    public function __construct(
        private TaskSchedulerService $taskSchedulerService,
        private SchedulingService $schedulingService,
        private RoomTaskService $roomTaskService,
        private ?AppLogger $appLogger = null
    ) {
    }

    /**
     * Generate schedule compatibility payload for smart-schedule API.
     *
     * @param int $houseId The house ID
     * @param \DateTime $startDate Schedule start date
     * @param \DateTime $endDate Schedule end date
     * @return array Generated schedule payload
     */
    public function generateSchedule(int $houseId, \DateTime $startDate, \DateTime $endDate): array
    {
        $this->appLogger?->info('Generating compatibility schedule', [
            'service' => 'SmartSchedulerService',
            'houseId' => $houseId,
            'startDate' => $startDate->format('Y-m-d'),
            'endDate' => $endDate->format('Y-m-d'),
        ]);

        $assignments = $this->buildAssignmentsForRange($houseId, $startDate, $endDate);
        $totalTasks = count($assignments);

        return [
            'schedule' => $this->groupAssignmentsByDate($assignments),
            'assignments' => $assignments,
            'unassignedTasks' => [],
            'totalTasks' => $totalTasks,
            'assignedTasks' => $totalTasks,
            'efficiency' => $totalTasks > 0 ? 1.0 : 0.0,
        ];
    }

    /**
     * Get scheduling preview payload for smart-schedule API.
     *
     * @param int $houseId The house ID
     * @param int $days Number of days to preview
     * @return array Preview data
     */
    public function getSchedulePreview(int $houseId, int $days = 7): array
    {
        $startDate = new \DateTime('today');
        $endDate = (new \DateTime())->modify("+{$days} days");
        $this->appLogger?->debug('Building smart schedule preview', [
            'service' => 'SmartSchedulerService',
            'houseId' => $houseId,
            'days' => $days,
        ]);

        $assignments = $this->buildAssignmentsForRange($houseId, $startDate, $endDate);
        $members = $this->schedulingService->getMemberAvailability($houseId);
        $dailyCapacity = $this->calculateDailyCapacity($members, $startDate, $endDate);

        $preview = [
            'dateRange' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
            ],
            'taskCount' => count($assignments),
            'memberCount' => count($members),
            'dailyCapacity' => $dailyCapacity,
            'potentialConflicts' => $this->detectPotentialConflicts($assignments, $dailyCapacity),
        ];

        return $preview;
    }

    /**
     * Apply a scheduling suggestion by assigning and rescheduling a room task.
     *
     * @param int $houseId The house ID
     * @param int $taskId The task ID to assign
     * @param string $assignedTo The Nextcloud user ID to assign the task to
     * @param string $scheduledFor The scheduled date (Y-m-d format)
     * @return RoomTask The updated task entity
     * @throws \InvalidArgumentException If validation fails
     */
    public function applySuggestion(int $houseId, int $taskId, string $assignedTo, string $scheduledFor): RoomTask
    {
        if (empty($assignedTo)) {
            throw new \InvalidArgumentException('Assignee cannot be empty');
        }

        $scheduledDate = \DateTime::createFromFormat('Y-m-d', $scheduledFor);
        if ($scheduledDate === false || $scheduledDate->format('Y-m-d') !== $scheduledFor) {
            throw new \InvalidArgumentException('Invalid date format. Use Y-m-d');
        }

        try {
            $preferences = $this->schedulingService->getUserPreferencesByNextcloudUserId($houseId, $assignedTo);
        } catch (DoesNotExistException $e) {
            $this->appLogger?->warning('Scheduling suggestion rejected for non-member assignee', [
                'service' => 'SmartSchedulerService',
                'houseId' => $houseId,
                'taskId' => $taskId,
                'assignedTo' => $assignedTo,
            ]);
            throw new \InvalidArgumentException('Assignee must be a house member', 0, $e);
        }
        $dayOfWeek = strtolower($scheduledDate->format('l'));
        if (!(bool)($preferences['weekdayAvailability'][$dayOfWeek] ?? true)) {
            $this->appLogger?->warning('Scheduling suggestion rejected due to member availability', [
                'service' => 'SmartSchedulerService',
                'houseId' => $houseId,
                'taskId' => $taskId,
                'assignedTo' => $assignedTo,
                'day' => $dayOfWeek,
            ]);
            throw new \InvalidArgumentException('Member is not available on ' . $dayOfWeek);
        }

        $this->roomTaskService->validateRoomTaskOwnership($taskId, $houseId);
        $this->roomTaskService->assignTask($taskId, $assignedTo);
        $task = $this->roomTaskService->rescheduleTask($taskId, $scheduledDate);

        $this->appLogger?->info('Compatibility suggestion applied', [
            'service' => 'SmartSchedulerService',
            'houseId' => $houseId,
            'taskId' => $taskId,
            'assignedTo' => $assignedTo,
            'scheduledFor' => $scheduledFor,
        ]);

        return $task;
    }

    /**
     * Build task assignments for a date range by aggregating weekly schedules.
     */
    private function buildAssignmentsForRange(int $houseId, \DateTime $startDate, \DateTime $endDate): array
    {
        $assignments = [];
        $seen = [];
        $currentWeekStart = $this->toWeekStart($startDate);

        while ($currentWeekStart <= $endDate) {
            $weeklySchedule = $this->taskSchedulerService->generateWeeklySchedule($houseId, clone $currentWeekStart);
            foreach (($weeklySchedule['tasks'] ?? []) as $assignment) {
                $scheduledDateRaw = $assignment['scheduled_date'] ?? null;
                if (!is_string($scheduledDateRaw)) {
                    continue;
                }

                $scheduledDate = \DateTime::createFromFormat('Y-m-d', $scheduledDateRaw);
                if ($scheduledDate === false || $scheduledDate < $startDate || $scheduledDate > $endDate) {
                    continue;
                }

                $assignmentKey = ($assignment['room_task_id'] ?? 'unknown') . '-' . $scheduledDateRaw;
                if (isset($seen[$assignmentKey])) {
                    continue;
                }

                $seen[$assignmentKey] = true;
                $assignments[] = [
                    'taskId' => (int)($assignment['room_task_id'] ?? 0),
                    'taskTitle' => (string)($assignment['task_name'] ?? 'Task'),
                    'assignedTo' => (string)($assignment['assigned_to'] ?? ''),
                    'date' => $scheduledDateRaw,
                    'energyCost' => (int)($assignment['energy_cost'] ?? 0),
                    'priorityScore' => (int)($assignment['priority_score'] ?? 0),
                ];
            }

            $currentWeekStart->modify('+7 days');
        }

        usort($assignments, static function (array $a, array $b): int {
            $dateComparison = strcmp($a['date'], $b['date']);
            if ($dateComparison !== 0) {
                return $dateComparison;
            }

            return $a['taskId'] <=> $b['taskId'];
        });

        return $assignments;
    }

    /**
     * Group assignments by date.
     */
    private function groupAssignmentsByDate(array $assignments): array
    {
        $grouped = [];
        foreach ($assignments as $assignment) {
            $date = $assignment['date'];
            $grouped[$date] ??= [];
            $grouped[$date][] = $assignment;
        }

        return $grouped;
    }

    /**
     * Calculate daily member capacity based on availability and energy budgets.
     */
    private function calculateDailyCapacity(array $members, \DateTime $startDate, \DateTime $endDate): array
    {
        $capacity = [];
        $current = clone $startDate;

        while ($current <= $endDate) {
            $date = $current->format('Y-m-d');
            $dayName = strtolower($current->format('l'));
            $dailyCapacity = 0;

            foreach ($members as $member) {
                if ((bool)($member['weekdayAvailability'][$dayName] ?? true)) {
                    $dailyCapacity += (int)($member['energyBudget'][$dayName] ?? 10);
                }
            }

            $capacity[$date] = $dailyCapacity;
            $current->modify('+1 day');
        }

        return $capacity;
    }

    /**
     * Detect potential scheduling conflicts by comparing demand and capacity per day.
     */
    private function detectPotentialConflicts(array $assignments, array $dailyCapacity): array
    {
        $demandByDay = [];
        foreach ($assignments as $assignment) {
            $date = $assignment['date'];
            $demandByDay[$date]['energy'] = ($demandByDay[$date]['energy'] ?? 0) + (int)$assignment['energyCost'];
            $demandByDay[$date]['tasks'] = ($demandByDay[$date]['tasks'] ?? 0) + 1;
        }

        $conflicts = [];
        foreach ($demandByDay as $date => $demand) {
            $capacity = (int)($dailyCapacity[$date] ?? 0);

            if ($capacity === 0 && $demand['tasks'] > 0) {
                $conflicts[] = [
                    'type' => 'insufficient_capacity',
                    'severity' => 'high',
                    'message' => sprintf('No member capacity available on %s for %d scheduled tasks.', $date, $demand['tasks']),
                ];
                continue;
            }

            if ($demand['energy'] > $capacity) {
                $conflicts[] = [
                    'type' => 'insufficient_capacity',
                    'severity' => 'medium',
                    'message' => sprintf('Scheduled energy demand (%d) exceeds available capacity (%d) on %s.', $demand['energy'], $capacity, $date),
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Normalize a date to the Monday of its week.
     */
    private function toWeekStart(\DateTime $date): \DateTime
    {
        $weekStart = clone $date;
        $weekStart->setTime(0, 0, 0);
        $weekStart->modify('monday this week');

        return $weekStart;
    }
}
