<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Titles;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Titles\TitleCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the TitleCatalog constant definitions (CS-PLAN-006 Phase 1).
 */
class TitleCatalogTest extends TestCase
{
    public function testIsValidCatalog_StockDefinition_ReturnsTrue(): void
    {
        $this->assertTrue(TitleCatalog::isValidCatalog());
    }

    public function testGetPackKeys_ReturnsThreePacks(): void
    {
        $this->assertSame(
            ['home_helpers', 'team_energy', 'cozy_home'],
            TitleCatalog::getPackKeys()
        );
    }

    public function testEachPackHasThreeAscendingTiers(): void
    {
        foreach (TitleCatalog::getPackKeys() as $packKey) {
            $tiers = TitleCatalog::getTiers($packKey);
            $this->assertCount(3, $tiers, $packKey . ' must have 3 tiers');
            $keys = array_column($tiers, 'key');
            $this->assertCount(3, array_unique($keys), $packKey . ' tier keys must be unique');

            $thresholds = array_column($tiers, 'threshold');
            $this->assertSame($thresholds, array_values($thresholds), $packKey . ' tiers must be ordered');
            for ($i = 1; $i < 3; $i++) {
                $this->assertGreaterThan(
                    $thresholds[$i - 1],
                    $thresholds[$i],
                    $packKey . ' thresholds must be strictly ascending'
                );
                $this->assertGreaterThan(0, $thresholds[$i], $packKey . ' thresholds must be positive');
            }
        }
    }

    public function testEachPackHasKnownSignal(): void
    {
        $validSignals = [
            TitleCatalog::SIGNAL_CONTRIBUTION_SCORE,
            TitleCatalog::SIGNAL_LONGEST_STREAK,
            TitleCatalog::SIGNAL_TOTAL_SPARKS_EARNED,
            TitleCatalog::SIGNAL_COINS,
        ];
        foreach (TitleCatalog::getPackKeys() as $packKey) {
            $signal = TitleCatalog::getPack($packKey)['signal'];
            $this->assertContains($signal, $validSignals, $packKey . ' has an unknown signal');
        }
    }

    public function testIsValidPack_KnownPack_ReturnsTrue(): void
    {
        $this->assertTrue(TitleCatalog::isValidPack('home_helpers'));
    }

    public function testIsValidPack_UnknownPack_ReturnsFalse(): void
    {
        $this->assertFalse(TitleCatalog::isValidPack('does_not_exist'));
    }

    public function testGetPack_UnknownPack_Throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TitleCatalog::getPack('nope');
    }

    public function testTierId_BuildsCanonicalIdentifier(): void
    {
        $this->assertSame('home_helpers.helper', TitleCatalog::tierId('home_helpers', 0));
        $this->assertSame('team_energy.team_anchor', TitleCatalog::tierId('team_energy', 2));
    }

    public function testGetSignalValue_ReadsCorrectStat(): void
    {
        $user = new HouseUser();
        $user->setContributionScore(123);
        $user->setLongestStreak(42);
        $user->setTotalSparksEarned(999);
        $user->setCoins(777);

        $this->assertSame(123, TitleCatalog::getSignalValue($user, TitleCatalog::SIGNAL_CONTRIBUTION_SCORE));
        $this->assertSame(42, TitleCatalog::getSignalValue($user, TitleCatalog::SIGNAL_LONGEST_STREAK));
        $this->assertSame(999, TitleCatalog::getSignalValue($user, TitleCatalog::SIGNAL_TOTAL_SPARKS_EARNED));
        $this->assertSame(777, TitleCatalog::getSignalValue($user, TitleCatalog::SIGNAL_COINS));
    }

    public function testGetSignalValue_UnknownSignal_ReturnsZero(): void
    {
        $user = new HouseUser();
        $this->assertSame(0, TitleCatalog::getSignalValue($user, 'unknown'));
    }
}
