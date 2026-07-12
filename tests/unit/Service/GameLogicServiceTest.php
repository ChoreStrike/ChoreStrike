<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Medal;
use OCA\ChoreStrike\Db\MedalMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Service\GameLogicService;
use OCA\ChoreStrike\Service\RewardConfigService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GameLogicService
 */
class GameLogicServiceTest extends TestCase
{
    private GameLogicService $service;
    private HouseUserMapper $houseUserMapper;
    private CompletedTaskMapper $completedTaskMapper;
    private MedalMapper $medalMapper;
    private RoomTaskMapper $roomTaskMapper;
    private RewardConfigService $configService;

    protected function setUp(): void
    {
        $this->houseUserMapper = $this->createMock(HouseUserMapper::class);
        $this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
        $this->medalMapper = $this->createMock(MedalMapper::class);
        $this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
        $this->configService = $this->createMock(RewardConfigService::class);

        $this->configService->method('getCoinBasePerEnergy')->willReturn(10);
        $this->configService->method('getEarlyBonusThresholdDays')->willReturn(21);
        $this->configService->method('getEarlyBonusMultiplier')->willReturn(1.5);

        $this->service = new GameLogicService(
            $this->houseUserMapper,
            $this->completedTaskMapper,
            $this->medalMapper,
            $this->roomTaskMapper,
            $this->configService
        );
    }

    /**
     * HandleTaskCompletion_ValidTask_ReturnsCompletionData
     */
    public function testHandleTaskCompletion_ValidTask_ReturnsCompletionData(): void
    {
        $roomTaskId = 1;
        $userId = 1;

        $task = new Task();
        $task->setId(1);
        $task->setEnergyCost(3);
        $task->setTaskTier('silver');

        $user = new HouseUser();
        $user->setId($userId);
        $user->setCurrentStreak(5);
        $user->setContributionScore(100);
        $user->setCoins(50);
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

        $roomTask = new RoomTask();
        $roomTask->setId($roomTaskId);
        $roomTask->setIdealDueDate(null);

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('find')
            ->with($roomTaskId)
            ->willReturn($roomTask);

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
        $this->assertIsInt($result['coins']);
        $this->assertTrue($result['isCombo']);
        $this->assertEquals(6, $result['currentStreak']);
    }

    /**
     * CalculateContributionScore_ReturnsEnergyCost
     */
    public function testCalculateContributionScore_ReturnsEnergyCost(): void
    {
        $task = new Task();
        $task->setEnergyCost(2);
        $task->setTaskTier('silver');

        $result = $this->service->calculateContributionScore($task, 70);

        // Simplified: contribution = energy cost
        $this->assertEquals(2, $result);
    }

    /**
     * CalculateCoins_BronzeTier_ReturnsBaseCoins
     */
    public function testCalculateCoins_BronzeTier_ReturnsBaseCoins(): void
    {
        $task = new Task();
        $task->setEnergyCost(3);
        $task->setTaskTier('bronze');

        $result = $this->service->calculateCoins($task, 1.0, false, 1.0);

        // 3 * 10 * 1.0 * 1.0 * 1.0 = 30
        $this->assertEquals(30, $result);
    }

    /**
     * CalculateCoins_SilverTier_ReturnsModerateCoins
     */
    public function testCalculateCoins_SilverTier_ReturnsModerateCoins(): void
    {
        $task = new Task();
        $task->setEnergyCost(3);
        $task->setTaskTier('silver');

        $result = $this->service->calculateCoins($task, 1.0, false, 1.0);

        // 3 * 10 * 1.5 * 1.0 * 1.0 = 45
        $this->assertEquals(45, $result);
    }

    /**
     * CalculateCoins_GoldTier_ReturnsHighCoins
     */
    public function testCalculateCoins_GoldTier_ReturnsHighCoins(): void
    {
        $task = new Task();
        $task->setEnergyCost(2);
        $task->setTaskTier('gold');

        $result = $this->service->calculateCoins($task, 1.0, false, 1.0);

        // 2 * 10 * 2.0 * 1.0 * 1.0 = 40
        $this->assertEquals(40, $result);
    }

    /**
     * CalculateCoins_PersonalMultiplier_ReturnsAdjustedCoins
     */
    public function testCalculateCoins_PersonalMultiplier_ReturnsAdjustedCoins(): void
    {
        $task = new Task();
        $task->setEnergyCost(2);
        $task->setTaskTier('silver');

        $result = $this->service->calculateCoins($task, 1.5, false, 1.0);

        // 2 * 10 * 1.5 * 1.5 * 1.0 = 45
        $this->assertEquals(45, $result);
    }

    /**
     * CalculateCoins_Combo_ReturnsDoubledCoins
     */
    public function testCalculateCoins_Combo_ReturnsDoubledCoins(): void
    {
        $task = new Task();
        $task->setEnergyCost(3);
        $task->setTaskTier('silver');

        $result = $this->service->calculateCoins($task, 1.0, true, 1.0);

        // 3 * 10 * 1.5 * 1.0 * 1.0 * 2 = 90
        $this->assertEquals(90, $result);
    }

    /**
     * CalculateCoins_EarlyBonus_ReturnsBonusCoins
     */
    public function testCalculateCoins_EarlyBonus_ReturnsBonusCoins(): void
    {
        $task = new Task();
        $task->setEnergyCost(2);
        $task->setTaskTier('silver');

        $result = $this->service->calculateCoins($task, 1.0, false, 1.5);

        // 2 * 10 * 1.5 * 1.0 * 1.5 = 45
        $this->assertEquals(45, $result);
    }

    /**
     * CalculateDaysEarly_NoIdealDueDate_ReturnsZero
     */
    public function testCalculateDaysEarly_NoIdealDueDate_ReturnsZero(): void
    {
        $roomTask = new RoomTask();
        $roomTask->setIdealDueDate(null);

        $result = $this->service->calculateDaysEarly($roomTask);

        $this->assertEquals(0, $result);
    }

    /**
     * CalculateDaysEarly_FutureDueDate_ReturnsPositiveDays
     */
    public function testCalculateDaysEarly_FutureDueDate_ReturnsPositiveDays(): void
    {
        $roomTask = new RoomTask();
        $futureDate = new \DateTime();
        $futureDate->add(new \DateInterval('P30D'));
        $roomTask->setIdealDueDate($futureDate);

        $result = $this->service->calculateDaysEarly($roomTask);

        // Should be approximately 30 days
        $this->assertGreaterThan(25, $result);
        $this->assertLessThan(35, $result);
    }

    /**
     * CalculateEarlyBonus_NoDaysEarly_ReturnsOne
     */
    public function testCalculateEarlyBonus_NoDaysEarly_ReturnsOne(): void
    {
        $result = $this->service->calculateEarlyBonus(0);

        $this->assertEquals(1.0, $result);
    }

    /**
     * CalculateEarlyBonus_BelowThreshold_ReturnsOne
     */
    public function testCalculateEarlyBonus_BelowThreshold_ReturnsOne(): void
    {
        $result = $this->service->calculateEarlyBonus(10);

        // 10 days < 21 threshold
        $this->assertEquals(1.0, $result);
    }

    /**
     * CalculateEarlyBonus_AboveThreshold_ReturnsMultiplier
     */
    public function testCalculateEarlyBonus_AboveThreshold_ReturnsMultiplier(): void
    {
        $result = $this->service->calculateEarlyBonus(25);

        // 25 days >= 21 threshold
        $this->assertEquals(1.5, $result);
    }

    /**
     * GetGameState_ReturnsUsers
     */
    public function testGetGameState_ReturnsUsers(): void
    {
        $houseId = 1;

        $user = new HouseUser();
        $user->setId(1);
        $user->setUserId('user123');
        $user->setDisplayName('Test User');

        $this->houseUserMapper
            ->expects($this->once())
            ->method('findByHouseId')
            ->with($houseId)
            ->willReturn([$user]);

        $result = $this->service->getGameState($houseId);

        $this->assertArrayHasKey('users', $result);
        $this->assertCount(1, $result['users']);
    }

    /**
     * GetLeaderboard_ReturnsSortedUsers
     */
    public function testGetLeaderboard_ReturnsSortedUsers(): void
    {
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
    public function testGetLeaderboard_WithCustomLimit_RespectsLimit(): void
    {
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
    public function testCheckAndAwardMedals_HighContributor_AwardsTeamAnchor(): void
    {
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
            ->willReturn(['taskCount' => 15, 'totalCoins' => 100]);

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
    public function testCheckAndAwardMedals_LongStreak_AwardsStreakKeeper(): void
    {
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
            ->willReturn(['taskCount' => 5, 'totalCoins' => 50]);

        $medal = new Medal();
        $medal->setId(1);
        $medal->setUserId(1);
        $medal->setMedalType('streak_keeper');

        $this->medalMapper
            ->expects($this->once())
            ->method('findByUserTypeAndWeek')
            ->willThrowException(new \Exception());

        $this->medalMapper
            ->expects($this->once())
            ->method('insert')
            ->willReturn($medal);

        $result = $this->service->checkAndAwardMedals($houseId);

        $this->assertCount(1, $result);
    }

    /**
     * GetMedals_ReturnsAllMedalsWithUserNames
     */
    public function testGetMedals_ReturnsAllMedalsWithUserNames(): void
    {
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
    public function testCalculatePoints_WithDifficultyAndPriority_ReturnsCorrectPoints(): void
    {
        $task = $this->createMock(Task::class);

        $points = $this->service->calculatePoints($task, 2, 3, false);

        $this->assertEquals(60, $points); // 2 * 3 * 10
    }

    /**
     * CalculatePoints_WithBonus_ReturnsIncreasedPoints
     */
    public function testCalculatePoints_WithBonus_ReturnsIncreasedPoints(): void
    {
        $task = $this->createMock(Task::class);

        $points = $this->service->calculatePoints($task, 2, 3, true);

        $this->assertEquals(90, $points); // (2 * 3 * 10) * 1.5
    }

    /**
     * CalculateStreakBonus_ReturnsCorrectBonus
     */
    public function testCalculateStreakBonus_ReturnsCorrectBonus(): void
    {
        $bonus = $this->service->calculateStreakBonus(5);

        $this->assertEquals(25, $bonus); // 5 * 5
    }

    /**
     * CalculateComboBonus_ReturnsCorrectBonus
     */
    public function testCalculateComboBonus_ReturnsCorrectBonus(): void
    {
        $bonus = $this->service->calculateComboBonus(3);

        $this->assertEquals(30, $bonus); // 3 * 10
    }

    /**
     * UpdateStreakCount_WithCompletionToday_IncrementsStreak
     */
    public function testUpdateStreakCount_WithCompletionToday_IncrementsStreak(): void
    {
        $newStreak = $this->service->updateStreakCount(5, true);

        $this->assertEquals(6, $newStreak);
    }

    /**
     * UpdateStreakCount_WithoutCompletionToday_ResetsStreak
     */
    public function testUpdateStreakCount_WithoutCompletionToday_ResetsStreak(): void
    {
        $newStreak = $this->service->updateStreakCount(5, false);

        $this->assertEquals(1, $newStreak);
    }

    /**
     * ShouldResetStreak_CompletedToday_ReturnsFalse
     */
    public function testShouldResetStreak_CompletedToday_ReturnsFalse(): void
    {
        $today = new \DateTime();
        $availability = ['monday' => true, 'tuesday' => true];

        $shouldReset = $this->service->shouldResetStreak($today, $availability);

        $this->assertFalse($shouldReset);
    }

    /**
     * ShouldResetStreak_CompletedYesterdayAvailableToday_ReturnsFalse
     */
    public function testShouldResetStreak_CompletedYesterdayAvailableToday_ReturnsFalse(): void
    {
        $yesterday = new \DateTime('-1 day');
        $todayName = strtolower((new \DateTime())->format('l'));
        $availability = [$todayName => true];

        $shouldReset = $this->service->shouldResetStreak($yesterday, $availability);

        $this->assertFalse($shouldReset);
    }

    /**
     * ShouldResetStreak_CompletedYesterdayNotAvailableToday_ReturnsTrue
     */
    public function testShouldResetStreak_CompletedYesterdayNotAvailableToday_ReturnsTrue(): void
    {
        $yesterday = new \DateTime('-1 day');
        $todayName = strtolower((new \DateTime())->format('l'));
        $availability = [$todayName => false];

        $shouldReset = $this->service->shouldResetStreak($yesterday, $availability);

        $this->assertTrue($shouldReset);
    }

    /**
     * ShouldResetStreak_CompletedTwoDaysAgo_ReturnsTrue
     */
    public function testShouldResetStreak_CompletedTwoDaysAgo_ReturnsTrue(): void
    {
        $twoDaysAgo = new \DateTime('-2 days');
        $availability = [];

        $shouldReset = $this->service->shouldResetStreak($twoDaysAgo, $availability);

        $this->assertTrue($shouldReset);
    }
}
