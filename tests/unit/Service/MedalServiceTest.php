<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Medal;
use OCA\ChoreStrike\Db\MedalMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Db\WeeklyGoalReceiptMapper;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\MedalService;
use OCA\ChoreStrike\Service\RhythmService;
use OCA\ChoreStrike\Service\WeeklyGoalService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MedalService (CS-PLAN-070 WP3, Q-074-01A): only the
 * RETAINED recognition awards remain — the personal rhythm tiers at
 * 3/6/12 consecutive successful planned task days (lifetime rows off the
 * RhythmService-derived streak) plus the household weekly milestone.
 * The old activity/combo catalog (team_anchor, flow_keeper,
 * room_guardian, team_player) retired with the wallet cutover.
 */
class MedalServiceTest extends TestCase {
	private MedalService $service;
	private HouseUserMapper $houseUserMapper;
	private MedalMapper $medalMapper;
	private AppLogger $appLogger;
	private WeeklyGoalService $weeklyGoalService;
	private RhythmService $rhythmService;
	private HouseMapper $houseMapper;
	private WeeklyGoalReceiptMapper $weeklyGoalReceiptMapper;
	private TransactionExecutor $transactions;

	protected function setUp(): void {
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->medalMapper = $this->createMock(MedalMapper::class);
		$this->appLogger = $this->createMock(AppLogger::class);
		$this->weeklyGoalService = $this->createMock(WeeklyGoalService::class);
		$this->rhythmService = $this->createMock(RhythmService::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->weeklyGoalReceiptMapper = $this->createMock(WeeklyGoalReceiptMapper::class);
		$this->transactions = $this->createMock(TransactionExecutor::class);
		$this->transactions->method('run')->willReturnCallback(static fn (callable $fn): mixed => $fn());
		$this->weeklyGoalService->method('getWeeklyGoalProgressForWeek')->willReturn([
			'achieved' => false,
			'percent' => 0,
		]);

		// Default: weekly goal not achieved (tests opt in explicitly).
		$this->weeklyGoalService->method('getWeeklyGoalProgress')->willReturn([
			'dueCount' => 5,
			'completedCount' => 1,
			'percent' => 20,
			'targetPercent' => 80,
			'achieved' => false,
			'weekStart' => '2026-09-21T00:00:00+00:00',
			'weekEnd' => '2026-09-28T00:00:00+00:00',
			'weekKey' => '2026-09-21',
		]);

		$this->service = new MedalService(
			$this->houseUserMapper,
			$this->medalMapper,
			$this->appLogger,
			$this->weeklyGoalService,
			$this->rhythmService,
			$this->houseMapper,
			$this->weeklyGoalReceiptMapper,
			$this->transactions
		);
	}

	private function member(int $id, string $ncId, int $streak): HouseUser {
		$user = new HouseUser();
		$user->setId($id);
		$user->setNextcloudUserId($ncId);
		$user->setCurrentStreak($streak);
		$user->setLongestStreak($streak);
		$user->setJoinedAt(new \DateTime('2025-01-01T00:00:00+00:00'));
		return $user;
	}

	private function weeklyProgress(bool $achieved, int $percent = 100): void {
		// A FRESH mock: PHPUnit keeps the first configured stub, so the
		// setUp default (not achieved) must not shadow the test override.
		$this->weeklyGoalService = $this->createMock(WeeklyGoalService::class);
		$this->weeklyGoalService->method('getWeeklyGoalProgressForWeek')->willReturn([
			'achieved' => false,
			'percent' => 0,
		]);
		$this->weeklyGoalService->method('getWeeklyGoalProgress')->willReturn([
			'dueCount' => 5,
			'completedCount' => $achieved ? 5 : 1,
			'percent' => $achieved ? $percent : 20,
			'targetPercent' => 80,
			'achieved' => $achieved,
			'weekStart' => '2026-09-21T00:00:00+00:00',
			'weekEnd' => '2026-09-28T00:00:00+00:00',
			'weekKey' => '2026-09-21',
		]);
		$this->service = new MedalService(
			$this->houseUserMapper,
			$this->medalMapper,
			$this->appLogger,
			$this->weeklyGoalService,
			$this->rhythmService,
			$this->houseMapper,
			$this->weeklyGoalReceiptMapper,
			$this->transactions
		);
	}

	public function testCheckAndAwardMedals_MemberJoinedAfterClosedWeek_DoesNotGetHistoricalMilestone(): void {
		$user = $this->member(7, 'late_joiner', 0);
		$user->setJoinedAt(new \DateTime('2026-09-29T00:00:00+00:00'));
		$this->houseUserMapper->method('findByHouseId')->willReturn([$user]);
		$this->weeklyProgress(true);
		$this->medalMapper->expects($this->never())->method('insert');

		self::assertSame([], $this->service->checkAndAwardMedals(1));
	}

	/**
	 * CheckAndAwardMedals_RhythmStreak_AwardsRetainedTiers
	 *
	 * C3d: a currentStreak of 12 crosses all three retained rhythm tiers
	 * (3/6/12) — lifetime rows, one award each.
	 */
	public function testCheckAndAwardMedals_RhythmStreak12_AwardsAllThreeTiers(): void {
		$this->houseUserMapper->method('findByHouseId')->willReturn([$this->member(1, 'alice', 12)]);
		$this->medalMapper->method('findByUserTypeAndWeek')
			->willThrowException(new DoesNotExistException());
		$this->medalMapper->method('insert')->willReturnArgument(0);

		$result = $this->service->checkAndAwardMedals(1);

		$types = array_map(static fn (Medal $m) => $m->getMedalType(), $result);
		$this->assertEquals(['rhythm_3', 'rhythm_6', 'rhythm_12'], $types);
		foreach ($result as $medal) {
			$this->assertSame(MedalService::LIFETIME_WEEK, $medal->getWeekStart());
		}
	}

	/**
	 * CheckAndAwardMedals_RhythmStreak3_AwardsOnlyRhythm3
	 */
	public function testCheckAndAwardMedals_RhythmStreak3_AwardsOnlyRhythm3(): void {
		$this->houseUserMapper->method('findByHouseId')->willReturn([$this->member(1, 'alice', 3)]);
		$this->medalMapper->method('findByUserTypeAndWeek')
			->willThrowException(new DoesNotExistException());
		$this->medalMapper->method('insert')->willReturnArgument(0);

		$result = $this->service->checkAndAwardMedals(1);

		$this->assertCount(1, $result);
		$this->assertEquals('rhythm_3', $result[0]->getMedalType());
		$this->assertEquals(3, $result[0]->getValue());
	}

	/**
	 * CheckAndAwardMedals_BelowRhythmThreshold_NoPersonalAward
	 */
	public function testCheckAndAwardMedals_BelowRhythmThreshold_NoPersonalAward(): void {
		$this->houseUserMapper->method('findByHouseId')->willReturn([$this->member(1, 'alice', 2)]);

		$this->medalMapper->expects($this->never())->method('findByUserTypeAndWeek');
		$this->medalMapper->expects($this->never())->method('insert');

		$this->assertCount(0, $this->service->checkAndAwardMedals(1));
	}

	/**
	 * CheckAndAwardMedals_NewMedal_NotifiesRecipient
	 */
	public function testCheckAndAwardMedals_NewMedal_NotifiesRecipient(): void {
		$this->houseUserMapper->method('findByHouseId')->willReturn([$this->member(7, 'alice', 3)]);
		$this->medalMapper->method('findByUserTypeAndWeek')
			->willThrowException(new DoesNotExistException());
		$this->medalMapper->method('insert')->willReturnArgument(0);

		// Q-076-02: the award bell is retired — the medal row is the
		// in-app celebration; no external dispatch exists at all.
		$result = $this->service->checkAndAwardMedals(1);

		$types = array_map(static fn (Medal $m) => $m->getMedalType(), $result);
		$this->assertSame(['rhythm_3'], $types, 'the award itself still happens — quietly, in-app');
	}

	/**
	 * CheckAndAwardMedals_ExistingMedal_DoesNotNotifyAgain
	 */
	public function testCheckAndAwardMedals_ExistingMedal_DoesNotNotifyAgain(): void {
		$this->houseUserMapper->method('findByHouseId')->willReturn([$this->member(7, 'alice', 3)]);

		$existingMedal = new Medal();
		$existingMedal->setId(99);
		$existingMedal->setMedalType('rhythm_3');
		$existingMedal->setUserId(7);

		$this->medalMapper->method('findByUserTypeAndWeek')->willReturn($existingMedal);
		$this->medalMapper->expects($this->never())->method('insert');

		$this->assertCount(0, $this->service->checkAndAwardMedals(1));
	}

	/**
	 * CheckAndAwardMedals_InsertUniqueViolation_TreatsAsExistingAndSkipsNotify
	 */
	public function testCheckAndAwardMedals_InsertUniqueViolation_TreatsAsExistingAndSkipsNotify(): void {
		$this->houseUserMapper->method('findByHouseId')->willReturn([$this->member(7, 'alice', 3)]);
		$this->medalMapper->method('findByUserTypeAndWeek')
			->willThrowException(new DoesNotExistException());
		$this->medalMapper->method('insert')
			->willThrowException(new \OCP\DB\Exception(
				'unique constraint',
				0,
				null,
				\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION
			));


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
						&& $context['medalType'] === 'rhythm_3'
						&& $context['weekStart'] === MedalService::LIFETIME_WEEK;
				})
			);

		$this->assertCount(0, $this->service->checkAndAwardMedals(1));
	}

	/**
	 * CheckAndAwardMedals_InsertNonUniqueDbError_Propagates
	 */
	public function testCheckAndAwardMedals_InsertNonUniqueDbError_Propagates(): void {
		$this->houseUserMapper->method('findByHouseId')->willReturn([$this->member(7, 'alice', 3)]);
		$this->medalMapper->method('findByUserTypeAndWeek')
			->willThrowException(new DoesNotExistException());
		$this->medalMapper->method('insert')
			->willThrowException(new \OCP\DB\Exception(
				'connection lost',
				0,
				null,
				\OCP\DB\Exception::REASON_OTHER
			));

		$this->expectException(\OCP\DB\Exception::class);

		$this->service->checkAndAwardMedals(1);
	}

	/**
	 * CheckAndAwardMedals_WeeklyGoalAchieved_AwardsWeeklyMilestoneToEveryMember
	 *
	 * The household weekly milestone (80% target) is a per-member medal
	 * keyed by the current house-local week — no extra Coins.
	 */
	public function testCheckAndAwardMedals_WeeklyGoalAchieved_AwardsWeeklyMilestoneToEveryMember(): void {
		$this->houseUserMapper->method('findByHouseId')
			->willReturn([$this->member(1, 'alice', 0), $this->member(2, 'bob', 0)]);
		$this->medalMapper->method('findByUserTypeAndWeek')
			->willThrowException(new DoesNotExistException());
		$this->medalMapper->method('insert')->willReturnArgument(0);
		$this->weeklyProgress(true, 100);

		$result = $this->service->checkAndAwardMedals(1);

		$this->assertCount(2, $result);
		foreach ($result as $medal) {
			$this->assertEquals('weekly_milestone', $medal->getMedalType());
			$this->assertEquals(100, $medal->getValue());
			$this->assertEquals('2026-09-21', $medal->getWeekStart());
		}
		$this->assertEquals([1, 2], array_map(static fn (Medal $m) => $m->getUserId(), $result));
	}

	/**
	 * CheckAndAwardMedals_WeeklyGoalNotAchieved_NoWeeklyMilestone
	 */
	public function testCheckAndAwardMedals_WeeklyGoalNotAchieved_NoWeeklyMilestone(): void {
		$this->houseUserMapper->method('findByHouseId')
			->willReturn([$this->member(1, 'alice', 0)]);

		$this->medalMapper->expects($this->never())->method('insert');

		$this->assertCount(0, $this->service->checkAndAwardMedals(1));
	}

	public function testCheckAndAwardMedals_CorrectionRevokesThenRestoresSameLifetimeAwardWithoutCelebration(): void {
		$user = $this->member(7, 'alice', 0);
		$award = new Medal();
		$award->setId(99);
		$award->setUserId(7);
		$award->setMedalType('rhythm_3');
		$award->setWeekStart(MedalService::LIFETIME_WEEK);
		$award->setValue(3);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$user]);
		$this->medalMapper->method('findByUserIds')->willReturn([7 => [$award]]);
		$values = [];
		$this->medalMapper->expects($this->exactly(2))->method('update')->willReturnCallback(
			static function (Medal $medal) use (&$values): Medal {
				$values[] = $medal->getValue();
				return $medal;
			}
		);
		$this->medalMapper->expects($this->never())->method('insert');

		self::assertSame([], $this->service->checkAndAwardMedals(1));
		$user->setLongestStreak(3);
		self::assertSame([], $this->service->checkAndAwardMedals(1));
		self::assertSame([0, 3], $values);
	}

	public function testCheckAndAwardMedals_IndependentPastRun_PreservesEarnedTierAfterRealBreak(): void {
		$user = $this->member(7, 'alice', 0);
		$user->setLongestStreak(6);
		$award = new Medal();
		$award->setUserId(7);
		$award->setMedalType('rhythm_6');
		$award->setWeekStart(MedalService::LIFETIME_WEEK);
		$award->setValue(6);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$user]);
		$this->medalMapper->method('findByUserIds')->willReturn([7 => [$award]]);
		$this->medalMapper->expects($this->never())->method('update');
		$this->medalMapper->method('findByUserTypeAndWeek')->willReturn($award);

		self::assertSame([], $this->service->checkAndAwardMedals(1));
		self::assertSame(6, $award->getValue());
	}

	public function testGetMedals_CorrectedFalseWeeklyMilestone_IsHiddenWithoutReadWrites(): void {
		$user = $this->member(7, 'alice', 0);
		$award = new Medal();
		$award->setUserId(7);
		$award->setMedalType('weekly_milestone');
		$award->setWeekStart('2026-09-14');
		$award->setValue(100);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$user]);
		$this->medalMapper->method('findByUserIds')->willReturn([7 => [$award]]);
		$this->medalMapper->expects($this->never())->method('update');

		self::assertSame([], $this->service->getMedals(1));
	}

	public function testGetMedals_UnsupportedRhythmAward_IsHiddenBeforeNextAwardCheck(): void {
		$user = $this->member(7, 'alice', 0);
		$award = new Medal();
		$award->setUserId(7);
		$award->setMedalType('rhythm_3');
		$award->setWeekStart(MedalService::LIFETIME_WEEK);
		$award->setValue(3);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$user]);
		$this->medalMapper->method('findByUserIds')->willReturn([7 => [$award]]);
		$this->medalMapper->expects($this->never())->method('update');

		self::assertSame([], $this->service->getMedals(1));
	}

	public function testClosedWeekJob_WaitsForWindowThenClaimsOnceAcrossCorrectionAndRetry(): void {
		$house = new House();
		$house->setId(1);
		$house->setTimezone('UTC');
		$this->houseMapper->method('find')->willReturn($house);
		$this->houseMapper->method('findForUpdate')->willReturn($house);
		$user = $this->member(1, 'alice', 0);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$user]);
		$key = '2026-09-28';
		$eligible = true;
		$claimed = false;
		$award = null;
		$this->weeklyGoalReceiptMapper->method('plannedWeeksPage')->willReturn([$key]);
		$this->weeklyGoalReceiptMapper->method('claimedWeeks')->willReturnCallback(
			static function () use (&$claimed, $key): array {
				return $claimed ? [$key => true] : [];
			}
		);
		$this->weeklyGoalReceiptMapper->expects($this->once())->method('claim')->willReturnCallback(
			static function () use (&$claimed): bool {
				$claimed = true;
				return true;
			}
		);
		$this->weeklyGoalService->method('progressForWeek')->willReturnCallback(
			static fn (House $house, \DateTime $now): array => ['weekKey' => $now->format('Y-m-d') < '2026-10-12' ? '2026-10-05' : '2026-10-12']
		);
		$this->weeklyGoalService->method('progressForPlannedWeek')->willReturnCallback(
			static function (House $house, string $weekKey, \DateTime $now) use (&$eligible): array {
				$open = $now->format('Y-m-d') < '2026-10-12';
				return [
					'weekKey' => $weekKey,
					'weekStart' => '2026-09-28T00:00:00+00:00',
					'weekEnd' => '2026-10-05T00:00:00+00:00',
					'percent' => $eligible ? 100 : 0,
					'targetPercent' => 80,
					'provisional' => $open,
					'achieved' => !$open && $eligible,
				];
			}
		);
		$this->medalMapper->method('findByUserIds')->willReturnCallback(
			static function () use (&$award): array {
				return $award === null ? [] : [1 => [$award]];
			}
		);
		$this->medalMapper->method('findByUserTypeAndWeek')->willReturnCallback(
			static function () use (&$award): Medal {
				if ($award === null) {
					throw new DoesNotExistException();
				}
				return $award;
			}
		);
		$this->medalMapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (Medal $medal) use (&$award): Medal {
				$award = $medal;
				return $medal;
			}
		);
		$this->medalMapper->expects($this->exactly(2))->method('update')->willReturnArgument(0);
		// Q-076-02: the weekly-goal bell is retired — the receipt claim is
		// the once-only in-app celebration marker; closed-week
		// reconciliation logic is untouched.

		$this->service->reconcileClosedWeeksForHouse(1, new \DateTime('2026-10-06 UTC'));
		self::assertNull($award, 'an open prior-week window cannot grant recognition');
		$this->service->reconcileClosedWeeksForHouse(1, new \DateTime('2026-10-13 UTC'));
		self::assertSame(100, $award->getValue());
		$eligible = false;
		$this->service->reconcileClosedWeeksForHouse(1, new \DateTime('2026-10-14 UTC'));
		self::assertSame(0, $award->getValue(), 'corrected unsupported award is revoked');
		$eligible = true;
		$this->service->reconcileClosedWeeksForHouse(1, new \DateTime('2026-10-15 UTC'));
		self::assertSame(100, $award->getValue(), 'requalification reuses the existing award and receipt');
	}

	public function testClosedWeekJob_ZeroOpportunityWeek_NeverClaimsOrAwards(): void {
		$house = new House();
		$house->setId(1);
		$this->houseMapper->method('find')->willReturn($house);
		$this->weeklyGoalReceiptMapper->method('plannedWeeksPage')->willReturn(['2026-09-21']);
		$this->weeklyGoalService->method('progressForWeek')->willReturn(['weekKey' => '2026-10-05']);
		$this->weeklyGoalService->method('progressForPlannedWeek')->willReturn([
			'provisional' => false, 'achieved' => false, 'percent' => 0,
		]);
		$this->weeklyGoalReceiptMapper->expects($this->never())->method('claim');
		$this->medalMapper->expects($this->never())->method('insert');

		$this->service->reconcileClosedWeeksForHouse(1, new \DateTime('2026-10-06 UTC'));
	}

	public function testClosedWeekJob_ConcurrentCorrectionBeforeLockedClaim_DoesNotMintFalseBellOrMedal(): void {
		$house = new House();
		$house->setId(1);
		$this->houseMapper->method('find')->willReturn($house);
		$this->houseMapper->expects($this->once())->method('findForUpdate')->willReturn($house);
		$this->weeklyGoalReceiptMapper->method('plannedWeeksPage')->willReturn(['2026-09-21']);
		$this->weeklyGoalService->method('progressForWeek')->willReturn(['weekKey' => '2026-10-05']);
		$reads = 0;
		$this->weeklyGoalService->method('progressForPlannedWeek')->willReturnCallback(
			static function () use (&$reads): array {
				$reads++;
				return ['provisional' => false, 'achieved' => $reads === 1, 'percent' => $reads === 1 ? 100 : 0];
			}
		);
		$this->weeklyGoalReceiptMapper->expects($this->never())->method('claim');
		$this->medalMapper->expects($this->never())->method('insert');

		$this->service->reconcileClosedWeeksForHouse(1, new \DateTime('2026-10-06 UTC'));
		self::assertSame(2, $reads, 'the corrected snapshot is re-read under the house lock and used for medals');
	}

	public function testClosedWeekJob_MissedCronWindow_PagesIntoOlderEligiblePlannedWeek(): void {
		$house = new House();
		$house->setId(1);
		$this->houseMapper->method('find')->willReturn($house);
		$this->houseMapper->method('findForUpdate')->willReturn($house);
		$firstPage = [];
		for ($i = 0; $i < 50; $i++) {
			$firstPage[] = (new \DateTime('2024-01-01 UTC'))->modify('+' . $i . ' weeks')->format('Y-m-d');
		}
		$this->weeklyGoalReceiptMapper->expects($this->exactly(2))->method('plannedWeeksPage')
			->willReturnCallback(static function (int $houseId, string $before, ?string $after, int $limit) use ($firstPage): array {
				self::assertSame(50, $limit);
				self::assertSame('2026-10-05', $before);
				return $after === null ? $firstPage : ($after === end($firstPage) ? ['2026-09-21'] : []);
			});
		$this->weeklyGoalService->method('progressForWeek')->willReturn(['weekKey' => '2026-10-05']);
		$this->weeklyGoalService->method('progressForPlannedWeek')->willReturnCallback(
			static function (House $house, string $key): array {
				$achieved = $key === '2026-09-21';
				return [
					'provisional' => false, 'achieved' => $achieved,
					'percent' => $achieved ? 100 : 0, 'targetPercent' => 80,
					'weekStart' => $key . 'T00:00:00+00:00',
				];
			}
		);
		$this->weeklyGoalReceiptMapper->expects($this->once())->method('claim')->with(1, '2026-09-21', $this->anything())->willReturn(true);
		// Q-076-02: the receipt is claimed without any bell dispatch.

		$this->service->reconcileClosedWeeksForHouse(1, new \DateTime('2026-10-05 UTC'));
	}

	public function testClosedWeekJob_ReceiptClaimedOnce_RerunDoesNotReclaimOrRecelebrate(): void {
		$house = new House();
		$house->setId(1);
		$this->houseMapper->method('find')->willReturn($house);
		$this->houseMapper->method('findForUpdate')->willReturn($house);
		$this->weeklyGoalReceiptMapper->method('plannedWeeksPage')->willReturn(['2026-09-21']);
		$claimed = false;
		$this->weeklyGoalReceiptMapper->method('claimedWeeks')->willReturnCallback(
			static function () use (&$claimed): array {
				return $claimed ? ['2026-09-21' => true] : [];
			}
		);
		// The receipt stays the once-only celebration marker: the rerun
		// sees the claimed week and claims nothing new.
		$this->weeklyGoalReceiptMapper->expects($this->once())->method('claim')->willReturnCallback(
			static function () use (&$claimed): bool {
				$claimed = true;
				return true;
			}
		);
		$this->weeklyGoalService->method('progressForWeek')->willReturn(['weekKey' => '2026-10-05']);
		$this->weeklyGoalService->method('progressForPlannedWeek')->willReturn([
			'provisional' => false, 'achieved' => true, 'percent' => 100,
			'targetPercent' => 80, 'weekStart' => '2026-09-21T00:00:00+00:00',
		]);
		$this->appLogger->expects($this->never())->method('logException');

		$this->service->reconcileClosedWeeksForHouse(1, new \DateTime('2026-10-06 UTC'));
		$this->service->reconcileClosedWeeksForHouse(1, new \DateTime('2026-10-06 UTC'));
	}

	/**
	 * GetMedals_ReturnsAllMedalsWithUserNames
	 */
	public function testGetMedals_ReturnsAllMedalsWithUserNames(): void {
		$houseId = 1;

		$user = new HouseUser();
		$user->setId(1);
		$user->setDisplayName('Test User');
		$user->setLongestStreak(6);

		$medal = new Medal();
		$medal->setId(1);
		$medal->setUserId(1);
		$medal->setMedalType('rhythm_6');
		$medal->setValue(6);
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
		$this->assertEquals('rhythm_6', $result[0]['medalType']);
		$this->assertEquals('Test User', $result[0]['userName']);
	}

	/**
	 * MedalService_HasNoNotificationDependency (Q-076-02 negative pin)
	 *
	 * Awards stay quietly in-app: the medal service must not depend on
	 * the notification choke point at all — a future bell path cannot
	 * reappear through this class.
	 */
	public function testMedalService_HasNoNotificationDependency(): void {
		$constructor = (new \ReflectionClass(MedalService::class))->getConstructor();
		self::assertNotNull($constructor);
		foreach ($constructor->getParameters() as $parameter) {
			$type = (string)$parameter->getType();
			self::assertNotSame(
				\OCA\ChoreStrike\Service\NotificationService::class,
				$type,
				'MedalService must not gain a notification dependency again'
			);
		}
	}
}
