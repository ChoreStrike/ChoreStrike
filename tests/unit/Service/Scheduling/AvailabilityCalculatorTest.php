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

class AvailabilityCalculatorTest extends TestCase
{
    private AvailabilityCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new AvailabilityCalculator();
    }

    public function testIsUserAvailable(): void
    {
        $settings = $this->createAvailabilitySettings();
        $settings->setWeekdayAvailability([
            'monday' => true,
            'saturday' => false,
        ] + $this->getDefaultAvailability());

        $this->assertTrue($this->calculator->isUserAvailable($settings, 'monday'));
        $this->assertFalse($this->calculator->isUserAvailable($settings, 'saturday'));
    }

    public function testGetAvailableDays(): void
    {
        $settings = $this->createAvailabilitySettings();
        $settings->setWeekdayAvailability([
            'monday' => true,
            'tuesday' => true,
            'wednesday' => true,
            'thursday' => true,
            'friday' => true,
            'saturday' => false,
            'sunday' => false,
        ]);

        $result = $this->calculator->getAvailableDays($settings);
        $this->assertEquals(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], $result);
    }

    public function testGetEnergyBudget(): void
    {
        $settings = $this->createAvailabilitySettings();
        $settings->setEnergyBudget([
            'monday' => 10,
        ] + $this->getDefaultEnergy());

        $result = $this->calculator->getEnergyBudget($settings, 'monday');
        $this->assertEquals(10, $result);
    }

    public function testHasEnergyBudget(): void
    {
        $settings = $this->createAvailabilitySettings();
        $settings->setEnergyBudget([
            'monday' => 15,
        ] + $this->getDefaultEnergy());

        // Should have enough budget
        $this->assertTrue($this->calculator->hasEnergyBudget($settings, 'monday', 5, 8));

        // Should not have enough budget
        $this->assertFalse($this->calculator->hasEnergyBudget($settings, 'monday', 8, 10));
    }

    public function testHasReachedTaskLimit(): void
    {
        $settings = $this->createAvailabilitySettings();
        $settings->setMaxTasksPerDay(3);

        // Should not have reached limit
        $this->assertFalse($this->calculator->hasReachedTaskLimit($settings, 'monday', 2));

        // Should have reached limit
        $this->assertTrue($this->calculator->hasReachedTaskLimit($settings, 'monday', 3));
    }

    public function testGetTotalWeeklyEnergy(): void
    {
        $settings = $this->createAvailabilitySettings();
        $settings->setEnergyBudget([
            'monday' => 10,
            'tuesday' => 10,
            'wednesday' => 10,
            'thursday' => 10,
            'friday' => 10,
            'saturday' => 10,
            'sunday' => 10,
        ]);

        $result = $this->calculator->getTotalWeeklyEnergy($settings);
        $this->assertEquals(70, $result);
    }

    public function testGetAvailableHours(): void
    {
        $settings = $this->createAvailabilitySettings();
        $settings->setTimeRanges([
            'start' => '09:00',
            'end' => '17:30',
        ]);

        $result = $this->calculator->getAvailableHours($settings);
        $this->assertEquals(8.5, $result);
    }

    public function testCalculateDailyCapacity(): void
    {
        $settings = $this->createAvailabilitySettings();
        $settings->setMaxTasksPerDay(3);
        $settings->setEnergyBudget(['monday' => 10] + $this->getDefaultEnergy());
        $settings->setWeekdayAvailability(['monday' => true] + $this->getDefaultAvailability());

        $result = $this->calculator->calculateDailyCapacity($settings);

        // Returns per-day structure with 'max_tasks', 'max_energy', 'available'
        $this->assertArrayHasKey('monday', $result);
        $this->assertEquals(3, $result['monday']['max_tasks']);
        $this->assertEquals(10, $result['monday']['max_energy']);
        $this->assertTrue($result['monday']['available']);
    }

    public function testCalculateAvailabilityScore(): void
    {
        $settings = $this->createAvailabilitySettings();
        $settings->setWeekdayAvailability(['monday' => true] + $this->getDefaultAvailability());
        $settings->setEnergyBudget(['monday' => 10] + $this->getDefaultEnergy());

        // Available with energy
        $score = $this->calculator->calculateAvailabilityScore($settings, 'monday', 2, 1);
        $this->assertGreaterThan(10, $score);

        // Not available
        $settings->setWeekdayAvailability(['monday' => false] + $this->getDefaultAvailability());
        $score = $this->calculator->calculateAvailabilityScore($settings, 'monday', 2, 1);
        $this->assertEquals(0.0, $score);
    }

    public function testFilterAvailableUsers(): void
    {
        $user1 = $this->createAvailabilitySettings('user1');
        $user2 = $this->createAvailabilitySettings('user2');
        $user3 = $this->createAvailabilitySettings('user3');

        $user1->setWeekdayAvailability(['monday' => true] + $this->getDefaultAvailability());
        $user2->setWeekdayAvailability(['monday' => false] + $this->getDefaultAvailability());
        $user3->setWeekdayAvailability(['monday' => true] + $this->getDefaultAvailability());

        $result = $this->calculator->filterAvailableUsers([$user1, $user2, $user3], 'monday');

        // array_filter preserves keys, so reindex with array_values
        $result = array_values($result);
        $this->assertCount(2, $result);
        $this->assertEquals('user1', $result[0]->getUserId());
        $this->assertEquals('user3', $result[1]->getUserId());
    }

    public function testFilterUsersByEnergy(): void
    {
        $user1 = $this->createAvailabilitySettings('user1');
        $user2 = $this->createAvailabilitySettings('user2');

        $user1->setEnergyBudget(['monday' => 10] + $this->getDefaultEnergy());
        $user2->setEnergyBudget(['monday' => 5] + $this->getDefaultEnergy());

        $currentEnergyUsage = [
            'user1' => ['monday' => 3],
            'user2' => ['monday' => 4],
        ];

        $result = $this->calculator->filterUsersByEnergy([$user1, $user2], 'monday', 5, $currentEnergyUsage);

        // User1: 3 + 5 = 8 <= 10 (available)
        // User2: 4 + 5 = 9 > 5 (not available)
        $this->assertCount(1, $result);
        $this->assertEquals('user1', $result[0]->getUserId());
    }

    public function testFilterUsersByTaskLimit(): void
    {
        $user1 = $this->createAvailabilitySettings('user1');
        $user2 = $this->createAvailabilitySettings('user2');

        $user1->setMaxTasksPerDay(3);
        $user2->setMaxTasksPerDay(2);

        $currentTaskCounts = [
            'user1' => ['monday' => 2],
            'user2' => ['monday' => 2],
        ];

        $result = $this->calculator->filterUsersByTaskLimit([$user1, $user2], 'monday', $currentTaskCounts);

        // User1: 2 < 3 (available)
        // User2: 2 >= 2 (not available)
        $this->assertCount(1, $result);
        $this->assertEquals('user1', $result[0]->getUserId());
    }

    private function createAvailabilitySettings(string $userId = 'test_user'): AvailabilitySettings
    {
        $settings = new AvailabilitySettings();
        $settings->setUserId($userId);
        $settings->setWeekdayAvailability($this->getDefaultAvailability());
        $settings->setEnergyBudget($this->getDefaultEnergy());
        return $settings;
    }

    private function getDefaultAvailability(): array
    {
        return [
            'monday' => true,
            'tuesday' => true,
            'wednesday' => true,
            'thursday' => true,
            'friday' => true,
            'saturday' => true,
            'sunday' => true,
        ];
    }

    private function getDefaultEnergy(): array
    {
        return [
            'monday' => 10,
            'tuesday' => 10,
            'wednesday' => 10,
            'thursday' => 10,
            'friday' => 10,
            'saturday' => 10,
            'sunday' => 10,
        ];
    }
}
