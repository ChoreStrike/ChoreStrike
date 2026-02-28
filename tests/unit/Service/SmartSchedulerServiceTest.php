<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Service\SmartSchedulerService;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Service\SchedulingService;
use OCP\ILogger;
use PHPUnit\Framework\TestCase;

class SmartSchedulerServiceTest extends TestCase {

	private SmartSchedulerService $service;
	private HouseUserMapper $houseUserMapper;
	private RoomTaskMapper $roomTaskMapper;
	private SchedulingService $schedulingService;
	private ILogger $logger;

	protected function setUp(): void {
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->schedulingService = $this->createMock(SchedulingService::class);
		$this->logger = $this->createMock(ILogger::class);

		$this->service = new SmartSchedulerService(
			$this->houseUserMapper,
			$this->roomTaskMapper,
			$this->schedulingService,
			$this->logger
		);
	}

	public function testGenerateScheduleCreatesOptimalAssignments(): void {
		$houseId = 1;
		$startDate = new \DateTime('2026-03-01');
		$endDate = new \DateTime('2026-03-07');

		// Mock tasks with different priorities and deadlines
		$tasks = [
			$this->createMockTask(1, 'High Priority Task', 1, '2026-03-02', 8),
			$this->createMockTask(2, 'Medium Priority Task', 3, '2026-03-05', 5),
			$this->createMockTask(3, 'Low Priority Task', 5, null, 3),
		];

		// Mock member availability
		$members = [
			[
				'userId' => 1,
				'userName' => 'Alice',
				'energyBudget' => [
					'monday' => 10, 'tuesday' => 10, 'wednesday' => 10,
					'thursday' => 10, 'friday' => 10, 'saturday' => 15, 'sunday' => 15,
				],
				'weekdayAvailability' => [
					'monday' => true, 'tuesday' => true, 'wednesday' => true,
					'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true,
				],
			],
			[
				'userId' => 2,
				'userName' => 'Bob',
				'energyBudget' => [
					'monday' => 8, 'tuesday' => 8, 'wednesday' => 8,
					'thursday' => 8, 'friday' => 8, 'saturday' => 12, 'sunday' => 12,
				],
				'weekdayAvailability' => [
					'monday' => true, 'tuesday' => false, 'wednesday' => true,
					'thursday' => true, 'friday' => true, 'saturday' => false, 'sunday' => true,
				],
			],
		];

		// Set up mocks
		$this->roomTaskMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn($tasks);

		$this->schedulingService
			->expects($this->once())
			->method('getMemberAvailability')
			->with($houseId)
			->willReturn($members);

		// Generate schedule
		$result = $this->service->generateSchedule($houseId, $startDate, $endDate);

		// Assertions
		$this->assertIsArray($result);
		$this->assertArrayHasKey('schedule', $result);
		$this->assertArrayHasKey('assignments', $result);
		$this->assertArrayHasKey('unassignedTasks', $result);
		$this->assertArrayHasKey('totalTasks', $result);
		$this->assertArrayHasKey('assignedTasks', $result);
		$this->assertArrayHasKey('efficiency', $result);

		$this->assertEquals(3, $result['totalTasks']);
		$this->assertGreaterThan(0, $result['assignedTasks']);
		$this->assertGreaterThanOrEqual(0, $result['efficiency']);
		$this->assertLessThanOrEqual(1, $result['efficiency']);

		// Check that high priority tasks are assigned first
		$assignments = $result['assignments'];
		$highPriorityTask = array_filter($assignments, fn($a) => $a['taskId'] === 1);
		$this->assertNotEmpty($highPriorityTask, 'High priority task should be assigned');
	}

	public function testGetSchedulePreviewReturnsCapacityAnalysis(): void {
		$houseId = 1;
		$days = 7;

		// Mock tasks
		$tasks = [
			$this->createMockTask(1, 'Task 1', 3, '2026-03-05', 5),
			$this->createMockTask(2, 'Task 2', 2, '2026-03-08', 8),
		];

		// Mock members
		$members = [
			[
				'userId' => 1,
				'userName' => 'Alice',
				'energyBudget' => [
					'monday' => 10, 'tuesday' => 10, 'wednesday' => 10,
					'thursday' => 10, 'friday' => 10, 'saturday' => 15, 'sunday' => 15,
				],
				'weekdayAvailability' => [
					'monday' => true, 'tuesday' => true, 'wednesday' => true,
					'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true,
				],
			],
		];

		// Set up mocks
		$this->roomTaskMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn($tasks);

		$this->schedulingService
			->expects($this->once())
			->method('getMemberAvailability')
			->with($houseId)
			->willReturn($members);

		// Get preview
		$preview = $this->service->getSchedulePreview($houseId, $days);

		// Assertions
		$this->assertIsArray($preview);
		$this->assertArrayHasKey('dateRange', $preview);
		$this->assertArrayHasKey('taskCount', $preview);
		$this->assertArrayHasKey('memberCount', $preview);
		$this->assertArrayHasKey('dailyCapacity', $preview);
		$this->assertArrayHasKey('potentialConflicts', $preview);

		$this->assertEquals(2, $preview['taskCount']);
		$this->assertEquals(1, $preview['memberCount']);
		$this->assertCount(7, $preview['dailyCapacity']);

		// Check daily capacity calculation
		$dailyCapacity = $preview['dailyCapacity'];
		foreach ($dailyCapacity as $date => $capacity) {
			$this->assertIsInt($capacity);
			$this->assertGreaterThan(0, $capacity);
		}
	}

	public function testAssignmentScoreCalculation(): void {
		$houseId = 1;
		$startDate = new \DateTime('2026-03-01');
		$endDate = new \DateTime('2026-03-07');

		// Create a task with deadline
		$task = $this->createMockTask(1, 'Urgent Task', 1, '2026-03-02', 8);

		// Create member with availability
		$members = [
			[
				'userId' => 1,
				'userName' => 'Alice',
				'energyBudget' => [
					'monday' => 10, 'tuesday' => 10, 'wednesday' => 10,
					'thursday' => 10, 'friday' => 10, 'saturday' => 15, 'sunday' => 15,
				],
				'weekdayAvailability' => [
					'monday' => true, 'tuesday' => true, 'wednesday' => true,
					'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true,
				],
			],
		];

		// Set up mocks
		$this->roomTaskMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$task]);

		$this->schedulingService
			->expects($this->once())
			->method('getMemberAvailability')
			->with($houseId)
			->willReturn($members);

		// Generate schedule
		$result = $this->service->generateSchedule($houseId, $startDate, $endDate);

		// Check that urgent task gets high score and is assigned
		$assignments = $result['assignments'];
		$this->assertNotEmpty($assignments);

		$urgentTaskAssignment = array_filter($assignments, fn($a) => $a['taskId'] === 1);
		$this->assertNotEmpty($urgentTaskAssignment);

		$assignment = array_values($urgentTaskAssignment)[0];
		$this->assertArrayHasKey('score', $assignment);
		$this->assertGreaterThan(0, $assignment['score']);
	}

	public function testEfficiencyCalculation(): void {
		$houseId = 1;
		$startDate = new \DateTime('2026-03-01');
		$endDate = new \DateTime('2026-03-07');

		// Create multiple tasks
		$tasks = [
			$this->createMockTask(1, 'Task 1', 3, '2026-03-05', 5),
			$this->createMockTask(2, 'Task 2', 3, '2026-03-06', 5),
			$this->createMockTask(3, 'Task 3', 3, '2026-03-07', 5),
		];

		// Create multiple members for better distribution
		$members = [
			[
				'userId' => 1,
				'userName' => 'Alice',
				'energyBudget' => [
					'monday' => 10, 'tuesday' => 10, 'wednesday' => 10,
					'thursday' => 10, 'friday' => 10, 'saturday' => 15, 'sunday' => 15,
				],
				'weekdayAvailability' => [
					'monday' => true, 'tuesday' => true, 'wednesday' => true,
					'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true,
				],
			],
			[
				'userId' => 2,
				'userName' => 'Bob',
				'energyBudget' => [
					'monday' => 10, 'tuesday' => 10, 'wednesday' => 10,
					'thursday' => 10, 'friday' => 10, 'saturday' => 15, 'sunday' => 15,
				],
				'weekdayAvailability' => [
					'monday' => true, 'tuesday' => true, 'wednesday' => true,
					'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true,
				],
			],
		];

		// Set up mocks
		$this->roomTaskMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn($tasks);

		$this->schedulingService
			->expects($this->once())
			->method('getMemberAvailability')
			->with($houseId)
			->willReturn($members);

		// Generate schedule
		$result = $this->service->generateSchedule($houseId, $startDate, $endDate);

		// Check efficiency calculation
		$this->assertArrayHasKey('efficiency', $result);
		$efficiency = $result['efficiency'];
		$this->assertGreaterThanOrEqual(0, $efficiency);
		$this->assertLessThanOrEqual(1, $efficiency);

		// With multiple members and tasks, efficiency should be reasonable
		$this->assertGreaterThan(0.5, $efficiency, 'Efficiency should be reasonable with multiple members');
	}

	public function testConflictDetection(): void {
		$houseId = 1;
		$days = 3;

		// Create tasks with high energy requirements
		$tasks = [
			$this->createMockTask(1, 'High Energy Task 1', 1, '2026-03-02', 50),
			$this->createMockTask(2, 'High Energy Task 2', 1, '2026-03-03', 50),
		];

		// Create member with limited energy budget
		$members = [
			[
				'userId' => 1,
				'userName' => 'Alice',
				'energyBudget' => [
					'monday' => 5, 'tuesday' => 5, 'wednesday' => 5,
					'thursday' => 5, 'friday' => 5, 'saturday' => 8, 'sunday' => 8,
				],
				'weekdayAvailability' => [
					'monday' => true, 'tuesday' => true, 'wednesday' => true,
					'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true,
				],
			],
		];

		// Set up mocks
		$this->roomTaskMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn($tasks);

		$this->schedulingService
			->expects($this->once())
			->method('getMemberAvailability')
			->with($houseId)
			->willReturn($members);

		// Get preview
		$preview = $this->service->getSchedulePreview($houseId, $days);

		// Check for conflicts
		$this->assertArrayHasKey('potentialConflicts', $preview);
		$conflicts = $preview['potentialConflicts'];
		$this->assertIsArray($conflicts);

		// Should detect insufficient capacity conflict
		$capacityConflicts = array_filter($conflicts, fn($c) => $c['type'] === 'insufficient_capacity');
		$this->assertNotEmpty($capacityConflicts, 'Should detect insufficient capacity conflict');
	}

	private function createMockTask(int $id, string $title, int $priority, ?string $deadline, int $energyCost): RoomTask {
		$task = $this->createMock(RoomTask::class);
		
		$task->method('getId')->willReturn($id);
		$task->method('getTitle')->willReturn($title);
		$task->method('getPriority')->willReturn($priority);
		$task->method('getDeadline')->willReturn($deadline);
		$task->method('getEnergyCost')->willReturn($energyCost);
		$task->method('getAssignedTo')->willReturn(null);
		$task->method('getCompletedAt')->willReturn(null);

		return $task;
	}
}
