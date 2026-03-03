<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Medal;
use OCA\ChoreStrike\Db\MedalMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Service\GameLogicService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GameLogicService
 */
class GameLogicServiceTest extends TestCase {

	private GameLogicService $service;
	private HouseUserMapper $houseUserMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private MedalMapper $medalMapper;
	private RoomMapper $roomMapper;

	protected function setUp(): void {
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->medalMapper = $this->createMock(MedalMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);

		$this->service = new GameLogicService(
			$this->houseUserMapper,
			$this->completedTaskMapper,
			$this->medalMapper,
			$this->roomMapper
		);
	}

	/**
	 * HandleTaskCompletion_ValidTask_ReturnsCompletionData
	 */
	public function testHandleTaskCompletion_ValidTask_ReturnsCompletionData(): void {
		$roomTaskId = 1;
		$userId = 1;

		$task = new Task();
		$task->setId(1);
		$task->setEnergyCost(3);
		$task->setPriority(2);

		$user = new HouseUser();
		$user->setId($userId);
		$user->setCurrentStreak(5);
		$user->setContributionScore(100);
		$user->setTeamSparks(50);
		$user->setLastActivity(new \DateTime('yesterday'));

		$recentCompletions = [
			new CompletedTask(),
			new CompletedTask()
		];

		$this->houseUserMapper
			->expects($this->once())
			->method('find')
			->with($userId)
			->willReturn($user);

		$this->completedTaskMapper
			->expects($this->once())
			->method('findRecentByUserId')
			->with($userId, 5)
			->willReturn($recentCompletions);

		$this->completedTaskMapper
			->expects($this->once())
			->method('insert');

		$this->houseUserMapper
			->expects($this->once())
			->method('update');

		$result = $this->service->handleTaskCompletion($roomTaskId, $userId, $task, 50);

		$this->assertIsInt($result['contributionScore']);
		$this->assertIsInt($result['teamSparks']);
		$this->assertTrue($result['isCombo']);
		$this->assertEquals(6, $result['currentStreak']);
	}

	/**
	 * CalculateContributionScore_HighPriority_ReturnsIncreasedScore
	 */
	public function testCalculateContributionScore_HighPriority_ReturnsIncreasedScore(): void {
		$task = new Task();
		$task->setEnergyCost(2);
		$task->setPriority(3);

		$result = $this->service->calculateContributionScore($task, 70);

		// Base: 2 * 10 = 20, High priority: 20 * 1.5 = 30
		$this->assertEquals(30, $result);
	}

	/**
	 * CalculateContributionScore_MediumPriority_ReturnsModerateScore
	 */
	public function testCalculateContributionScore_MediumPriority_ReturnsModerateScore(): void {
		$task = new Task();
		$task->setEnergyCost(2);
		$task->setPriority(2);

		$result = $this->service->calculateContributionScore($task, 70);

		// Base: 2 * 10 = 20, Medium priority: 20 * 1.2 = 24
		$this->assertEquals(24, $result);
	}

	/**
	 * CalculateContributionScore_DirtyRoom_ReturnsIncreasedScore
	 */
	public function testCalculateContributionScore_DirtyRoom_ReturnsIncreasedScore(): void {
		$task = new Task();
		$task->setEnergyCost(2);
		$task->setPriority(1);

		$result = $this->service->calculateContributionScore($task, 30);

		// Base: 2 * 10 = 20, Dirty room (<40): 20 * 1.3 = 26
		$this->assertEquals(26, $result);
	}

	/**
	 * CalculateContributionScore_VeryDirtyRoom_ReturnsHigherScore
	 */
	public function testCalculateContributionScore_VeryDirtyRoom_ReturnsHigherScore(): void {
		$task = new Task();
		$task->setEnergyCost(2);
		$task->setPriority(1);

		$result = $this->service->calculateContributionScore($task, 10);

		// Base: 2 * 10 = 20, Very dirty room (<20): 20 * 1.5 = 30
		$this->assertEquals(30, $result);
	}

	/**
	 * CalculateTeamSparks_BaseCalculation_ReturnsSparks
	 */
	public function testCalculateTeamSparks_BaseCalculation_ReturnsSparks(): void {
		$task = new Task();
		$task->setEnergyCost(3);
		$task->setPriority(1);

		$result = $this->service->calculateTeamSparks($task, false);

		// 3 * 5 = 15
		$this->assertEquals(15, $result);
	}

	/**
	 * CalculateTeamSparks_Combo_ReturnsDoubledSparks
	 */
	public function testCalculateTeamSparks_Combo_ReturnsDoubledSparks(): void {
		$task = new Task();
		$task->setEnergyCost(3);
		$task->setPriority(1);

		$result = $this->service->calculateTeamSparks($task, true);

		// 3 * 5 * 2 = 30
		$this->assertEquals(30, $result);
	}

	/**
	 * CalculateTeamSparks_HighPriority_ReturnsBonus
	 */
	public function testCalculateTeamSparks_HighPriority_ReturnsBonus(): void {
		$task = new Task();
		$task->setEnergyCost(2);
		$task->setPriority(3);

		$result = $this->service->calculateTeamSparks($task, false);

		// 2 * 5 = 10 + 10 bonus = 20
		$this->assertEquals(20, $result);
	}

	/**
	 * CalculateMonsterCount_CleanRoom_ReturnsZero
	 */
	public function testCalculateMonsterCount_CleanRoom_ReturnsZero(): void {
		$result = $this->service->calculateMonsterCount(90);

		$this->assertEquals(0, $result);
	}

	/**
	 * CalculateMonsterCount_ModeratelyClean_ReturnsOne
	 */
	public function testCalculateMonsterCount_ModeratelyClean_ReturnsOne(): void {
		$result = $this->service->calculateMonsterCount(70);

		$this->assertEquals(1, $result);
	}

	/**
	 * CalculateMonsterCount_SomewhatDirty_ReturnsTwo
	 */
	public function testCalculateMonsterCount_SomewhatDirty_ReturnsTwo(): void {
		$result = $this->service->calculateMonsterCount(50);

		$this->assertEquals(2, $result);
	}

	/**
	 * CalculateMonsterCount_Dirty_ReturnsThree
	 */
	public function testCalculateMonsterCount_Dirty_ReturnsThree(): void {
		$result = $this->service->calculateMonsterCount(30);

		$this->assertEquals(3, $result);
	}

	/**
	 * CalculateMonsterCount_VeryDirty_ReturnsFour
	 */
	public function testCalculateMonsterCount_VeryDirty_ReturnsFour(): void {
		$result = $this->service->calculateMonsterCount(10);

		$this->assertEquals(4, $result);
	}

	/**
	 * GetMonsterType_Clean_ReturnsDustBunny
	 */
	public function testGetMonsterType_Clean_ReturnsDustBunny(): void {
		$result = $this->service->getMonsterType(70);

		$this->assertEquals('dust-bunny', $result);
	}

	/**
	 * GetMonsterType_Moderate_ReturnsSlimeBlob
	 */
	public function testGetMonsterType_Moderate_ReturnsSlimeBlob(): void {
		$result = $this->service->getMonsterType(50);

		$this->assertEquals('slime-blob', $result);
	}

	/**
	 * GetMonsterType_Dirty_ReturnsPixelSpider
	 */
	public function testGetMonsterType_Dirty_ReturnsPixelSpider(): void {
		$result = $this->service->getMonsterType(30);

		$this->assertEquals('pixel-spider', $result);
	}

	/**
	 * GetMonsterType_VeryDirty_ReturnsDustDemon
	 */
	public function testGetMonsterType_VeryDirty_ReturnsDustDemon(): void {
		$result = $this->service->getMonsterType(10);

		$this->assertEquals('dust-demon', $result);
	}

	/**
	 * GetGameState_ReturnsMonstersAndUsers
	 */
	public function testGetGameState_ReturnsMonstersAndUsers(): void {
		$houseId = 1;

		$user = new HouseUser();
		$user->setId(1);
		$user->setUserId('user123');
		$user->setDisplayName('Test User');

		$room = new Room();
		$room->setId(1);
		$room->setName('Kitchen');
		$room->setCleanliness(30);

		$this->houseUserMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$user]);

		$this->roomMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$room]);

		$result = $this->service->getGameState($houseId);

		$this->assertArrayHasKey('monsters', $result);
		$this->assertArrayHasKey('users', $result);
		$this->assertArrayHasKey('totalMonsters', $result);
		$this->assertGreaterThan(0, $result['totalMonsters']);
	}

	/**
	 * GetLeaderboard_ReturnsSortedUsers
	 */
	public function testGetLeaderboard_ReturnsSortedUsers(): void {
		$houseId = 1;

		$user1 = new HouseUser();
		$user1->setId(1);
		$user1->setContributionScore(100);

		$user2 = new HouseUser();
		$user2->setId(2);
		$user2->setContributionScore(50);

		$this->houseUserMapper
			->expects($this->once())
			->method('getLeaderboard')
			->with($houseId, 10)
			->willReturn([$user1, $user2]);

		$result = $this->service->getLeaderboard($houseId);

		$this->assertCount(2, $result);
		$this->assertEquals(100, $result[0]['contributionScore']);
	}

	/**
	 * GetLeaderboard_WithCustomLimit_RespectsLimit
	 */
	public function testGetLeaderboard_WithCustomLimit_RespectsLimit(): void {
		$houseId = 1;
		$limit = 5;

		$this->houseUserMapper
			->expects($this->once())
			->method('getLeaderboard')
			->with($houseId, $limit)
			->willReturn([]);

		$result = $this->service->getLeaderboard($houseId, $limit);

		$this->assertIsArray($result);
	}

	/**
	 * CheckAndAwardMedals_HighContributor_AwardsTeamAnchor
	 */
	public function testCheckAndAwardMedals_HighContributor_AwardsTeamAnchor(): void {
		$houseId = 1;

		$user = new HouseUser();
		$user->setId(1);
		$user->setCurrentStreak(3);

		$this->houseUserMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$user]);

		$this->completedTaskMapper
			->expects($this->once())
			->method('getUserStats')
			->willReturn(['taskCount' => 15, 'totalSparks' => 100]);

		$this->medalMapper
			->expects($this->once())
			->method('findByUserTypeAndWeek')
			->willThrowException(new \Exception());

		$this->medalMapper
			->expects($this->once())
			->method('insert')
			->willReturnCallback(function ($medal) {
				return $medal;
			});

		$result = $this->service->checkAndAwardMedals($houseId);

		$this->assertCount(1, $result);
		$this->assertEquals('team_anchor', $result[0]->getMedalType());
	}

	/**
	 * CheckAndAwardMedals_LongStreak_AwardsStreakKeeper
	 */
	public function testCheckAndAwardMedals_LongStreak_AwardsStreakKeeper(): void {
		$houseId = 1;

		$user = new HouseUser();
		$user->setId(1);
		$user->setCurrentStreak(10);

		$this->houseUserMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$user]);

		$this->completedTaskMapper
			->expects($this->once())
			->method('getUserStats')
			->willReturn(['taskCount' => 5, 'totalSparks' => 50]);

		$medal = new Medal();
		$medal->setId(1);
		$medal->setUserId(1);
		$medal->setMedalType('streak_keeper');

		$this->medalMapper
			->expects($this->exactly(2))
			->method('findByUserTypeAndWeek')
			->willThrowException(new \Exception());

		$this->medalMapper
			->expects($this->exactly(2))
			->method('insert')
			->willReturn($medal);

		$result = $this->service->checkAndAwardMedals($houseId);

		$this->assertCount(2, $result);
	}

	/**
	 * GetMedals_ReturnsAllMedalsWithUserNames
	 */
	public function testGetMedals_ReturnsAllMedalsWithUserNames(): void {
		$houseId = 1;

		$user = new HouseUser();
		$user->setId(1);
		$user->setDisplayName('Test User');

		$medal = new Medal();
		$medal->setId(1);
		$medal->setUserId(1);
		$medal->setMedalType('team_anchor');
		$medal->setAwardedAt(new \DateTime());

		$this->houseUserMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$user]);

		$this->medalMapper
			->expects($this->once())
			->method('findByUserId')
			->with(1)
			->willReturn([$medal]);

		$result = $this->service->getMedals($houseId);

		$this->assertCount(1, $result);
		$this->assertEquals('team_anchor', $result[0]['medalType']);
		$this->assertEquals('Test User', $result[0]['userName']);
	}

	/**
	 * CalculatePoints_WithDifficultyAndPriority_ReturnsCorrectPoints
	 */
	public function testCalculatePoints_WithDifficultyAndPriority_ReturnsCorrectPoints(): void {
		$task = $this->createMock(Task::class);
		
		$points = $this->service->calculatePoints($task, 2, 3, false);
		
		$this->assertEquals(60, $points); // 2 * 3 * 10
	}

	/**
	 * CalculatePoints_WithBonus_ReturnsIncreasedPoints
	 */
	public function testCalculatePoints_WithBonus_ReturnsIncreasedPoints(): void {
		$task = $this->createMock(Task::class);
		
		$points = $this->service->calculatePoints($task, 2, 3, true);
		
		$this->assertEquals(90, $points); // (2 * 3 * 10) * 1.5
	}

	/**
	 * CalculateStreakBonus_ReturnsCorrectBonus
	 */
	public function testCalculateStreakBonus_ReturnsCorrectBonus(): void {
		$bonus = $this->service->calculateStreakBonus(5);
		
		$this->assertEquals(25, $bonus); // 5 * 5
	}

	/**
	 * CalculateComboBonus_ReturnsCorrectBonus
	 */
	public function testCalculateComboBonus_ReturnsCorrectBonus(): void {
		$bonus = $this->service->calculateComboBonus(3);
		
		$this->assertEquals(30, $bonus); // 3 * 10
	}

	/**
	 * UpdateStreakCount_WithCompletionToday_IncrementsStreak
	 */
	public function testUpdateStreakCount_WithCompletionToday_IncrementsStreak(): void {
		$newStreak = $this->service->updateStreakCount(5, true);
		
		$this->assertEquals(6, $newStreak);
	}

	/**
	 * UpdateStreakCount_WithoutCompletionToday_ResetsStreak
	 */
	public function testUpdateStreakCount_WithoutCompletionToday_ResetsStreak(): void {
		$newStreak = $this->service->updateStreakCount(5, false);
		
		$this->assertEquals(1, $newStreak);
	}

	/**
	 * ShouldResetStreak_CompletedToday_ReturnsFalse
	 */
	public function testShouldResetStreak_CompletedToday_ReturnsFalse(): void {
		$today = new \DateTime();
		$availability = ['monday' => true, 'tuesday' => true];
		
		$shouldReset = $this->service->shouldResetStreak($today, $availability);
		
		$this->assertFalse($shouldReset);
	}

	/**
	 * ShouldResetStreak_CompletedYesterdayAvailableToday_ReturnsFalse
	 */
	public function testShouldResetStreak_CompletedYesterdayAvailableToday_ReturnsFalse(): void {
		$yesterday = new \DateTime('-1 day');
		$todayName = strtolower((new \DateTime())->format('l'));
		$availability = [$todayName => true];
		
		$shouldReset = $this->service->shouldResetStreak($yesterday, $availability);
		
		$this->assertFalse($shouldReset);
	}

	/**
	 * ShouldResetStreak_CompletedYesterdayNotAvailableToday_ReturnsTrue
	 */
	public function testShouldResetStreak_CompletedYesterdayNotAvailableToday_ReturnsTrue(): void {
		$yesterday = new \DateTime('-1 day');
		$todayName = strtolower((new \DateTime())->format('l'));
		$availability = [$todayName => false];
		
		$shouldReset = $this->service->shouldResetStreak($yesterday, $availability);
		
		$this->assertTrue($shouldReset);
	}

	/**
	 * ShouldResetStreak_CompletedTwoDaysAgo_ReturnsTrue
	 */
	public function testShouldResetStreak_CompletedTwoDaysAgo_ReturnsTrue(): void {
		$twoDaysAgo = new \DateTime('-2 days');
		$availability = [];
		
		$shouldReset = $this->service->shouldResetStreak($twoDaysAgo, $availability);
		
		$this->assertTrue($shouldReset);
	}
}
