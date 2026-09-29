<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Titles;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Titles\TitleCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the TitleCatalog constant definitions (CS-PLAN-006
 * Phase 1; WP4 single-series cutover per CS-PLAN-070 C3d/D7c).
 */
class TitleCatalogTest extends TestCase {
	public function testIsValidCatalog_StockDefinition_ReturnsTrue(): void {
		$this->assertTrue(TitleCatalog::isValidCatalog());
	}

	public function testGetPackKeys_ReturnsSingleRetainedSeries(): void {
		$this->assertSame(
			['family_rhythm'],
			TitleCatalog::getPackKeys()
		);
	}

	/** C3d: the effort/wallet title series (home_helpers/team_energy) retired. */
	public function testRetiredPacks_AreAbsent(): void {
		$this->assertFalse(TitleCatalog::isValidPack('home_helpers'));
		$this->assertFalse(TitleCatalog::isValidPack('team_energy'));
		$this->assertFalse(TitleCatalog::isValidPack('cozy_home'));
	}

	public function testSeriesHasThreeAscendingTiers(): void {
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

	/** The fixed 3/6/12 first-slice thresholds (WP4: not config-overridable). */
	public function testRetainedThresholds_AreFixedAt3_6_12(): void {
		$tiers = TitleCatalog::getTiers(TitleCatalog::PACK_FAMILY_RHYTHM);
		$this->assertSame([3, 6, 12], array_column($tiers, 'threshold'));
	}

	public function testSeriesUsesTheValidHistoryMaximumSignal(): void {
		$this->assertSame(
			TitleCatalog::SIGNAL_LONGEST_STREAK,
			TitleCatalog::getPack(TitleCatalog::PACK_FAMILY_RHYTHM)['signal']
		);
	}

	public function testIsValidPack_RetainedSeries_ReturnsTrue(): void {
		$this->assertTrue(TitleCatalog::isValidPack('family_rhythm'));
	}

	public function testIsValidPack_UnknownSeries_ReturnsFalse(): void {
		$this->assertFalse(TitleCatalog::isValidPack('does_not_exist'));
	}

	public function testGetPack_UnknownSeries_Throws(): void {
		$this->expectException(\InvalidArgumentException::class);
		TitleCatalog::getPack('nope');
	}

	public function testTierId_BuildsCanonicalIdentifier(): void {
		$this->assertSame('family_rhythm.rhythm_3', TitleCatalog::tierId('family_rhythm', 0));
		$this->assertSame('family_rhythm.rhythm_12', TitleCatalog::tierId('family_rhythm', 2));
	}

	public function testGetSignalValue_ReadsTheValidHistoryMaximum(): void {
		$user = new HouseUser();
		$user->setLongestStreak(42);
		$user->setCurrentStreak(1);

		$this->assertSame(42, TitleCatalog::getSignalValue($user, TitleCatalog::SIGNAL_LONGEST_STREAK));
	}

	public function testGetSignalValue_UnknownSignal_ReturnsZero(): void {
		$user = new HouseUser();
		$this->assertSame(0, TitleCatalog::getSignalValue($user, 'unknown'));
	}
}
