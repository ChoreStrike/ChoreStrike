<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\OccurrenceMember;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Db\WorkShare;
use OCA\ChoreStrike\Db\WorkShareMapper;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\RhythmService;
use PHPUnit\Framework\TestCase;

class RhythmServiceTest extends TestCase {
	private OccurrenceMapper $occurrences;
	private OccurrenceMemberMapper $members;
	private CompletedTaskMapper $completions;
	private WorkShareMapper $shares;
	private HouseUserMapper $houseUsers;
	private HouseMapper $houses;
	private TransactionExecutor $transactions;
	private RhythmService $service;

	protected function setUp(): void {
		$this->occurrences = $this->createMock(OccurrenceMapper::class);
		$this->members = $this->createMock(OccurrenceMemberMapper::class);
		$this->completions = $this->createMock(CompletedTaskMapper::class);
		$this->shares = $this->createMock(WorkShareMapper::class);
		$this->houseUsers = $this->createMock(HouseUserMapper::class);
		$this->houses = $this->createMock(HouseMapper::class);
		$this->transactions = $this->createMock(TransactionExecutor::class);
		$this->transactions->method('run')->willReturnCallback(static fn (callable $fn): mixed => $fn());
		$house = new House();
		$house->setId(1);
		$house->setTimezone('UTC');
		$this->houses->method('find')->willReturn($house);
		$this->service = new RhythmService($this->occurrences, $this->members, $this->completions,
			$this->houses, $this->houseUsers, new CalendarService(), $this->shares, $this->transactions);
	}

	private function user(int $id, int $walletId): HouseUser {
		$user = new HouseUser();
		$user->setId($id);
		$user->setWalletId($walletId);
		$user->setCurrentStreak(0);
		$user->setLongestStreak(0);
		return $user;
	}

	private function occurrence(int $id, string $day, ?int $completionId, ?string $end = null, bool $suspended = false): Occurrence {
		$row = new Occurrence();
		$row->setId($id);
		$row->setKind(Occurrence::KIND_SCHEDULED);
		$row->setPlannedDate(new \DateTime($day));
		$row->setDayStart(new \DateTime($day . ' 00:00:00 UTC'));
		$row->setWindowStart(new \DateTime($day . ' 00:00:00 UTC'));
		$row->setWindowEnd(new \DateTime($end ?? $day . ' +1 day'));
		$row->setCompletionId($completionId);
		$row->setSuspensionState($suspended ? Occurrence::SUSPENSION_SUSPENDED : Occurrence::SUSPENSION_NONE);
		return $row;
	}

	private function obligation(int $id, int $walletId): OccurrenceMember {
		$row = new OccurrenceMember();
		$row->setOccurrenceId($id);
		$row->setWalletId($walletId);
		$row->setObligated(true);
		$row->setDisposition(OccurrenceMember::DISPOSITION_ACTIVE);
		return $row;
	}

	private function completed(int $id, string $day, string $class = CompletedTask::CLASS_AGREED): CompletedTask {
		$row = new CompletedTask();
		$row->setId($id);
		$row->setCompletedAt(new \DateTime($day . ' 12:00:00 UTC'));
		$row->setCompletionClass($class);
		return $row;
	}

	private function share(int $walletId): WorkShare {
		$share = new WorkShare();
		$share->setWalletId($walletId);
		return $share;
	}

	/** @param Occurrence[] $rows */
	private function seed(array $rows, array $members, array $completions, array $shares): void {
		$this->occurrences->method('findPlannedPage')->willReturnCallback(
			static function (int $houseId, string $through, ?string $afterDay, int $afterId, int $size) use ($rows): array {
				$remaining = array_values(array_filter($rows, static fn (Occurrence $row): bool
					=> $row->getPlannedDate()->format('Y-m-d') <= $through
					&& ($afterDay === null || $row->getPlannedDate()->format('Y-m-d') > $afterDay
						|| ($row->getPlannedDate()->format('Y-m-d') === $afterDay && $row->getId() > $afterId))));
				return array_slice($remaining, 0, $size);
			}
		);
		$this->occurrences->expects($this->never())->method('findByHouseId');
		$this->members->method('findByOccurrenceIds')->willReturnCallback(
			static fn (array $ids): array => array_intersect_key($members, array_flip($ids))
		);
		$this->members->expects($this->never())->method('findByOccurrence');
		$this->completions->method('findValidByIds')->willReturnCallback(
			static fn (array $ids): array => array_intersect_key($completions, array_flip($ids))
		);
		$this->completions->expects($this->never())->method('isValidCompletion');
		$this->shares->method('findSelectedByCompletionIds')->willReturnCallback(
			static fn (array $ids): array => array_intersect_key($shares, array_flip($ids))
		);
	}

	public function testRecomputeForMembers_AbsentAssignee_DoesNotReceiveCoworkerSuccess(): void {
		$this->seed([$this->occurrence(1, '2026-09-24', 11)], [1 => [
			$this->obligation(1, 100), $this->obligation(1, 200),
		]], [11 => $this->completed(11, '2026-09-24')], [11 => [$this->share(100)]]);
		$alice = $this->user(1, 100);
		$bob = $this->user(2, 200);
		$result = $this->service->recomputeForMembers(1, [$alice, $bob], new \DateTime('2026-09-25 12:00:00 UTC'));
		self::assertSame(1, $result[1]['currentStreak']);
		self::assertSame(0, $result[2]['currentStreak']);
	}

	public function testRecomputeForMembers_PendingEarlierDay_HoldsLaterStepsUntilDeadline(): void {
		$this->seed([
			$this->occurrence(1, '2026-09-20', 11),
			$this->occurrence(2, '2026-09-21', null, '2026-09-27 00:00:00 UTC'),
			$this->occurrence(3, '2026-09-22', 13),
			$this->occurrence(4, '2026-09-23', 14),
		], [1 => [$this->obligation(1, 100)], 2 => [$this->obligation(2, 100)],
			3 => [$this->obligation(3, 100)], 4 => [$this->obligation(4, 100)]], [
				11 => $this->completed(11, '2026-09-20'),
				13 => $this->completed(13, '2026-09-22'),
				14 => $this->completed(14, '2026-09-23'),
			], [11 => [$this->share(100)], 13 => [$this->share(100)], 14 => [$this->share(100)]]);
		$user = $this->user(1, 100);
		self::assertSame(['currentStreak' => 1, 'longestStreak' => 1],
			$this->service->recomputeForMembers(1, [$user], new \DateTime('2026-09-25 12:00:00 UTC'))[1]);
		self::assertSame(['currentStreak' => 2, 'longestStreak' => 2],
			$this->service->recomputeForMembers(1, [$user], new \DateTime('2026-09-28 12:00:00 UTC'))[1]);
	}

	public function testRecomputeForMembers_RecognizedSuspension_IsNeutral(): void {
		$this->seed([$this->occurrence(1, '2026-09-22', 11),
			$this->occurrence(2, '2026-09-23', null, null, true),
			$this->occurrence(3, '2026-09-24', 13)],
			[1 => [$this->obligation(1, 100)], 2 => [$this->obligation(2, 100)], 3 => [$this->obligation(3, 100)]],
			[11 => $this->completed(11, '2026-09-22'), 13 => $this->completed(13, '2026-09-24')],
			[11 => [$this->share(100)], 13 => [$this->share(100)]]);
		self::assertSame(2, $this->service->recomputeForMembers(1, [$this->user(1, 100)],
			new \DateTime('2026-09-25 12:00:00 UTC'))[1]['currentStreak']);
	}

	public function testRecomputeForMembers_LateCatchUp_NeverCountsAsOnTime(): void {
		$this->seed([$this->occurrence(1, '2026-09-20', 11)], [1 => [$this->obligation(1, 100)]],
			[11 => $this->completed(11, '2026-09-24', CompletedTask::CLASS_LATE)], [11 => [$this->share(100)]]);
		self::assertSame(0, $this->service->recomputeForMembers(1, [$this->user(1, 100)],
			new \DateTime('2026-09-25 12:00:00 UTC'))[1]['longestStreak']);
	}

	public function testRecomputeForMembers_SuspendedLateVoluntaryWork_DoesNotCreateMiss(): void {
		$this->seed([$this->occurrence(1, '2026-09-19', 10),
			$this->occurrence(2, '2026-09-20', 11, null, true),
			$this->occurrence(3, '2026-09-22', 13)],
			[1 => [$this->obligation(1, 100)], 2 => [$this->obligation(2, 100)], 3 => [$this->obligation(3, 100)]],
			[10 => $this->completed(10, '2026-09-19'),
				11 => $this->completed(11, '2026-09-24', CompletedTask::CLASS_LATE),
				13 => $this->completed(13, '2026-09-22')],
			[10 => [$this->share(100)], 11 => [$this->share(100)], 13 => [$this->share(100)]]);
		self::assertSame(2, $this->service->recomputeForMembers(1, [$this->user(1, 100)],
			new \DateTime('2026-09-25 12:00:00 UTC'))[1]['currentStreak']);
	}

	public function testRecomputeForMembers_EarlyWork_WaitsForItsPlannedDay(): void {
		$occurrence = $this->occurrence(1, '2026-09-26', 11);
		$occurrence->setWindowStart(new \DateTime('2026-09-25 00:00:00 UTC'));
		$this->seed([$occurrence], [1 => [$this->obligation(1, 100)]],
			[11 => $this->completed(11, '2026-09-25')], [11 => [$this->share(100)]]);
		$user = $this->user(1, 100);
		self::assertSame(0, $this->service->recomputeForMembers(1, [$user], new \DateTime('2026-09-25 12:00:00 UTC'))[1]['currentStreak']);
		self::assertSame(1, $this->service->recomputeForMembers(1, [$user], new \DateTime('2026-09-26 12:00:00 UTC'))[1]['currentStreak']);
	}

	public function testRecomputeForMembers_OlderThanOnePage_RetainsIndependentLifetimeRun(): void {
		$rows = $members = $completions = $shares = [];
		for ($id = 1; $id <= 303; $id++) {
			$day = (new \DateTime('2025-01-01 UTC'))->modify('+' . $id . ' days')->format('Y-m-d');
			$rows[] = $this->occurrence($id, $day, $id + 1000);
			$members[$id] = [$this->obligation($id, 100)];
			$completions[$id + 1000] = $this->completed($id + 1000, $day);
			$shares[$id + 1000] = [$this->share(100)];
		}
		$this->seed($rows, $members, $completions, $shares);
		$result = $this->service->recomputeForMembers(1, [$this->user(1, 100)], new \DateTime('2026-09-25 12:00:00 UTC'));
		self::assertSame(303, $result[1]['longestStreak']);
	}

	public function testRefreshForJobs_ExpiredDeadlineWithoutMutation_ResetsPersistedStreak(): void {
		$this->seed([$this->occurrence(1, '2026-09-24', null)], [1 => [$this->obligation(1, 100)]], [], []);
		$user = $this->user(1, 100);
		$user->setCurrentStreak(2);
		$user->setLongestStreak(2);
		$house = new House();
		$house->setId(1);
		$this->houses->method('findOneForJobs')->willReturn($house);
		$this->houses->expects($this->once())->method('findForUpdate')->with(1)->willReturn($house);
		$this->houseUsers->method('findByHouseId')->with(1)->willReturn([$user]);
		$this->houseUsers->expects($this->once())->method('findForUpdate')->with(1)->willReturn($user);
		$this->houseUsers->expects($this->once())->method('update')->with($user);

		$this->service->refreshForJobs(new \DateTime('2026-09-25 12:00:00 UTC'));
		self::assertSame(0, $user->getCurrentStreak());
		self::assertSame(0, $user->getLongestStreak());
	}
}
