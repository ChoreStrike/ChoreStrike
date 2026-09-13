<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Service\GameLogicService;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCA\ChoreStrike\Service\TitleService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GameLogicService — the completion-reward orchestration.
 * The weekly medal cluster lives in MedalServiceTest (CS-PLAN-067 §3.2).
 */
class GameLogicServiceTest extends TestCase {
	private GameLogicService $service;
	private HouseUserMapper $houseUserMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private RewardConfigService $configService;

	protected function setUp(): void {
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->configService = $this->createMock(RewardConfigService::class);

		$this->configService->method('getCoinBasePerEnergy')->willReturn(10);
		$this->configService->method('getEarlyBonusThresholdDays')->willReturn(21);
		$this->configService->method('getEarlyBonusMultiplier')->willReturn(1.5);
		$this->configService->method('getStreakFreezeCap')->willReturn(2);
		$this->configService->method('getComboBonusFreezeThreshold')->willReturn(5);
		$this->configService->method('getComboMultiplier')->willReturn(2.0);

		$this->service = $this->buildService(null);
	}

	private function buildService(?TitleService $titleService): GameLogicService {
		return new GameLogicService(
			$this->houseUserMapper,
			$this->completedTaskMapper,
			$this->configService,
			$titleService
		);
	}

	/**
	 * Build a CompletedTask seeded with a completed_at offset from now.
	 *
	 * The merged combo-window fetch (CR-SVC-50) filters on the timestamp,
	 * so completion fixtures must carry a real completed_at.
	 */
	private function completionAt(string $modifier): CompletedTask {
		$completion = new CompletedTask();
		$completion->setCompletedAt(new \DateTime($modifier));
		return $completion;
	}

	/**
	 * Build a minimal RoomTask for handleTaskCompletion (the locked entity
	 * is threaded in by the caller since CS-PLAN-036).
	 */
	private function roomTaskEntity(int $roomTaskId): RoomTask {
		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setIdealDueDate(null);
		return $roomTask;
	}

	/**
	 * CS-PLAN-067 §3.2: the caller-owned ledger row is REQUIRED — every
	 * handleTaskCompletion fixture threads one (the production caller is
	 * CompletionService::complete).
	 */
	private function ledgerRow(): CompletedTask {
		return new CompletedTask();
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
			$this->completionAt('-1 minute'),
			$this->completionAt('-2 minutes'),
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

		// CS-PLAN-036: ONE pre-insert 10-minute window fetch replaces the old
		// 5-minute + post-insert 10-minute query pair (CR-SVC-50).
		$this->completedTaskMapper
			->expects($this->once())
			->method('findRecentByUserId')
			->with($userId, 10)
			->willReturn($recentCompletions);

		// CS-PLAN-067 §3.2: handleTaskCompletion NEVER inserts the ledger —
		// the caller (CompletionService) owns exactly one insert.
		$this->completedTaskMapper
			->expects($this->never())
			->method('insert');

		$this->houseUserMapper
			->expects($this->once())
			->method('update');

		$result = $this->service->handleTaskCompletion($this->roomTaskEntity($roomTaskId), $userId, $task, null, [], $this->ledgerRow());

		$this->assertIsInt($result['contributionScore']);
		$this->assertIsInt($result['coins']);
		$this->assertTrue($result['isCombo']);
		$this->assertEquals(6, $result['currentStreak']);
		$this->assertArrayHasKey('streakFreezeUsed', $result);
		$this->assertArrayHasKey('streakFreezesRemaining', $result);
		$this->assertArrayHasKey('comboBonusFreeze', $result);
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
		$roomTask->setIdealDueDate(new \DateTime('2026-05-15T00:00:00+02:00'));

		$result = $this->service->calculateDaysEarly($roomTask, new \DateTime('2026-04-15T10:30:00+02:00'));

		$this->assertSame(30, $result);
	}

	/**
	 * CalculateDaysEarly_NearMidnightCompletion_CountsCalendarDays
	 *
	 * CR-SVC-32 regression: one minute before midnight the raw-second math
	 * rounded the diff to 0 days; the calendar-day diff counts the boundary.
	 */
	public function testCalculateDaysEarly_NearMidnightCompletion_CountsCalendarDays(): void {
		$roomTask = new RoomTask();
		$roomTask->setIdealDueDate(new \DateTime('2026-05-11T00:00:00+02:00'));

		// 2026-05-10 23:59 → next calendar day is the due day: 1 day early.
		$result = $this->service->calculateDaysEarly($roomTask, new \DateTime('2026-05-10T23:59:00+02:00'));
		$this->assertSame(1, $result);

		// Same calendar day as the due date: 0 days early, whatever the time.
		$sameDay = $this->service->calculateDaysEarly($roomTask, new \DateTime('2026-05-11T23:59:00+02:00'));
		$this->assertSame(0, $sameDay);
	}

	/**
	 * CalculateDaysEarly_LateCompletion_ReturnsNegativeCalendarDays
	 */
	public function testCalculateDaysEarly_LateCompletion_ReturnsNegativeCalendarDays(): void {
		$roomTask = new RoomTask();
		$roomTask->setIdealDueDate(new \DateTime('2026-05-10T00:00:00+02:00'));

		$result = $this->service->calculateDaysEarly($roomTask, new \DateTime('2026-05-12T08:00:00+02:00'));

		$this->assertSame(-2, $result);
	}

	/**
	 * CalculateDaysEarly_DstTransitionWeekend_DeterministicCalendarDiff
	 *
	 * CR-SVC-32 regression: Europe/Berlin springs forward on 2026-03-29
	 * (02:00 → 03:00), so that week has a 23-hour day. A completion on the
	 * transition day itself must count 0 days early even though only 21
	 * wall-clock hours remain until 23:00 — raw-second division rounded
	 * that to 1.
	 */
	public function testCalculateDaysEarly_DstTransitionWeekend_DeterministicCalendarDiff(): void {
		$tz = new \DateTimeZone('Europe/Berlin');

		$roomTask = new RoomTask();
		$roomTask->setIdealDueDate(new \DateTime('2026-03-29T23:00:00', $tz));

		// Completion on the DST transition day: same calendar day → 0.
		$result = $this->service->calculateDaysEarly($roomTask, new \DateTime('2026-03-29T01:00:00', $tz));
		$this->assertSame(0, $result);

		// Completion two calendar days before the due day → 2, even though
		// only 58 wall-clock hours (one 23-hour DST day) lie in between.
		$twoDaysBefore = $this->service->calculateDaysEarly($roomTask, new \DateTime('2026-03-27T12:00:00', $tz));
		$this->assertSame(2, $twoDaysBefore);
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

		$this->completedTaskMapper->method('findRecentByUserId')->willReturn([]);
		// CS-PLAN-067 §3.2: handleTaskCompletion NEVER inserts the ledger —
		// the caller (CompletionService) owns exactly one insert.
		$this->completedTaskMapper->expects($this->never())->method('insert');
		$this->houseUserMapper->expects($this->once())->method('update');

		$this->service->handleTaskCompletion($this->roomTaskEntity($roomTaskId), $userId, $task, null, [], $this->ledgerRow());

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

		$this->completedTaskMapper->method('findRecentByUserId')->willReturn([]);
		// CS-PLAN-067 §3.2: handleTaskCompletion NEVER inserts the ledger —
		// the caller (CompletionService) owns exactly one insert.
		$this->completedTaskMapper->expects($this->never())->method('insert');
		$this->houseUserMapper->expects($this->once())->method('update');

		$result = $this->service->handleTaskCompletion($this->roomTaskEntity($roomTaskId), $userId, $task, null, [], $this->ledgerRow());

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

		$this->completedTaskMapper->method('findRecentByUserId')->willReturn([]);
		// CS-PLAN-067 §3.2: handleTaskCompletion NEVER inserts the ledger —
		// the caller (CompletionService) owns exactly one insert.
		$this->completedTaskMapper->expects($this->never())->method('insert');
		$this->houseUserMapper->expects($this->once())->method('update');

		$result = $this->service->handleTaskCompletion($this->roomTaskEntity($roomTaskId), $userId, $task, null, [], $this->ledgerRow());

		$this->assertFalse($result['streakFreezeUsed']);
		$this->assertEquals(0, $result['streakFreezesRemaining']);
		$this->assertEquals(1, $result['currentStreak']);
	}

	/**
	 * HandleTaskCompletion_ComboBonus_AwardsFreeze
	 *
	 * CS-PLAN-036: threshold − 1 prior completions (4) + the in-flight
	 * completion (+1) reach the threshold of 5 — the merged pre-insert
	 * fetch must still count the completion being processed.
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

		// 4 prior completions (threshold 5 − 1): two inside the 5-minute
		// combo window (isCombo), two more inside the 10-minute window.
		$this->completedTaskMapper->method('findRecentByUserId')->willReturn([
			$this->completionAt('-1 minute'),
			$this->completionAt('-2 minutes'),
			$this->completionAt('-7 minutes'),
			$this->completionAt('-8 minutes'),
		]);

		// CS-PLAN-067 §3.2: handleTaskCompletion NEVER inserts the ledger —
		// the caller (CompletionService) owns exactly one insert.
		$this->completedTaskMapper->expects($this->never())->method('insert');
		$this->houseUserMapper->expects($this->once())->method('update');

		$result = $this->service->handleTaskCompletion($this->roomTaskEntity($roomTaskId), $userId, $task, null, [], $this->ledgerRow());

		$this->assertTrue($result['isCombo']);
		$this->assertTrue($result['comboBonusFreeze']);
		$this->assertEquals(1, $result['streakFreezesRemaining']);
	}

	/**
	 * HandleTaskCompletion_ComboWindow_FiveMinuteBoundary
	 *
	 * CS-PLAN-036: the 5-minute combo window is derived in PHP by timestamp
	 * filtering of the single 10-minute fetch. −4:59 rows count toward
	 * isCombo; −5:01 rows do not.
	 */
	public function testHandleTaskCompletion_ComboWindow_FiveMinuteBoundary(): void {
		// Fresh mocks per case: re-stubbing the same mock would not
		// reliably override the previous window fixture.
		$isComboFor = function (array $recent): bool {
			$task = new Task();
			$task->setId(1);
			$task->setEnergyCost(1);

			$user = new HouseUser();
			$user->setId(1);
			$user->setCurrentStreak(1);
			$user->setLastActivity(new \DateTime('yesterday'));

			$houseUserMapper = $this->createMock(HouseUserMapper::class);
			$houseUserMapper->method('findForUpdate')->willReturn($user);
			$completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
			$completedTaskMapper->method('findRecentByUserId')->willReturn($recent);

			$service = new GameLogicService(
				$houseUserMapper,
				$completedTaskMapper,
				$this->configService,
				null
			);

			return $service->handleTaskCompletion($this->roomTaskEntity(1), 1, $task, null, [], $this->ledgerRow())['isCombo'];
		};

		$this->assertTrue($isComboFor([
			$this->completionAt('-1 minute'),
			$this->completionAt('-299 seconds'),
		]), 'Two completions within 5 minutes (−4:59) must be a combo');

		$this->assertFalse($isComboFor([
			$this->completionAt('-1 minute'),
			$this->completionAt('-301 seconds'),
		]), 'Only one completion within 5 minutes (−5:01) must not be a combo');

		$this->assertTrue($isComboFor([
			$this->completionAt('-301 seconds'),
			$this->completionAt('-599 seconds'),
			$this->completionAt('-1 minute'),
			$this->completionAt('-2 minutes'),
		]), 'Stale (5–10 min) rows stay in the fetch but only the two fresh ones drive isCombo');
	}

	/**
	 * HandleTaskCompletion_ComboWindow_StaleRowsDoNotMintBonusFreeze
	 *
	 * Rows beyond the 10-minute window (mapper contract: not returned) must
	 * not reach the bonus threshold. Two −5:01 completions alone are no
	 * combo at all; even counted, 2 + 1 < threshold 5.
	 */
	public function testHandleTaskCompletion_ComboWindow_StaleRowsDoNotMintBonusFreeze(): void {
		$task = new Task();
		$task->setId(1);
		$task->setEnergyCost(1);

		$user = new HouseUser();
		$user->setId(1);
		$user->setCurrentStreak(5);
		$user->setStreakFreezes(0);
		$user->setLastActivity(new \DateTime('yesterday'));

		$this->houseUserMapper->method('findForUpdate')->willReturn($user);
		$this->completedTaskMapper->method('findRecentByUserId')->willReturn([
			$this->completionAt('-301 seconds'),
			$this->completionAt('-601 seconds'),
		]);

		$result = $this->service->handleTaskCompletion($this->roomTaskEntity(1), 1, $task, null, [], $this->ledgerRow());

		$this->assertFalse($result['isCombo']);
		$this->assertFalse($result['comboBonusFreeze']);
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
			$this->configService,
			null
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
			$this->configService,
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
		$this->completedTaskMapper->method('findRecentByUserId')->willReturn([]);
		// CS-PLAN-067 §3.2: handleTaskCompletion NEVER inserts the ledger —
		// the caller (CompletionService) owns exactly one insert.
		$this->completedTaskMapper->expects($this->never())->method('insert');
		$this->houseUserMapper->expects($this->once())->method('update');

		// The service must NOT dispatch the notification itself (deferred to
		// CompletionService's post-commit phase, after commit) — pinned
		// structurally: no NotificationService dependency at all.
		self::assertNotContains(
			\OCA\ChoreStrike\Service\NotificationService::class,
			array_map(
				static fn (\ReflectionParameter $p): string => (string)($p->getType()),
				(new \ReflectionClass(GameLogicService::class))->getConstructor()->getParameters()
			),
			'GameLogicService must not depend on NotificationService (CS-PLAN-067 §3.2)'
		);

		$result = $service->handleTaskCompletion($this->roomTaskEntity($roomTaskId), $userId, $task, null, [], $this->ledgerRow());

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
		$this->completedTaskMapper->method('findRecentByUserId')->willReturn([]);
		// CS-PLAN-067 §3.2: handleTaskCompletion NEVER inserts the ledger —
		// the caller (CompletionService) owns exactly one insert.
		$this->completedTaskMapper->expects($this->never())->method('insert');
		$this->houseUserMapper->expects($this->once())->method('update');

		// No threshold crossed → no title payload to dispatch anywhere.
		$result = $service->handleTaskCompletion($this->roomTaskEntity($roomTaskId), $userId, $task, null, [], $this->ledgerRow());

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
			$this->configService,
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
		$this->completedTaskMapper->method('findRecentByUserId')->willReturn([]);
		// CS-PLAN-067 §3.2: handleTaskCompletion NEVER inserts the ledger —
		// the caller (CompletionService) owns exactly one insert.
		$this->completedTaskMapper->expects($this->never())->method('insert');
		$this->houseUserMapper->expects($this->once())->method('update');

		$result = $service->handleTaskCompletion($this->roomTaskEntity($roomTaskId), $userId, $task, null, [], $this->ledgerRow());

		$this->assertNull($result['newTitle']);
	}
}
