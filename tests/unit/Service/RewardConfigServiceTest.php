<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RewardConfigService (WP3-reshaped registry).
 *
 * CS-PLAN-070 WP3: every earning multiplier, Coin-base, freeze and combo
 * key is retired — money is wallet minor units at the frozen rate, and
 * recognition is valid-history rhythm. This suite pins the REMAINING
 * registry (energyMaxPerTask, weeklyGoalTargetPercent, titlesEnabled)
 * plus the title-threshold read/clamp/write path.
 */
class RewardConfigServiceTest extends TestCase {
	private RewardConfigService $service;
	private IAppConfig $config;

	protected function setUp(): void {
		$this->config = $this->createMock(IAppConfig::class);
		$this->service = new RewardConfigService(
			$this->config,
			$this->createMock(AppLogger::class)
		);
	}

	public function testGetEnergyMaxPerTask_ReturnsDefault(): void {
		$this->config->method('getValueString')->willReturnCallback(static fn ($app, $key, $default) => $default);
		$this->assertSame(9, $this->service->getEnergyMaxPerTask());
	}

	public function testSetEnergyMaxPerTask_NegativeValue_ClampsToZero(): void {
		$this->config->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'energy_max_per_task', '0');
		$this->service->setEnergyMaxPerTask(-10);
	}

	public function testGetWeeklyGoalTargetPercent_ReturnsDefault(): void {
		$this->config->method('getValueString')->willReturnCallback(static fn ($app, $key, $default) => $default);
		$this->assertSame(80, $this->service->getWeeklyGoalTargetPercent());
	}

	public function testGetWeeklyGoalTargetPercent_ClampsOutOfRange(): void {
		$this->config->method('getValueString')->willReturn('0');
		$this->assertSame(1, $this->service->getWeeklyGoalTargetPercent());
	}

	public function testGetWeeklyGoalTargetPercent_ClampsAboveHundred(): void {
		$this->config->method('getValueString')->willReturn('140');
		$this->assertSame(100, $this->service->getWeeklyGoalTargetPercent());
	}

	public function testSetWeeklyGoalTargetPercent_StoresClampedValue(): void {
		$this->config->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'weekly_goal_target_percent', '100');
		$this->service->setWeeklyGoalTargetPercent(120);
	}

	public function testGetTitlesEnabled_ReturnsDefaultTrue(): void {
		$this->config->method('getValueString')->willReturnCallback(static fn ($app, $key, $default) => $default);
		$this->assertTrue($this->service->getTitlesEnabled());
	}

	public function testSetTitlesEnabled_StoresValue(): void {
		$this->config->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'titles_enabled', '0');
		$this->service->setTitlesEnabled(false);
	}

	public function testGetTitleThreshold_ReturnsCatalogDefaultWhenUnset(): void {
		$this->config->method('getValueString')->willReturnCallback(static fn ($app, $key, $default) => $default);
		// home_helpers tier 0 catalog default = 5000 energy minor.
		$this->assertSame(5000, $this->service->getTitleThreshold('home_helpers', 0));
	}

	public function testGetTitleThreshold_ClampsBelowOneToOne(): void {
		$this->config->method('getValueString')->willReturn('0');
		$this->assertSame(1, $this->service->getTitleThreshold('home_helpers', 0));
	}

	public function testSetTitleThreshold_StoresClampedValue(): void {
		$this->config->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'title_threshold_home_helpers_0', '1');
		$this->service->setTitleThreshold('home_helpers', 0, -5);
	}

	public function testGetAllConfig_ContainsOnlyRetainedFields(): void {
		$this->config->method('getValueString')->willReturnCallback(static fn ($app, $key, $default) => $default);
		$config = $this->service->getAllConfig();

		// WP3: multiplier/freeze/combo/teamwork fields are retired — the
		// config surface carries exactly the retained keys + thresholds.
		$this->assertSame(['energyMaxPerTask', 'weeklyGoalTargetPercent', 'titlesEnabled', 'titleThresholds'], array_keys($config));
	}

	public function testUpdateConfig_IgnoresRetiredFields(): void {
		// A client payload of retired fields must be ignored, not written.
		$this->config->expects($this->never())->method('setValueString');
		$this->service->updateConfig([
			'tierBronzeMultiplier' => 5.0,
			'comboMultiplier' => 9.0,
			'streakFreezeCap' => 99,
			'coinBasePerEnergy' => 42,
		]);
	}

	public function testUpdateConfig_AppliesWeeklyGoalAndTitles(): void {
		$this->config->expects($this->exactly(2))
			->method('setValueString')
			->withConsecutive(
				['chorestrike', 'weekly_goal_target_percent', '90'],
				['chorestrike', 'titles_enabled', '0'],
			);
		$this->service->updateConfig([
			'weeklyGoalTargetPercent' => 90,
			'titlesEnabled' => false,
		]);
	}
}
