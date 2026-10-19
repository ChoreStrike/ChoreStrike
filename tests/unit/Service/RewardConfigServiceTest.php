<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RewardConfigService (WP4-reshaped registry).
 *
 * CS-PLAN-070 WP3: every earning multiplier, Coin-base, freeze and combo
 * key is retired — money is wallet minor units at the frozen rate, and
 * recognition is valid-history rhythm. CS-PLAN-070 WP4: the weekly-goal
 * target (fixed 80%) and the per-tier title thresholds (fixed 3/6/12)
 * are fixed policy — no override keys exist. This suite pins the
 * REMAINING registry (energyMaxPerTask, titlesEnabled, the CS-PLAN-086
 * arcadeGateEnabled) and the absence of the retired override surfaces.
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

	/** CS-PLAN-086 D-086-02: the arcade unlock gate is on by default. */
	public function testGetArcadeGateEnabled_ReturnsDefaultTrue(): void {
		$this->config->method('getValueString')->willReturnCallback(static fn ($app, $key, $default) => $default);
		$this->assertTrue($this->service->getArcadeGateEnabled());
	}

	public function testGetArcadeGateEnabled_StoredOff_ReturnsFalse(): void {
		$this->config->method('getValueString')
			->with('chorestrike', 'arcade_gate_enabled', '1')
			->willReturn('0');
		$this->assertFalse($this->service->getArcadeGateEnabled());
	}

	public function testSetArcadeGateEnabled_StoresValue(): void {
		$this->config->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'arcade_gate_enabled', '0');
		$this->service->setArcadeGateEnabled(false);
	}

	/** R086H-10: the test-mode reset drops the stored gate through the registry. */
	public function testResetArcadeGateEnabled_DeletesTheStoredKey(): void {
		$this->config->expects($this->once())
			->method('deleteKey')
			->with('chorestrike', 'arcade_gate_enabled');
		$this->service->resetArcadeGateEnabled();
	}

	/** WP4: the weekly-goal target override is retired with its key. */
	public function testWeeklyGoalTargetPercent_OverrideIsRetired(): void {
		$reflection = new \ReflectionClass(RewardConfigService::class);
		$this->assertFalse($reflection->hasConstant('KEY_WEEKLY_GOAL_TARGET_PERCENT'));
		$this->assertFalse($reflection->hasConstant('KEY_TITLE_THRESHOLD_PREFIX'));
		$this->assertFalse($reflection->hasMethod('getWeeklyGoalTargetPercent'));
		$this->assertFalse($reflection->hasMethod('setWeeklyGoalTargetPercent'));
		$this->assertFalse($reflection->hasMethod('getTitleThreshold'));
		$this->assertFalse($reflection->hasMethod('setTitleThreshold'));
	}

	public function testGetAllConfig_ContainsOnlyRetainedFields(): void {
		$this->config->method('getValueString')->willReturnCallback(static fn ($app, $key, $default) => $default);
		$config = $this->service->getAllConfig();

		// WP3/WP4: multiplier/freeze/combo/teamwork/goal/threshold fields
		// are retired — the config surface carries exactly the retained keys.
		$this->assertSame(['energyMaxPerTask', 'titlesEnabled', 'arcadeGateEnabled'], array_keys($config));
	}

	public function testUpdateConfig_IgnoresRetiredFields(): void {
		// A client payload of retired fields must be ignored, not written.
		$this->config->expects($this->never())->method('setValueString');
		$this->service->updateConfig([
			'tierBronzeMultiplier' => 5.0,
			'comboMultiplier' => 9.0,
			'streakFreezeCap' => 99,
			'coinBasePerEnergy' => 42,
			'weeklyGoalTargetPercent' => 90,
			'titleThresholds' => ['family_rhythm' => [0 => 1]],
		]);
	}

	public function testUpdateConfig_AppliesTitles(): void {
		$this->config->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'titles_enabled', '0');
		$this->service->updateConfig([
			'titlesEnabled' => false,
		]);
	}
}
