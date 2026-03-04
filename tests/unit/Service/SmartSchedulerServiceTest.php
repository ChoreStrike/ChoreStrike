<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\SchedulingService;
use OCA\ChoreStrike\Service\SmartSchedulerService;
use OCA\ChoreStrike\Service\TaskSchedulerService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\ILogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SmartSchedulerServiceTest extends TestCase
{
    private SmartSchedulerService $service;
    private TaskSchedulerService|MockObject $taskSchedulerService;
    private SchedulingService|MockObject $schedulingService;
    private RoomTaskService|MockObject $roomTaskService;
    private ILogger|MockObject $logger;

    protected function setUp(): void
    {
        $this->taskSchedulerService = $this->createMock(TaskSchedulerService::class);
        $this->schedulingService = $this->createMock(SchedulingService::class);
        $this->roomTaskService = $this->createMock(RoomTaskService::class);
        $this->logger = $this->createMock(ILogger::class);

        $this->service = new SmartSchedulerService(
            $this->taskSchedulerService,
            $this->schedulingService,
            $this->roomTaskService,
            $this->logger
        );
    }

    /**
     * GenerateSchedule_MapsWeeklyAssignmentsToCompatibilityPayload
     */
    public function testGenerateSchedule_MapsWeeklyAssignmentsToCompatibilityPayload(): void
    {
        $this->taskSchedulerService
            ->expects($this->exactly(3))
            ->method('generateWeeklySchedule')
            ->willReturnOnConsecutiveCalls(
                [
                    'tasks' => [
                        [
                            'room_task_id' => 11,
                            'task_name' => 'Clean Kitchen',
                            'scheduled_date' => '2026-03-03',
                            'assigned_to' => 'alice',
                            'energy_cost' => 4,
                            'priority_score' => 80,
                        ],
                        [
                            'room_task_id' => 12,
                            'task_name' => 'Out of Range',
                            'scheduled_date' => '2026-03-20',
                            'assigned_to' => 'bob',
                            'energy_cost' => 2,
                            'priority_score' => 30,
                        ],
                    ],
                ],
                [
                    'tasks' => [
                        [
                            'room_task_id' => 13,
                            'task_name' => 'Bathroom',
                            'scheduled_date' => '2026-03-09',
                            'assigned_to' => 'carol',
                            'energy_cost' => 3,
                            'priority_score' => 55,
                        ],
                    ],
                ],
                [
                    'tasks' => [],
                ]
            );

        $result = $this->service->generateSchedule(1, new \DateTime('2026-03-01'), new \DateTime('2026-03-10'));

        $this->assertCount(2, $result['assignments']);
        $this->assertSame(2, $result['totalTasks']);
        $this->assertSame('Clean Kitchen', $result['assignments'][0]['taskTitle']);
        $this->assertArrayHasKey('2026-03-03', $result['schedule']);
    }

    /**
     * GetSchedulePreview_DetectsInsufficientCapacityConflicts
     */
    public function testGetSchedulePreview_DetectsInsufficientCapacityConflicts(): void
    {
        $today = new \DateTime('today');
        $todayDate = $today->format('Y-m-d');
        $todayDay = strtolower($today->format('l'));

        $this->taskSchedulerService
            ->expects($this->atLeastOnce())
            ->method('generateWeeklySchedule')
            ->willReturn([
                'tasks' => [
                    [
                        'room_task_id' => 21,
                        'task_name' => 'Heavy Task',
                        'scheduled_date' => $todayDate,
                        'assigned_to' => 'member-a',
                        'energy_cost' => 10,
                        'priority_score' => 70,
                    ],
                ],
            ]);

        $weekdayAvailability = [
            'monday' => true,
            'tuesday' => true,
            'wednesday' => true,
            'thursday' => true,
            'friday' => true,
            'saturday' => true,
            'sunday' => true,
            $todayDay => false,
        ];

        $this->schedulingService
            ->expects($this->once())
            ->method('getMemberAvailability')
            ->willReturn([
                [
                    'userId' => 'member-a',
                    'userName' => 'Member A',
                    'energyBudget' => [
                        'monday' => 10,
                        'tuesday' => 10,
                        'wednesday' => 10,
                        'thursday' => 10,
                        'friday' => 10,
                        'saturday' => 10,
                        'sunday' => 10,
                    ],
                    'weekdayAvailability' => $weekdayAvailability,
                ],
            ]);

        $preview = $this->service->getSchedulePreview(1, 1);

        $this->assertSame(1, $preview['taskCount']);
        $this->assertNotEmpty($preview['potentialConflicts']);
        $this->assertSame('insufficient_capacity', $preview['potentialConflicts'][0]['type']);
    }

    /**
     * ApplySuggestion_AssignsAndReschedulesTaskWhenInputIsValid
     */
    public function testApplySuggestion_AssignsAndReschedulesTaskWhenInputIsValid(): void
    {
        $this->schedulingService
            ->expects($this->once())
            ->method('getUserPreferencesByNextcloudUserId')
            ->with(42, 'user1')
            ->willReturn([
                'weekdayAvailability' => ['monday' => true],
            ]);

        $this->roomTaskService
            ->expects($this->once())
            ->method('validateRoomTaskOwnership')
            ->with(7, 42);

        $this->roomTaskService
            ->expects($this->once())
            ->method('assignTask')
            ->with(7, 'user1');

        $updatedTask = new RoomTask();
        $updatedTask->setId(7);
        $updatedTask->setAssignedToUserId('user1');
        $updatedTask->setNextDue(new \DateTime('2026-03-16'));

        $this->roomTaskService
            ->expects($this->once())
            ->method('rescheduleTask')
            ->with(7, $this->isInstanceOf(\DateTime::class))
            ->willReturn($updatedTask);

        $result = $this->service->applySuggestion(42, 7, 'user1', '2026-03-16');

        $this->assertSame(7, $result->getId());
        $this->assertSame('user1', $result->getAssignedToUserId());
    }

    /**
     * ApplySuggestion_InvalidDate_ThrowsInvalidArgumentException
     */
    public function testApplySuggestion_InvalidDate_ThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date format. Use Y-m-d');

        $this->service->applySuggestion(42, 7, 'user1', '16-03-2026');
    }

    /**
     * ApplySuggestion_UnavailableMember_ThrowsInvalidArgumentException
     */
    public function testApplySuggestion_UnavailableMember_ThrowsInvalidArgumentException(): void
    {
        $this->schedulingService
            ->expects($this->once())
            ->method('getUserPreferencesByNextcloudUserId')
            ->with(42, 'user1')
            ->willReturn([
                'weekdayAvailability' => ['monday' => false],
            ]);

        $this->roomTaskService->expects($this->never())->method('assignTask');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Member is not available on monday');

        $this->service->applySuggestion(42, 7, 'user1', '2026-03-16');
    }

    /**
     * ApplySuggestion_NonHouseMember_ThrowsInvalidArgumentException
     */
    public function testApplySuggestion_NonHouseMember_ThrowsInvalidArgumentException(): void
    {
        $this->schedulingService
            ->expects($this->once())
            ->method('getUserPreferencesByNextcloudUserId')
            ->with(42, 'outsider')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->roomTaskService->expects($this->never())->method('assignTask');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Assignee must be a house member');

        $this->service->applySuggestion(42, 7, 'outsider', '2026-03-16');
    }
}
