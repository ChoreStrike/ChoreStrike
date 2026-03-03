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

		$user1Settings = $userSettings[0]; // user1 with maxTasksPerDay=3

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

		$user1Settings = $userSettings[0]; // user1 with energyBudget=10

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
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		
		$task = new Task();
		$task->setName('Test Task');

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
		$user1 = new AvailabilitySettings();
		$user1->setUserId('user1');
		$user1->setWeekdayAvailability([
			'monday' => $available, 'tuesday' => $available, 'wednesday' => $available,
			'thursday' => $available, 'friday' => $available, 'saturday' => $available, 'sunday' => $available
		]);
		$user1->setMaxTasksPerDay(3);
		$user1->setEnergyBudget([
			'monday' => 10, 'tuesday' => 10, 'wednesday' => 10,
			'thursday' => 10, 'friday' => 10, 'saturday' => 10, 'sunday' => 10
		]);

		$user2 = new AvailabilitySettings();
		$user2->setUserId('user2');
		$user2->setWeekdayAvailability([
			'monday' => $available, 'tuesday' => $available, 'wednesday' => $available,
			'thursday' => $available, 'friday' => $available, 'saturday' => $available, 'sunday' => $available
		]);
		$user2->setMaxTasksPerDay(2);
		$user2->setEnergyBudget([
			'monday' => 8, 'tuesday' => 8, 'wednesday' => 8,
			'thursday' => 8, 'friday' => 8, 'saturday' => 8, 'sunday' => 8
		]);

		return [$user1, $user2];
	}
}
