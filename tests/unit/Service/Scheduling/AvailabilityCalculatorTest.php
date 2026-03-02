<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service\Scheduling;

use OCA\ChoreStrike\Service\Scheduling\AvailabilityCalculator;
use OCA\ChoreStrike\Db\AvailabilitySettings;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class AvailabilityCalculatorTest extends TestCase {

	private AvailabilityCalculator $calculator;
	private AvailabilitySettings|MockObject $settings;

	protected function setUp(): void {
		$this->calculator = new AvailabilityCalculator();
		$this->settings = $this->createMockAvailabilitySettings();
	}

	public function testIsUserAvailable(): void {
		$this->settings->method('isAvailableOn')
			->with('monday')
			->willReturn(true);

		$result = $this->calculator->isUserAvailable($this->settings, 'monday');
		$this->assertTrue($result);

		$this->settings->method('isAvailableOn')
			->with('saturday')
			->willReturn(false);

		$result = $this->calculator->isUserAvailable($this->settings, 'saturday');
		$this->assertFalse($result);
	}

	public function testGetAvailableDays(): void {
		$availableDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
		$this->settings->method('getAvailableDays')->willReturn($availableDays);

		$result = $this->calculator->getAvailableDays($this->settings);
		$this->assertEquals($availableDays, $result);
	}

	public function testGetEnergyBudget(): void {
		$this->settings->method('getEnergyForDay')
			->with('monday')
			->willReturn(10);

		$result = $this->calculator->getEnergyBudget($this->settings, 'monday');
		$this->assertEquals(10, $result);
	}

	public function testHasEnergyBudget(): void {
		$this->settings->method('getEnergyForDay')
			->willReturn(15);

		// Should have enough budget
		$result = $this->calculator->hasEnergyBudget($this->settings, 'monday', 5, 8);
		$this->assertTrue($result);

		// Should not have enough budget
		$result = $this->calculator->hasEnergyBudget($this->settings, 'monday', 8, 10);
		$this->assertFalse($result);
	}

	public function testHasReachedTaskLimit(): void {
		$this->settings->method('getMaxTasksPerDay')->willReturn(3);

		// Should not have reached limit
		$result = $this->calculator->hasReachedTaskLimit($this->settings, 'monday', 2);
		$this->assertFalse($result);

		// Should have reached limit
		$result = $this->calculator->hasReachedTaskLimit($this->settings, 'monday', 3);
		$this->assertTrue($result);
	}

	public function testGetTotalWeeklyEnergy(): void {
		$this->settings->method('getTotalWeeklyEnergy')->willReturn(70);

		$result = $this->calculator->getTotalWeeklyEnergy($this->settings);
		$this->assertEquals(70, $result);
	}

	public function testGetAvailableHours(): void {
		$this->settings->method('getAvailableHours')->willReturn(8.5);

		$result = $this->calculator->getAvailableHours($this->settings);
		$this->assertEquals(8.5, $result);
	}

	public function testCalculateDailyCapacity(): void {
		$this->settings->method('getMaxTasksPerDay')->willReturn(3);
		$this->settings->method('getEnergyForDay')->willReturn(10);
		$this->settings->method('isAvailableOn')->willReturn(true);

		$result = $this->calculator->calculateDailyCapacity($this->settings);

		$weekDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
		$this->assertCount(7, $result);

		foreach ($weekDays as $day) {
			$this->assertArrayHasKey($day, $result);
			$this->assertEquals(3, $result[$day]['max_tasks']);
			$this->assertEquals(10, $result[$day]['max_energy']);
			$this->assertTrue($result[$day]['available']);
		}
	}

	public function testFilterAvailableUsers(): void {
		$user1 = $this->createMockAvailabilitySettings('user1');
		$user2 = $this->createMockAvailabilitySettings('user2');
		$user3 = $this->createMockAvailabilitySettings('user3');

		$user1->method('isAvailableOn')->with('monday')->willReturn(true);
		$user2->method('isAvailableOn')->with('monday')->willReturn(false);
		$user3->method('isAvailableOn')->with('monday')->willReturn(true);

		$users = [$user1, $user2, $user3];
		$result = $this->calculator->filterAvailableUsers($users, 'monday');

		$this->assertCount(2, $result);
		$this->assertEquals('user1', $result[0]->getUserId());
		$this->assertEquals('user3', $result[1]->getUserId());
	}

	public function testFilterUsersByEnergy(): void {
		$user1 = $this->createMockAvailabilitySettings('user1');
		$user2 = $this->createMockAvailabilitySettings('user2');

		$user1->method('getEnergyForDay')->willReturn(10);
		$user2->method('getEnergyForDay')->willReturn(5);

		$currentUsage = [
			'user1' => ['monday' => 3],
			'user2' => ['monday' => 4],
		];

		$users = [$user1, $user2];
		$result = $this->calculator->filterUsersByEnergy($users, 'monday', 5, $currentUsage);

		// User1: 3 + 5 = 8 <= 10 (available)
		// User2: 4 + 5 = 9 > 5 (not available)
		$this->assertCount(1, $result);
		$this->assertEquals('user1', $result[0]->getUserId());
	}

	public function testFilterUsersByTaskLimit(): void {
		$user1 = $this->createMockAvailabilitySettings('user1');
		$user2 = $this->createMockAvailabilitySettings('user2');

		$user1->method('getMaxTasksPerDay')->willReturn(3);
		$user2->method('getMaxTasksPerDay')->willReturn(2);

		$currentCounts = [
			'user1' => ['monday' => 2],
			'user2' => ['monday' => 2],
		];

		$users = [$user1, $user2];
		$result = $this->calculator->filterUsersByTaskLimit($users, 'monday', $currentCounts);

		// User1: 2 < 3 (available)
		// User2: 2 >= 2 (not available)
		$this->assertCount(1, $result);
		$this->assertEquals('user1', $result[0]->getUserId());
	}

	public function testCalculateAvailabilityScore(): void {
		$this->settings->method('isAvailableOn')->willReturn(true);
		$this->settings->method('getEnergyForDay')->willReturn(10);
		$this->settings->method('getMaxTasksPerDay')->willReturn(3);

		// High availability score
		$score = $this->calculator->calculateAvailabilityScore($this->settings, 'monday', 2, 1);
		$this->assertGreaterThan(10, $score);

		// Not available
		$this->settings->method('isAvailableOn')->willReturn(false);
		$score = $this->calculator->calculateAvailabilityScore($this->settings, 'monday', 2, 1);
		$this->assertEquals(0.0, $score);
	}

	private function createMockAvailabilitySettings(string $userId): AvailabilitySettings {
		$settings = $this->createMock(AvailabilitySettings::class);
		$settings->method('getUserId')->willReturn($userId);
		return $settings;
	}
}
