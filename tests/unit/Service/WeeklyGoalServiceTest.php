<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\RecognitionFact;
use OCA\ChoreStrike\Service\RecognitionFactsService;
use OCA\ChoreStrike\Service\WeeklyGoalService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The weekly goal over the recognition facts (CS-PLAN-091 WP-B1, ADR-021
 * decision 5): what counts in a house-local week, the fixed 80 % target,
 * neutral absences and the provisional state of an open week.
 */
class WeeklyGoalServiceTest extends TestCase {
	private RecognitionFactsService&MockObject $facts;
	private WeeklyGoalService $service;
	private House $house;

	protected function setUp(): void {
		$this->facts = $this->createMock(RecognitionFactsService::class);
		$this->house = new House();
		$this->house->setId(1);
		$this->house->setTimezone('Europe/Berlin');
		$houses = $this->createMock(HouseMapper::class);
		$houses->method('find')->willReturn($this->house);
		$this->service = new WeeklyGoalService($houses, $this->facts, new CalendarService());
	}

	/**
	 * @param list<int> $workers
	 * @param list<int> $neutralFor
	 */
	private function fact(string $state, array $responsible = [500], array $workers = [], array $neutralFor = [], bool $houseNeutral = false): RecognitionFact {
		return new RecognitionFact(
			'2026-11-18',
			'2026-11-16',
			$state,
			$responsible,
			array_fill_keys($workers, true),
			array_fill_keys($neutralFor, true),
			$houseNeutral,
			in_array($state, [RecognitionFact::OPEN, RecognitionFact::UPCOMING], true),
			11,
		);
	}

	private function after(): \DateTime {
		return new \DateTime('2026-11-24 09:00:00', new \DateTimeZone('Europe/Berlin'));
	}

	public function testProgressForPlannedWeek_CountsKeptWorkAgainstEverythingDue(): void {
		$this->facts->expects(self::once())->method('factsForWeek')->with($this->house, '2026-11-16', self::isInstanceOf(\DateTime::class))->willReturn([
			$this->fact(RecognitionFact::KEPT, [500], [500]),
			$this->fact(RecognitionFact::KEPT, [], [501]),
			$this->fact(RecognitionFact::KEPT, [500], [501]),
			$this->fact(RecognitionFact::KEPT, [500], [500]),
			$this->fact(RecognitionFact::LATE, [500], [500]),
		]);

		$progress = $this->service->progressForPlannedWeek($this->house, '2026-11-16', $this->after());

		self::assertSame([5, 4, 80, true, false], [$progress['dueCount'], $progress['completedCount'], $progress['percent'], $progress['achieved'], $progress['provisional']]);
		self::assertSame([5, 4, 0, 80], [$progress['eligible'], $progress['onTime'], $progress['neutral'], $progress['targetPercent']]);
		self::assertSame('2026-11-16', $progress['weekKey']);
	}

	public function testProgressForPlannedWeek_LateAndMissedWork_NeverReachTheTarget(): void {
		$this->facts->method('factsForWeek')->willReturn([
			$this->fact(RecognitionFact::KEPT, [500], [500]),
			$this->fact(RecognitionFact::LATE, [500], [500]),
			$this->fact(RecognitionFact::MISSED),
		]);

		$progress = $this->service->progressForPlannedWeek($this->house, '2026-11-16', $this->after());

		self::assertSame([3, 1, 33, false], [$progress['dueCount'], $progress['completedCount'], $progress['percent'], $progress['achieved']]);
	}

	public function testProgressForPlannedWeek_AbsenceIsNeutral_KeptWorkDuringItStillCounts(): void {
		$this->facts->method('factsForWeek')->willReturn([
			$this->fact(RecognitionFact::MISSED, [500], [], [500], true),
			$this->fact(RecognitionFact::KEPT, [500], [500], [500], true),
		]);

		$progress = $this->service->progressForPlannedWeek($this->house, '2026-11-16', $this->after());

		self::assertSame([1, 1, 1, 100, true], [$progress['dueCount'], $progress['completedCount'], $progress['neutral'], $progress['percent'], $progress['achieved']]);
	}

	public function testProgressForPlannedWeek_EmptyWeek_IsNeutralAndNeverAchieved(): void {
		$this->facts->method('factsForWeek')->willReturn([]);

		$progress = $this->service->progressForPlannedWeek($this->house, '2026-11-16', $this->after());

		self::assertSame([0, 0, false], [$progress['dueCount'], $progress['percent'], $progress['achieved']]);
	}

	public function testProgressForPlannedWeek_AnOpenWindowKeepsAPastWeekProvisional(): void {
		$this->facts->method('factsForWeek')->willReturn([
			$this->fact(RecognitionFact::KEPT, [500], [500]),
			$this->fact(RecognitionFact::OPEN),
		]);

		$progress = $this->service->progressForPlannedWeek($this->house, '2026-11-16', $this->after());

		self::assertTrue($progress['provisional']);
		self::assertFalse($progress['achieved'], 'a week is only achieved once nothing in it can change');
	}

	public function testProgressForWeek_UsesTheHouseLocalWeekAndStaysProvisionalUntilItEnds(): void {
		// Sunday 22 November, 23:30 in Berlin, is still the week of the 16th
		// (and already Monday in Auckland): the house zone decides.
		$this->facts->expects(self::once())->method('factsForWeek')->with($this->house, '2026-11-16', self::anything())
			->willReturn([$this->fact(RecognitionFact::KEPT, [500], [500])]);

		$progress = $this->service->progressForWeek($this->house, new \DateTime('2026-11-22 23:30:00', new \DateTimeZone('Europe/Berlin')));

		self::assertSame('2026-11-16', $progress['weekKey']);
		self::assertTrue($progress['provisional']);
		self::assertFalse($progress['achieved']);
		self::assertSame('2026-11-15T23:00:00+00:00', $progress['weekStart'], 'the week starts at house-local midnight');
	}

	public function testProgressForPlannedWeek_RejectsAKeyThatIsNoMonday(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service->progressForPlannedWeek($this->house, '2026-11-17', $this->after());
	}

	public function testProgressForMember_CountsOnlyOwnWorkWithOwnShares(): void {
		$member = new HouseUser();
		$member->setId(7);
		$member->setWalletId(500);
		$this->facts->method('factsForWeek')->willReturn([
			$this->fact(RecognitionFact::KEPT, [500], [500]),
			$this->fact(RecognitionFact::KEPT, [500], [501]),
			$this->fact(RecognitionFact::MISSED, [500], [], [500]),
			$this->fact(RecognitionFact::KEPT, [500], [500], [500]),
			$this->fact(RecognitionFact::KEPT, [501], [501]),
			$this->fact(RecognitionFact::MISSED, []),
			$this->fact(RecognitionFact::OPEN, [500]),
		]);

		$slice = $this->service->progressForMember($this->house, $member, new \DateTime('2026-11-19 12:00:00', new \DateTimeZone('Europe/Berlin')));

		self::assertSame([4, 2, 1, true, '2026-11-16'], [$slice['eligible'], $slice['onTime'], $slice['neutral'], $slice['provisional'], $slice['weekKey']]);
	}

	public function testProgressForMember_WithoutAWallet_HasNothingToCount(): void {
		$this->facts->expects(self::never())->method('factsForWeek');

		$slice = $this->service->progressForMember($this->house, new HouseUser(), $this->after());

		self::assertSame([0, 0, 0], [$slice['eligible'], $slice['onTime'], $slice['neutral']]);
	}
}
