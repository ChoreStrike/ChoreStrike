<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\Medal;
use OCA\ChoreStrike\Db\MedalMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\UserStats;
use OCA\ChoreStrike\Service\MedalService;
use OCA\ChoreStrike\Service\UserStatsService;
use OCA\ChoreStrike\Service\ComboService;
use PHPUnit\Framework\TestCase;

class MedalServiceTest extends TestCase {
	private MedalService $service;
	private MedalMapper $medalMapper;
	private HouseUserMapper $houseUserMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private UserStatsService $userStatsService;
	private ComboService $comboService;

	protected function setUp(): void {
		parent::setUp();
		$this->medalMapper = $this->createMock(MedalMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->userStatsService = $this->createMock(UserStatsService::class);
		$this->comboService = $this->createMock(ComboService::class);

		$this->service = new MedalService(
			$this->medalMapper,
			$this->houseUserMapper,
			$this->completedTaskMapper,
			$this->userStatsService,
			$this->comboService
		);
	}

	public function testAwardMedalCreatesNewMedal(): void {
		$newMedal = new Medal();
		$newMedal->setId(1);
		$newMedal->setUserId(1); // house user ID is int
		$newMedal->setMedalType('team_anchor');
		$newMedal->setValue(50);

		$this->medalMapper->expects($this->once())
			->method('findByUserTypeAndWeek')
			->willThrowException(new \Exception());

		$this->medalMapper->expects($this->once())
			->method('insert')
			->willReturn($newMedal);

		// awardMedal expects houseUserId as int, not Nextcloud string userId
		$result = $this->service->awardMedal(1, '1', 'team_anchor');

		$this->assertNotNull($result);
		$this->assertEquals('team_anchor', $result->getMedalType());
		$this->assertEquals(50, $result->getValue());
	}

	public function testAwardMedalReturnsNullIfAlreadyAwarded(): void {
		$existingMedal = new Medal();
		$existingMedal->setId(1);

		$this->medalMapper->expects($this->once())
			->method('findByUserTypeAndWeek')
			->willReturn($existingMedal);

		$result = $this->service->awardMedal(1, '1', 'team_anchor');

		$this->assertNull($result);
	}

	public function testHasMedalThisWeekReturnsTrueIfExists(): void {
		$existingMedal = new Medal();

		$this->medalMapper->expects($this->once())
			->method('findByUserTypeAndWeek')
			->willReturn($existingMedal);

		$result = $this->service->hasMedalThisWeek(1, 'user1', 'team_anchor');

		$this->assertTrue($result);
	}

	public function testHasMedalThisWeekReturnsFalseIfNotExists(): void {
		$this->medalMapper->expects($this->once())
			->method('findByUserTypeAndWeek')
			->willThrowException(new \Exception());

		$result = $this->service->hasMedalThisWeek(1, 'user1', 'team_anchor');

		$this->assertFalse($result);
	}

	public function testGetMedalInfoReturnsCorrectData(): void {
		$info = $this->service->getMedalInfo('team_anchor');

		$this->assertEquals('Team Anchor', $info['name']);
		$this->assertEquals(50, $info['points']);
		$this->assertEquals('common', $info['rarity']);
		$this->assertEquals('🏆', $info['icon']);
	}

	public function testGetAllMedalsReturnsAllTypes(): void {
		$medals = $this->service->getAllMedals();

		$this->assertCount(5, $medals);
		$this->assertArrayHasKey('team_anchor', $medals);
		$this->assertArrayHasKey('legendary_helper', $medals);
	}

	public function testGetUserMedalsReturnsUserMedals(): void {
		$medal1 = new Medal();
		$medal1->setId(1);
		$medal2 = new Medal();
		$medal2->setId(2);

		$this->medalMapper->expects($this->once())
			->method('findByUserId')
			->with('user1')
			->willReturn([$medal1, $medal2]);

		$result = $this->service->getUserMedals('user1');

		$this->assertCount(2, $result);
	}

	public function testCheckAndAwardMedalsAwardsTeamAnchor(): void {
		$user = new HouseUser();
		$user->setId(1);
		$user->setNextcloudUserId('user1');
		$user->setCurrentStreak(3);

		$this->houseUserMapper->expects($this->once())
			->method('findByHouseId')
			->with(1)
			->willReturn([$user]);

		// 10+ tasks this week -> Team Anchor
		$this->completedTaskMapper->expects($this->once())
			->method('getUserStats')
			->willReturn(['taskCount' => 12]);

		// Medal not yet awarded
		$this->medalMapper->expects($this->any())
			->method('findByUserTypeAndWeek')
			->willThrowException(new \Exception());

		$newMedal = new Medal();
		$newMedal->setId(1);
		$newMedal->setMedalType('team_anchor');

		$this->medalMapper->expects($this->once())
			->method('insert')
			->willReturn($newMedal);

		$result = $this->service->checkAndAwardMedals(1);

		$this->assertCount(1, $result);
		$this->assertEquals('team_anchor', $result[0]->getMedalType());
	}

	public function testCheckAndAwardMedalsAwardsStreakKeeper(): void {
		$user = new HouseUser();
		$user->setId(1);
		$user->setNextcloudUserId('user1');
		$user->setCurrentStreak(10);

		$this->houseUserMapper->expects($this->once())
			->method('findByHouseId')
			->with(1)
			->willReturn([$user]);

		// Less than 10 tasks -> no Team Anchor
		$this->completedTaskMapper->expects($this->once())
			->method('getUserStats')
			->willReturn(['taskCount' => 5]);

		// 7+ day streak -> Streak Keeper
		$this->medalMapper->expects($this->any())
			->method('findByUserTypeAndWeek')
			->willThrowException(new \Exception());

		$newMedal = new Medal();
		$newMedal->setId(1);
		$newMedal->setMedalType('streak_keeper');

		$this->medalMapper->expects($this->once())
			->method('insert')
			->willReturn($newMedal);

		$result = $this->service->checkAndAwardMedals(1);

		$this->assertCount(1, $result);
		$this->assertEquals('streak_keeper', $result[0]->getMedalType());
	}

	public function testCheckAndAwardMedalsAwardsLegendaryHelper(): void {
		$user = new HouseUser();
		$user->setId(1);
		$user->setNextcloudUserId('user1');
		$user->setCurrentStreak(3);

		$this->houseUserMapper->expects($this->once())
			->method('findByHouseId')
			->with(1)
			->willReturn([$user]);

		$this->completedTaskMapper->expects($this->once())
			->method('getUserStats')
			->willReturn(['taskCount' => 5]);

		$stats = new UserStats();
		$stats->setTasksCompleted(150);

		$this->userStatsService->expects($this->once())
			->method('getOrCreateStats')
			->willReturn($stats);

		$this->comboService->expects($this->once())
			->method('getComboStats')
			->willReturn([]);

		$this->medalMapper->expects($this->any())
			->method('findByUserTypeAndWeek')
			->willThrowException(new \Exception());

		$newMedal = new Medal();
		$newMedal->setId(1);
		$newMedal->setMedalType('legendary_helper');

		$this->medalMapper->expects($this->once())
			->method('insert')
			->willReturn($newMedal);

		$result = $this->service->checkAndAwardMedals(1);

		$this->assertCount(1, $result);
		$this->assertEquals('legendary_helper', $result[0]->getMedalType());
	}

	public function testCheckAndAwardMedalsAwardsComboMaster(): void {
		$user = new HouseUser();
		$user->setId(1);
		$user->setNextcloudUserId('user1');
		$user->setCurrentStreak(3);

		$this->houseUserMapper->expects($this->once())
			->method('findByHouseId')
			->with(1)
			->willReturn([$user]);

		$this->completedTaskMapper->expects($this->once())
			->method('getUserStats')
			->willReturn(['taskCount' => 5]);

		$stats = new UserStats();
		$stats->setTasksCompleted(50);
		$stats->setCurrentStreak(3);

		$this->userStatsService->expects($this->once())
			->method('getOrCreateStats')
			->willReturn($stats);

		// 5+ combo -> Combo Master
		$this->comboService->expects($this->once())
			->method('getComboStats')
			->willReturn([
				['user_id' => 'user1', 'max_combo' => 7, 'combo_count' => 3]
			]);

		$this->medalMapper->expects($this->any())
			->method('findByUserTypeAndWeek')
			->willThrowException(new \Exception());

		$newMedal = new Medal();
		$newMedal->setId(1);
		$newMedal->setMedalType('combo_master');

		$this->medalMapper->expects($this->once())
			->method('insert')
			->willReturn($newMedal);

		$result = $this->service->checkAndAwardMedals(1);

		$this->assertCount(1, $result);
		$this->assertEquals('combo_master', $result[0]->getMedalType());
	}

	public function testCheckAndAwardMedalsAwardsMultipleMedals(): void {
		$user = new HouseUser();
		$user->setId(1);
		$user->setNextcloudUserId('user1');
		$user->setCurrentStreak(10);

		$this->houseUserMapper->expects($this->once())
			->method('findByHouseId')
			->with(1)
			->willReturn([$user]);

		// 10+ tasks -> Team Anchor
		$this->completedTaskMapper->expects($this->once())
			->method('getUserStats')
			->willReturn(['taskCount' => 15]);

		// 10 day streak -> Streak Keeper
		$this->medalMapper->expects($this->any())
			->method('findByUserTypeAndWeek')
			->willThrowException(new \Exception());

		$medal1 = new Medal();
		$medal1->setId(1);
		$medal1->setMedalType('team_anchor');

		$medal2 = new Medal();
		$medal2->setId(2);
		$medal2->setMedalType('streak_keeper');

		$this->medalMapper->expects($this->exactly(2))
			->method('insert')
			->willReturnOnConsecutiveCalls($medal1, $medal2);

		$result = $this->service->checkAndAwardMedals(1);

		$this->assertCount(2, $result);
	}
}
