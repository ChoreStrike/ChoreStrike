<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\OccurrenceMember;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\Pause;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\ScheduleRevisionMapper;
use OCA\ChoreStrike\Db\WorkShareMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\DailyCapacityService;
use OCA\ChoreStrike\Service\PauseProjectionService;
use OCA\ChoreStrike\Service\RecurrenceCalculator;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The whole-day capacity evaluator (CS-PLAN-073 §3.2, RF-073-WP2-08):
 * exact minor-unit share vectors, occurrence-identity dedupe, the frozen
 * Q-073-02 saved-zone time basis, frozen completion energies, work
 * appointments consuming their actual (cross-zone) work day, neutral
 * suspension consuming nothing, helper-only releases, child limits,
 * overload/availability/pause edge gates and the replacement rule that
 * removes the old allocation before testing the new commitment.
 *
 * @covers \OCA\ChoreStrike\Service\DailyCapacityService
 */
class DailyCapacityServiceTest extends TestCase {
	private DailyCapacityService $service;
	private HouseMapper|MockObject $houseMapper;
	private RoomTaskMapper|MockObject $roomTaskMapper;
	private ScheduleRevisionMapper|MockObject $revisionMapper;
	private OccurrenceMapper|MockObject $occurrenceMapper;
	private OccurrenceMemberMapper|MockObject $occMemberMapper;
	private CompletedTaskMapper|MockObject $completedTaskMapper;
	private WorkShareMapper|MockObject $workShareMapper;
	private HouseUserMapper|MockObject $houseUserMapper;
	private PauseProjectionService|MockObject $pauseProjection;
	private RecurrenceCalculator|MockObject $calculator;
	private CalendarService|MockObject $calendar;

	/** @var list<HouseUser> */
	private array $members = [];
	/** @var list<Occurrence> */
	private array $open = [];
	/** @var array<int, list<OccurrenceMember>> */
	private array $membersByOccurrence = [];
	/** @var list<array<string, mixed>> */
	private array $completions = [];
	/** @var list<Pause> */
	private array $recognized = [];
	private string $houseZone = 'UTC';

	protected function setUp(): void {
		parent::setUp();
		$this->members = [];
		$this->open = [];
		$this->membersByOccurrence = [];
		$this->completions = [];
		$this->recognized = [];
		$this->houseZone = 'UTC';

		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->houseMapper->method('find')->willReturnCallback(function (): House {
			$house = new House();
			$house->setId(7);
			$house->setName('Capacity House');
			$house->setTimezone($this->houseZone);
			return $house;
		});
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->roomTaskMapper->method('findByHouse')->willReturn([]);
		$this->revisionMapper = $this->createMock(ScheduleRevisionMapper::class);
		$this->revisionMapper->method('findByHouseId')->willReturn([]);
		$this->occurrenceMapper = $this->createMock(OccurrenceMapper::class);
		$this->occurrenceMapper->method('findOpenByHouseId')
			->willReturnCallback(fn (): array => $this->open);
		$this->occMemberMapper = $this->createMock(OccurrenceMemberMapper::class);
		$this->occMemberMapper->method('findByOccurrenceIds')
			->willReturnCallback(fn (): array => $this->membersByOccurrence);
		$this->occMemberMapper->method('findByOccurrence')
			->willReturnCallback(fn (int $occurrenceId): array => $this->membersByOccurrence[$occurrenceId] ?? []);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		// WP3 §3.2 capacity seam: completed effort arrives as each
		// participant's SELECTED frozen work share of every valid
		// completion (wallet-keyed, exact minor units).
		$this->workShareMapper = $this->createMock(WorkShareMapper::class);
		$this->workShareMapper->method('findSelectedSharesBetweenByHouse')
			->willReturnCallback(fn (): array => $this->completions);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->houseUserMapper->method('findByHouseId')
			->willReturnCallback(fn (): array => $this->members);
		$this->pauseProjection = $this->createMock(PauseProjectionService::class);
		$this->pauseProjection->method('recognizedForHouse')
			->willReturnCallback(fn (): array => $this->recognized);
		$this->pauseProjection->method('currentWalletIds')
			->willReturnCallback(function (): array {
				$wallets = [];
				foreach ($this->members as $member) {
					if ($member->getWalletId() !== null) {
						$wallets[(int)$member->getWalletId()] = true;
					}
				}
				return $wallets;
			});
		$this->calculator = $this->createMock(RecurrenceCalculator::class);
		$this->calendar = $this->createMock(CalendarService::class);
		$this->calendar->method('getTimeZone')
			->willReturnCallback(fn (): \DateTimeZone => new \DateTimeZone($this->houseZone));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$this->service = new DailyCapacityService(
			$this->houseMapper,
			$this->roomTaskMapper,
			$this->revisionMapper,
			$this->occurrenceMapper,
			$this->occMemberMapper,
			$this->completedTaskMapper,
			$this->workShareMapper,
			$this->houseUserMapper,
			$this->pauseProjection,
			$this->calculator,
			$this->calendar,
			$l10n,
		);
	}

	// ------------------------------------------------------------------
	// Fixtures
	// ------------------------------------------------------------------

	private function member(int $id, string $userId, int $walletId, string $role = 'member', ?array $preferences = null): HouseUser {
		$member = new HouseUser();
		$member->setId($id);
		$member->setHouseId(7);
		$member->setNextcloudUserId($userId);
		$member->setDisplayName($userId);
		$member->setRole($role);
		$member->setWalletId($walletId);
		if ($preferences !== null) {
			$member->setSchedulingPreferences($preferences);
		}
		$this->members[] = $member;
		return $member;
	}

	private function occurrence(int $id, int $roomTaskId, string $plannedKey, int $energy, string $state = Occurrence::SUSPENSION_NONE, string $zone = 'UTC'): Occurrence {
		$occurrence = new Occurrence();
		$occurrence->setId($id);
		$occurrence->setHouseId(7);
		$occurrence->setRoomTaskId($roomTaskId);
		$occurrence->setScheduleRevId(3);
		$occurrence->setOrdinal($id);
		$occurrence->setKind(Occurrence::KIND_SCHEDULED);
		$occurrence->setPlannedDate(\DateTime::createFromFormat('!Y-m-d', $plannedKey));
		$occurrence->setTimezone($zone);
		$occurrence->setEnergy($energy);
		$occurrence->setVersion(0);
		$occurrence->setSuspensionState($state);
		$this->open[] = $occurrence;
		return $occurrence;
	}

	private function memberRow(int $occurrenceId, int $walletId, string $role, bool $obligated, int $sharePct, ?string $workDate = null, ?string $workZone = null, string $disposition = OccurrenceMember::DISPOSITION_ACTIVE): OccurrenceMember {
		$row = new OccurrenceMember();
		$row->setOccurrenceId($occurrenceId);
		$row->setWalletId($walletId);
		$row->setRole($role);
		$row->setObligated($obligated);
		$row->setSharePct($sharePct);
		$row->setWorkDate($workDate !== null ? \DateTime::createFromFormat('!Y-m-d', $workDate) : null);
		$row->setWorkZone($workZone);
		$row->setDisposition($disposition);
		$this->membersByOccurrence[$occurrenceId][] = $row;
		return $row;
	}

	/**
	 * One selected work-share row (wallet-keyed, exact energy minor).
	 */
	private function completion(int $walletId, int $energy, string $instant, int $completionId = 1): void {
		$this->completions[] = [
			'completionId' => $completionId,
			'walletId' => $walletId,
			'energyMinor' => $energy * 100,
			'completedAt' => new \DateTime($instant),
			'workDate' => null,
			'workZone' => null,
			'occurrenceKey' => null,
		];
	}

	private function personalPause(int $walletId, string $start, string $end, int $groupId = 1): Pause {
		$pause = new Pause();
		$pause->setHouseId(7);
		$pause->setPauseGroupId($groupId);
		$pause->setRevision(1);
		$pause->setScope(Pause::SCOPE_PERSONAL);
		$pause->setSubjectWalletId($walletId);
		$pause->setState(Pause::STATE_ACKNOWLEDGED);
		$pause->setReasonCode(Pause::REASON_TRAVEL);
		$pause->setStartDate(new \DateTime($start));
		$pause->setEndDate(new \DateTime($end));
		$pause->setTimezone('UTC');
		$this->recognized[] = $pause;
		return $pause;
	}

	// 2026-10-05 is a Monday (weekday default budget 10).
	private const MON = '2026-10-05';
	private const TUE = '2026-10-06';
	private const WED = '2026-10-07';

	// ------------------------------------------------------------------
	// Exact shares and dedupe (§3.2)
	// ------------------------------------------------------------------

	public function testEvaluateDays_ExactShareVectors_InMinorUnits(): void {
		$this->member(1, 'dad', 11);
		$this->member(2, 'mom', 12);
		$this->member(3, 'kid', 13);
		$this->occurrence(9, 5, self::MON, 3);
		// The wallet-order remainder split 34/33/33 of energy 3 — exact
		// hundredths, never truncated whole units.
		$this->memberRow(9, 11, OccurrenceMember::ROLE_RESPONSIBLE, true, 34);
		$this->memberRow(9, 12, OccurrenceMember::ROLE_JOINT, true, 33);
		$this->memberRow(9, 13, OccurrenceMember::ROLE_JOINT, true, 33);

		$report = $this->service->evaluateDays(7, [self::MON]);

		self::assertSame(102, $report[self::MON]['dad']['plannedEnergyMinor'], '3 × 34% = 1.02 energy exactly');
		self::assertSame(99, $report[self::MON]['mom']['plannedEnergyMinor']);
		self::assertSame(99, $report[self::MON]['kid']['plannedEnergyMinor']);
		self::assertSame(1000 - 102, $report[self::MON]['dad']['remainingEnergyMinor'], 'remaining stays exact in minor units');
		self::assertSame(1, $report[self::MON]['dad']['taskCount'], 'one distinct occurrence counts once per person');
	}

	public function testEvaluateDays_FulfilledObligation_NeverPlannedAndCompleted(): void {
		$this->member(1, 'dad', 11);
		// The fulfilled obligation is NOT in the open set (mapper contract);
		// its frozen energy arrives through the selected work shares only.
		$this->completion(11, 3, self::MON . ' 09:00:00 UTC');
		// A distinct open occurrence of the same task plans separately.
		$this->occurrence(10, 5, self::MON, 2);
		$this->memberRow(10, 11, OccurrenceMember::ROLE_RESPONSIBLE, true, 100);

		$report = $this->service->evaluateDays(7, [self::MON]);

		self::assertSame(300, $report[self::MON]['dad']['completedEnergyMinor'], 'the frozen completion energy is counted once');
		self::assertSame(200, $report[self::MON]['dad']['plannedEnergyMinor'], 'only the distinct open occurrence plans');
		self::assertSame(2, $report[self::MON]['dad']['taskCount'], 'completed + planned distinct occurrences');
	}

	// ------------------------------------------------------------------
	// Time basis and frozen energy (Q-073-02 rules 1-2)
	// ------------------------------------------------------------------

	public function testEvaluateDays_CompletedInstant_MappedByHouseZoneDay(): void {
		$this->houseZone = 'America/New_York';
		$this->member(1, 'dad', 11);
		// Frozen Q-073-02 vector: 2026-10-01T22:30:00Z is inside the New
		// York October 1 interval [04:00Z, next-day 04:00Z).
		$this->completion(11, 2, '2026-10-01T22:30:00+00:00');

		$report = $this->service->evaluateDays(7, ['2026-10-01', '2026-10-02']);

		self::assertSame(200, $report['2026-10-01']['dad']['completedEnergyMinor'], 'the instant consumes the New York October 1 capacity');
		self::assertSame(0, $report['2026-10-02']['dad']['completedEnergyMinor'], 'and never the next candidate day');
	}

	public function testEvaluateDays_WorkAppointment_ConsumesActualCrossZoneWorkDay(): void {
		$this->member(1, 'dad', 11);
		// Planned Wednesday, agreed appointment Tuesday in Sydney: the
		// saved-zone day interval [Oct 5 13:00Z, Oct 6 13:00Z) intersects
		// BOTH UTC boundary candidate days (conservative rule 2) and never
		// the planned day.
		$this->occurrence(9, 5, self::WED, 2);
		$this->memberRow(9, 11, OccurrenceMember::ROLE_RESPONSIBLE, true, 100, '2026-10-06', 'Australia/Sydney');

		$report = $this->service->evaluateDays(7, [self::MON, self::TUE, self::WED]);

		self::assertSame(200, $report[self::MON]['dad']['plannedEnergyMinor'], 'the Sydney work day reaches into the UTC Oct 5 evening');
		self::assertSame(200, $report[self::TUE]['dad']['plannedEnergyMinor'], 'and covers the UTC Oct 6 morning — the same single commitment');
		self::assertSame(0, $report[self::WED]['dad']['plannedEnergyMinor'], 'the planned day is not charged for early work');
		self::assertSame(1, $report[self::MON]['dad']['taskCount'], 'the intersecting commitment counts once per candidate day');
	}

	// ------------------------------------------------------------------
	// Neutral suspension, helper release, limits (§3.4/Q-073-01)
	// ------------------------------------------------------------------

	public function testEvaluateDays_SuspendedOccurrence_ConsumesNoLiveCapacity(): void {
		$this->member(1, 'dad', 11);
		// The recognized personal pause of the sole responsibility
		// suspends the occurrence (Q-073-01) — its frozen snapshot must
		// consume no live planned capacity.
		$this->personalPause(11, self::MON, self::TUE);
		$this->occurrence(9, 5, self::MON, 3);
		$this->memberRow(9, 11, OccurrenceMember::ROLE_RESPONSIBLE, true, 100);

		$report = $this->service->evaluateDays(7, [self::MON]);

		self::assertSame(0, $report[self::MON]['dad']['plannedEnergyMinor'], 'a suspended plan retains its snapshot but consumes nothing');
		self::assertSame(0, $report[self::MON]['dad']['taskCount']);
		self::assertTrue($report[self::MON]['dad']['paused']);
	}

	public function testEvaluateDays_HelperOnlyPause_ReleasesOnlyTheHelperCommitment(): void {
		$this->member(1, 'dad', 11);
		$this->member(2, 'mom', 12);
		$this->personalPause(12, self::MON, self::TUE);
		$this->occurrence(9, 5, self::MON, 3);
		$this->memberRow(9, 11, OccurrenceMember::ROLE_RESPONSIBLE, true, 100);
		$this->memberRow(9, 12, OccurrenceMember::ROLE_HELPER, false, 50);

		$report = $this->service->evaluateDays(7, [self::MON]);

		self::assertSame(300, $report[self::MON]['dad']['plannedEnergyMinor'], 'the remaining duty stays with the responsible');
		self::assertSame(0, $report[self::MON]['mom']['plannedEnergyMinor'], 'the helper commitment is released');
		self::assertTrue($report[self::MON]['mom']['paused']);
		self::assertContains('PAUSED', $report[self::MON]['mom']['reasons']);
		self::assertFalse($report[self::MON]['dad']['paused']);
	}

	public function testEvaluateDays_ChildLimitAndOverload_Reasons(): void {
		$this->member(1, 'kid', 13, 'child', ['maxDailyTasks' => 1]);
		// Two open occurrences on the same day: the count limit fires and
		// an over-budget load flags the overload with exact amounts.
		$this->occurrence(9, 5, self::MON, 6);
		$this->memberRow(9, 13, OccurrenceMember::ROLE_RESPONSIBLE, true, 100);
		$this->occurrence(10, 6, self::MON, 6);
		$this->memberRow(10, 13, OccurrenceMember::ROLE_RESPONSIBLE, true, 100);

		$report = $this->service->evaluateDays(7, [self::MON]);

		self::assertSame(1200, $report[self::MON]['kid']['plannedEnergyMinor']);
		self::assertTrue($report[self::MON]['kid']['overloaded'], '12 > 10 budget — the overload stays visible');
		self::assertContains('OVER_CAPACITY', $report[self::MON]['kid']['reasons']);
		self::assertContains('CHILD_LIMIT', $report[self::MON]['kid']['reasons']);
		self::assertSame(2, $report[self::MON]['kid']['taskCount']);
		self::assertSame(0, $report[self::MON]['kid']['remainingEnergyMinor'], 'remaining never goes negative');
	}

	// ------------------------------------------------------------------
	// Commitment gates (§3.2/§3.3)
	// ------------------------------------------------------------------

	public function testAssertCommitmentsFit_Gates(): void {
		$this->member(1, 'dad', 11);
		$this->member(2, 'mom', 12, 'member', ['weekdayAvailability' => ['monday' => false]]);
		$this->member(3, 'kid', 13, 'child', ['maxDailyTasks' => 1]);
		$this->personalPause(11, self::MON, self::TUE);
		$this->occurrence(9, 5, self::MON, 2);
		$this->memberRow(9, 13, OccurrenceMember::ROLE_RESPONSIBLE, true, 100);

		// Paused member.
		try {
			$this->service->assertCommitmentsFit(7, self::MON, [['userId' => 'dad', 'energy' => 1]]);
			self::fail('a paused member cannot take a new commitment');
		} catch (ConflictException $e) {
			self::assertStringContainsString('paused', $e->getMessage());
		}
		// Unavailable member.
		try {
			$this->service->assertCommitmentsFit(7, self::MON, [['userId' => 'mom', 'energy' => 1]]);
			self::fail('an unavailable member cannot take a new commitment');
		} catch (ConflictException $e) {
			self::assertStringContainsString('not available', $e->getMessage());
		}
		// Non-member.
		try {
			$this->service->assertCommitmentsFit(7, self::MON, [['userId' => 'stranger', 'energy' => 1]]);
			self::fail('a stranger is never a valid commitment target');
		} catch (ConflictException $e) {
			self::assertStringContainsString('house members', $e->getMessage());
		}
		// Child day limit (kid already holds one open occurrence).
		try {
			$this->service->assertCommitmentsFit(7, self::MON, [['userId' => 'kid', 'energy' => 1]]);
			self::fail('the child day limit gates the commitment');
		} catch (ConflictException $e) {
			self::assertStringContainsString('daily task limit', $e->getMessage());
		}
		// Budget exceeded (kid: 200 planned + 900 minor new > 1000).
		try {
			$this->service->assertCommitmentsFit(7, self::MON, [['userId' => 'kid', 'energyMinor' => 901]]);
			self::fail('the energy budget gates the commitment');
		} catch (ConflictException $e) {
			self::assertStringContainsString('energy budget', $e->getMessage());
		}
	}

	public function testAssertCommitmentsFit_ReplacedOccurrence_RemovesOldAllocationFirst(): void {
		$this->member(1, 'dad', 11);
		// The replaced occurrence charges dad exactly his whole budget.
		$occurrence = $this->occurrence(9, 5, self::MON, 10);
		$this->memberRow(9, 11, OccurrenceMember::ROLE_RESPONSIBLE, true, 100);

		$commitments = [['userId' => 'dad', 'energyMinor' => 1000]];
		try {
			$this->service->assertCommitmentsFit(7, self::MON, $commitments);
			self::fail('without the replacement rule the day is full');
		} catch (ConflictException $e) {
			self::assertStringContainsString('energy budget', $e->getMessage());
		}

		// A same-day replacement removes exactly the old allocation first —
		// the identical commitment now fits (RF-073-WP2-21). No exception
		// means the gate passed.
		$this->service->assertCommitmentsFit(7, self::MON, $commitments, $occurrence);
	}

	// ------------------------------------------------------------------
	// Shared-collection pass (RF-073-WP2-16)
	// ------------------------------------------------------------------

	public function testWithSharedCollection_RunsOneBatchedCollectPerPass(): void {
		$this->member(1, 'dad', 11);
		$this->occurrence(9, 5, self::MON, 2);
		$this->memberRow(9, 11, OccurrenceMember::ROLE_RESPONSIBLE, true, 100);

		$collects = 0;
		$this->occurrenceMapper = $this->createMock(OccurrenceMapper::class);
		$this->occurrenceMapper->method('findOpenByHouseId')
			->willReturnCallback(function () use (&$collects): array {
				$collects++;
				return $this->open;
			});
		$this->rebuildService();

		// Outside a shared pass every public read collects on its own.
		$this->service->plannedBaseline(7, [self::MON]);
		$this->service->evaluateDays(7, [self::MON]);
		self::assertSame(2, $collects);

		// Inside the pass both reads share ONE collection.
		$collects = 0;
		[$baseline, $report] = $this->service->withSharedCollection(fn (): array => [
			$this->service->plannedBaseline(7, [self::MON]),
			$this->service->evaluateDays(7, [self::MON]),
		]);
		self::assertSame(1, $collects, 'one batched collection per shared pass');
		self::assertSame(200, $report[self::MON]['dad']['plannedEnergyMinor']);
		self::assertSame(200, $baseline[0]['dad'][self::MON]);

		// The pass cache never survives the pass.
		$collects = 0;
		$this->service->evaluateDays(7, [self::MON]);
		self::assertSame(1, $collects);
	}

	/**
	 * Re-instantiate the service after swapping a mapper mock mid-test.
	 */
	private function rebuildService(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$this->service = new DailyCapacityService(
			$this->houseMapper,
			$this->roomTaskMapper,
			$this->revisionMapper,
			$this->occurrenceMapper,
			$this->occMemberMapper,
			$this->completedTaskMapper,
			$this->workShareMapper,
			$this->houseUserMapper,
			$this->pauseProjection,
			$this->calculator,
			$this->calendar,
			$l10n,
		);
	}
}
