<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\ScheduleRevision;
use OCA\ChoreStrike\Service\CycleRule;
use OCA\ChoreStrike\Service\RecurrenceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * T1-A pins (CS-PLAN-072 WP1; ADR-021 decision 1): the recurrence
 * calculator works on LOCAL CALENDAR DATES from immutable rule inputs —
 * never elapsed 86,400-second arithmetic.
 *
 * Pinned families (expected date lists are FROZEN regression data, taken
 * over from the enumeration of the stored revisions that CS-PLAN-091 WP-B7
 * retired):
 *  - daily interval N from an anchor,
 *  - weekly on named weekdays (multi-day weeks),
 *  - fortnightly anchored on the CHOSEN first anchor (2026-12-26 →
 *    2027-01-09, 2027-01-23; never odd/even ISO weeks),
 *  - monthly with clamp-to-last-day preserving the original day (Jan 31 →
 *    Feb 28/29 → Mar 31; same for 29/30),
 *  - completion-relative interval successor arithmetic (calendar months,
 *    DST-safe week/day steps),
 *  - active-bound clamping.
 */
class RecurrenceCalculatorTest extends TestCase {
	private const ISO_WEEKDAYS = ['monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7];

	private RecurrenceCalculator $calculator;

	protected function setUp(): void {
		$this->calculator = new RecurrenceCalculator();
	}

	/**
	 * @param array{unit: string, interval?: int, weekdays?: list<string>, monthDay?: int, activeFrom?: string, activeUntil?: string} $shape
	 */
	private function fixedRule(array $shape): CycleRule {
		$weekdays = array_map(static fn (string $name): int => self::ISO_WEEKDAYS[$name], $shape['weekdays'] ?? []);
		sort($weekdays);
		return new CycleRule(true, false, false, $shape['unit'], $shape['interval'] ?? 1, $weekdays, $shape['monthDay'] ?? null, 0, $shape['activeFrom'] ?? null, $shape['activeUntil'] ?? null);
	}

	/**
	 * The rule days from `$from` through `$to`, each found from the day
	 * after the one before it.
	 *
	 * @return list<string>
	 */
	private function days(CycleRule $rule, string $anchor, string $from, string $to): array {
		$days = [];
		$day = $this->calculator->firstFixedDayOnOrAfter($rule, $anchor, $from);
		while ($day !== null && $day <= $to) {
			$days[] = $day;
			$day = $this->calculator->firstFixedDayOnOrAfter($rule, $anchor, RecurrenceCalculator::dayAfter($day));
		}
		return $days;
	}

	public function testDailyEveryThirdDayFromAnchor(): void {
		$rule = $this->fixedRule(['unit' => CycleRule::UNIT_DAY, 'interval' => 3]);

		self::assertSame(
			['2026-09-01', '2026-09-04', '2026-09-07', '2026-09-10', '2026-09-13'],
			$this->days($rule, '2026-09-01', '2026-09-01', '2026-09-15'),
			'daily interval 3 counts calendar days from the anchor'
		);
	}

	public function testWeeklyNamedWeekdays_HoldsEveryConfiguredDay(): void {
		$rule = $this->fixedRule(['unit' => CycleRule::UNIT_WEEK, 'weekdays' => ['monday', 'wednesday', 'friday']]);

		self::assertSame(
			['2026-09-02', '2026-09-04', '2026-09-07', '2026-09-09', '2026-09-11', '2026-09-14'],
			// The anchor is a Wednesday.
			$this->days($rule, '2026-09-02', '2026-09-01', '2026-09-15'),
			'the anchor week holds only weekdays on/after the anchor itself'
		);
	}

	/**
	 * T1-A fortnightly pin: the cadence anchors on the Monday of the ISO
	 * week of the CHOSEN first anchor — never on odd/even ISO weeks.
	 */
	public function testFortnightlyAnchorsOnChosenFirstAnchor(): void {
		$rule = $this->fixedRule(['unit' => CycleRule::UNIT_WEEK, 'interval' => 2, 'weekdays' => ['saturday']]);

		self::assertSame(
			['2026-12-26', '2027-01-09', '2027-01-23'],
			// The anchor is a Saturday.
			$this->days($rule, '2026-12-26', '2026-12-01', '2027-01-31'),
			'fortnightly = every 2nd week from the anchor week, not odd/even ISO weeks'
		);
	}

	/**
	 * T1-A monthly clamp pin: the originally selected day survives months
	 * without it — Jan 31 → Feb 28 (2026) → Mar 31 → Apr 30; Feb 29 in a
	 * leap year.
	 */
	public function testMonthlyClampsToLastDay_PreservingOriginalDay(): void {
		$rule = $this->fixedRule(['unit' => CycleRule::UNIT_MONTH]);

		self::assertSame(
			['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'],
			$this->days($rule, '2026-01-31', '2026-01-01', '2026-04-30'),
			'without a chosen day of the month the anchor\'s day is the rule day'
		);
		self::assertSame(
			['2028-01-31', '2028-02-29', '2028-03-31'],
			$this->days($this->fixedRule(['unit' => CycleRule::UNIT_MONTH, 'monthDay' => 31]), '2028-01-31', '2028-01-01', '2028-03-31'),
			'the clamp uses the month\'s real last day (leap February)'
		);
	}

	public function testMonthlyEveryThirdMonth(): void {
		$rule = $this->fixedRule(['unit' => CycleRule::UNIT_MONTH, 'interval' => 3]);

		self::assertSame(
			['2026-01-15', '2026-04-15', '2026-07-15'],
			$this->days($rule, '2026-01-15', '2026-01-01', '2026-08-31')
		);
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

	public function testActiveBounds_ClampTheRuleDays(): void {
		$rule = $this->fixedRule(['unit' => CycleRule::UNIT_DAY, 'activeFrom' => '2026-09-03', 'activeUntil' => '2026-09-05']);

		self::assertSame(
			['2026-09-03', '2026-09-04', '2026-09-05'],
			$this->days($rule, '2026-09-01', '2026-09-01', '2026-09-30'),
			'the bounds are inclusive'
		);
		self::assertNull($this->calculator->firstFixedDayOnOrAfter($rule, '2026-09-01', '2026-09-06'), 'no rule day is left past the bound');
	}

	public function testWeeklyWithoutWeekdays_FallsBackToWholeWeekSteps(): void {
		$rule = $this->fixedRule(['unit' => CycleRule::UNIT_WEEK]);

		self::assertSame(
			['2026-09-02', '2026-09-09', '2026-09-16'],
			$this->days($rule, '2026-09-02', '2026-09-01', '2026-09-20'),
			'weekly without a weekday set steps whole weeks from the anchor'
		);
	}

	public function testMondayOfIsoWeek(): void {
		self::assertSame('2026-12-21', RecurrenceCalculator::mondayOf('2026-12-26'));
		self::assertSame('2026-09-07', RecurrenceCalculator::mondayOf('2026-09-07'));
		self::assertSame('2026-09-07', RecurrenceCalculator::mondayOf('2026-09-13'));
	}

	/**
	 * Whether a day belongs to a fixed rule, decided per day from the rule's
	 * definition alone (ADR-021 decision 1) — the reference the lookup is
	 * compared with. It shares no code with the calculator.
	 *
	 * @param array{unit: string, interval?: int, weekdays?: list<string>, monthDay?: int, activeFrom?: string, activeUntil?: string} $shape
	 */
	private static function isRuleDay(array $shape, string $anchorKey, string $dayKey): bool {
		if ($dayKey < $anchorKey || $dayKey < ($shape['activeFrom'] ?? $dayKey) || $dayKey > ($shape['activeUntil'] ?? $dayKey)) {
			return false;
		}
		$utc = new \DateTimeZone('UTC');
		$anchor = new \DateTimeImmutable($anchorKey, $utc);
		$day = new \DateTimeImmutable($dayKey, $utc);
		$interval = $shape['interval'] ?? 1;

		if ($shape['unit'] === CycleRule::UNIT_MONTH) {
			$months = ((int)$day->format('Y') - (int)$anchor->format('Y')) * 12 + (int)$day->format('n') - (int)$anchor->format('n');
			$ruleDay = min($shape['monthDay'] ?? (int)$anchor->format('j'), (int)$day->format('t'));
			return $months % $interval === 0 && (int)$day->format('j') === $ruleDay;
		}
		if ($shape['unit'] === CycleRule::UNIT_WEEK && ($shape['weekdays'] ?? []) !== []) {
			$weeks = intdiv($anchor->modify('monday this week')->diff($day->modify('monday this week'))->days, 7);
			return $weeks % $interval === 0 && in_array(strtolower($day->format('l')), $shape['weekdays'], true);
		}
		$step = $shape['unit'] === CycleRule::UNIT_WEEK ? $interval * 7 : $interval;
		return $anchor->diff($day)->days % $step === 0;
	}

	/**
	 * ADR-021 decision 1: the lookup finds a rule day without walking the
	 * sequence from its anchor. Its days through March 2028 must be exactly
	 * the days the rule's definition names, and whatever day it starts from,
	 * it must land on the next of them. The frozen first days of each shape
	 * pin the reference itself.
	 *
	 * @param list<string> $firstDays
	 * @dataProvider fixedRuleProvider
	 */
	public function testFirstFixedDayOnOrAfter_LandsOnTheNextRuleDayFromAnyDay(array $shape, string $anchor, array $firstDays): void {
		$rule = $this->fixedRule($shape);
		$expected = [];
		for ($day = $anchor; $day <= '2028-03-31'; $day = (new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d')) {
			if (self::isRuleDay($shape, $anchor, $day)) {
				$expected[] = $day;
			}
		}
		$sequence = $this->days($rule, $anchor, '2020-01-01', '2028-03-31');

		self::assertSame($firstDays, array_slice($expected, 0, count($firstDays)));
		self::assertSame($expected, $sequence, 'the lookup yields the days of the rule and no others');
		self::assertGreaterThanOrEqual($anchor, $sequence[0], 'no rule day lies before the anchor');

		for ($probe = $anchor; $probe <= '2027-03-31'; $probe = RecurrenceCalculator::dayAfter($probe)) {
			$next = null;
			foreach ($sequence as $date) {
				if ($date >= $probe) {
					$next = $date;
					break;
				}
			}
			self::assertSame($next, $this->calculator->firstFixedDayOnOrAfter($rule, $anchor, $probe), 'from ' . $probe);
		}
	}

	public static function fixedRuleProvider(): array {
		return [
			'every day' => [['unit' => CycleRule::UNIT_DAY], '2026-10-20', ['2026-10-20', '2026-10-21', '2026-10-22']],
			'every third day' => [['unit' => CycleRule::UNIT_DAY, 'interval' => 3], '2026-10-20', ['2026-10-20', '2026-10-23', '2026-10-26']],
			'whole weeks without weekdays' => [['unit' => CycleRule::UNIT_WEEK, 'interval' => 2], '2026-10-21', ['2026-10-21', '2026-11-04', '2026-11-18', '2026-12-02']],
			// The anchor is a Wednesday: its week holds the Thursday only.
			'two weekdays, anchored mid-week' => [['unit' => CycleRule::UNIT_WEEK, 'weekdays' => ['monday', 'thursday']], '2026-10-21', ['2026-10-22', '2026-10-26', '2026-10-29', '2026-11-02']],
			'fortnightly on the anchor parity' => [['unit' => CycleRule::UNIT_WEEK, 'interval' => 2, 'weekdays' => ['saturday']], '2026-12-26', ['2026-12-26', '2027-01-09', '2027-01-23']],
			'monthly on the 31st' => [['unit' => CycleRule::UNIT_MONTH, 'monthDay' => 31], '2026-10-31', ['2026-10-31', '2026-11-30', '2026-12-31', '2027-01-31', '2027-02-28']],
			'every second month on the 30th' => [['unit' => CycleRule::UNIT_MONTH, 'interval' => 2, 'monthDay' => 30], '2026-12-30', ['2026-12-30', '2027-02-28', '2027-04-30', '2027-06-30']],
			'bounded weekdays' => [['unit' => CycleRule::UNIT_WEEK, 'weekdays' => ['tuesday'], 'activeFrom' => '2026-11-10', 'activeUntil' => '2027-01-31'], '2026-10-20', ['2026-11-10', '2026-11-17', '2026-11-24']],
		];
	}

	public function testFirstFixedDayOnOrAfter_BoundedRuleEndsOnItsLastDay(): void {
		$rule = $this->fixedRule(['unit' => CycleRule::UNIT_WEEK, 'weekdays' => ['tuesday'], 'activeFrom' => '2026-11-10', 'activeUntil' => '2027-01-31']);
		$sequence = $this->days($rule, '2026-10-20', '2020-01-01', '2028-03-31');

		self::assertCount(12, $sequence);
		self::assertSame('2027-01-26', end($sequence), 'the last Tuesday inside the bound');
	}

	public function testFirstFixedDayOnOrAfter_MonthDayAheadOfTheAnchorDayStartsInTheAnchorMonth(): void {
		$rule = new CycleRule(true, false, false, ScheduleRevision::UNIT_MONTH, 1, [], 20, 0, null, null);

		self::assertSame('2026-11-20', $this->calculator->firstFixedDayOnOrAfter($rule, '2026-11-05', '2026-11-05'));
		self::assertSame('2026-12-20', $this->calculator->firstFixedDayOnOrAfter($rule, '2026-11-25', '2026-11-25'), 'never before the anchor');
		self::assertSame(44, RecurrenceCalculator::daysBetween('2026-10-20', '2026-12-03'), 'across the end of summer time');
	}

	/**
	 * RF-091-05: the rule of the derived windows carries its own units and
	 * reads the one weekday list. The units keep the values of the stored
	 * schedule revisions, which nothing writes any more.
	 */
	public function testCycleRule_UnitsAndWeekdays_MatchTheStoredModel(): void {
		self::assertSame(
			[\OCA\ChoreStrike\Db\ScheduleRevision::UNIT_DAY, \OCA\ChoreStrike\Db\ScheduleRevision::UNIT_WEEK, \OCA\ChoreStrike\Db\ScheduleRevision::UNIT_MONTH],
			[\OCA\ChoreStrike\Service\CycleRule::UNIT_DAY, \OCA\ChoreStrike\Service\CycleRule::UNIT_WEEK, \OCA\ChoreStrike\Service\CycleRule::UNIT_MONTH]
		);

		$task = new \OCA\ChoreStrike\Db\Task();
		$task->setSchedulingMode(\OCA\ChoreStrike\Db\Task::SCHEDULING_STATIC);
		$task->setFrequencyType('weekly');
		$task->setFrequencyValue(1);
		$task->setFixedScheduleDays(json_encode(['Sunday', 'monday', ' wednesday ', 'noday']));

		// ISO weekdays, Monday first, as the canonical list orders them.
		self::assertSame([1, 3, 7], \OCA\ChoreStrike\Service\CycleRule::fromTask($task)->weekdays);
	}
}
