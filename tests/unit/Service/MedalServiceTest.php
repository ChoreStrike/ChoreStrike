<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Medal;
use OCA\ChoreStrike\Db\MedalMapper;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\MedalService;
use OCA\ChoreStrike\Service\NotificationService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MedalService (CS-PLAN-067 §3.2) — the weekly medal
 * cluster moved out of GameLogicService; predicates, award timing, race
 * handling and notification behavior are unchanged.
 */
class MedalServiceTest extends TestCase {
	private MedalService $service;
	private HouseUserMapper $houseUserMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private MedalMapper $medalMapper;
	private NotificationService $notificationService;
	private AppLogger $appLogger;
	private HouseMapper $houseMapper;

	protected function setUp(): void {
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->medalMapper = $this->createMock(MedalMapper::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->appLogger = $this->createMock(AppLogger::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		// CS-PLAN-057: medal week resolves the house timezone — default to a
		// UTC house.
		$utcHouse = new House();
		$utcHouse->setTimezone('UTC');
		$this->houseMapper->method('find')->willReturn($utcHouse);

		$this->service = new MedalService(
			$this->houseUserMapper,
			$this->completedTaskMapper,
			$this->medalMapper,
			$this->notificationService,
			$this->appLogger,
			$this->houseMapper,
			new CalendarService()
		);
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
			->method('getUserStatsByHouse')
			->willReturn([1 => ['taskCount' => 15, 'totalCoins' => 100]]);

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
			->method('getUserStatsByHouse')
			->willReturn([1 => ['taskCount' => 5, 'totalCoins' => 50]]);

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
		$this->completedTaskMapper->method('getUserStatsByHouse')
			->willReturn([7 => ['taskCount' => 15, 'totalCoins' => 100]]);
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
		$this->completedTaskMapper->method('getUserStatsByHouse')
			->willReturn([7 => ['taskCount' => 5, 'totalCoins' => 50]]);
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
	 * awardMedalIfNew helper catches it via OCP\DB\Exception and returns null
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
		$this->completedTaskMapper->method('getUserStatsByHouse')
			->willReturn([7 => ['taskCount' => 15, 'totalCoins' => 100]]);
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

		// CS-PLAN-052: the expected race-loser swallow must be observable —
		// a warning with stable event + service context keys.
		$this->appLogger->expects($this->once())
			->method('warning')
			->with(
				'MedalService: concurrent medal award race lost',
				$this->callback(function (array $context): bool {
					return $context['event'] === 'medal_award_race_lost'
						&& $context['service'] === 'MedalService'
						&& $context['houseUserId'] === 7
						&& $context['medalType'] === 'team_anchor'
						&& is_string($context['weekStart'])
						&& $context['weekStart'] !== '';
				})
			);

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
		$this->completedTaskMapper->method('getUserStatsByHouse')
			->willReturn([7 => ['taskCount' => 15, 'totalCoins' => 100]]);
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
		$this->completedTaskMapper->method('getUserStatsByHouse')
			->willReturn([1 => ['taskCount' => 2, 'totalCoins' => 20, 'comboCount' => 0, 'activeDays' => 4]]);
		$this->completedTaskMapper->method('getMaxRoomCompletionCountByHouse')
			->willReturn([1 => 2]);

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
		$this->completedTaskMapper->method('getUserStatsByHouse')
			->willReturn([1 => ['taskCount' => 2, 'totalCoins' => 20, 'comboCount' => 0, 'activeDays' => 3]]);
		$this->completedTaskMapper->method('getMaxRoomCompletionCountByHouse')
			->willReturn([1 => 2]);

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
		$this->completedTaskMapper->method('getUserStatsByHouse')
			->willReturn([1 => ['taskCount' => 2, 'totalCoins' => 20, 'comboCount' => 0, 'activeDays' => 2]]);
		$this->completedTaskMapper->method('getMaxRoomCompletionCountByHouse')
			->willReturn([1 => 5]);

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
		$this->completedTaskMapper->method('getUserStatsByHouse')
			->willReturn([1 => ['taskCount' => 2, 'totalCoins' => 20, 'comboCount' => 0, 'activeDays' => 2]]);
		$this->completedTaskMapper->method('getMaxRoomCompletionCountByHouse')
			->willReturn([1 => 4]);

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
		$this->completedTaskMapper->method('getUserStatsByHouse')
			->willReturn([1 => ['taskCount' => 2, 'totalCoins' => 20, 'comboCount' => 3, 'activeDays' => 2]]);
		$this->completedTaskMapper->method('getMaxRoomCompletionCountByHouse')
			->willReturn([1 => 2]);

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
		$this->completedTaskMapper->method('getUserStatsByHouse')
			->willReturn([1 => ['taskCount' => 2, 'totalCoins' => 20, 'comboCount' => 2, 'activeDays' => 2]]);
		$this->completedTaskMapper->method('getMaxRoomCompletionCountByHouse')
			->willReturn([1 => 2]);

		$this->medalMapper->expects($this->never())->method('findByUserTypeAndWeek');
		$this->medalMapper->expects($this->never())->method('insert');

		$result = $this->service->checkAndAwardMedals($houseId);

		$this->assertCount(0, $result);
	}

	/**
	 * CheckAndAwardMedals_UserAbsentFromGroupedMaps_TreatedAsZero
	 *
	 * CS-PLAN-036: a user with no completions in the range is absent from
	 * the grouped maps — medal checks must treat that as all-zero stats
	 * (same as the old per-user getUserStats on an empty range).
	 */
	public function testCheckAndAwardMedals_UserAbsentFromGroupedMaps_TreatedAsZero(): void {
		$houseId = 1;

		$user = new HouseUser();
		$user->setId(42);
		$user->setCurrentStreak(3);

		$this->houseUserMapper->method('findByHouseId')->willReturn([$user]);
		$this->completedTaskMapper->method('getUserStatsByHouse')->willReturn([]);
		$this->completedTaskMapper->method('getMaxRoomCompletionCountByHouse')->willReturn([]);

		$this->medalMapper->expects($this->never())->method('findByUserTypeAndWeek');
		$this->medalMapper->expects($this->never())->method('insert');

		$result = $this->service->checkAndAwardMedals($houseId);

		$this->assertCount(0, $result);
	}

	/**
	 * GetMedals_ReturnsAllMedalsWithUserNames
	 *
	 * CS-PLAN-036: medals come from one findByUserIds batch fetch; the
	 * payload shape (user order, then that user's medals) is unchanged.
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
			->method('findByUserIds')
			->with([1])
			->willReturn([1 => [$medal]]);

		$result = $this->service->getMedals($houseId);

		$this->assertCount(1, $result);
		$this->assertEquals('team_anchor', $result[0]['medalType']);
		$this->assertEquals('Test User', $result[0]['userName']);
	}
}
