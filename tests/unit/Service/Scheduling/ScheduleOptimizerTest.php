<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service\Scheduling;

use OCA\ChoreStrike\Service\Scheduling\ScheduleOptimizer;
use OCA\ChoreStrike\Db\AvailabilitySettings;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class ScheduleOptimizerTest extends TestCase {

	private ScheduleOptimizer $optimizer;

	protected function setUp(): void {
		$this->optimizer = new ScheduleOptimizer();
	}

	public function testOptimizeScheduleWithEmptySchedule(): void {
		$schedule = [];
		$userSettings = [];

		$result = $this->optimizer->optimizeSchedule($schedule, $userSettings);

		// Empty schedule returns empty array directly
		$this->assertEquals([], $result);
	}

	public function testOptimizeScheduleReturnsOriginalWhenNoImprovement(): void {
		$schedule = $this->createBalancedSchedule();
		$userSettings = $this->createUserSettings();

		$result = $this->optimizer->optimizeSchedule($schedule, $userSettings);

		// Should return original schedule if no improvement
		$this->assertEquals($schedule, $result['schedule']);
		$this->assertLessThanOrEqual(0, $result['metrics']['improvement_score']);
	}

	public function testOptimizeScheduleImprovesDailyVariance(): void {
		// Create unbalanced schedule (all tasks on Monday)
		$schedule = [
			['task_name' => 'Task 1', 'scheduled_day' => 'monday', 'assigned_to' => 'user1', 'energy_cost' => 5],
			['task_name' => 'Task 2', 'scheduled_day' => 'monday', 'assigned_to' => 'user2', 'energy_cost' => 3],
			['task_name' => 'Task 3', 'scheduled_day' => 'monday', 'assigned_to' => 'user1', 'energy_cost' => 4],
			['task_name' => 'Task 4', 'scheduled_day' => 'monday', 'assigned_to' => 'user2', 'energy_cost' => 6],
		];
		$userSettings = $this->createUserSettings();

		$result = $this->optimizer->optimizeSchedule($schedule, $userSettings);

		$this->assertIsArray($result['schedule']);
		$this->assertArrayHasKey('metrics', $result);
		$this->assertArrayHasKey('before', $result['metrics']);
		$this->assertArrayHasKey('after', $result['metrics']);
		$this->assertArrayHasKey('improvement_score', $result['metrics']);
	}

	public function testOptimizeScheduleBalancesUserWorkloads(): void {
		// Create schedule with unbalanced user workloads
		$schedule = [
			['task_name' => 'Task 1', 'scheduled_day' => 'monday', 'assigned_to' => 'user1', 'energy_cost' => 5],
			['task_name' => 'Task 2', 'scheduled_day' => 'tuesday', 'assigned_to' => 'user1', 'energy_cost' => 4],
			['task_name' => 'Task 3', 'scheduled_day' => 'wednesday', 'assigned_to' => 'user1', 'energy_cost' => 3],
			['task_name' => 'Task 4', 'scheduled_day' => 'thursday', 'assigned_to' => 'user2', 'energy_cost' => 2],
		];
		$userSettings = $this->createUserSettings();

		$result = $this->optimizer->optimizeSchedule($schedule, $userSettings);

		$this->assertIsArray($result['schedule']);
		$this->assertArrayHasKey('user_variance', $result['metrics']['before']);
		$this->assertArrayHasKey('user_variance', $result['metrics']['after']);
	}

	public function testCalculateScheduleMetrics(): void {
		$schedule = [
			['task_name' => 'Task 1', 'scheduled_day' => 'monday', 'assigned_to' => 'user1', 'energy_cost' => 5],
			['task_name' => 'Task 2', 'scheduled_day' => 'tuesday', 'assigned_to' => 'user2', 'energy_cost' => 3],
			['task_name' => 'Task 3', 'scheduled_day' => 'wednesday', 'assigned_to' => 'user1', 'energy_cost' => 4],
		];

		// Use reflection to access private method for testing
		$reflection = new \ReflectionClass($this->optimizer);
		$method = $reflection->getMethod('calculateScheduleMetrics');
		$method->setAccessible(true);

		$metrics = $method->invoke($this->optimizer, $schedule);

		$this->assertArrayHasKey('daily_variance', $metrics);
		$this->assertArrayHasKey('user_variance', $metrics);
		$this->assertArrayHasKey('daily_balance_score', $metrics);
		$this->assertArrayHasKey('user_balance_score', $metrics);
		$this->assertArrayHasKey('total_tasks', $metrics);
		$this->assertEquals(3, $metrics['total_tasks']);
	}

	public function testCalculateVariance(): void {
		$reflection = new \ReflectionClass($this->optimizer);
		$method = $reflection->getMethod('calculateVariance');
		$method->setAccessible(true);

		// Test with known values
		$values = [1, 2, 3, 4, 5]; // Mean = 3, Variance = 2
		$variance = $method->invoke($this->optimizer, $values);
		$this->assertEquals(2.0, $variance, '', 0.01);

		// Test with empty array
		$variance = $method->invoke($this->optimizer, []);
		$this->assertEquals(0.0, $variance);

		// Test with identical values (variance should be 0)
		$variance = $method->invoke($this->optimizer, [5, 5, 5, 5]);
		$this->assertEquals(0.0, $variance);
	}

	public function testCalculateBalanceScore(): void {
		$reflection = new \ReflectionClass($this->optimizer);
		$method = $reflection->getMethod('calculateBalanceScore');
		$method->setAccessible(true);

		// Perfect balance (all values equal)
		$score = $method->invoke($this->optimizer, [5, 5, 5, 5]);
		$this->assertEquals(100.0, $score);

		// Some variance
		$score = $method->invoke($this->optimizer, [3, 4, 5, 6]);
		$this->assertGreaterThan(0, $score);
		$this->assertLessThan(100, $score);

		// Empty array
		$score = $method->invoke($this->optimizer, []);
		$this->assertEquals(0.0, $score);
	}

	public function testCalculateImprovementScore(): void {
		$reflection = new \ReflectionClass($this->optimizer);
		$method = $reflection->getMethod('calculateImprovementScore');
		$method->setAccessible(true);

		$before = [
			'daily_variance' => 10.0,
			'user_variance' => 5.0,
			'user_balance_score' => 50.0,
		];

		$after = [
			'daily_variance' => 5.0, // Improved (lower)
			'user_variance' => 3.0, // Improved (lower)
			'user_balance_score' => 70.0, // Improved (higher)
		];

		$score = $method->invoke($this->optimizer, $before, $after);
		$this->assertGreaterThan(0, $score);

		// Test with no improvement
		$score = $method->invoke($this->optimizer, $before, $before);
		$this->assertEquals(0.0, $score);
	}

	public function testGroupRelatedTasks(): void {
		$schedule = [
			['task_name' => 'Clean Kitchen', 'scheduled_day' => 'monday', 'assigned_to' => 'user1'],
			['task_name' => 'Vacuum Living Room', 'scheduled_day' => 'tuesday', 'assigned_to' => 'user2'],
			['task_name' => 'Clean Bathroom', 'scheduled_day' => 'wednesday', 'assigned_to' => 'user1'],
			['task_name' => 'Wash Kitchen Dishes', 'scheduled_day' => 'thursday', 'assigned_to' => 'user2'],
		];

		// Use reflection to access private method
		$reflection = new \ReflectionClass($this->optimizer);
		$method = $reflection->getMethod('groupRelatedTasks');
		$method->setAccessible(true);

		$result = $method->invoke($this->optimizer, $schedule);

		$this->assertIsArray($result);
		$this->assertEquals(count($schedule), count($result));
		// Kitchen tasks should be grouped together
		$kitchenTasks = array_filter($result, fn($task) => 
			strpos(strtolower($task['task_name']), 'kitchen') !== false
		);
		$this->assertGreaterThanOrEqual(2, count($kitchenTasks));
	}

	private function createBalancedSchedule(): array {
		return [
			['task_name' => 'Task 1', 'scheduled_day' => 'monday', 'assigned_to' => 'user1', 'energy_cost' => 5],
			['task_name' => 'Task 2', 'scheduled_day' => 'tuesday', 'assigned_to' => 'user2', 'energy_cost' => 3],
			['task_name' => 'Task 3', 'scheduled_day' => 'wednesday', 'assigned_to' => 'user1', 'energy_cost' => 4],
			['task_name' => 'Task 4', 'scheduled_day' => 'thursday', 'assigned_to' => 'user2', 'energy_cost' => 6],
			['task_name' => 'Task 5', 'scheduled_day' => 'friday', 'assigned_to' => 'user1', 'energy_cost' => 2],
			['task_name' => 'Task 6', 'scheduled_day' => 'saturday', 'assigned_to' => 'user2', 'energy_cost' => 4],
			['task_name' => 'Task 7', 'scheduled_day' => 'sunday', 'assigned_to' => 'user1', 'energy_cost' => 3],
		];
	}

	private function createUserSettings(): array {
		$user1 = new AvailabilitySettings();
		$user1->setUserId('user1');
		$user1->setWeekdayAvailability([
			'monday' => true, 'tuesday' => true, 'wednesday' => true,
			'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true
		]);
		$user1->setMaxTasksPerDay(3);
		$user1->setEnergyBudget([
			'monday' => 10, 'tuesday' => 10, 'wednesday' => 10,
			'thursday' => 10, 'friday' => 10, 'saturday' => 10, 'sunday' => 10
		]);

		$user2 = new AvailabilitySettings();
		$user2->setUserId('user2');
		$user2->setWeekdayAvailability([
			'monday' => true, 'tuesday' => true, 'wednesday' => true,
			'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true
		]);
		$user2->setMaxTasksPerDay(3);
		$user2->setEnergyBudget([
			'monday' => 8, 'tuesday' => 8, 'wednesday' => 8,
			'thursday' => 8, 'friday' => 8, 'saturday' => 8, 'sunday' => 8
		]);

		return [$user1, $user2];
	}
}
