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
 * Unit tests for TitleService (CS-PLAN-006 Phase 2).
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

		$this->service = new TitleService($this->houseUserMapper, $this->rewardConfig);
	}

	private function userWithStats(int $contribution, int $longestStreak, int $totalSparks): HouseUser {
		$user = new HouseUser();
		$user->setId(1);
		$user->setContributionScore($contribution);
		$user->setLongestStreak($longestStreak);
		$user->setTotalSparksEarned($totalSparks);
		return $user;
	}

	public function testGetCurrentTitle_NoPackSelected_ReturnsNull(): void {
		$user = $this->userWithStats(5000, 100, 20000);
		$this->assertNull($this->service->getCurrentTitle($user));
	}

	public function testGetCurrentTitle_PackSelectedButBelowTier1_ReturnsNull(): void {
		$user = $this->userWithStats(10, 1, 10);
		$user->setSelectedTitlePack('home_helpers');
		$this->assertNull($this->service->getCurrentTitle($user));
	}

	public function testGetCurrentTitle_Tier1Unlocked_ReturnsTier1(): void {
		$user = $this->userWithStats(60, 1, 10);
		$user->setSelectedTitlePack('home_helpers');

		$title = $this->service->getCurrentTitle($user);

		$this->assertNotNull($title);
		$this->assertSame('home_helpers', $title['pack']);
		$this->assertSame(0, $title['tierIndex']);
		$this->assertSame('helper', $title['tierKey']);
	}

	public function testGetCurrentTitle_Tier3Unlocked_ReturnsHighest(): void {
		$user = $this->userWithStats(1500, 1, 10);
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

	public function testGetCurrentTitle_CozyHomePack_UsesCoins(): void {
		// cozy_home is driven by the actively-maintained `coins` signal.
		$user = $this->userWithStats(0, 0, 0);
		$user->setCoins(3000);
		$user->setSelectedTitlePack('cozy_home');

		$title = $this->service->getCurrentTitle($user);

		$this->assertNotNull($title);
		$this->assertSame('cozy_home', $title['pack']);
		$this->assertSame(1, $title['tierIndex']);
		$this->assertSame('room_ranger', $title['tierKey']);
	}

	public function testGetUnlockedTitles_AggregatesAcrossPacks(): void {
		// contribution 60 → home_helpers.helper; longestStreak 8 → team_energy.spark_starter
		$user = $this->userWithStats(60, 8, 0);

		$unlocked = $this->service->getUnlockedTitles($user);

		$tierIds = array_map(fn ($t) => $t['pack'] . '.' . $t['tierKey'], $unlocked);
		$this->assertContains('home_helpers.helper', $tierIds);
		$this->assertContains('team_energy.spark_starter', $tierIds);
		$this->assertNotContains('cozy_home.cozy_buddy', $tierIds);
	}

	public function testSelectPack_KnownPack_PersistsSelection(): void {
		$user = $this->userWithStats(60, 8, 0);
		$this->houseUserMapper->expects($this->once())->method('update')->with($user);

		$this->service->selectPack($user, 'team_energy');

		$this->assertSame('team_energy', $user->getSelectedTitlePack());
	}

	public function testSelectPack_UnknownPack_Throws(): void {
		$user = $this->userWithStats(60, 8, 0);
		$this->houseUserMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->selectPack($user, 'nope');
	}

	public function testDetectUnlock_FirstCross_ReturnsNewTitlesAndMutatesCache(): void {
		$user = $this->userWithStats(60, 8, 0);

		$unlocked = $this->service->detectUnlock($user);

		$tierIds = array_map(fn ($t) => $t['pack'] . '.' . $t['tierKey'], $unlocked);
		$this->assertContains('home_helpers.helper', $tierIds);
		$this->assertContains('team_energy.spark_starter', $tierIds);
		// Cache now holds the new titles.
		$this->assertContains('home_helpers.helper', $user->getUnlockedTitles());
	}

	public function testDetectUnlock_ReRunWithSameStats_IsIdempotentAndReturnsEmpty(): void {
		$user = $this->userWithStats(60, 8, 0);

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
		// Override home_helpers tier 0 threshold to 5 (lower than the stat of 60).
		$this->rewardConfig = $this->createMock(RewardConfigService::class);
		$this->rewardConfig->method('getTitleThreshold')->willReturnMap([
			['home_helpers', 0, 5],
			['home_helpers', 1, 250],
			['home_helpers', 2, 1000],
			['team_energy', 0, 7],
			['team_energy', 1, 30],
			['team_energy', 2, 90],
			['cozy_home', 0, 500],
			['cozy_home', 1, 2500],
			['cozy_home', 2, 10000],
		]);
		$service = new TitleService($this->houseUserMapper, $this->rewardConfig);

		$user = $this->userWithStats(60, 1, 1);

		$unlocked = $service->detectUnlock($user);
		$this->assertNotEmpty($unlocked);
		$this->assertSame('home_helpers.helper', $unlocked[0]['pack'] . '.' . $unlocked[0]['tierKey']);
	}

	public function testGetCatalogView_ReturnsAllPacksWithThresholds(): void {
		$view = $this->service->getCatalogView();

		$this->assertCount(3, $view);
		$this->assertArrayHasKey('home_helpers', $view);
		$this->assertCount(3, $view['home_helpers']['tiers']);
		$this->assertSame(50, $view['home_helpers']['tiers'][0]['threshold']);
	}
}
