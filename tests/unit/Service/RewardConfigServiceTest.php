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

    /**
     * GetTitlesEnabled_ReturnsDefaultTrue
     */
    public function testGetTitlesEnabled_ReturnsDefaultTrue(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) {
                return $default;
            });

        $this->assertTrue($this->service->getTitlesEnabled());
    }

    /**
     * SetTitlesEnabled_StoresValue
     */
    public function testSetTitlesEnabled_StoresValue(): void
    {
        $this->config
            ->expects($this->once())
            ->method('setValueString')
            ->with('chorestrike', 'titles_enabled', '0');

        $this->service->setTitlesEnabled(false);
    }

    /**
     * GetTitleThreshold_ReturnsCatalogDefaultWhenUnset
     */
    public function testGetTitleThreshold_ReturnsCatalogDefaultWhenUnset(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) {
                return $default;
            });

        // home_helpers tier 0 default is 50.
        $this->assertSame(50, $this->service->getTitleThreshold('home_helpers', 0));
    }

    /**
     * GetTitleThreshold_ClampsBelowOneToOne
     */
    public function testGetTitleThreshold_ClampsBelowOneToOne(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturn('0');

        $this->assertSame(1, $this->service->getTitleThreshold('home_helpers', 0));
    }

    /**
     * SetTitleThreshold_StoresClampedValue
     */
    public function testSetTitleThreshold_StoresClampedValue(): void
    {
        $this->config
            ->expects($this->once())
            ->method('setValueString')
            ->with('chorestrike', 'title_threshold_home_helpers_1', '15');

        $this->service->setTitleThreshold('home_helpers', 1, 15);
    }

    /**
     * GetAllConfig_IncludesTitleKeys
     */
    public function testGetAllConfig_IncludesTitleKeys(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) {
                return $default;
            });

        $result = $this->service->getAllConfig();

        $this->assertArrayHasKey('titlesEnabled', $result);
        $this->assertTrue($result['titlesEnabled']);
        $this->assertArrayHasKey('titleThresholds', $result);
        $this->assertCount(3, $result['titleThresholds']['home_helpers']);
    }

    /**
     * UpdateConfig_HandlesTitlesEnabledAndThresholds
     */
    public function testUpdateConfig_HandlesTitlesEnabledAndThresholds(): void
    {
        $this->config
            ->expects($this->exactly(4))
            ->method('setValueString');

        $this->service->updateConfig([
            'titlesEnabled' => false,
            'titleThresholds' => [
                'home_helpers' => [60, 260, 1010],
            ],
        ]);
    }

    /**
     * UpdateConfig_RejectsNonAscendingThresholds_BeforeAnyWrite
     */
    public function testUpdateConfig_RejectsNonAscendingThresholds_BeforeAnyWrite(): void
    {
        // Non-ascending (500 then 50) must be rejected before any config write.
        $this->config->expects($this->never())->method('setValueString');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->updateConfig([
            'titleThresholds' => [
                'home_helpers' => [500, 50, 1000],
            ],
        ]);
    }

    /**
     * UpdateConfig_TitleThresholdsSparsePayload_WritesCorrectTierIndex
     *
     * Regression: applyTitleThresholds() iterated array_values($tierValues),
     * which reindexes a sparse payload (e.g. [2 => 1000]) to position 0, so
     * the value was written to the wrong tier. The tier index must come from
     * the original key.
     */
    public function testUpdateConfig_TitleThresholdsSparsePayload_WritesCorrectTierIndex(): void
    {
        $written = [];
        $this->config
            ->method('setValueString')
            ->willReturnCallback(function (string $app, string $key, string $value) use (&$written): void {
                $written[$key] = $value;
            });

        // Sparse payload: only tier index 2 provided.
        $this->service->updateConfig([
            'titleThresholds' => [
                'home_helpers' => [2 => 1000],
            ],
        ]);

        $this->assertSame('1000', $written['title_threshold_home_helpers_2'] ?? null);
        $this->assertArrayNotHasKey('title_threshold_home_helpers_0', $written);
        $this->assertArrayNotHasKey('title_threshold_home_helpers_1', $written);
    }

    /**
     * GetComboMultiplier_ReturnsDefault
     */
    public function testGetComboMultiplier_ReturnsDefault(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) {
                return $default;
            });

        $result = $this->service->getComboMultiplier();

        $this->assertEquals(2.0, $result);
    }

    /**
     * GetComboMultiplier_ClampsNegativeToZero
     */
    public function testGetComboMultiplier_ClampsNegativeToZero(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturn('-1.5');

        $result = $this->service->getComboMultiplier();

        $this->assertEquals(0.0, $result);
    }

    /**
     * SetComboMultiplier_StoresClampedValue
     */
    public function testSetComboMultiplier_StoresClampedValue(): void
    {
        $this->config
            ->expects($this->once())
            ->method('setValueString')
            ->with('chorestrike', 'combo_multiplier', '3');

        $this->service->setComboMultiplier(3.0);
    }

    /**
     * SetComboMultiplier_ClampsNegativeToZero
     */
    public function testSetComboMultiplier_ClampsNegativeToZero(): void
    {
        $this->config
            ->expects($this->once())
            ->method('setValueString')
            ->with('chorestrike', 'combo_multiplier', '0');

        $this->service->setComboMultiplier(-1.0);
    }

    /**
     * GetAllConfig_IncludesComboMultiplierKey
     */
    public function testGetAllConfig_IncludesComboMultiplierKey(): void
    {
        $this->config
            ->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) {
                return $default;
            });

        $result = $this->service->getAllConfig();

        $this->assertArrayHasKey('comboMultiplier', $result);
        $this->assertEquals(2.0, $result['comboMultiplier']);
    }

    /**
     * UpdateConfig_HandlesComboMultiplierKey
     */
    public function testUpdateConfig_HandlesComboMultiplierKey(): void
    {
        $this->config
            ->expects($this->once())
            ->method('setValueString')
            ->with('chorestrike', 'combo_multiplier', '3');

        $this->service->updateConfig([
            'comboMultiplier' => 3.0,
        ]);
    }
}
