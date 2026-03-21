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
}
