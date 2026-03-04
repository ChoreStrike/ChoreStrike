<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\AvailabilitySettings;
use OCA\ChoreStrike\Db\AvailabilitySettingsMapper;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\UserStatsMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Service\Scheduling\AvailabilityCalculator;
use OCA\ChoreStrike\Service\Scheduling\ScheduleOptimizer;
use OCA\ChoreStrike\Service\Scheduling\TaskAssignmentAlgorithm;
use OCA\ChoreStrike\Service\Scheduling\TaskPriorityScorer;
use OCA\ChoreStrike\Service\Scheduling\TaskReassignmentSuggester;
use OCA\ChoreStrike\Service\TaskSchedulerService;
use PHPUnit\Framework\TestCase;

class TaskSchedulerServiceTest extends TestCase
{
    private TaskSchedulerService $service;
    private AvailabilitySettingsMapper $availabilityMapper;
    private FloorMapper $floorMapper;
    private RoomTaskMapper $roomTaskMapper;
    private UserStatsMapper $userStatsMapper;
    private TaskMapper $taskMapper;
    private RoomMapper $roomMapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->availabilityMapper = $this->createMock(AvailabilitySettingsMapper::class);
        $this->floorMapper = $this->createMock(FloorMapper::class);
        $this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
        $this->userStatsMapper = $this->createMock(UserStatsMapper::class);
        $this->taskMapper = $this->createMock(TaskMapper::class);
        $this->roomMapper = $this->createMock(RoomMapper::class);
        $priorityScorer = new TaskPriorityScorer($this->taskMapper);
        $availabilityCalculator = new AvailabilityCalculator();
        $assignmentAlgorithm = new TaskAssignmentAlgorithm();
        $scheduleOptimizer = new ScheduleOptimizer();
        $reassignmentSuggester = new TaskReassignmentSuggester(
            $this->taskMapper,
            $this->roomTaskMapper,
            $this->availabilityMapper,
            $this->roomMapper,
            $this->floorMapper
        );

        $this->service = new TaskSchedulerService(
            $this->availabilityMapper,
            $this->floorMapper,
            $this->roomTaskMapper,
            $this->userStatsMapper,
            $this->taskMapper,
            $this->roomMapper,
            $priorityScorer,
            $availabilityCalculator,
            $assignmentAlgorithm,
            $scheduleOptimizer,
            $reassignmentSuggester
        );
    }

    public function testGenerateWeeklyScheduleReturnsSchedule(): void
    {
        $houseId = 1;
        $weekStart = new \DateTime('2026-03-02');

        $floor = new Floor();
        $floor->setId(1);

        $this->floorMapper->expects($this->once())
            ->method('findByHouseId')
            ->with($houseId)
            ->willReturn([$floor]);

        $room = new Room();
        $room->setId(1);

        $this->roomMapper->expects($this->once())
            ->method('findByFloorId')
            ->with(1)
            ->willReturn([$room]);

        // Mock room tasks
        $roomTask = new RoomTask();
        $roomTask->setId(1);
        $roomTask->setTaskId(1);
        $roomTask->setNextDue(new \DateTime('2026-03-05'));
        $roomTask->setIsActive(true);

        $this->roomTaskMapper->expects($this->once())
            ->method('findByRoomId')
            ->with(1, true)
            ->willReturn([$roomTask]);

        // Mock task
        $task = new Task();
        $task->setId(1);
        $task->setName('Test Task');
        $task->setPriority(2);
        $task->setEnergyCost(3);

        $this->taskMapper->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($task);

        // Mock availability settings
        $settings = new AvailabilitySettings();
        $settings->setHouseId($houseId);
        $settings->setUserId('user1');
        $settings->setWeekdayAvailability([
            'monday' => true,
            'tuesday' => true,
            'wednesday' => true,
            'thursday' => true,
            'friday' => true,
            'saturday' => false,
            'sunday' => false,
        ]);
        $settings->setEnergyBudget([
            'monday' => 10, 'tuesday' => 10, 'wednesday' => 10,
            'thursday' => 10, 'friday' => 10, 'saturday' => 0, 'sunday' => 0,
        ]);
        $settings->setMaxTasksPerDay(5);

        $this->availabilityMapper->expects($this->once())
            ->method('findByHouse')
            ->with($houseId)
            ->willReturn([$settings]);

        $result = $this->service->generateWeeklySchedule($houseId, $weekStart);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('week_start', $result);
        $this->assertArrayHasKey('tasks', $result);
        $this->assertArrayHasKey('total_tasks', $result);
        $this->assertEquals('2026-03-02', $result['week_start']);
    }

    public function testGenerateWeeklyScheduleDistributesByPriority(): void
    {
        $houseId = 1;
        $weekStart = new \DateTime('2026-03-02');

        // High priority overdue task should be scheduled first
        $overdueTask = new RoomTask();
        $overdueTask->setId(1);
        $overdueTask->setTaskId(1);
        $overdueTask->setNextDue(new \DateTime('-2 days'));
        $overdueTask->setIsActive(true);

        // Normal task
        $normalTask = new RoomTask();
        $normalTask->setId(2);
        $normalTask->setTaskId(2);
        $normalTask->setNextDue(new \DateTime('+5 days'));
        $normalTask->setIsActive(true);

        $floor = new Floor();
        $floor->setId(1);

        $this->floorMapper->expects($this->once())
            ->method('findByHouseId')
            ->willReturn([$floor]);

        $room = new Room();
        $room->setId(1);

        $this->roomMapper->expects($this->once())
            ->method('findByFloorId')
            ->with(1)
            ->willReturn([$room]);

        $this->roomTaskMapper->expects($this->once())
            ->method('findByRoomId')
            ->willReturn([$overdueTask, $normalTask]);

        $highPriorityTask = new Task();
        $highPriorityTask->setId(1);
        $highPriorityTask->setPriority(3);
        $highPriorityTask->setEnergyCost(2);

        $lowPriorityTask = new Task();
        $lowPriorityTask->setId(2);
        $lowPriorityTask->setPriority(1);
        $lowPriorityTask->setEnergyCost(2);

        $this->taskMapper->expects($this->exactly(2))
            ->method('find')
            ->willReturnOnConsecutiveCalls($highPriorityTask, $lowPriorityTask);

        $settings = new AvailabilitySettings();
        $settings->setHouseId($houseId);
        $settings->setUserId('user1');
        $settings->setMaxTasksPerDay(5);
        $settings->setWeekdayAvailability([
            'monday' => true, 'tuesday' => true, 'wednesday' => true,
            'thursday' => true, 'friday' => true, 'saturday' => false, 'sunday' => false,
        ]);
        $settings->setEnergyBudget([
            'monday' => 10, 'tuesday' => 10, 'wednesday' => 10,
            'thursday' => 10, 'friday' => 10, 'saturday' => 0, 'sunday' => 0,
        ]);

        $this->availabilityMapper->expects($this->once())
            ->method('findByHouse')
            ->willReturn([$settings]);

        $result = $this->service->generateWeeklySchedule($houseId, $weekStart);

        // Overdue high priority task should be in schedule
        $this->assertGreaterThan(0, count($result['tasks']));
    }

    public function testGetScheduleStatsReturnsCorrectData(): void
    {
        $houseId = 1;
        $weekStart = new \DateTime('2026-03-02');

        // Minimal mock to test stats calculation
        $floor = new Floor();
        $floor->setId(1);

        $this->floorMapper->expects($this->once())
            ->method('findByHouseId')
            ->willReturn([$floor]);

        $room = new Room();
        $room->setId(1);

        $this->roomMapper->expects($this->once())
            ->method('findByFloorId')
            ->with(1)
            ->willReturn([$room]);

        $this->roomTaskMapper->expects($this->once())
            ->method('findByRoomId')
            ->willReturn([]);

        $this->availabilityMapper->expects($this->once())
            ->method('findByHouse')
            ->willReturn([]);

        $stats = $this->service->getScheduleStats($houseId, $weekStart);

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_tasks', $stats);
        $this->assertArrayHasKey('by_day', $stats);
        $this->assertArrayHasKey('by_user', $stats);
        $this->assertArrayHasKey('total_energy', $stats);
    }

    public function testScheduleRespectsMaxTasksPerDay(): void
    {
        // Create availability with low max tasks
        $settings = new AvailabilitySettings();
        $settings->setHouseId(1);
        $settings->setUserId('user1');
        $settings->setMaxTasksPerDay(2); // Only 2 tasks per day
        $settings->setWeekdayAvailability([
            'monday' => true, 'tuesday' => false, 'wednesday' => false,
            'thursday' => false, 'friday' => false, 'saturday' => false, 'sunday' => false,
        ]);
        $settings->setEnergyBudget([
            'monday' => 20, 'tuesday' => 0, 'wednesday' => 0,
            'thursday' => 0, 'friday' => 0, 'saturday' => 0, 'sunday' => 0,
        ]);

        $availableDays = $settings->getAvailableDays();
        $this->assertEquals(['monday'], $availableDays);

        $distribution = $settings->getDefaultDistribution();
        $this->assertArrayHasKey('monday', $distribution);
        $this->assertEquals(1.0, $distribution['monday']); // 100% on Monday
    }

    public function testScheduleRespectsEnergyBudget(): void
    {
        $settings = new AvailabilitySettings();
        $settings->setHouseId(1);
        $settings->setUserId('user1');
        $settings->setMaxTasksPerDay(5);
        $settings->setEnergyBudget([
            'monday' => 5,    // Low energy Monday
            'tuesday' => 15,   // High energy Tuesday
            'wednesday' => 10,
            'thursday' => 10,
            'friday' => 10,
            'saturday' => 0,
            'sunday' => 0,
        ]);
        $settings->setWeekdayAvailability([
            'monday' => true, 'tuesday' => true, 'wednesday' => true,
            'thursday' => true, 'friday' => true, 'saturday' => false, 'sunday' => false,
        ]);

        $totalEnergy = $settings->getTotalWeeklyEnergy();
        $this->assertEquals(50, $totalEnergy);

        $distribution = $settings->getDefaultDistribution();

        // Tuesday should have higher percentage (15/50 = 30%)
        $this->assertGreaterThan($distribution['monday'], $distribution['tuesday']);
    }

    public function testWeekendWarriorDistribution(): void
    {
        $settings = new AvailabilitySettings();
        $settings->setHouseId(1);
        $settings->setUserId('user1');
        $settings->setPlanningMode('weekend_warrior');
        $settings->setWeekdayAvailability([
            'monday' => true,
            'tuesday' => true,
            'wednesday' => false,
            'thursday' => false,
            'friday' => false,
            'saturday' => true,
            'sunday' => true,
        ]);

        $distribution = $settings->getDefaultDistribution();

        // Weekend days should have 40% each
        $this->assertEquals(0.4, $distribution['saturday']);
        $this->assertEquals(0.4, $distribution['sunday']);
        // Weekdays should have only 5%
        $this->assertEquals(0.05, $distribution['monday']);
        $this->assertEquals(0.05, $distribution['tuesday']);
    }

    public function testDailyCleanerDistribution(): void
    {
        $settings = new AvailabilitySettings();
        $settings->setHouseId(1);
        $settings->setUserId('user1');
        $settings->setPlanningMode('daily_cleaner');
        $settings->setWeekdayAvailability([
            'monday' => true,
            'tuesday' => true,
            'wednesday' => true,
            'thursday' => false,
            'friday' => false,
            'saturday' => false,
            'sunday' => false,
        ]);

        $distribution = $settings->getDefaultDistribution();

        // Each available day should have equal distribution (33.33%)
        $expected = 1.0 / 3.0;
        $this->assertEqualsWithDelta($expected, $distribution['monday'], 0.01);
        $this->assertEqualsWithDelta($expected, $distribution['tuesday'], 0.01);
        $this->assertEqualsWithDelta($expected, $distribution['wednesday'], 0.01);
    }

    public function testAutoBalanceDistribution(): void
    {
        $settings = new AvailabilitySettings();
        $settings->setHouseId(1);
        $settings->setUserId('user1');
        $settings->setPlanningMode('auto_balance');
        $settings->setEnergyBudget([
            'monday' => 20,
            'tuesday' => 10,
            'wednesday' => 10,
            'thursday' => 10,
            'friday' => 10,
            'saturday' => 20,
            'sunday' => 20,
        ]);
        $settings->setWeekdayAvailability([
            'monday' => true, 'tuesday' => true, 'wednesday' => true,
            'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true,
        ]);

        $distribution = $settings->getDefaultDistribution();

        // Days with 20 energy should have 2x the percentage of days with 10 energy
        $this->assertEqualsWithDelta($distribution['monday'], 2 * $distribution['tuesday'], 0.01);
        $this->assertEqualsWithDelta($distribution['saturday'], 2 * $distribution['wednesday'], 0.01);
    }

    public function testIsAvailableOnReturnsCorrectValue(): void
    {
        $settings = new AvailabilitySettings();
        $settings->setWeekdayAvailability([
            'monday' => true,
            'tuesday' => false,
            'wednesday' => true,
        ]);

        $this->assertTrue($settings->isAvailableOn('monday'));
        $this->assertFalse($settings->isAvailableOn('tuesday'));
        $this->assertTrue($settings->isAvailableOn('wednesday'));
        $this->assertFalse($settings->isAvailableOn('invalid_day'));
    }

    public function testGetEnergyForDayReturnsCorrectValue(): void
    {
        $settings = new AvailabilitySettings();
        $settings->setEnergyBudget([
            'monday' => 15,
            'tuesday' => 8,
        ]);

        $this->assertEquals(15, $settings->getEnergyForDay('monday'));
        $this->assertEquals(8, $settings->getEnergyForDay('tuesday'));
        $this->assertEquals(0, $settings->getEnergyForDay('invalid_day'));
    }

    public function testValidPlanningModes(): void
    {
        $this->assertTrue(AvailabilitySettings::isValidPlanningMode('weekend_warrior'));
        $this->assertTrue(AvailabilitySettings::isValidPlanningMode('daily_cleaner'));
        $this->assertTrue(AvailabilitySettings::isValidPlanningMode('custom'));
        $this->assertTrue(AvailabilitySettings::isValidPlanningMode('auto_balance'));
        $this->assertFalse(AvailabilitySettings::isValidPlanningMode('invalid_mode'));
    }
}
