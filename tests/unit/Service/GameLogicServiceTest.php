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
use OCA\ChoreStrike\Service\NotificationService;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCA\ChoreStrike\Service\TitleService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GameLogicService
 */
class GameLogicServiceTest extends TestCase {
	private GameLogicService $service;
	private HouseUserMapper $houseUserMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private MedalMapper $medalMapper;
	private RoomTaskMapper $roomTaskMapper;
	private RewardConfigService $configService;
	private NotificationService $notificationService;

	protected function setUp(): void {
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->medalMapper = $this->createMock(MedalMapper::class);
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->configService = $this->createMock(RewardConfigService::class);
		$this->notificationService = $this->createMock(NotificationService::class);

		$this->configService->method('getCoinBasePerEnergy')->willReturn(10);
		$this->configService->method('getEarlyBonusThresholdDays')->willReturn(21);
		$this->configService->method('getEarlyBonusMultiplier')->willReturn(1.5);
		$this->configService->method('getStreakFreezeCap')->willReturn(2);
		$this->configService->method('getComboBonusFreezeThreshold')->willReturn(5);
		$this->configService->method('getComboMultiplier')->willReturn(2.0);

		$this->service = new GameLogicService(
			$this->houseUserMapper,
			$this->completedTaskMapper,
			$this->medalMapper,
			$this->roomTaskMapper,
			$this->configService,
			$this->notificationService
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

		// CR-SVC-04: the house_user row must be locked (findForUpdate) inside
		// the completion path so concurrent same-user completions cannot
		// clobber each other's coins/streak update.
		$this->houseUserMapper
			->expects($this->once())
			->method('findForUpdate')
			->with($userId)
			->willReturn($user);

		$this->houseUserMapper->expects($this->never())->method('find');

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setIdealDueDate(null);

		$this->roomTaskMapper
			->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->completedTaskMapper
			->method('findRecentByUserId')
			->willReturnCallback(function ($uid, $limit) use ($recentCompletions) {
				return $recentCompletions;
			});

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
		$this->assertArrayHasKey('streakFreezeUsed', $result);
		$this->assertArrayHasKey('streakFreezesRemaining', $result);
		$this->assertArrayHasKey('comboBonusFreeze', $result);
	}

	/**
	 * HandleTaskCompletion_WithNextcloudTaskId_StampsCompletionRow
	 *
	 * CR-SVC-46: sync completions thread the Nextcloud task id through the
	 * canonical path so the inserted completion row carries it —
	 * findPendingSyncs() filters on completed_tasks.nextcloud_task_id and
	 * never saw un-stamped rows. Without the id the row stays un-stamped.
	 */
	public function testHandleTaskCompletion_WithNextcloudTaskId_StampsCompletionRow(): void {
		$task = new Task();
		$task->setId(1);
		$task->setEnergyCost(3);

		$user = new HouseUser();
		$user->setId(1);
		$user->setCurrentStreak(1);
		$user->setLastActivity(new \DateTime('yesterday'));

		$this->houseUserMapper->method('findForUpdate')->willReturn($user);

		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setIdealDueDate(null);
		$this->roomTaskMapper->method('find')->willReturn($roomTask);

		$this->completedTaskMapper->method('findRecentByUserId')->willReturn([]);

		$this->completedTaskMapper
			->expects($this->once())
			->method('insert')
			->with($this->callback(function (CompletedTask $completedTask): bool {
				return $completedTask->getNextcloudTaskId() === 4242;
			}));

		$this->service->handleTaskCompletion(1, 1, $task, 50, 4242);
	}

	/**
	 * HandleTaskCompletion_WithoutNextcloudTaskId_LeavesCompletionRowUnstamped
	 */
	public function testHandleTaskCompletion_WithoutNextcloudTaskId_LeavesCompletionRowUnstamped(): void {
		$task = new Task();
		$task->setId(1);
		$task->setEnergyCost(3);

		$user = new HouseUser();
		$user->setId(1);
		$user->setCurrentStreak(1);
		$user->setLastActivity(new \DateTime('yesterday'));

		$this->houseUserMapper->method('findForUpdate')->willReturn($user);

		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setIdealDueDate(null);
		$this->roomTaskMapper->method('find')->willReturn($roomTask);

		$this->completedTaskMapper->method('findRecentByUserId')->willReturn([]);

		$this->completedTaskMapper
			->expects($this->once())
			->method('insert')
			->with($this->callback(function (CompletedTask $completedTask): bool {
				return $completedTask->getNextcloudTaskId() === null;
			}));

		$this->service->handleTaskCompletion(1, 1, $task, 50);
	}

	/**
	 * CalculateContributionScore_ReturnsEnergyCost
	 *
	 * CR-SVC-57: the signature carries no cleanliness param — contribution
	 * is the documented energy-cost simplification.
	 */
	public function testCalculateContributionScore_ReturnsEnergyCost(): void {
		$task = new Task();
		$task->setEnergyCost(2);
		$task->setTaskTier('silver');

		$result = $this->service->calculateContributionScore($task);

		// Simplified: contribution = energy cost
		$this->assertEquals(2, $result);
	}

	/**
	 * CalculateCoins_BronzeTier_ReturnsBaseCoins
	 */
	public function testCalculateCoins_BronzeTier_ReturnsBaseCoins(): void {
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
	public function testCalculateCoins_SilverTier_ReturnsModerateCoins(): void {
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
	public function testCalculateCoins_GoldTier_ReturnsHighCoins(): void {
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
	public function testCalculateCoins_PersonalMultiplier_ReturnsAdjustedCoins(): void {
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
	public function testCalculateCoins_Combo_ReturnsDoubledCoins(): void {
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
	public function testCalculateCoins_EarlyBonus_ReturnsBonusCoins(): void {
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
	public function testCalculateDaysEarly_NoIdealDueDate_ReturnsZero(): void {
		$roomTask = new RoomTask();
		$roomTask->setIdealDueDate(null);

		$result = $this->service->calculateDaysEarly($roomTask);

		$this->assertEquals(0, $result);
	}

	/**
	 * CalculateDaysEarly_FutureDueDate_ReturnsPositiveDays
	 */
	public function testCalculateDaysEarly_FutureDueDate_ReturnsPositiveDays(): void {
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
	public function testCalculateEarlyBonus_NoDaysEarly_ReturnsOne(): void {
		$result = $this->service->calculateEarlyBonus(0);

		$this->assertEquals(1.0, $result);
	}

	/**
	 * CalculateEarlyBonus_BelowThreshold_ReturnsOne
	 */
	public function testCalculateEarlyBonus_BelowThreshold_ReturnsOne(): void {
		$result = $this->service->calculateEarlyBonus(10);

		// 10 days < 21 threshold
		$this->assertEquals(1.0, $result);
	}

	/**
	 * CalculateEarlyBonus_AboveThreshold_ReturnsMultiplier
	 */
	public function testCalculateEarlyBonus_AboveThreshold_ReturnsMultiplier(): void {
		$result = $this->service->calculateEarlyBonus(25);

		// 25 days >= 21 threshold
		$this->assertEquals(1.5, $result);
	}

	/**
	 * GetGameState_ReturnsUsers
	 */
	public function testGetGameState_ReturnsUsers(): void {
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
			->willReturn(['taskCount' => 15, 'totalCoins' => 100]);

		$this->medalMapper
			->expects($this->once())
			->method('findByUserTypeAndWeek')
			->willThrowException(new DoesNotExistException());

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
			->willReturn(['taskCount' => 5, 'totalCoins' => 50]);

		$medal = new Medal();
		$medal->setId(1);
		$medal->setUserId(1);
		$medal->setMedalType('streak_keeper');

		$this->medalMapper
			->expects($this->once())
			->method('findByUserTypeAndWeek')
			->willThrowException(new DoesNotExistException());

		$this->medalMapper
			->expects($this->once())
			->method('insert')
			->willReturn($medal);

		$result = $this->service->checkAndAwardMedals($houseId);

		$this->assertCount(1, $result);
	}

	/**
	 * CheckAndAwardMedals_NewMedal_NotifiesRecipient
	 *
	 * A newly-awarded medal must dispatch a server-side notification to the
	 * recipient's Nextcloud account (bell + mobile feed). The lookup uses
	 * the house user's nextcloud_user_id.
	 */
	public function testCheckAndAwardMedals_NewMedal_NotifiesRecipient(): void {
		$houseId = 1;

		$user = new HouseUser();
		$user->setId(7);
		$user->setNextcloudUserId('alice');
		$user->setCurrentStreak(3);

		$this->houseUserMapper->method('findByHouseId')->willReturn([$user]);
		$this->completedTaskMapper->method('getUserStats')
			->willReturn(['taskCount' => 15, 'totalCoins' => 100]);
		$this->medalMapper->method('findByUserTypeAndWeek')
			->willThrowException(new DoesNotExistException());
		$this->medalMapper->method('insert')->willReturnArgument(0);

		$this->notificationService->expects($this->once())
			->method('notifyMedalAwarded')
			->with('alice', 'team_anchor', 15, $this->isType('string'));

		$this->service->checkAndAwardMedals($houseId);
	}

	/**
	 * CheckAndAwardMedals_ExistingMedal_DoesNotNotifyAgain
	 *
	 * Idempotency: re-checking medals in a week that already saw an award
	 * must NOT re-notify the user. The awardMedalIfNew helper returns null
	 * for already-existing medals and skips the dispatch.
	 */
	public function testCheckAndAwardMedals_ExistingMedal_DoesNotNotifyAgain(): void {
		$houseId = 1;

		$user = new HouseUser();
		$user->setId(7);
		$user->setNextcloudUserId('alice');
		$user->setCurrentStreak(10);

		$existingMedal = new Medal();
		$existingMedal->setId(99);
		$existingMedal->setMedalType('streak_keeper');
		$existingMedal->setUserId(7);

		$this->houseUserMapper->method('findByHouseId')->willReturn([$user]);
		$this->completedTaskMapper->method('getUserStats')
			->willReturn(['taskCount' => 5, 'totalCoins' => 50]);
		$this->medalMapper->method('findByUserTypeAndWeek')->willReturn($existingMedal);
		$this->medalMapper->expects($this->never())->method('insert');

		$this->notificationService->expects($this->never())->method('notifyMedalAwarded');

		$result = $this->service->checkAndAwardMedals($houseId);

		// Existing medal returns null from awardMedalIfNew → not collected.
		$this->assertCount(0, $result);
	}

	/**
	 * CheckAndAwardMedals_InsertUniqueViolation_TreatsAsExistingAndSkipsNotify
	 *
	 * Race-loser scenario: two concurrent medal checks both find no existing
	 * medal; the loser's insert throws a unique-constraint violation. The
	 * new awardMedalIfNew catches it via OCP\DB\Exception and returns null
	 * (treats the medal as already-existing), so the API call doesn't fail
	 * and no duplicate notification is dispatched.
	 */
	public function testCheckAndAwardMedals_InsertUniqueViolation_TreatsAsExistingAndSkipsNotify(): void {
		$houseId = 1;

		$user = new HouseUser();
		$user->setId(7);
		$user->setNextcloudUserId('alice');
		$user->setCurrentStreak(3);

		$this->houseUserMapper->method('findByHouseId')->willReturn([$user]);
		$this->completedTaskMapper->method('getUserStats')
			->willReturn(['taskCount' => 15, 'totalCoins' => 100]);
		$this->medalMapper->method('findByUserTypeAndWeek')
			->willThrowException(new DoesNotExistException());
		$this->medalMapper->method('insert')
			->willThrowException(new \OCP\DB\Exception(
				'unique constraint',
				0,
				null,
				\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION
			));

		$this->notificationService->expects($this->never())->method('notifyMedalAwarded');

		$result = $this->service->checkAndAwardMedals($houseId);

		$this->assertCount(0, $result);
	}

	/**
	 * CheckAndAwardMedals_InsertNonUniqueDbError_Propagates
	 *
	 * A non-unique-constraint DB error during medal insert (e.g. FK
	 * violation, deadlock, connection loss) must propagate — the medal is
	 * not silently swallowed as if it were a race-loser. Regression for the
	 * over-broad catch.
	 */
	public function testCheckAndAwardMedals_InsertNonUniqueDbError_Propagates(): void {
		$houseId = 1;

		$user = new HouseUser();
		$user->setId(7);
		$user->setNextcloudUserId('alice');
		$user->setCurrentStreak(3);

		$this->houseUserMapper->method('findByHouseId')->willReturn([$user]);
		$this->completedTaskMapper->method('getUserStats')
			->willReturn(['taskCount' => 15, 'totalCoins' => 100]);
		$this->medalMapper->method('findByUserTypeAndWeek')
			->willThrowException(new DoesNotExistException());
		$this->medalMapper->method('insert')
			->willThrowException(new \OCP\DB\Exception(
				'connection lost',
				0,
				null,
				\OCP\DB\Exception::REASON_OTHER
			));

		$this->notificationService->expects($this->never())->method('notifyMedalAwarded');
		$this->expectException(\OCP\DB\Exception::class);

		$this->service->checkAndAwardMedals($houseId);
	}

	/**
	 * CheckAndAwardMedals_FrequentDailyActivity_AwardsFlowKeeper
	 */
	public function testCheckAndAwardMedals_FrequentDailyActivity_AwardsFlowKeeper(): void {
		$houseId = 1;

		$user = new HouseUser();
		$user->setId(1);
		$user->setCurrentStreak(3);

		$this->houseUserMapper->method('findByHouseId')->willReturn([$user]);
		$this->completedTaskMapper->method('getUserStats')
			->willReturn(['taskCount' => 2, 'totalCoins' => 20, 'comboCount' => 0, 'activeDays' => 4]);
		$this->completedTaskMapper->method('getMaxRoomCompletionCountByUser')
			->willReturn(2);

		$this->medalMapper->method('findByUserTypeAndWeek')
			->willThrowException(new DoesNotExistException());
		$this->medalMapper->method('insert')->willReturnArgument(0);

		$result = $this->service->checkAndAwardMedals($houseId);

		$this->assertCount(1, $result);
		$this->assertEquals('flow_keeper', $result[0]->getMedalType());
		$this->assertEquals(4, $result[0]->getValue());
	}

	/**
	 * CheckAndAwardMedals_BelowFlowKeeperThreshold_NoAward
	 */
	public function testCheckAndAwardMedals_BelowFlowKeeperThreshold_NoAward(): void {
		$houseId = 1;

		$user = new HouseUser();
		$user->setId(1);
		$user->setCurrentStreak(3);

		$this->houseUserMapper->method('findByHouseId')->willReturn([$user]);
		$this->completedTaskMapper->method('getUserStats')
			->willReturn(['taskCount' => 2, 'totalCoins' => 20, 'comboCount' => 0, 'activeDays' => 3]);
		$this->completedTaskMapper->method('getMaxRoomCompletionCountByUser')
			->willReturn(2);

		$this->medalMapper->expects($this->never())->method('findByUserTypeAndWeek');
		$this->medalMapper->expects($this->never())->method('insert');

		$result = $this->service->checkAndAwardMedals($houseId);

		$this->assertCount(0, $result);
	}

	/**
	 * CheckAndAwardMedals_RoomConcentration_AwardsRoomGuardian
	 */
	public function testCheckAndAwardMedals_RoomConcentration_AwardsRoomGuardian(): void {
		$houseId = 1;

		$user = new HouseUser();
		$user->setId(1);
		$user->setCurrentStreak(3);

		$this->houseUserMapper->method('findByHouseId')->willReturn([$user]);
		$this->completedTaskMapper->method('getUserStats')
			->willReturn(['taskCount' => 2, 'totalCoins' => 20, 'comboCount' => 0, 'activeDays' => 2]);
		$this->completedTaskMapper->method('getMaxRoomCompletionCountByUser')
			->willReturn(5);

		$this->medalMapper->method('findByUserTypeAndWeek')
			->willThrowException(new DoesNotExistException());
		$this->medalMapper->method('insert')->willReturnArgument(0);

		$result = $this->service->checkAndAwardMedals($houseId);

		$this->assertCount(1, $result);
		$this->assertEquals('room_guardian', $result[0]->getMedalType());
		$this->assertEquals(5, $result[0]->getValue());
	}

	/**
	 * CheckAndAwardMedals_BelowRoomGuardianThreshold_NoAward
	 */
	public function testCheckAndAwardMedals_BelowRoomGuardianThreshold_NoAward(): void {
		$houseId = 1;

		$user = new HouseUser();
		$user->setId(1);
		$user->setCurrentStreak(3);

		$this->houseUserMapper->method('findByHouseId')->willReturn([$user]);
		$this->completedTaskMapper->method('getUserStats')
			->willReturn(['taskCount' => 2, 'totalCoins' => 20, 'comboCount' => 0, 'activeDays' => 2]);
		$this->completedTaskMapper->method('getMaxRoomCompletionCountByUser')
			->willReturn(4);

		$this->medalMapper->expects($this->never())->method('findByUserTypeAndWeek');
		$this->medalMapper->expects($this->never())->method('insert');

		$result = $this->service->checkAndAwardMedals($houseId);

		$this->assertCount(0, $result);
	}

	/**
	 * CheckAndAwardMedals_ManyCombos_AwardsTeamPlayer
	 */
	public function testCheckAndAwardMedals_ManyCombos_AwardsTeamPlayer(): void {
		$houseId = 1;

		$user = new HouseUser();
		$user->setId(1);
		$user->setCurrentStreak(3);

		$this->houseUserMapper->method('findByHouseId')->willReturn([$user]);
		$this->completedTaskMapper->method('getUserStats')
			->willReturn(['taskCount' => 2, 'totalCoins' => 20, 'comboCount' => 3, 'activeDays' => 2]);
		$this->completedTaskMapper->method('getMaxRoomCompletionCountByUser')
			->willReturn(2);

		$this->medalMapper->method('findByUserTypeAndWeek')
			->willThrowException(new DoesNotExistException());
		$this->medalMapper->method('insert')->willReturnArgument(0);

		$result = $this->service->checkAndAwardMedals($houseId);

		$this->assertCount(1, $result);
		$this->assertEquals('team_player', $result[0]->getMedalType());
		$this->assertEquals(3, $result[0]->getValue());
	}

	/**
	 * CheckAndAwardMedals_BelowTeamPlayerThreshold_NoAward
	 */
	public function testCheckAndAwardMedals_BelowTeamPlayerThreshold_NoAward(): void {
		$houseId = 1;

		$user = new HouseUser();
		$user->setId(1);
		$user->setCurrentStreak(3);

		$this->houseUserMapper->method('findByHouseId')->willReturn([$user]);
		$this->completedTaskMapper->method('getUserStats')
			->willReturn(['taskCount' => 2, 'totalCoins' => 20, 'comboCount' => 2, 'activeDays' => 2]);
		$this->completedTaskMapper->method('getMaxRoomCompletionCountByUser')
			->willReturn(2);

		$this->medalMapper->expects($this->never())->method('findByUserTypeAndWeek');
		$this->medalMapper->expects($this->never())->method('insert');

		$result = $this->service->checkAndAwardMedals($houseId);

		$this->assertCount(0, $result);
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
	 * HandleTaskCompletion_AdvancesLongestStreak_ToEffectiveStreak
	 *
	 * The longest streak must track the effective (post-freeze) current
	 * streak, because team_energy title unlocks read longestStreak.
	 */
	public function testHandleTaskCompletion_AdvancesLongestStreak_ToEffectiveStreak(): void {
		$roomTaskId = 1;
		$userId = 1;

		$task = new Task();
		$task->setId(1);
		$task->setEnergyCost(3);
		$task->setTaskTier('bronze');

		$user = new HouseUser();
		$user->setId($userId);
		$user->setCurrentStreak(5);
		$user->setLongestStreak(3);
		$user->setLastActivity(new \DateTime('yesterday'));

		$this->houseUserMapper->method('findForUpdate')->willReturn($user);

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setIdealDueDate(null);
		$this->roomTaskMapper->method('find')->willReturn($roomTask);

		$this->completedTaskMapper->method('findRecentByUserId')->willReturn([]);
		$this->completedTaskMapper->expects($this->once())->method('insert');
		$this->houseUserMapper->expects($this->once())->method('update');

		$this->service->handleTaskCompletion($roomTaskId, $userId, $task, 50);

		// Streak advanced 5 → 6 (yesterday completion), longest tracks it.
		$this->assertEquals(6, $user->getLongestStreak());
	}

	/**
	 * HandleTaskCompletion_StreakWouldReset_FreezeConsumed
	 */
	public function testHandleTaskCompletion_StreakWouldReset_FreezeConsumed(): void {
		$roomTaskId = 1;
		$userId = 1;

		$task = new Task();
		$task->setId(1);
		$task->setEnergyCost(3);
		$task->setTaskTier('bronze');

		$user = new HouseUser();
		$user->setId($userId);
		$user->setCurrentStreak(10);
		$user->setStreakFreezes(2);
		$user->setLastActivity(new \DateTime('-3 days'));

		$this->houseUserMapper->method('findForUpdate')->willReturn($user);

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setIdealDueDate(null);
		$this->roomTaskMapper->method('find')->willReturn($roomTask);

		$this->completedTaskMapper->method('findRecentByUserId')->willReturn([]);
		$this->completedTaskMapper->expects($this->once())->method('insert');
		$this->houseUserMapper->expects($this->once())->method('update');

		$result = $this->service->handleTaskCompletion($roomTaskId, $userId, $task, 50);

		$this->assertTrue($result['streakFreezeUsed']);
		$this->assertEquals(1, $result['streakFreezesRemaining']);
		$this->assertEquals(10, $result['currentStreak']);
	}

	/**
	 * HandleTaskCompletion_StreakWouldReset_NoFreezes_ResetsStreak
	 */
	public function testHandleTaskCompletion_StreakWouldReset_NoFreezes_ResetsStreak(): void {
		$roomTaskId = 1;
		$userId = 1;

		$task = new Task();
		$task->setId(1);
		$task->setEnergyCost(3);
		$task->setTaskTier('bronze');

		$user = new HouseUser();
		$user->setId($userId);
		$user->setCurrentStreak(10);
		$user->setStreakFreezes(0);
		$user->setLastActivity(new \DateTime('-3 days'));

		$this->houseUserMapper->method('findForUpdate')->willReturn($user);

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setIdealDueDate(null);
		$this->roomTaskMapper->method('find')->willReturn($roomTask);

		$this->completedTaskMapper->method('findRecentByUserId')->willReturn([]);
		$this->completedTaskMapper->expects($this->once())->method('insert');
		$this->houseUserMapper->expects($this->once())->method('update');

		$result = $this->service->handleTaskCompletion($roomTaskId, $userId, $task, 50);

		$this->assertFalse($result['streakFreezeUsed']);
		$this->assertEquals(0, $result['streakFreezesRemaining']);
		$this->assertEquals(1, $result['currentStreak']);
	}

	/**
	 * HandleTaskCompletion_ComboBonus_AwardsFreeze
	 */
	public function testHandleTaskCompletion_ComboBonus_AwardsFreeze(): void {
		$roomTaskId = 1;
		$userId = 1;

		$task = new Task();
		$task->setId(1);
		$task->setEnergyCost(3);
		$task->setTaskTier('bronze');

		$user = new HouseUser();
		$user->setId($userId);
		$user->setCurrentStreak(5);
		$user->setStreakFreezes(0);
		$user->setLastActivity(new \DateTime('yesterday'));

		$this->houseUserMapper->method('findForUpdate')->willReturn($user);

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setIdealDueDate(null);
		$this->roomTaskMapper->method('find')->willReturn($roomTask);

		$recentCompletions = array_fill(0, 6, new CompletedTask());
		$this->completedTaskMapper
			->method('findRecentByUserId')
			->willReturnCallback(function ($uid, $limit) use ($recentCompletions) {
				if ($limit === 5) {
					return array_slice($recentCompletions, 0, 5);
				}
				return $recentCompletions;
			});

		$this->completedTaskMapper->expects($this->once())->method('insert');
		$this->houseUserMapper->expects($this->once())->method('update');

		$result = $this->service->handleTaskCompletion($roomTaskId, $userId, $task, 50);

		$this->assertTrue($result['isCombo']);
		$this->assertTrue($result['comboBonusFreeze']);
		$this->assertEquals(1, $result['streakFreezesRemaining']);
	}

	/**
	 * CalculateCoins_Combo_UsesConfigMultiplier
	 *
	 * The combo multiplier is sourced from RewardConfigService (default 2.0),
	 * not hardcoded.
	 */
	public function testCalculateCoins_Combo_UsesConfigMultiplier(): void {
		$task = new Task();
		$task->setEnergyCost(3);
		$task->setTaskTier('silver');

		$this->configService = $this->createMock(RewardConfigService::class);
		$this->configService->method('getCoinBasePerEnergy')->willReturn(10);
		$this->configService->method('getComboMultiplier')->willReturn(3.0);

		$service = new GameLogicService(
			$this->houseUserMapper,
			$this->completedTaskMapper,
			$this->medalMapper,
			$this->roomTaskMapper,
			$this->configService,
			$this->notificationService
		);

		$result = $service->calculateCoins($task, 1.0, true, 1.0);

		// 3 * 10 * 1.5 * 1.0 * 1.0 * 3.0 = 135
		$this->assertEquals(135, $result);
	}

	/**
	 * Build a GameLogicService wired with a TitleService + a config that has
	 * titles enabled, reusing the mocked mappers from setUp.
	 */
	private function createServiceWithTitles(TitleService $titleService): GameLogicService {
		$this->configService = $this->createMock(RewardConfigService::class);
		$this->configService->method('getCoinBasePerEnergy')->willReturn(10);
		$this->configService->method('getEarlyBonusThresholdDays')->willReturn(21);
		$this->configService->method('getEarlyBonusMultiplier')->willReturn(1.5);
		$this->configService->method('getStreakFreezeCap')->willReturn(2);
		$this->configService->method('getComboBonusFreezeThreshold')->willReturn(5);
		$this->configService->method('getComboMultiplier')->willReturn(2.0);
		$this->configService->method('getTitlesEnabled')->willReturn(true);

		return new GameLogicService(
			$this->houseUserMapper,
			$this->completedTaskMapper,
			$this->medalMapper,
			$this->roomTaskMapper,
			$this->configService,
			$this->notificationService,
			$titleService
		);
	}

	/**
	 * HandleTaskCompletion_NewTitleUnlock_ReturnsPayloadWithoutDispatching
	 *
	 * CR-SVC-12: the title-unlock notification is deferred past commit, so
	 * handleTaskCompletion must return the new-title payload WITHOUT
	 * dispatching notifyTitleUnlocked (RoomService dispatches it after commit).
	 */
	public function testHandleTaskCompletion_NewTitleUnlock_ReturnsPayloadWithoutDispatching(): void {
		$titleService = $this->createMock(TitleService::class);
		$newTitle = [
			'pack' => 'home_helpers',
			'packLabelKey' => 'titles.pack.home_helpers',
			'tierIndex' => 0,
			'tierKey' => 'helper',
			'labelKey' => 'titles.home_helpers.helper',
			'threshold' => 50,
		];
		$titleService->expects($this->once())
			->method('detectUnlock')
			->willReturn([$newTitle]);

		$service = $this->createServiceWithTitles($titleService);

		$roomTaskId = 1;
		$userId = 1;

		$task = new Task();
		$task->setId(1);
		$task->setEnergyCost(3);
		$task->setTaskTier('bronze');

		$user = new HouseUser();
		$user->setId($userId);
		$user->setNextcloudUserId('alice');
		$user->setCurrentStreak(5);
		$user->setLastActivity(new \DateTime('yesterday'));

		$this->houseUserMapper->method('findForUpdate')->willReturn($user);
		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setIdealDueDate(null);
		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->completedTaskMapper->method('findRecentByUserId')->willReturn([]);
		$this->completedTaskMapper->expects($this->once())->method('insert');
		$this->houseUserMapper->expects($this->once())->method('update');

		// The service must NOT dispatch the notification itself (deferred to
		// RoomService, after commit).
		$this->notificationService->expects($this->never())->method('notifyTitleUnlocked');

		$result = $service->handleTaskCompletion($roomTaskId, $userId, $task, 50);

		// ...but it must surface the payload so RoomService can dispatch it.
		$this->assertSame($newTitle, $result['newTitle']);
	}

	/**
	 * HandleTaskCompletion_NoThresholdCrossed_DoesNotNotify
	 */
	public function testHandleTaskCompletion_NoThresholdCrossed_DoesNotNotify(): void {
		$titleService = $this->createMock(TitleService::class);
		$titleService->expects($this->once())
			->method('detectUnlock')
			->willReturn([]);

		$service = $this->createServiceWithTitles($titleService);

		$roomTaskId = 1;
		$userId = 1;

		$task = new Task();
		$task->setId(1);
		$task->setEnergyCost(3);
		$task->setTaskTier('bronze');

		$user = new HouseUser();
		$user->setId($userId);
		$user->setNextcloudUserId('alice');
		$user->setCurrentStreak(5);
		$user->setLastActivity(new \DateTime('yesterday'));

		$this->houseUserMapper->method('findForUpdate')->willReturn($user);
		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setIdealDueDate(null);
		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->completedTaskMapper->method('findRecentByUserId')->willReturn([]);
		$this->completedTaskMapper->expects($this->once())->method('insert');
		$this->houseUserMapper->expects($this->once())->method('update');

		$this->notificationService->expects($this->never())->method('notifyTitleUnlocked');

		$result = $service->handleTaskCompletion($roomTaskId, $userId, $task, 50);

		$this->assertNull($result['newTitle']);
	}

	/**
	 * HandleTaskCompletion_TitlesDisabled_SkipsDetection
	 */
	public function testHandleTaskCompletion_TitlesDisabled_SkipsDetection(): void {
		$titleService = $this->createMock(TitleService::class);
		$titleService->expects($this->never())->method('detectUnlock');

		$service = $this->createServiceWithTitles($titleService);
		// Flip the feature flag off after construction.
		$this->configService = $this->createMock(RewardConfigService::class);
		$this->configService->method('getTitlesEnabled')->willReturn(false);
		// Rebuild with disabled flag (other config methods use defaults that
		// are not reached when titles are disabled — but the coin calc still
		// runs, so re-stub the coin getters).
		$this->configService->method('getCoinBasePerEnergy')->willReturn(10);
		$this->configService->method('getEarlyBonusThresholdDays')->willReturn(21);
		$this->configService->method('getEarlyBonusMultiplier')->willReturn(1.5);
		$this->configService->method('getStreakFreezeCap')->willReturn(2);
		$this->configService->method('getComboBonusFreezeThreshold')->willReturn(5);
		$this->configService->method('getComboMultiplier')->willReturn(2.0);
		$service = new GameLogicService(
			$this->houseUserMapper,
			$this->completedTaskMapper,
			$this->medalMapper,
			$this->roomTaskMapper,
			$this->configService,
			$this->notificationService,
			$titleService
		);

		$roomTaskId = 1;
		$userId = 1;

		$task = new Task();
		$task->setId(1);
		$task->setEnergyCost(3);
		$task->setTaskTier('bronze');

		$user = new HouseUser();
		$user->setId($userId);
		$user->setNextcloudUserId('alice');
		$user->setCurrentStreak(5);
		$user->setLastActivity(new \DateTime('yesterday'));

		$this->houseUserMapper->method('findForUpdate')->willReturn($user);
		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setIdealDueDate(null);
		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->completedTaskMapper->method('findRecentByUserId')->willReturn([]);
		$this->completedTaskMapper->expects($this->once())->method('insert');
		$this->houseUserMapper->expects($this->once())->method('update');

		$result = $service->handleTaskCompletion($roomTaskId, $userId, $task, 50);

		$this->assertNull($result['newTitle']);
	}
}
