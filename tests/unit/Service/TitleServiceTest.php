<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Service\TitleService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TitleService (CS-PLAN-006 Phase 2; WP4 single-series
 * cutover per CS-PLAN-070 C3d/D7c, Q-075-02 A: one retained rhythm series
 * qualifying on the valid-history maximum, no pack selection, fixed
 * thresholds).
 */
class TitleServiceTest extends TestCase {
	private TitleService $service;

	protected function setUp(): void {
		$this->service = new TitleService();
	}

	private function userWithStats(int $contributionMinor, int $longestStreak, int $currentStreak): HouseUser {
		$user = new HouseUser();
		$user->setId(1);
		$user->setContributionMinor($contributionMinor);
		$user->setLongestStreak($longestStreak);
		$user->setCurrentStreak($currentStreak);
		return $user;
	}

	public function testGetCurrentTitle_BelowTier1_ReturnsNull(): void {
		$user = $this->userWithStats(100, 2, 2);
		$this->assertNull($this->service->getCurrentTitle($user));
	}

	public function testGetCurrentTitle_Tier1Unlocked_ReturnsTier1(): void {
		$user = $this->userWithStats(0, 3, 1);

		$title = $this->service->getCurrentTitle($user);

		$this->assertNotNull($title);
		$this->assertSame('family_rhythm', $title['pack']);
		$this->assertSame(0, $title['tierIndex']);
		$this->assertSame('rhythm_3', $title['tierKey']);
		$this->assertSame(3, $title['threshold']);
	}

	public function testGetCurrentTitle_Tier3Unlocked_ReturnsHighest(): void {
		$user = $this->userWithStats(0, 12, 4);

		$title = $this->service->getCurrentTitle($user);

		$this->assertNotNull($title);
		$this->assertSame(2, $title['tierIndex']);
		$this->assertSame('rhythm_12', $title['tierKey']);
	}

	/**
	 * D7c retention pin: qualification reads the valid-history MAXIMUM
	 * (longestStreak), so an ordinary later streak break never strips an
	 * earned title.
	 */
	public function testGetCurrentTitle_OrdinaryStreakBreak_RetainsEarnedTitle(): void {
		// Valid-history max 7, but the current run broke back to 1.
		$user = $this->userWithStats(0, 7, 1);

		$title = $this->service->getCurrentTitle($user);

		$this->assertNotNull($title);
		$this->assertSame(1, $title['tierIndex']);
		$this->assertSame('rhythm_6', $title['tierKey']);
	}

	/**
	 * The retired packs are unknown — a stale client cannot re-select
	 * them, and their signals (contribution/currentStreak) no longer
	 * qualify anything.
	 */
	public function testGetCurrentTitle_RetiredPacksAndSignals_DoNotQualify(): void {
		// High contribution and high current streak alone earn nothing.
		$user = $this->userWithStats(150000, 0, 90);
		$this->assertNull($this->service->getCurrentTitle($user));
	}

	public function testGetUnlockedTitles_ReturnsOnlyRetainedTiers(): void {
		$user = $this->userWithStats(6000, 8, 3);

		$unlocked = $this->service->getUnlockedTitles($user);

		$tierIds = array_map(fn ($t) => $t['pack'] . '.' . $t['tierKey'], $unlocked);
		$this->assertSame(['family_rhythm.rhythm_3', 'family_rhythm.rhythm_6'], $tierIds);
	}

	public function testGetUnlockedTitles_Above12_GrantsAllThree(): void {
		$user = $this->userWithStats(0, 20, 2);
		$tierIds = array_map(fn ($t) => $t['tierKey'], $this->service->getUnlockedTitles($user));
		$this->assertSame(['rhythm_3', 'rhythm_6', 'rhythm_12'], $tierIds);
	}

	public function testEnrichWithCurrentTitle_AddsServerTitleToPayload(): void {
		$user = $this->userWithStats(0, 4, 4);
		$data = $this->service->enrichWithCurrentTitle(['id' => 1], $user);
		$this->assertNotNull($data['currentTitle']);
		$this->assertSame('rhythm_3', $data['currentTitle']['tierKey']);
	}

	public function testDetectUnlock_FirstCross_ReturnsNewTitlesAndMutatesMarker(): void {
		$user = $this->userWithStats(0, 7, 7);

		$unlocked = $this->service->detectUnlock($user);

		$tierIds = array_map(fn ($t) => $t['pack'] . '.' . $t['tierKey'], $unlocked);
		$this->assertSame(['family_rhythm.rhythm_3', 'family_rhythm.rhythm_6'], $tierIds);
		// Marker now holds the new titles.
		$this->assertContains('family_rhythm.rhythm_3', $user->getUnlockedTitles());
	}

	public function testDetectUnlock_ReRunWithSameStats_IsIdempotentAndReturnsEmpty(): void {
		$user = $this->userWithStats(0, 7, 7);

		$first = $this->service->detectUnlock($user);
		$this->assertNotEmpty($first);

		// Later ordinary break: the marker is untouched, nothing re-fires.
		$user->setCurrentStreak(1);
		$second = $this->service->detectUnlock($user);
		$this->assertSame([], $second);
	}

	public function testDetectUnlock_BelowAllThresholds_ReturnsEmpty(): void {
		$user = $this->userWithStats(1, 2, 2);

		$this->assertSame([], $this->service->detectUnlock($user));
	}

	/** No config threshold override exists (WP4 retirement pin). */
	public function testThresholds_AreFixed_NoConfigOverridePath(): void {
		$user = $this->userWithStats(0, 3, 3);
		$title = $this->service->getCurrentTitle($user);
		$this->assertSame(3, $title['threshold']);

		$reflection = new \ReflectionClass(TitleService::class);
		$this->assertFalse($reflection->hasMethod('getTitleThreshold'));
		$this->assertFalse($reflection->hasMethod('selectPack'));
	}

	/**
	 * ReconcileUnlocked_CorrectionRemovesOnlyUnsupportedTiers (C3g)
	 *
	 * A marked tier is revoked ONLY when the corrected valid history no
	 * longer reaches its threshold. A tier still supported by the
	 * corrected stats stays marked.
	 */
	public function testReconcileUnlocked_CorrectionRemovesOnlyUnsupportedTiers(): void {
		$user = $this->userWithStats(0, 8, 1);
		$user->addUnlockedTitle('family_rhythm.rhythm_3');
		$user->addUnlockedTitle('family_rhythm.rhythm_6');
		$user->addUnlockedTitle('family_rhythm.rhythm_12'); // unsupported (12 needed)

		$removed = $this->service->reconcileUnlocked($user);

		$this->assertSame(['family_rhythm.rhythm_12'], $removed);
		$this->assertContains('family_rhythm.rhythm_3', $user->getUnlockedTitles());
		$this->assertContains('family_rhythm.rhythm_6', $user->getUnlockedTitles());
		$this->assertNotContains('family_rhythm.rhythm_12', $user->getUnlockedTitles());
	}

	/**
	 * ReconcileUnlocked_AllTiersSupported_ReturnsEmptyWithoutMutation
	 */
	public function testReconcileUnlocked_AllTiersSupported_ReturnsEmptyWithoutMutation(): void {
		$user = $this->userWithStats(0, 12, 2);
		$user->addUnlockedTitle('family_rhythm.rhythm_3');
		$user->addUnlockedTitle('family_rhythm.rhythm_6');

		$this->assertSame([], $this->service->reconcileUnlocked($user));
		$this->assertCount(2, $user->getUnlockedTitles());
	}

	public function testGetCatalogView_ReturnsTheRetainedSeriesWithFixedThresholds(): void {
		$view = $this->service->getCatalogView();

		$this->assertSame(['family_rhythm'], array_keys($view));
		$this->assertCount(3, $view['family_rhythm']['tiers']);
		$this->assertSame('longestStreak', $view['family_rhythm']['signal']);
		$this->assertSame(3, $view['family_rhythm']['tiers'][0]['threshold']);
		$this->assertSame(6, $view['family_rhythm']['tiers'][1]['threshold']);
		$this->assertSame(12, $view['family_rhythm']['tiers'][2]['threshold']);
	}
}
