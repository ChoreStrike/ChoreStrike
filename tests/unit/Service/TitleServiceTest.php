<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCA\ChoreStrike\Service\TitleService;
use OCA\ChoreStrike\Titles\TitleCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TitleService (CS-PLAN-006 Phase 2; WP3 cutover per
 * CS-PLAN-070 D7c/C3d: the coins-driven cozy_home pack is retired, the
 * retained family_rhythm track keys off currentStreak 3/6/12).
 */
class TitleServiceTest extends TestCase {
	private HouseUserMapper $houseUserMapper;
	private RewardConfigService $rewardConfig;
	private TitleService $service;

	protected function setUp(): void {
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->rewardConfig = $this->createMock(RewardConfigService::class);

		// Default: config returns the catalog default thresholds.
		$this->rewardConfig
			->method('getTitleThreshold')
			->willReturnCallback(function (string $packKey, int $tierIndex): int {
				return TitleCatalog::getTiers($packKey)[$tierIndex]['threshold'];
			});

		$this->service = new TitleService($this->houseUserMapper, $this->rewardConfig, $this->createIdentityL10n());
	}

	private function createIdentityL10n(): \OCP\IL10N {
		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		return $l10n;
	}

	private function userWithStats(int $contributionMinor, int $longestStreak, int $currentStreak): HouseUser {
		$user = new HouseUser();
		$user->setId(1);
		$user->setContributionMinor($contributionMinor);
		$user->setLongestStreak($longestStreak);
		$user->setCurrentStreak($currentStreak);
		return $user;
	}

	public function testGetCurrentTitle_NoPackSelected_ReturnsNull(): void {
		$user = $this->userWithStats(5000, 100, 12);
		$this->assertNull($this->service->getCurrentTitle($user));
	}

	public function testGetCurrentTitle_PackSelectedButBelowTier1_ReturnsNull(): void {
		$user = $this->userWithStats(100, 1, 1);
		$user->setSelectedTitlePack('home_helpers');
		$this->assertNull($this->service->getCurrentTitle($user));
	}

	public function testGetCurrentTitle_Tier1Unlocked_ReturnsTier1(): void {
		$user = $this->userWithStats(6000, 1, 1);
		$user->setSelectedTitlePack('home_helpers');

		$title = $this->service->getCurrentTitle($user);

		$this->assertNotNull($title);
		$this->assertSame('home_helpers', $title['pack']);
		$this->assertSame(0, $title['tierIndex']);
		$this->assertSame('helper', $title['tierKey']);
		$this->assertSame(5000, $title['threshold']);
	}

	public function testGetCurrentTitle_Tier3Unlocked_ReturnsHighest(): void {
		$user = $this->userWithStats(150000, 1, 1);
		$user->setSelectedTitlePack('home_helpers');

		$title = $this->service->getCurrentTitle($user);

		$this->assertNotNull($title);
		$this->assertSame(2, $title['tierIndex']);
		$this->assertSame('care_captain', $title['tierKey']);
	}

	public function testGetCurrentTitle_TeamEnergyPack_UsesLongestStreak(): void {
		$user = $this->userWithStats(0, 35, 0);
		$user->setSelectedTitlePack('team_energy');

		$title = $this->service->getCurrentTitle($user);

		$this->assertNotNull($title);
		$this->assertSame('team_energy', $title['pack']);
		$this->assertSame(1, $title['tierIndex']);
		$this->assertSame('flow_keeper', $title['tierKey']);
	}

	public function testGetCurrentTitle_FamilyRhythmPack_UsesCurrentStreak(): void {
		// C3d: the retained rhythm track keys off currentStreak (3/6/12).
		$user = $this->userWithStats(0, 0, 7);
		$user->setSelectedTitlePack('family_rhythm');

		$title = $this->service->getCurrentTitle($user);

		$this->assertNotNull($title);
		$this->assertSame('family_rhythm', $title['pack']);
		$this->assertSame(1, $title['tierIndex']);
		$this->assertSame('rhythm_6', $title['tierKey']);
	}

	public function testGetCurrentTitle_RetiredCozyHomePack_IsUnknown(): void {
		// WP3 cutover: the coins-driven pack no longer exists.
		$user = $this->userWithStats(0, 0, 0);
		$user->setSelectedTitlePack('cozy_home');

		$this->assertNull($this->service->getCurrentTitle($user));
	}

	public function testGetUnlockedTitles_AggregatesAcrossPacks(): void {
		// contributionMinor 6000 → home_helpers.helper; longestStreak 8 →
		// team_energy.spark_starter; currentStreak 3 → family_rhythm.rhythm_3
		$user = $this->userWithStats(6000, 8, 3);

		$unlocked = $this->service->getUnlockedTitles($user);

		$tierIds = array_map(fn ($t) => $t['pack'] . '.' . $t['tierKey'], $unlocked);
		$this->assertContains('home_helpers.helper', $tierIds);
		$this->assertContains('team_energy.spark_starter', $tierIds);
		$this->assertContains('family_rhythm.rhythm_3', $tierIds);
		$this->assertNotContains('home_helpers.care_captain', $tierIds);
	}

	public function testSelectPack_KnownPack_PersistsSelection(): void {
		$user = $this->userWithStats(6000, 8, 0);
		$user->setNextcloudUserId('self-nc');
		$actor = new HouseUser();
		$actor->setNextcloudUserId('self-nc');
		$actor->setRole('member');
		$this->houseUserMapper->expects($this->once())->method('update')->with($user);

		// Self-selection (RF-058-07): the actor is the target user.
		$this->service->selectPack($user, $actor, 'team_energy');

		$this->assertSame('team_energy', $user->getSelectedTitlePack());
	}

	public function testSelectPack_OwnerActor_PersistsSelectionForAnyTarget(): void {
		$user = $this->userWithStats(6000, 8, 0);
		$user->setNextcloudUserId('member-nc');
		$owner = new HouseUser();
		$owner->setNextcloudUserId('owner-nc');
		$owner->setRole('owner');
		$this->houseUserMapper->expects($this->once())->method('update')->with($user);

		$this->service->selectPack($user, $owner, 'team_energy');

		$this->assertSame('team_energy', $user->getSelectedTitlePack());
	}

	public function testSelectPack_NonOwnerOtherTarget_ThrowsForbidden(): void {
		$user = $this->userWithStats(6000, 8, 0);
		$user->setNextcloudUserId('member-nc');
		$other = new HouseUser();
		$other->setNextcloudUserId('other-nc');
		$other->setRole('member');
		$this->houseUserMapper->expects($this->never())->method('update');

		$this->expectException(\OCA\ChoreStrike\Exception\ForbiddenException::class);
		$this->service->selectPack($user, $other, 'team_energy');
	}

	public function testSelectPack_UnknownPack_Throws(): void {
		$user = $this->userWithStats(6000, 8, 0);
		$user->setNextcloudUserId('self-nc');
		$actor = new HouseUser();
		$actor->setNextcloudUserId('self-nc');
		$actor->setRole('member');
		$this->houseUserMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->selectPack($user, $actor, 'nope');
	}

	public function testDetectUnlock_FirstCross_ReturnsNewTitlesAndMutatesCache(): void {
		$user = $this->userWithStats(6000, 8, 0);

		$unlocked = $this->service->detectUnlock($user);

		$tierIds = array_map(fn ($t) => $t['pack'] . '.' . $t['tierKey'], $unlocked);
		$this->assertContains('home_helpers.helper', $tierIds);
		$this->assertContains('team_energy.spark_starter', $tierIds);
		// Cache now holds the new titles.
		$this->assertContains('home_helpers.helper', $user->getUnlockedTitles());
	}

	public function testDetectUnlock_ReRunWithSameStats_IsIdempotentAndReturnsEmpty(): void {
		$user = $this->userWithStats(6000, 8, 0);

		$first = $this->service->detectUnlock($user);
		$this->assertNotEmpty($first);

		$second = $this->service->detectUnlock($user);
		$this->assertSame([], $second);
	}

	public function testDetectUnlock_BelowAllThresholds_ReturnsEmpty(): void {
		$user = $this->userWithStats(1, 1, 1);

		$this->assertSame([], $this->service->detectUnlock($user));
	}

	public function testDetectUnlock_HonoursConfigThresholdOverride(): void {
		// Override home_helpers tier 0 threshold to 500 (lower than the stat of 6000).
		$this->rewardConfig = $this->createMock(RewardConfigService::class);
		$this->rewardConfig->method('getTitleThreshold')->willReturnMap([
			['home_helpers', 0, 500],
			['home_helpers', 1, 25000],
			['home_helpers', 2, 100000],
			['team_energy', 0, 7],
			['team_energy', 1, 30],
			['team_energy', 2, 90],
			['family_rhythm', 0, 3],
			['family_rhythm', 1, 6],
			['family_rhythm', 2, 12],
		]);
		$service = new TitleService($this->houseUserMapper, $this->rewardConfig, $this->createIdentityL10n());

		$user = $this->userWithStats(6000, 1, 1);

		$unlocked = $service->detectUnlock($user);
		$this->assertNotEmpty($unlocked);
		$this->assertSame('home_helpers.helper', $unlocked[0]['pack'] . '.' . $unlocked[0]['tierKey']);
	}

	/**
	 * ReconcileUnlocked_CorrectionRemovesOnlyUnsupportedTiers (C3g)
	 *
	 * WP3 correction reconciliation: a cached tier is revoked ONLY when
	 * the corrected valid history no longer reaches its threshold. A
	 * tier still supported by the corrected stats stays cached.
	 */
	public function testReconcileUnlocked_CorrectionRemovesOnlyUnsupportedTiers(): void {
		$user = $this->userWithStats(6000, 8, 0);
		$user->addUnlockedTitle('home_helpers.helper');
		$user->addUnlockedTitle('team_energy.spark_starter');
		$user->addUnlockedTitle('home_helpers.care_captain'); // unsupported (250000 needed)

		$removed = $this->service->reconcileUnlocked($user);

		$this->assertSame(['home_helpers.care_captain'], $removed);
		$this->assertContains('home_helpers.helper', $user->getUnlockedTitles());
		$this->assertContains('team_energy.spark_starter', $user->getUnlockedTitles());
		$this->assertNotContains('home_helpers.care_captain', $user->getUnlockedTitles());
	}

	/**
	 * ReconcileUnlocked_AllTiersSupported_ReturnsEmptyWithoutMutation
	 */
	public function testReconcileUnlocked_AllTiersSupported_ReturnsEmptyWithoutMutation(): void {
		$user = $this->userWithStats(6000, 8, 0);
		$user->addUnlockedTitle('home_helpers.helper');
		$user->addUnlockedTitle('team_energy.spark_starter');

		$this->assertSame([], $this->service->reconcileUnlocked($user));
		$this->assertCount(2, $user->getUnlockedTitles());
	}

	public function testGetCatalogView_ReturnsAllPacksWithThresholds(): void {
		$view = $this->service->getCatalogView();

		$this->assertCount(3, $view);
		$this->assertArrayHasKey('home_helpers', $view);
		$this->assertArrayHasKey('team_energy', $view);
		$this->assertArrayHasKey('family_rhythm', $view);
		$this->assertArrayNotHasKey('cozy_home', $view);
		$this->assertCount(3, $view['home_helpers']['tiers']);
		$this->assertSame(5000, $view['home_helpers']['tiers'][0]['threshold']);
		$this->assertSame(3, $view['family_rhythm']['tiers'][0]['threshold']);
		$this->assertSame(12, $view['family_rhythm']['tiers'][2]['threshold']);
	}
}
