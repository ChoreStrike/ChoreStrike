<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service\Scheduling;

use OCA\ChoreStrike\Service\Scheduling\TaskAssignmentAlgorithm;
use OCA\ChoreStrike\Db\AvailabilitySettings;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class TaskAssignmentAlgorithmTest extends TestCase {

	private TaskAssignmentAlgorithm $algorithm;

	protected function setUp(): void {
		$this->algorithm = new TaskAssignmentAlgorithm();
	}

	public function testFindBestAssignment(): void {
		$scoredTask = $this->createScoredTask(50, 5, false);
		$userSettings = $this->createUserSettings();
		$userDistributions = ['user1' => ['monday' => 0.5, 'tuesday' => 0.3]];
		$dailyWorkload = [
			'user1' => [
				'monday' => ['task_count' => 1, 'energy_total' => 5],
				'tuesday' => ['task_count' => 0, 'energy_total' => 0],
			],
			'user2' => [
				'monday' => ['task_count' => 0, 'energy_total' => 0],
				'tuesday' => ['task_count' => 0, 'energy_total' => 0],
			],
		];

		$result = $this->algorithm->findBestAssignment($scoredTask, $userSettings, $userDistributions, $dailyWorkload);

		$this->assertNotNull($result);
		$this->assertArrayHasKey('day', $result);
		$this->assertArrayHasKey('user_id', $result);
		$this->assertArrayHasKey('score', $result);
		$this->assertGreaterThan(0, $result['score']);
	}

	public function testFindBestAssignmentReturnsNullWhenNoValidAssignment(): void {
		$scoredTask = $this->createScoredTask(50, 5, false);
		
		// Create users with no availability
		$userSettings = $this->createUserSettings(false); // All unavailable
		$userDistributions = [];
		$dailyWorkload = [];

		$result = $this->algorithm->findBestAssignment($scoredTask, $userSettings, $userDistributions, $dailyWorkload);

		$this->assertNull($result);
	}

	public function testDistributeTasks(): void {
		$scoredTasks = [
			$this->createScoredTask(80, 5, true), // High priority, overdue
			$this->createScoredTask(40, 3, false), // Lower priority
		];
		$userSettings = $this->createUserSettings();
		$weekStart = new \DateTime('2026-03-01');

		$result = $this->algorithm->distributeTasks($scoredTasks, $userSettings, $weekStart);

		$this->assertIsArray($result);
		$this->assertLessThanOrEqual(count($scoredTasks), count($result));

		// Check structure of assigned tasks
		foreach ($result as $assignment) {
			$this->assertArrayHasKey('room_task_id', $assignment);
			$this->assertArrayHasKey('task_name', $assignment);
			$this->assertArrayHasKey('scheduled_date', $assignment);
			$this->assertArrayHasKey('scheduled_day', $assignment);
			$this->assertArrayHasKey('assigned_to', $assignment);
			$this->assertArrayHasKey('priority_score', $assignment);
			$this->assertArrayHasKey('energy_cost', $assignment);
			$this->assertArrayHasKey('is_overdue', $assignment);
		}
	}

	public function testGetAssignmentStats(): void {
		$schedule = [
			[
				'scheduled_day' => 'monday',
				'assigned_to' => 'user1',
				'priority_score' => 80,
				'energy_cost' => 5,
				'is_overdue' => true,
			],
			[
				'scheduled_day' => 'tuesday',
				'assigned_to' => 'user2',
				'priority_score' => 40,
				'energy_cost' => 3,
				'is_overdue' => false,
			],
			[
				'scheduled_day' => 'monday',
				'assigned_to' => 'user1',
				'priority_score' => 60,
				'energy_cost' => 4,
				'is_overdue' => false,
			],
		];

		$result = $this->algorithm->getAssignmentStats($schedule);

		$this->assertEquals(3, $result['total_assignments']);
		$this->assertEquals(['monday' => 2, 'tuesday' => 1], $result['by_day']);
		$this->assertEquals(['user1' => 2, 'user2' => 1], $result['by_user']);
		$this->assertEquals(1, $result['overdue_assigned']);
		$this->assertEquals(2, $result['high_priority_assigned']); // 80 and 60 >= 50
		$this->assertEquals(12, $result['total_energy']);
	}

	public function testAssignmentValidation(): void {
		$scoredTask = $this->createScoredTask(50, 5, false);
		$userSettings = $this->createUserSettings();
		
		// Test with user at task limit
		$dailyWorkload = [
			'user1' => [
				'monday' => ['task_count' => 3, 'energy_total' => 5], // At limit
				'tuesday' => ['task_count' => 0, 'energy_total' => 0],
			],
		];

		$user1Settings = $userSettings[0]; // user1
		$user1Settings->method('getMaxTasksPerDay')->willReturn(3);

		$result = $this->algorithm->findBestAssignment($scoredTask, [$user1Settings], [], $dailyWorkload);

		// Should not assign to user1 on monday (at limit), but might assign to user1 on tuesday
		if ($result) {
			$this->assertNotEquals('monday', $result['day']);
		}
	}

	public function testEnergyBudgetValidation(): void {
		$scoredTask = $this->createScoredTask(50, 8, false); // High energy cost
		$userSettings = $this->createUserSettings();
		
		// Test with user at energy limit
		$dailyWorkload = [
			'user1' => [
				'monday' => ['task_count' => 1, 'energy_total' => 7], // Near limit
				'tuesday' => ['task_count' => 0, 'energy_total' => 0],
			],
		];

		$user1Settings = $userSettings[0]; // user1
		$user1Settings->method('getEnergyForDay')->willReturn(10);

		$result = $this->algorithm->findBestAssignment($scoredTask, [$user1Settings], [], $dailyWorkload);

		// Should not assign to user1 on monday (7 + 8 > 10), but might assign to user1 on tuesday
		if ($result) {
			$this->assertNotEquals('monday', $result['day']);
		}
	}

	public function testOverdueTaskPrioritization(): void {
		$overdueTask = $this->createScoredTask(50, 5, true);
		$normalTask = $this->createScoredTask(50, 5, false);
		$userSettings = $this->createUserSettings();
		$userDistributions = ['user1' => ['monday' => 0.5, 'tuesday' => 0.5]];
		$dailyWorkload = [];

		$overdueResult = $this->algorithm->findBestAssignment($overdueTask, $userSettings, $userDistributions, $dailyWorkload);
		$normalResult = $this->algorithm->findBestAssignment($normalTask, $userSettings, $userDistributions, $dailyWorkload);

		// Overdue task should prefer earlier in the week
		if ($overdueResult && $normalResult) {
			$overdueDayIndex = array_search($overdueResult['day'], ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']);
			$normalDayIndex = array_search($normalResult['day'], ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']);
			
			$this->assertLessThanOrEqual($overdueDayIndex, $normalDayIndex);
		}
	}

	private function createScoredTask(int $priorityScore, int $energyCost, bool $isOverdue): array {
		$roomTask = $this->createMock(RoomTask::class);
		$roomTask->method('getId')->willReturn(1);
		
		$task = $this->createMock(Task::class);
		$task->method('getName')->willReturn('Test Task');

		return [
			'room_task' => $roomTask,
			'task' => $task,
			'priority_score' => $priorityScore,
			'energy_cost' => $energyCost,
			'is_overdue' => $isOverdue,
			'days_until_due' => $isOverdue ? -2 : 5,
		];
	}

	private function createUserSettings(bool $available = true): array {
		$user1 = $this->createMock(AvailabilitySettings::class);
		$user1->method('getUserId')->willReturn('user1');
		$user1->method('isAvailableOn')->willReturn($available);
		$user1->method('getMaxTasksPerDay')->willReturn(3);
		$user1->method('getEnergyForDay')->willReturn(10);
		$user1->method('getDefaultDistribution')->willReturn(['monday' => 0.5, 'tuesday' => 0.3, 'wednesday' => 0.2]);

		$user2 = $this->createMock(AvailabilitySettings::class);
		$user2->method('getUserId')->willReturn('user2');
		$user2->method('isAvailableOn')->willReturn($available);
		$user2->method('getMaxTasksPerDay')->willReturn(2);
		$user2->method('getEnergyForDay')->willReturn(8);
		$user2->method('getDefaultDistribution')->willReturn(['monday' => 0.3, 'tuesday' => 0.4, 'wednesday' => 0.3]);

		return [$user1, $user2];
	}
}
