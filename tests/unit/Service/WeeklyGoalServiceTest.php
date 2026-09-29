<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\WorkShareMapper;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\WeeklyGoalService;
use PHPUnit\Framework\TestCase;

class WeeklyGoalServiceTest extends TestCase {
	private House $house;
	private OccurrenceMapper $occurrences;
	private CompletedTaskMapper $completions;
	private WeeklyGoalService $service;

	protected function setUp(): void {
		$this->house = new House();
		$this->house->setId(1);
		$this->house->setTimezone('UTC');
		$houses = $this->createMock(HouseMapper::class);
		$houses->method('find')->willReturn($this->house);
		$this->occurrences = $this->createMock(OccurrenceMapper::class);
		$this->completions = $this->createMock(CompletedTaskMapper::class);
		// WP4: the 80% target is fixed policy — no reward-config dependency.
		$this->service = new WeeklyGoalService(
			$houses,
			$this->occurrences,
			$this->completions,
			$this->createMock(OccurrenceMemberMapper::class),
			$this->createMock(WorkShareMapper::class),
			new CalendarService()
		);
	}

	private function occurrence(int $id, string $day, ?int $completionId, bool $suspended = false, ?string $end = null): Occurrence {
		$row = new Occurrence();
		$row->setId($id);
		$row->setPlannedDate(new \DateTime($day));
		$row->setWindowStart(new \DateTime($day . ' 00:00:00 UTC'));
		$row->setWindowEnd(new \DateTime($end ?? $day . ' +1 day'));
		$row->setCompletionId($completionId);
		$row->setSuspensionState($suspended ? Occurrence::SUSPENSION_SUSPENDED : Occurrence::SUSPENSION_NONE);
		return $row;
	}

	private function completion(int $id, string $day, string $class = CompletedTask::CLASS_AGREED): CompletedTask {
		$row = new CompletedTask();
		$row->setId($id);
		$row->setCompletionClass($class);
		$row->setCompletedAt(new \DateTime($day . ' 12:00:00 UTC'));
		return $row;
	}

	/** @param Occurrence[] $rows */
	private function seed(array $rows, array $valid): void {
		$this->occurrences->method('findByHousePlannedBetween')->willReturnCallback(
			static fn (int $houseId, string $start, string $end): array => array_values(array_filter($rows,
				static fn (Occurrence $row): bool => $row->getPlannedDate()->format('Y-m-d') >= $start && $row->getPlannedDate()->format('Y-m-d') <= $end))
		);
		$this->completions->method('findValidByIds')->willReturnCallback(
			static fn (array $ids): array => array_intersect_key($valid, array_flip($ids))
		);
	}

	public function testProgressForWeek_DailyChore_HasSevenDistinctOpportunities(): void {
		$rows = [];
		for ($day = 0; $day < 7; $day++) {
			$date = (new \DateTime('2026-09-21 UTC'))->modify('+' . $day . ' days')->format('Y-m-d');
			$rows[] = $this->occurrence($day + 1, $date, $day === 0 ? 11 : null);
		}
		$this->seed($rows, [11 => $this->completion(11, '2026-09-21')]);
		$progress = $this->service->progressForWeek($this->house, new \DateTime('2026-09-24 12:00:00 UTC'));
		self::assertSame(7, $progress['dueCount']);
		self::assertSame(1, $progress['completedCount']);
		self::assertSame(14, $progress['percent']);
		self::assertTrue($progress['provisional']);
		self::assertFalse($progress['achieved']);
		// §3.4 read-contract names mirror the house counts.
		self::assertSame(7, $progress['eligible']);
		self::assertSame(1, $progress['onTime']);
		self::assertSame(0, $progress['neutral']);
		self::assertSame(80, $progress['targetPercent']);
	}

	public function testProgressForPlannedWeek_OnTimeCrossWeekWindow_RemainsInPlannedWeek(): void {
		$this->seed([$this->occurrence(1, '2026-09-27', 11, false, '2026-09-29 00:00:00 UTC')],
			[11 => $this->completion(11, '2026-09-28')]);
		$open = $this->service->progressForPlannedWeek($this->house, '2026-09-21', new \DateTime('2026-09-28 12:00:00 UTC'));
		self::assertSame(1, $open['completedCount']);
		self::assertTrue($open['provisional']);
		self::assertFalse($open['achieved']);
		$closed = $this->service->progressForPlannedWeek($this->house, '2026-09-21', new \DateTime('2026-09-29 12:00:00 UTC'));
		self::assertTrue($closed['achieved']);
		self::assertSame(1, $closed['dueCount']);
	}

	public function testProgressForWeek_LateCatchUp_DoesNotMoveIntoCurrentWeek(): void {
		$this->seed([$this->occurrence(1, '2026-09-21', 11)],
			[11 => $this->completion(11, '2026-09-29', CompletedTask::CLASS_LATE)]);
		$prior = $this->service->progressForPlannedWeek($this->house, '2026-09-21', new \DateTime('2026-09-30 UTC'));
		$current = $this->service->progressForWeek($this->house, new \DateTime('2026-09-30 UTC'));
		self::assertSame(1, $prior['dueCount']);
		self::assertSame(0, $prior['completedCount']);
		self::assertSame(0, $current['dueCount']);
		self::assertFalse($current['achieved']);
	}

	public function testProgressForWeek_SuspendedAndEmptyWeek_AreNeutral(): void {
		// The recognized suspension falls INSIDE the evaluated week (the
		// Monday 2026-09-28 week) — it is the neutral remainder.
		$this->seed([$this->occurrence(1, '2026-09-29', 11, true)],
			[11 => $this->completion(11, '2026-09-30', CompletedTask::CLASS_LATE)]);
		$progress = $this->service->progressForWeek($this->house, new \DateTime('2026-09-30 UTC'));
		self::assertSame(0, $progress['dueCount']);
		self::assertSame(0, $progress['percent']);
		self::assertFalse($progress['achieved']);
		self::assertSame(0, $progress['eligible']);
		self::assertSame(1, $progress['neutral']);
	}

	public function testProgressForPlannedWeek_UndoDeflatesValidNumerator(): void {
		$this->seed([$this->occurrence(1, '2026-09-21', 11), $this->occurrence(2, '2026-09-22', 12)],
			[11 => $this->completion(11, '2026-09-21')]);
		$progress = $this->service->progressForPlannedWeek($this->house, '2026-09-21', new \DateTime('2026-09-29 UTC'));
		self::assertSame(2, $progress['dueCount']);
		self::assertSame(1, $progress['completedCount']);
		self::assertSame(50, $progress['percent']);
	}

	public function testProgressForWeek_HouseLocalWeekWindow_IsHalfOpenAcrossDst(): void {
		$this->house->setTimezone('Europe/Berlin');
		$this->seed([], []);
		$progress = $this->service->progressForWeek($this->house, new \DateTime('2026-10-26 01:00:00 UTC'));
		self::assertSame('2026-10-26', $progress['weekKey']);
		self::assertSame('2026-10-25T23:00:00+00:00', $progress['weekStart']);
		self::assertSame('2026-11-01T23:00:00+00:00', $progress['weekEnd']);
	}
}
