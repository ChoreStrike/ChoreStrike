<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\AvailabilitySettings;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AvailabilitySettings entity logic
 */
class AvailabilitySettingsTest extends TestCase {
	private AvailabilitySettings $settings;

	protected function setUp(): void {
		$this->settings = new AvailabilitySettings();
	}

	public function testDefaults_AllDaysAvailable(): void {
		$days = $this->settings->getAvailableDays();

		$this->assertCount(7, $days);
		$this->assertContains('monday', $days);
		$this->assertContains('sunday', $days);
	}

	public function testIsAvailableOn_ChecksCaseInsensitive(): void {
		$this->assertTrue($this->settings->isAvailableOn('Monday'));
		$this->assertTrue($this->settings->isAvailableOn('MONDAY'));
	}

	public function testIsAvailableOn_UnknownDay_ReturnsFalse(): void {
		$this->assertFalse($this->settings->isAvailableOn('funday'));
	}

	public function testGetEnergyForDay_ReturnsBudgetValue(): void {
		$this->assertEquals(10, $this->settings->getEnergyForDay('monday'));
	}

	public function testGetEnergyForDay_UnknownDay_ReturnsZero(): void {
		$this->assertEquals(0, $this->settings->getEnergyForDay('funday'));
	}

	public function testGetTotalWeeklyEnergy_SumsAllDays(): void {
		$this->assertEquals(70, $this->settings->getTotalWeeklyEnergy());
	}

	public function testGetAvailableHours_DefaultRange(): void {
		$hours = $this->settings->getAvailableHours();

		$this->assertEquals(9.0, $hours);
	}

	public function testGetAvailableHours_InvalidRange_ReturnsDefault(): void {
		$this->settings->setTimeRanges(['start' => 'invalid', 'end' => 'also-invalid']);

		$this->assertEquals(8.0, $this->settings->getAvailableHours());
	}

	public function testIsValidPlanningMode_ValidModes(): void {
		$this->assertTrue(AvailabilitySettings::isValidPlanningMode('auto_balance'));
		$this->assertTrue(AvailabilitySettings::isValidPlanningMode('weekend_warrior'));
		$this->assertTrue(AvailabilitySettings::isValidPlanningMode('daily_cleaner'));
		$this->assertTrue(AvailabilitySettings::isValidPlanningMode('custom'));
	}

	public function testIsValidPlanningMode_InvalidMode(): void {
		$this->assertFalse(AvailabilitySettings::isValidPlanningMode('yolo'));
	}

	public function testGetAvailableDays_RespectsDisabledDays(): void {
		$this->settings->setWeekdayAvailability([
			'monday' => true,
			'tuesday' => false,
			'wednesday' => true,
			'thursday' => false,
			'friday' => true,
			'saturday' => false,
			'sunday' => false,
		]);

		$days = $this->settings->getAvailableDays();

		$this->assertCount(3, $days);
		$this->assertContains('monday', $days);
		$this->assertNotContains('tuesday', $days);
	}

	public function testGetDefaultDistribution_DailyCleaner_EvenSplit(): void {
		$this->settings->setPlanningMode('daily_cleaner');

		$dist = $this->settings->getDefaultDistribution();

		$this->assertCount(7, $dist);
		$this->assertEqualsWithDelta(1.0 / 7, $dist['monday'], 0.001);
	}

	public function testGetDefaultDistribution_NoAvailableDays_ReturnsEmpty(): void {
		$this->settings->setWeekdayAvailability([
			'monday' => false, 'tuesday' => false, 'wednesday' => false,
			'thursday' => false, 'friday' => false, 'saturday' => false, 'sunday' => false,
		]);
		$this->settings->setPlanningMode('daily_cleaner');

		$this->assertEmpty($this->settings->getDefaultDistribution());
	}

	public function testJsonSerialize_IncludesComputedFields(): void {
		$this->settings->setId(1);
		$this->settings->setHouseId(2);
		$this->settings->setUserId('alice');

		$json = $this->settings->jsonSerialize();

		$this->assertArrayHasKey('availableDays', $json);
		$this->assertArrayHasKey('totalWeeklyEnergy', $json);
		$this->assertArrayHasKey('availableHours', $json);
		$this->assertArrayHasKey('defaultDistribution', $json);
	}
}
