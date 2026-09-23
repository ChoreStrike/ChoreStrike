<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\ScheduleRevision;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Service\RecurrenceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * T1-A pins (CS-PLAN-072 WP1; parent CS-PLAN-070 T1-A): the recurrence
 * calculator enumerates LOCAL CALENDAR DATES from immutable rule inputs —
 * never elapsed 86,400-second arithmetic — and ordinals are positions in
 * the complete anchored sequence, independent of any query window.
 *
 * Pinned families (expected date lists are FROZEN regression data):
 *  - daily interval N from an anchor,
 *  - weekly on named weekdays (multi-day weeks),
 *  - fortnightly anchored on the CHOSEN first anchor (2026-12-26 →
 *    2027-01-09, 2027-01-23; never odd/even ISO weeks),
 *  - monthly with clamp-to-last-day preserving the original day (Jan 31 →
 *    Feb 28/29 → Mar 31; same for 29/30),
 *  - completion-relative interval successor arithmetic (calendar months,
 *    DST-safe week/day steps),
 *  - active-bound clamping and query-window independence.
 */
class RecurrenceCalculatorTest extends TestCase {
	private RecurrenceCalculator $calculator;

	protected function setUp(): void {
		$this->calculator = new RecurrenceCalculator();
	}

	private function buildRevision(array $overrides = []): ScheduleRevision {
		$revision = new ScheduleRevision();
		$revision->setId(1);
		$revision->setRoomTaskId(1);
		$revision->setRevision(1);
		$revision->setCadenceMode($overrides['cadenceMode'] ?? ScheduleRevision::CADENCE_FIXED);
		$revision->setCadenceUnit($overrides['cadenceUnit'] ?? ScheduleRevision::UNIT_DAY);
		$revision->setCadenceInterval($overrides['cadenceInterval'] ?? 1);
		$revision->setAnchorDate(new \DateTime($overrides['anchor'] ?? '2026-09-01'));
		if (isset($overrides['weekdays'])) {
			$revision->setWeekdays(json_encode($overrides['weekdays']));
		}
		$revision->setLifetime($overrides['lifetime'] ?? Task::RECURRENCE_RECURRING);
		if (isset($overrides['activeFrom'])) {
			$revision->setActiveFrom(new \DateTime($overrides['activeFrom']));
		}
		if (isset($overrides['activeUntil'])) {
			$revision->setActiveUntil(new \DateTime($overrides['activeUntil']));
		}
		return $revision;
	}

	private function dates(ScheduleRevision $revision, string $from, string $to): array {
		return array_map(
			static fn (array $entry): string => $entry['date'],
			$this->calculator->enumerate($revision, $from, $to)
		);
	}

	private function ordinals(ScheduleRevision $revision, string $from, string $to): array {
		return array_map(
			static fn (array $entry): int => $entry['ordinal'],
			$this->calculator->enumerate($revision, $from, $to)
		);
	}

	public function testDailyEveryThirdDayFromAnchor(): void {
		$revision = $this->buildRevision([
			'cadenceUnit' => ScheduleRevision::UNIT_DAY,
			'cadenceInterval' => 3,
			'anchor' => '2026-09-01',
		]);

		self::assertSame(
			['2026-09-01', '2026-09-04', '2026-09-07', '2026-09-10', '2026-09-13'],
			$this->dates($revision, '2026-09-01', '2026-09-15'),
			'daily interval 3 counts calendar days from the anchor'
		);
	}

	public function testWeeklyNamedWeekdays_EmitsEveryConfiguredDay(): void {
		$revision = $this->buildRevision([
			'cadenceUnit' => ScheduleRevision::UNIT_WEEK,
			'cadenceInterval' => 1,
			'anchor' => '2026-09-02', // Wednesday
			'weekdays' => ['monday', 'wednesday', 'friday'],
		]);

		self::assertSame(
			['2026-09-02', '2026-09-04', '2026-09-07', '2026-09-09', '2026-09-11', '2026-09-14'],
			$this->dates($revision, '2026-09-01', '2026-09-15'),
			'week 0 emits only weekdays on/after the anchor itself'
		);
	}

	/**
	 * T1-A fortnightly pin: the cadence anchors on the Monday of the ISO
	 * week of the CHOSEN first anchor — never on odd/even ISO weeks.
	 */
	public function testFortnightlyAnchorsOnChosenFirstAnchor(): void {
		$revision = $this->buildRevision([
			'cadenceUnit' => ScheduleRevision::UNIT_WEEK,
			'cadenceInterval' => 2,
			'anchor' => '2026-12-26', // Saturday
			'weekdays' => ['saturday'],
		]);

		self::assertSame(
			['2026-12-26', '2027-01-09', '2027-01-23'],
			$this->dates($revision, '2026-12-01', '2027-01-31'),
			'fortnightly = every 2nd week from the anchor week, not odd/even ISO weeks'
		);
	}

	/**
	 * T1-A monthly clamp pin: the originally selected day survives months
	 * without it — Jan 31 → Feb 28 (2026) → Mar 31; Jan 30 → Feb 28;
	 * Feb 29 → Feb 28 (non-leap).
	 */
	public function testMonthlyClampsToLastDay_PreservingOriginalDay(): void {
		$revision = $this->buildRevision([
			'cadenceUnit' => ScheduleRevision::UNIT_MONTH,
			'cadenceInterval' => 1,
			'anchor' => '2026-01-31',
		]);

		self::assertSame(
			['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'],
			$this->dates($revision, '2026-01-01', '2026-04-30')
		);

		$leap = $this->buildRevision([
			'cadenceUnit' => ScheduleRevision::UNIT_MONTH,
			'anchor' => '2028-01-31',
		]);
		self::assertSame(
			['2028-01-31', '2028-02-29', '2028-03-31'],
			$this->dates($leap, '2028-01-01', '2028-03-31'),
			'the clamp uses the month\'s real last day (leap February)'
		);
	}

	public function testMonthlyEveryThirdMonth(): void {
		$revision = $this->buildRevision([
			'cadenceUnit' => ScheduleRevision::UNIT_MONTH,
			'cadenceInterval' => 3,
			'anchor' => '2026-01-15',
		]);

		self::assertSame(
			['2026-01-15', '2026-04-15', '2026-07-15'],
			$this->dates($revision, '2026-01-01', '2026-08-31')
		);
	}

	public function testIntervalRevisionsYieldNothing_InEnumerate(): void {
		$revision = $this->buildRevision([
			'cadenceMode' => ScheduleRevision::CADENCE_INTERVAL,
			'cadenceUnit' => ScheduleRevision::UNIT_DAY,
		]);

		self::assertSame([], $this->dates($revision, '2026-01-01', '2027-01-01'));
	}

	/**
	 * Interval successor arithmetic is calendar-based: months clamp
	 * (Jan 31 + 1 month = Feb 28), weeks/days step whole calendar units
	 * (never elapsed-time math — DST correctness is delegated to the
	 * calendar service when a date becomes an instant).
	 */
	public function testNextIntervalDate_MonthClamps_WeekAndDayStep(): void {
		self::assertSame('2026-02-28', $this->calculator->nextIntervalDate('2026-01-31', 'month', 1));
		self::assertSame('2026-03-31', $this->calculator->nextIntervalDate('2026-01-31', 'month', 2));
		self::assertSame('2026-02-28', $this->calculator->nextIntervalDate('2026-01-31', 'week', 4));
		self::assertSame('2027-01-01', $this->calculator->nextIntervalDate('2026-12-25', 'day', 7));
		self::assertSame('2026-10-05', $this->calculator->nextIntervalDate('2026-10-01', 'day', 4));
	}

	/**
	 * Ordinals are positions in the COMPLETE anchored sequence — a query
	 * window never renumbers them.
	 */
	public function testOrdinalsAreQueryWindowIndependent(): void {
		$revision = $this->buildRevision([
			'cadenceUnit' => ScheduleRevision::UNIT_DAY,
			'cadenceInterval' => 7,
			'anchor' => '2026-09-07',
		]);

		self::assertSame([1, 2, 3], $this->ordinals($revision, '2026-09-01', '2026-09-25'));
		self::assertSame([3], $this->ordinals($revision, '2026-09-18', '2026-09-25'));
		self::assertSame([4, 5, 6], $this->ordinals($revision, '2026-09-26', '2026-10-15'));
	}

	public function testActiveBoundsClampEmission_OrdinalsUnaffected(): void {
		$revision = $this->buildRevision([
			'cadenceUnit' => ScheduleRevision::UNIT_DAY,
			'cadenceInterval' => 1,
			'anchor' => '2026-09-01',
			'activeFrom' => '2026-09-03',
			'activeUntil' => '2026-09-05',
		]);

		self::assertSame(
			['2026-09-03', '2026-09-04', '2026-09-05'],
			$this->dates($revision, '2026-09-01', '2026-09-30'),
			'bounds clamp emission (inclusive) but never renumber ordinals'
		);
		self::assertSame([3, 4, 5], $this->ordinals($revision, '2026-09-01', '2026-09-30'));
	}

	public function testOnceLifetime_SingleAnchorEmission(): void {
		$revision = $this->buildRevision([
			'lifetime' => Task::RECURRENCE_ONCE,
			'anchor' => '2026-10-11',
		]);

		self::assertSame(['2026-10-11'], $this->dates($revision, '2026-10-01', '2026-10-31'));
		self::assertSame([], $this->dates($revision, '2026-10-12', '2026-10-31'));
	}

	public function testWeeklyWithoutWeekdays_FallsBackToWholeWeekSteps(): void {
		$revision = $this->buildRevision([
			'cadenceUnit' => ScheduleRevision::UNIT_WEEK,
			'cadenceInterval' => 1,
			'anchor' => '2026-09-02',
		]);

		self::assertSame(
			['2026-09-02', '2026-09-09', '2026-09-16'],
			$this->dates($revision, '2026-09-01', '2026-09-20'),
			'weekly without a weekday set steps whole weeks from the anchor'
		);
	}

	public function testMondayOfIsoWeek(): void {
		self::assertSame('2026-12-21', RecurrenceCalculator::mondayOf('2026-12-26'));
		self::assertSame('2026-09-07', RecurrenceCalculator::mondayOf('2026-09-07'));
		self::assertSame('2026-09-07', RecurrenceCalculator::mondayOf('2026-09-13'));
	}

	public function testDegenerateInputs_NonAdvancingGuardsDoNotLoop(): void {
		$revision = $this->buildRevision([
			'cadenceUnit' => ScheduleRevision::UNIT_DAY,
			'cadenceInterval' => 0,
			'anchor' => '2026-09-01',
		]);

		// The calculator clamps a degenerate zero interval to 1 — bounded
		// emission, never a non-advancing loop.
		self::assertSame(
			['2026-09-01', '2026-09-02', '2026-09-03'],
			$this->dates($revision, '2026-09-01', '2026-09-03')
		);
	}

	public function testReversedWindow_ReturnsNothing(): void {
		$revision = $this->buildRevision(['anchor' => '2026-09-01']);
		self::assertSame([], $this->dates($revision, '2026-10-01', '2026-09-01'));
	}
}
