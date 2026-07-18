<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Service\RewardConfigService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RewardConfigService
 */
class RewardConfigServiceTest extends TestCase
{
    private RewardConfigService $service;
    private IAppConfig $config;

    protected function setUp(): void
    {
        $this->config = $this->createMock(IAppConfig::class);
        $this->service = new RewardConfigService($this->config);
    }

    /**
     * GetTierMultiplier_Bronze_ReturnsDefault
     */
    public function testGetTierMultiplier_Bronze_ReturnsDefault(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturn('1.0');

        $result = $this->service->getTierMultiplier('bronze');

        $this->assertEquals(1.0, $result);
    }

    /**
     * GetTierMultiplier_Silver_ReturnsDefault
     */
    public function testGetTierMultiplier_Silver_ReturnsDefault(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturn('1.5');

        $result = $this->service->getTierMultiplier('silver');

        $this->assertEquals(1.5, $result);
    }

    /**
     * GetTierMultiplier_Gold_ReturnsDefault
     */
    public function testGetTierMultiplier_Gold_ReturnsDefault(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturn('2.0');

        $result = $this->service->getTierMultiplier('gold');

        $this->assertEquals(2.0, $result);
    }

    /**
     * GetEarlyBonusThresholdDays_ReturnsDefault
     */
    public function testGetEarlyBonusThresholdDays_ReturnsDefault(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturn('21');

        $result = $this->service->getEarlyBonusThresholdDays();

        $this->assertEquals(21, $result);
    }

    /**
     * GetEarlyBonusMultiplier_ReturnsDefault
     */
    public function testGetEarlyBonusMultiplier_ReturnsDefault(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturn('1.5');

        $result = $this->service->getEarlyBonusMultiplier();

        $this->assertEquals(1.5, $result);
    }

    /**
     * GetEnergyMaxPerTask_ReturnsDefault
     */
    public function testGetEnergyMaxPerTask_ReturnsDefault(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturn('9');

        $result = $this->service->getEnergyMaxPerTask();

        $this->assertEquals(9, $result);
    }

    /**
     * GetCoinBasePerEnergy_ReturnsDefault
     */
    public function testGetCoinBasePerEnergy_ReturnsDefault(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturn('10');

        $result = $this->service->getCoinBasePerEnergy();

        $this->assertEquals(10, $result);
    }

    /**
     * GetAllConfig_ReturnsAllValues
     */
    public function testGetAllConfig_ReturnsAllValues(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) {
                return $default;
            });

        $result = $this->service->getAllConfig();

        $this->assertArrayHasKey('tierBronzeMultiplier', $result);
        $this->assertArrayHasKey('tierSilverMultiplier', $result);
        $this->assertArrayHasKey('tierGoldMultiplier', $result);
        $this->assertArrayHasKey('earlyBonusThresholdDays', $result);
        $this->assertArrayHasKey('earlyBonusMultiplier', $result);
        $this->assertArrayHasKey('energyMaxPerTask', $result);
        $this->assertArrayHasKey('coinBasePerEnergy', $result);
    }

    /**
     * SetTierMultiplier_StoresValue
     */
    public function testSetTierMultiplier_StoresValue(): void
    {
        $this->config
            ->expects($this->once())
            ->method('setValueString')
            ->with('chorestrike', 'tier_gold_multiplier', '2.5');

        $this->service->setTierMultiplier('gold', 2.5);
    }

    /**
     * UpdateConfig_UpdatesMultipleValues
     */
    public function testUpdateConfig_UpdatesMultipleValues(): void
    {
        $this->config
            ->expects($this->exactly(2))
            ->method('setValueString');

        $this->service->updateConfig([
            'tierGoldMultiplier' => 2.5,
            'energyMaxPerTask' => 12,
        ]);
    }

    /**
     * GetStreakFreezesPerWeek_ReturnsDefault
     */
    public function testGetStreakFreezesPerWeek_ReturnsDefault(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturn('1');

        $result = $this->service->getStreakFreezesPerWeek();

        $this->assertEquals(1, $result);
    }

    /**
     * GetStreakFreezeCap_ReturnsDefault
     */
    public function testGetStreakFreezeCap_ReturnsDefault(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturn('2');

        $result = $this->service->getStreakFreezeCap();

        $this->assertEquals(2, $result);
    }

    /**
     * GetComboBonusFreezeThreshold_ReturnsDefault
     */
    public function testGetComboBonusFreezeThreshold_ReturnsDefault(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturn('5');

        $result = $this->service->getComboBonusFreezeThreshold();

        $this->assertEquals(5, $result);
    }

    /**
     * SetStreakFreezesPerWeek_StoresValue
     */
    public function testSetStreakFreezesPerWeek_StoresValue(): void
    {
        $this->config
            ->expects($this->once())
            ->method('setValueString')
            ->with('chorestrike', 'streak_freezes_per_week', '3');

        $this->service->setStreakFreezesPerWeek(3);
    }

    /**
     * GetAllConfig_IncludesFreezeKeys
     */
    public function testGetAllConfig_IncludesFreezeKeys(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) {
                return $default;
            });

        $result = $this->service->getAllConfig();

        $this->assertArrayHasKey('streakFreezesPerWeek', $result);
        $this->assertArrayHasKey('streakFreezeCap', $result);
        $this->assertArrayHasKey('comboBonusFreezeThreshold', $result);
    }

    /**
     * UpdateConfig_HandlesFreezeKeys
     */
    public function testUpdateConfig_HandlesFreezeKeys(): void
    {
        $this->config
            ->expects($this->exactly(2))
            ->method('setValueString');

        $this->service->updateConfig([
            'streakFreezesPerWeek' => 2,
            'streakFreezeCap' => 3,
        ]);
    }

    /**
     * GetWeeklyGoalTargetPercent_ReturnsDefault
     */
    public function testGetWeeklyGoalTargetPercent_ReturnsDefault(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturn('80');

        $result = $this->service->getWeeklyGoalTargetPercent();

        $this->assertEquals(80, $result);
    }

    /**
     * GetWeeklyGoalTargetPercent_ClampsOutOfRange
     */
    public function testGetWeeklyGoalTargetPercent_ClampsOutOfRange(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) {
                // Stored value below the floor.
                return '0';
            });

        $result = $this->service->getWeeklyGoalTargetPercent();

        $this->assertEquals(1, $result);
    }

    /**
     * GetWeeklyGoalTargetPercent_ClampsAboveHundred
     */
    public function testGetWeeklyGoalTargetPercent_ClampsAboveHundred(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturn('150');

        $result = $this->service->getWeeklyGoalTargetPercent();

        $this->assertEquals(100, $result);
    }

    /**
     * SetWeeklyGoalTargetPercent_StoresClampedValue
     */
    public function testSetWeeklyGoalTargetPercent_StoresClampedValue(): void
    {
        $this->config
            ->expects($this->once())
            ->method('setValueString')
            ->with('chorestrike', 'weekly_goal_target_percent', '90');

        $this->service->setWeeklyGoalTargetPercent(90);
    }

    /**
     * SetWeeklyGoalTargetPercent_ClampsBelowOne
     */
    public function testSetWeeklyGoalTargetPercent_ClampsBelowOne(): void
    {
        $this->config
            ->expects($this->once())
            ->method('setValueString')
            ->with('chorestrike', 'weekly_goal_target_percent', '1');

        $this->service->setWeeklyGoalTargetPercent(-5);
    }

    /**
     * GetAllConfig_IncludesWeeklyGoalKey
     */
    public function testGetAllConfig_IncludesWeeklyGoalKey(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) {
                return $default;
            });

        $result = $this->service->getAllConfig();

        $this->assertArrayHasKey('weeklyGoalTargetPercent', $result);
        $this->assertEquals(80, $result['weeklyGoalTargetPercent']);
    }

    /**
     * UpdateConfig_HandlesWeeklyGoalKey
     */
    public function testUpdateConfig_HandlesWeeklyGoalKey(): void
    {
        $this->config
            ->expects($this->once())
            ->method('setValueString')
            ->with('chorestrike', 'weekly_goal_target_percent', '75');

        $this->service->updateConfig([
            'weeklyGoalTargetPercent' => 75,
        ]);
    }
}
