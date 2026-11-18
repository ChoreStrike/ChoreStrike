<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Service\CurrentCycle;
use OCA\ChoreStrike\Service\CycleHistory;
use OCA\ChoreStrike\Service\RecurrenceCalculator;
use OCA\ChoreStrike\Service\WindowService;
use PHPUnit\Framework\TestCase;

/**
 * Derived due windows (CS-PLAN-091 WP-B1, ADR-021 decisions 1 and 2): one
 * current cycle per room task, computed from the rule, the stored inputs
 * and the valid completions in house-local days. The expected days are
 * frozen regression data.
 */
class WindowServiceTest extends TestCase {
	private const ZONE = 'Europe/Berlin';

	private WindowService $windows;

	protected function setUp(): void {
		$this->windows = new WindowService(new RecurrenceCalculator(), $this->createMock(CompletedTaskMapper::class));
	}

	private function task(array $overrides = []): Task {
		$task = new Task();
		$task->setId(7);
		$task->setSchedulingMode($overrides['mode'] ?? Task::SCHEDULING_DYNAMIC);
		$task->setFrequencyType($overrides['type'] ?? 'weekly');
		$task->setFrequencyValue($overrides['value'] ?? 1);
		$task->setRecurrenceMode($overrides['recurrence'] ?? Task::RECURRENCE_RECURRING);
		$task->setFlexDays($overrides['flex'] ?? 0);
		$task->setIsVoluntary($overrides['bonus'] ?? false);
		if (isset($overrides['weekdays'])) {
			$task->setFixedScheduleDays(json_encode($overrides['weekdays']));
		}
		if (isset($overrides['monthDay'])) {
			$task->setFixedMonthDay($overrides['monthDay']);
		}
		if (isset($overrides['activeFrom'])) {
			$task->setActiveFrom(new \DateTime($overrides['activeFrom'] . ' 12:00:00', new \DateTimeZone(self::ZONE)));
		}
		if (isset($overrides['activeUntil'])) {
			$task->setActiveUntil(new \DateTime($overrides['activeUntil'] . ' 12:00:00', new \DateTimeZone(self::ZONE)));
		}
		return $task;
	}

	private function roomTask(?string $anchor, array $overrides = []): RoomTask {
		$roomTask = new RoomTask();
		$roomTask->setId(11);
		$roomTask->setTaskId(7);
		$roomTask->setIsActive($overrides['active'] ?? true);
		$roomTask->setCreatedAt(new \DateTime($overrides['createdAt'] ?? '2026-10-01 08:00:00 UTC'));
		if ($anchor !== null) {
			$roomTask->setAnchorOn(new \DateTime($anchor));
		}
		if (isset($overrides['sleepingSince'])) {
			$roomTask->setSleepingSince(new \DateTime($overrides['sleepingSince']));
		}
		if (isset($overrides['cycleKey'])) {
			$roomTask->setCycleKey($overrides['cycleKey']);
			$roomTask->setPostponeCount($overrides['postponeCount'] ?? 1);
			$roomTask->setPostponeDays($overrides['postponeDays'] ?? 1);
		}
		return $roomTask;
	}

	private function instant(string $localTime, string $zone = self::ZONE): \DateTime {
		return new \DateTime($localTime, new \DateTimeZone($zone));
	}

	private function current(RoomTask $roomTask, Task $task, ?CycleHistory $history, string $now, string $zone = self::ZONE): ?CurrentCycle {
		return $this->windows->currentCycle($roomTask, $task, $history, new \DateTimeZone($zone), $this->instant($now, $zone));
	}

	/** @return list<string> */
	private function centres(array $cycles): array {
		return array_map(static fn (CurrentCycle $cycle): string => $cycle->centreOn, $cycles);
	}

	// ------------------------------------------------------------------
	// After completion
	// ------------------------------------------------------------------

	public function testAfterCompletion_NeverDone_WindowAroundTheFirstDate(): void {
		$cycle = $this->current($this->roomTask('2026-11-20'), $this->task(['flex' => 2]), null, '2026-11-18 10:00');

		self::assertSame(['d2026-11-20', '2026-11-20', '2026-11-18', '2026-11-22'], [$cycle->key, $cycle->centreOn, $cycle->startOn, $cycle->endOn]);
		self::assertFalse($cycle->isOverdue('2026-11-22'));
		self::assertTrue($cycle->isOverdue('2026-11-23'), 'overdue once the window has ended');
	}

	public function testAfterCompletion_Done_NextWindowFollowsTheWorkDayOfTheLatestCompletion(): void {
		$history = new CycleHistory(41, '2026-11-10', 'd2026-11-03');

		$weekly = $this->current($this->roomTask('2026-11-03'), $this->task(['flex' => 1]), $history, '2026-11-18 10:00');
		self::assertSame(['c41', '2026-11-17', '2026-11-16', '2026-11-18'], [$weekly->key, $weekly->centreOn, $weekly->startOn, $weekly->endOn]);

		$everyThreeDays = $this->current($this->roomTask('2026-11-03'), $this->task(['type' => 'daily', 'value' => 3]), $history, '2026-11-18 10:00');
		self::assertSame('2026-11-13', $everyThreeDays->centreOn);
		self::assertTrue($everyThreeDays->isOverdue('2026-11-18'), 'one red entry, however many periods passed');
	}

	public function testAfterCompletion_Monthly_ClampsToTheLastDayOfAShorterMonth(): void {
		$cycle = $this->current($this->roomTask('2027-01-01'), $this->task(['type' => 'monthly']), new CycleHistory(5, '2027-01-31', 'c4'), '2027-02-01 09:00');

		self::assertSame('2027-02-28', $cycle->centreOn);
	}

	public function testAfterCompletion_FirstCycleDoneEarly_DoesNotComeBack(): void {
		// The first date is still ahead, and the completion fulfilled exactly that cycle.
		$cycle = $this->current($this->roomTask('2026-11-25'), $this->task(), new CycleHistory(9, '2026-11-18', 'd2026-11-25'), '2026-11-18 20:00');

		self::assertSame(['c9', '2026-11-25'], [$cycle->key, $cycle->centreOn]);
	}

	/**
	 * Review regression (CS-PLAN-091 WP-B1 interim Full review): a second
	 * early completion before a future first date must not bring the
	 * fulfilled first cycle back, or every later completion conflicts.
	 */
	public function testAfterCompletion_TwoEarlyCompletionsBeforeAFutureFirstDate_TheFirstCycleStaysUsed(): void {
		// Done early for d2026-11-25 on the 19th, then the next cycle (c9) on the 20th.
		$cycle = $this->current($this->roomTask('2026-11-25'), $this->task(['type' => 'daily']), new CycleHistory(10, '2026-11-20', 'c9', '2026-11-25'), '2026-11-20 20:00');

		self::assertSame(['c10', '2026-11-21'], [$cycle->key, $cycle->centreOn]);
	}

	public function testAfterCompletion_AnchoredAgainAfterTheLatestCompletion_UsesTheDay(): void {
		// A wake day after the last completion starts a fresh cycle (decision 7).
		$cycle = $this->current($this->roomTask('2026-11-16'), $this->task(), new CycleHistory(9, '2026-09-01', 'c8'), '2026-11-18 10:00');

		self::assertSame(['d2026-11-16', '2026-11-16'], [$cycle->key, $cycle->centreOn]);
	}

	public function testAfterCompletion_WithoutAStoredAnchor_FallsBackToTheCreationDay(): void {
		// 22:30 UTC on 30 September is already 1 October in Berlin.
		$roomTask = $this->roomTask(null, ['createdAt' => '2026-09-30 22:30:00 UTC']);

		self::assertSame('d2026-10-01', $this->current($roomTask, $this->task(), null, '2026-11-18 10:00')->key);
	}

	public function testAfterCompletion_Bounded_EndsWhenTheNextCentreLiesBeyondTheBound(): void {
		$task = $this->task(['recurrence' => Task::RECURRENCE_BOUNDED, 'activeUntil' => '2026-11-30']);

		self::assertSame('2026-11-27', $this->current($this->roomTask('2026-11-06'), $task, new CycleHistory(3, '2026-11-20', 'c2'), '2026-11-21 10:00')->centreOn);
		self::assertNull($this->current($this->roomTask('2026-11-06'), $task, new CycleHistory(4, '2026-11-27', 'c3'), '2026-11-28 10:00'));
	}

	// ------------------------------------------------------------------
	// One-time
	// ------------------------------------------------------------------

	public function testOneTime_OpenOnItsFirstDateAndGoneOnceDone(): void {
		$task = $this->task(['recurrence' => Task::RECURRENCE_ONCE, 'flex' => 1]);

		$open = $this->current($this->roomTask('2026-11-20'), $task, null, '2026-12-24 10:00');
		self::assertSame(['d2026-11-20', '2026-11-19', '2026-11-21'], [$open->key, $open->startOn, $open->endOn]);
		self::assertTrue($open->isOverdue('2026-12-24'), 'never missed: it stays the red entry until it is done late');
		self::assertSame([], $this->windows->missedCycles($this->roomTask('2026-11-20'), $task, null, new \DateTimeZone(self::ZONE), $this->instant('2026-12-24 10:00')));

		self::assertNull($this->current($this->roomTask('2026-11-20'), $task, new CycleHistory(2, '2026-12-24', 'd2026-11-20'), '2026-12-24 11:00'));
	}

	public function testOneTime_OnAFixedDefinition_IsStillASingleCycle(): void {
		$task = $this->task(['recurrence' => Task::RECURRENCE_ONCE, 'mode' => Task::SCHEDULING_STATIC, 'weekdays' => ['saturday']]);

		self::assertSame('d2026-11-18', $this->current($this->roomTask('2026-11-18'), $task, null, '2026-11-30 10:00')->key);
	}

	// ------------------------------------------------------------------
	// Fixed days
	// ------------------------------------------------------------------

	public function testFixedWeekday_StaysCurrentUntilTheNextWindowOpens(): void {
		$task = $this->task(['mode' => Task::SCHEDULING_STATIC, 'weekdays' => ['saturday'], 'flex' => 1]);
		$roomTask = $this->roomTask('2026-11-02');

		// Wednesday: Saturday 14 has ended, Saturday 21 opens on Friday 20.
		$wednesday = $this->current($roomTask, $task, null, '2026-11-18 10:00');
		self::assertSame(['d2026-11-14', '2026-11-13', '2026-11-15'], [$wednesday->key, $wednesday->startOn, $wednesday->endOn]);
		self::assertTrue($wednesday->isOverdue('2026-11-18'));

		$friday = $this->current($roomTask, $task, null, '2026-11-20 00:05');
		self::assertSame('d2026-11-21', $friday->key, 'the red entry ends when the next window opens');
		self::assertFalse($friday->isOverdue('2026-11-20'));
	}

	public function testFixedWeekday_MissedCyclesAreTheOnesThatStoppedBeingCurrent(): void {
		$task = $this->task(['mode' => Task::SCHEDULING_STATIC, 'weekdays' => ['saturday'], 'flex' => 1]);
		$roomTask = $this->roomTask('2026-11-02');
		$zone = new \DateTimeZone(self::ZONE);

		self::assertSame(['2026-11-07'], $this->centres($this->windows->missedCycles($roomTask, $task, null, $zone, $this->instant('2026-11-18 10:00'))));
		self::assertSame(['2026-11-07', '2026-11-14'], $this->centres($this->windows->missedCycles($roomTask, $task, null, $zone, $this->instant('2026-11-20 10:00'))));
		self::assertSame(['2026-11-14'], $this->centres($this->windows->missedCycles($roomTask, $task, null, $zone, $this->instant('2026-11-20 10:00'), '2026-11-07')), 'recorded cycles are not walked again');
		self::assertSame([], $this->windows->missedCycles($roomTask, $task, new CycleHistory(3, '2026-11-14', 'd2026-11-14', '2026-11-14'), $zone, $this->instant('2026-11-18 10:00')), 'a done cycle is never missed');
	}

	public function testFixedWeekday_DoneEarly_FulfilsTheUpcomingCycleAndTheDayStays(): void {
		$task = $this->task(['mode' => Task::SCHEDULING_STATIC, 'weekdays' => ['saturday'], 'flex' => 1]);

		// Done on Monday 16 for Saturday 21: the following cycle is Saturday 28.
		$cycle = $this->current($this->roomTask('2026-11-02'), $task, new CycleHistory(8, '2026-11-16', 'd2026-11-21', '2026-11-21'), '2026-11-18 10:00');

		self::assertSame(['d2026-11-28', '2026-11-27', '2026-11-29'], [$cycle->key, $cycle->startOn, $cycle->endOn]);
	}

	public function testFixedWeekday_SeveralDaysAWeek_OverlappingWindowsKeepOneCurrentCycle(): void {
		$task = $this->task(['mode' => Task::SCHEDULING_STATIC, 'weekdays' => ['monday', 'wednesday'], 'flex' => 1]);
		$roomTask = $this->roomTask('2026-11-16');

		// Tuesday 17: Monday's window [15, 17] is open, so it is still current.
		self::assertSame('d2026-11-16', $this->current($roomTask, $task, null, '2026-11-17 12:00')->key);
		// Wednesday 18: Monday's window has ended and Wednesday's has opened.
		self::assertSame('d2026-11-18', $this->current($roomTask, $task, null, '2026-11-18 12:00')->key);
	}

	public function testFixedWeekday_Fortnightly_KeepsTheParityOfItsAnchor(): void {
		$task = $this->task(['mode' => Task::SCHEDULING_STATIC, 'value' => 2, 'weekdays' => ['saturday']]);
		$roomTask = $this->roomTask('2026-12-26');
		$zone = new \DateTimeZone(self::ZONE);

		$cycles = $this->windows->cyclesThrough($roomTask, $task, null, $zone, $this->instant('2026-12-20 10:00'), '2027-02-01');

		self::assertSame(['2026-12-26', '2027-01-09', '2027-01-23'], $this->centres($cycles));
	}

	public function testFixedDayOfMonth_ClampsToTheLastDayOfTheMonth(): void {
		$task = $this->task(['mode' => Task::SCHEDULING_STATIC, 'type' => 'monthly', 'monthDay' => 31]);
		$zone = new \DateTimeZone(self::ZONE);

		$cycles = $this->windows->cyclesThrough($this->roomTask('2027-01-15'), $task, null, $zone, $this->instant('2027-01-16 10:00'), '2027-04-30');

		self::assertSame(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30'], $this->centres($cycles));
	}

	public function testFixedDayOfMonth_WithoutAStoredDay_UsesTheDayOfItsAnchor(): void {
		$task = $this->task(['mode' => Task::SCHEDULING_STATIC, 'type' => 'monthly']);

		self::assertSame('d2026-12-10', $this->current($this->roomTask('2026-11-10'), $task, new CycleHistory(1, '2026-11-10', 'd2026-11-10', '2026-11-10'), '2026-11-18 10:00')->key);
	}

	public function testFixed_LongUnattended_FindsTheCurrentCycleWithoutWalkingTheYears(): void {
		$task = $this->task(['mode' => Task::SCHEDULING_STATIC, 'type' => 'daily']);
		$roomTask = $this->roomTask('2019-01-01');
		$zone = new \DateTimeZone(self::ZONE);

		self::assertSame('d2026-11-18', $this->current($roomTask, $task, null, '2026-11-18 10:00')->key);

		$missed = $this->windows->missedCycles($roomTask, $task, null, $zone, $this->instant('2026-11-18 10:00'), '2026-11-10');
		self::assertSame(['2026-11-11', '2026-11-17'], [$missed[0]->centreOn, $missed[count($missed) - 1]->centreOn]);
		self::assertCount(7, $missed);
	}

	public function testFixed_Bounded_TheLastCycleStaysCurrentUntilItIsDone(): void {
		$task = $this->task(['mode' => Task::SCHEDULING_STATIC, 'weekdays' => ['saturday'], 'recurrence' => Task::RECURRENCE_BOUNDED, 'activeUntil' => '2026-11-15']);
		$roomTask = $this->roomTask('2026-11-02');

		$cycle = $this->current($roomTask, $task, null, '2027-06-01 10:00');
		self::assertSame('d2026-11-14', $cycle->key);
		self::assertTrue($cycle->isOverdue('2027-06-01'));

		self::assertNull($this->current($roomTask, $task, new CycleHistory(2, '2027-06-01', 'd2026-11-14', '2026-11-14'), '2027-06-01 11:00'));
	}

	/**
	 * RF-091-04: an ended rule is found from its bound, not by walking every
	 * cycle since its anchor — also where that would exceed the step limit.
	 */
	public function testFixed_Bounded_LongAfterItsEnd_FindsTheLastCycleFromTheBound(): void {
		$daily = $this->task(['mode' => Task::SCHEDULING_STATIC, 'type' => 'daily', 'recurrence' => Task::RECURRENCE_BOUNDED, 'activeUntil' => '2026-12-31']);
		// More than a thousand rule days lie between the anchor and the bound.
		$cycle = $this->current($this->roomTask('2023-01-01', ['createdAt' => '2023-01-01 08:00:00 UTC']), $daily, null, '2027-06-01 10:00');
		self::assertSame('d2026-12-31', $cycle->key);

		$monthly = $this->task(['mode' => Task::SCHEDULING_STATIC, 'type' => 'monthly', 'monthDay' => 31, 'recurrence' => Task::RECURRENCE_BOUNDED, 'activeUntil' => '2026-11-15']);
		self::assertSame('d2026-10-31', $this->current($this->roomTask('2026-01-31'), $monthly, null, '2027-06-01 10:00')->key);
	}

	/**
	 * Review regression (CS-PLAN-091 WP-B1 interim Full review): a bound is
	 * stored as the midnight of its day (UTC); a house west of UTC must not
	 * read it as the day before.
	 */
	public function testFixed_Bounded_UtcMidnightBound_IsTheSameDayWestOfUtc(): void {
		$task = $this->task(['mode' => Task::SCHEDULING_STATIC, 'weekdays' => ['saturday'], 'recurrence' => Task::RECURRENCE_BOUNDED]);
		$task->setActiveUntil(new \DateTime('2026-11-14 00:00:00', new \DateTimeZone('UTC')));

		// On the 14th the window of the bound's Saturday opens, so it is current.
		$cycle = $this->current($this->roomTask('2026-11-02'), $task, null, '2026-11-14 10:00', 'America/New_York');
		self::assertSame('d2026-11-14', $cycle->key, 'the Saturday of the bound is still a cycle');
	}

	public function testFixed_NeverStartsBeforeItsAnchor(): void {
		$task = $this->task(['mode' => Task::SCHEDULING_STATIC, 'weekdays' => ['monday', 'friday']]);

		// Anchored on a Wednesday: Monday of that week is not a rule day.
		self::assertSame('d2026-11-20', $this->current($this->roomTask('2026-11-18'), $task, null, '2026-11-18 10:00')->key);
		// A fulfilled rule day from before the anchor (an earlier rule) does not pull it back.
		self::assertSame('d2026-11-20', $this->current($this->roomTask('2026-11-18'), $task, new CycleHistory(1, '2026-10-05', 'd2026-10-05', '2026-10-05'), '2026-11-18 10:00')->key);
	}

	// ------------------------------------------------------------------
	// Postponement, sleeping, bonus
	// ------------------------------------------------------------------

	public function testPostponement_MovesOnlyTheCycleItBelongsTo(): void {
		$task = $this->task(['mode' => Task::SCHEDULING_STATIC, 'weekdays' => ['saturday'], 'flex' => 2]);
		$roomTask = $this->roomTask('2026-11-02', ['cycleKey' => 'd2026-11-14', 'postponeCount' => 2, 'postponeDays' => 2]);
		$zone = new \DateTimeZone(self::ZONE);

		// Window [12, 16] moved to [14, 18]: still open on Wednesday 18.
		$cycle = $this->current($roomTask, $task, null, '2026-11-18 10:00');
		self::assertSame(['d2026-11-14', '2026-11-14', '2026-11-18', 2, 2], [$cycle->key, $cycle->startOn, $cycle->endOn, $cycle->postponeCount, $cycle->postponeDays]);
		self::assertSame(CompletedTask::CLASS_ON_TIME, $cycle->classify('2026-11-18'), 'a postponed window still counts as on time');

		$following = $this->windows->cyclesThrough($roomTask, $task, null, $zone, $this->instant('2026-11-18 10:00'), '2026-11-30')[1];
		self::assertSame(['2026-11-19', '2026-11-23', 0], [$following->startOn, $following->endOn, $following->postponeCount], 'the next cycle keeps its days');
	}

	public function testPostponement_OfAnEarlierCycle_IsIgnored(): void {
		$roomTask = $this->roomTask('2026-11-03', ['cycleKey' => 'd2026-11-03', 'postponeDays' => 3]);

		$cycle = $this->current($roomTask, $this->task(['flex' => 1]), new CycleHistory(41, '2026-11-10', 'd2026-11-03'), '2026-11-18 10:00');

		self::assertSame(['c41', '2026-11-16', '2026-11-18', 0], [$cycle->key, $cycle->startOn, $cycle->endOn, $cycle->postponeDays]);
	}

	public function testNoCycle_ForSleepingBonusAndInactiveTasks(): void {
		self::assertNull($this->current($this->roomTask('2026-11-10', ['sleepingSince' => '2026-11-12 08:00:00 UTC']), $this->task(), null, '2026-11-18 10:00'));
		self::assertNull($this->current($this->roomTask('2026-11-10'), $this->task(['bonus' => true]), null, '2026-11-18 10:00'));
		self::assertNull($this->current($this->roomTask('2026-11-10', ['active' => false]), $this->task(), null, '2026-11-18 10:00'));
		self::assertSame([], $this->windows->cyclesThrough($this->roomTask('2026-11-10'), $this->task(['bonus' => true]), null, new \DateTimeZone(self::ZONE), $this->instant('2026-11-18 10:00'), '2026-12-31'));
	}

	// ------------------------------------------------------------------
	// House-local days
	// ------------------------------------------------------------------

	public function testBlock_ASleepingTaskCarriesTheReceiptOfWakingIt(): void {
		$asleep = $this->roomTask('2026-11-10', ['sleepingSince' => '2026-11-12 08:00:00']);
		$asleep->setCycleVersion(6);
		$this->assertSame(['state' => 'asleep', 'expectedVersion' => 6, 'wakeOn' => null], $this->windows->block($asleep, $this->task(), null, '2026-11-18'));

		$asleep->setWakeOn(new \DateTime('2026-12-01'));
		$this->assertSame('2026-12-01', $this->windows->block($asleep, $this->task(), null, '2026-11-18')['wakeOn']);
		// Asleep wins over bonus: a sleeping bonus task is not on offer.
		$this->assertSame('asleep', $this->windows->block($asleep, $this->task(['bonus' => true]), null, '2026-11-18')['state']);
	}

	/**
	 * Decision 6: the row's `canPostpone` and the command decide through one rule.
	 *
	 * @dataProvider postponeRefusals
	 */
	public function testPostponeRefusal_SaysWhichRuleApplies(int $flex, int $presses, string $today, ?string $refusal): void {
		$task = $this->task(['flex' => $flex]);
		$roomTask = $this->roomTask('2026-11-18', $presses > 0 ? ['cycleKey' => 'd2026-11-18', 'postponeCount' => $presses, 'postponeDays' => 0] : []);
		$cycle = $this->current($roomTask, $task, null, '2026-11-18 10:00:00');

		$this->assertSame($refusal, WindowService::postponeRefusal($task, $cycle, $today));
		$this->assertSame($refusal === null, $this->windows->block($roomTask, $task, $cycle, $today)['canPostpone']);
	}

	public static function postponeRefusals(): array {
		return [
			'flex days and presses left' => [2, 2, '2026-11-18', null],
			'no flex days' => [0, 0, '2026-11-18', ConflictException::REASON_NOT_POSTPONABLE],
			'three presses' => [2, 3, '2026-11-18', ConflictException::REASON_NOT_POSTPONABLE],
			'a red task, whatever is left' => [2, 0, '2026-11-25', ConflictException::REASON_WINDOW_ENDED],
		];
	}

	/**
	 * Decision 7, D-091-28: a woken task hangs on its wake day; a fixed
	 * rule continues on its next rule day and keeps its parity.
	 *
	 * @dataProvider wakeAnchors
	 */
	public function testWakeAnchor_FollowsTheRule(array $rule, string $anchor, string $wakeDay, ?string $expected): void {
		$this->assertSame(
			$expected,
			$this->windows->wakeAnchor($this->roomTask($anchor), $this->task($rule), $wakeDay, new \DateTimeZone(self::ZONE))
		);
	}

	public static function wakeAnchors(): array {
		return [
			'after completion: the wake day' => [[], '2026-09-01', '2026-11-18', '2026-11-18'],
			'one-time: the wake day' => [['recurrence' => Task::RECURRENCE_ONCE], '2026-09-01', '2026-11-18', '2026-11-18'],
			// 2026-11-18 is a Wednesday; Mondays every week.
			'fixed weekday: the next rule day' => [['mode' => Task::SCHEDULING_STATIC, 'weekdays' => ['monday']], '2026-09-07', '2026-11-18', '2026-11-23'],
			'fixed weekday on a rule day: that day' => [['mode' => Task::SCHEDULING_STATIC, 'weekdays' => ['monday']], '2026-09-07', '2026-11-23', '2026-11-23'],
			// Every other Monday from 2026-09-07: 11-16 and 11-30 are rule days, 11-23 is not.
			'fortnightly: the parity stays' => [['mode' => Task::SCHEDULING_STATIC, 'value' => 2, 'weekdays' => ['monday']], '2026-09-07', '2026-11-18', '2026-11-30'],
			'fixed day of month: clamped as ever' => [['mode' => Task::SCHEDULING_STATIC, 'type' => 'monthly', 'monthDay' => 31], '2026-08-31', '2026-11-18', '2026-11-30'],
			'a bounded rule past its end: the wake day, so no cycle is left' => [
				['mode' => Task::SCHEDULING_STATIC, 'weekdays' => ['monday'], 'activeUntil' => '2026-10-31'], '2026-09-07', '2026-11-18', '2026-11-18',
			],
			'a bonus task has no anchor' => [['bonus' => true], '2026-09-01', '2026-11-18', null],
		];
	}

	public function testWakeAnchor_ABoundedRulePastItsEnd_LeavesNoCycleAndNoMiss(): void {
		$task = $this->task(['mode' => Task::SCHEDULING_STATIC, 'weekdays' => ['monday'], 'activeUntil' => '2026-10-31']);
		$woken = $this->roomTask('2026-11-18');

		$this->assertNull($this->current($woken, $task, null, '2026-11-18 10:00:00'));
		$this->assertSame([], $this->windows->missedCycles($woken, $task, null, new \DateTimeZone(self::ZONE), $this->instant('2026-11-18 10:00:00')));
	}

	public function testHouseTimeZone_DecidesWhichDayItIs(): void {
		$task = $this->task(['mode' => Task::SCHEDULING_STATIC, 'type' => 'daily']);
		$roomTask = $this->roomTask('2026-11-10');
		$instant = new \DateTime('2026-11-18 23:30:00', new \DateTimeZone('UTC'));

		self::assertSame('d2026-11-18', $this->windows->currentCycle($roomTask, $task, null, new \DateTimeZone('UTC'), $instant)->key);
		self::assertSame('d2026-11-19', $this->windows->currentCycle($roomTask, $task, null, new \DateTimeZone('Pacific/Auckland'), $instant)->key);
		self::assertSame('d2026-11-18', $this->windows->currentCycle($roomTask, $task, null, new \DateTimeZone('America/Los_Angeles'), $instant)->key);
		self::assertSame('2026-11-19', WindowService::todayKey($instant, new \DateTimeZone(self::ZONE)));
	}

	public function testDaylightSaving_WindowsAreCalendarDaysNotElapsedHours(): void {
		// Berlin leaves summer time on 25 October 2026 (a 25-hour day).
		$window = $this->current($this->roomTask('2026-10-25'), $this->task(['flex' => 1]), null, '2026-10-24 10:00');
		self::assertSame(['2026-10-24', '2026-10-26'], [$window->startOn, $window->endOn]);

		$next = $this->current($this->roomTask('2026-10-01'), $this->task(), new CycleHistory(6, '2026-10-22', 'c5'), '2026-10-26 00:30');
		self::assertSame('2026-10-29', $next->centreOn);

		// Spring forward, 28 March 2027 (a 23-hour day): 23:30 is still that day.
		$spring = $this->current($this->roomTask('2027-03-28'), $this->task(), null, '2027-03-28 23:30');
		self::assertFalse($spring->isOverdue(WindowService::todayKey($this->instant('2027-03-28 23:30'), new \DateTimeZone(self::ZONE))));
	}

	// ------------------------------------------------------------------
	// Projections and classification
	// ------------------------------------------------------------------

	public function testCyclesThrough_AfterCompletion_ProjectsFromTheCurrentCentre(): void {
		$zone = new \DateTimeZone(self::ZONE);
		$cycles = $this->windows->cyclesThrough($this->roomTask('2026-11-03'), $this->task(['flex' => 1]), new CycleHistory(41, '2026-11-10', 'd2026-11-03'), $zone, $this->instant('2026-11-18 10:00'), '2026-12-02');

		self::assertSame(['c41', 'p2026-11-24', 'p2026-12-01'], array_map(static fn (CurrentCycle $cycle): string => $cycle->key, $cycles));
		self::assertSame([], $this->windows->cyclesThrough($this->roomTask('2026-11-25'), $this->task(), null, $zone, $this->instant('2026-11-18 10:00'), '2026-11-24'), 'nothing when the current window opens after the range');
	}

	/**
	 * RF-091-04: a range far ahead shows the current cycle first and then
	 * only the projections that reach into it — the rule is not walked from
	 * today, and the result equals the full walk cut to the range.
	 *
	 * @return array<string, array{array<string, mixed>, ?CycleHistory, int}>
	 */
	public static function farRanges(): array {
		return [
			'fixed weekday' => [['mode' => Task::SCHEDULING_STATIC, 'weekdays' => ['saturday'], 'flex' => 1], null, 6],
			'fixed day of the month' => [['mode' => Task::SCHEDULING_STATIC, 'type' => 'monthly', 'monthDay' => 31], null, 2],
			'after completion, every three days' => [['type' => 'daily', 'value' => 3, 'flex' => 1], new CycleHistory(41, '2026-11-10', 'd2026-11-03'), 12],
			'after completion, every two weeks' => [['type' => 'weekly', 'value' => 2], new CycleHistory(41, '2026-11-10', 'd2026-11-03'), 4],
			// A month interval is walked: twelve cheap steps a year, never days.
			'after completion, monthly' => [['type' => 'monthly'], new CycleHistory(41, '2026-11-10', 'd2026-11-03'), 13],
		];
	}

	/** @dataProvider farRanges */
	public function testCyclesThrough_AFarRange_SkipsWhatLiesBeforeIt(array $rule, ?CycleHistory $history, int $atMost): void {
		$zone = new \DateTimeZone(self::ZONE);
		$now = $this->instant('2026-11-18 10:00');
		$task = $this->task($rule);
		$roomTask = $this->roomTask('2026-11-03');
		[$from, $through] = ['2027-11-01', '2027-11-30'];

		$all = $this->windows->cyclesThrough($roomTask, $task, $history, $zone, $now, $through);
		$ranged = $this->windows->cyclesThrough($roomTask, $task, $history, $zone, $now, $through, $from);

		$inRange = static fn (CurrentCycle $cycle): bool => $cycle->endOn >= $from;
		self::assertSame($all[0]->key, $ranged[0]->key, 'the current cycle leads the list');
		self::assertSame(
			array_map(static fn (CurrentCycle $cycle): string => $cycle->key, array_values(array_filter(array_slice($all, 1), $inRange))),
			array_map(static fn (CurrentCycle $cycle): string => $cycle->key, array_values(array_filter(array_slice($ranged, 1), $inRange))),
			'the skipped walk yields the projections of the full one'
		);
		self::assertLessThanOrEqual($atMost, count($ranged), 'a year of projections before the range is not walked');
		self::assertGreaterThan(1, count($ranged));
	}

	public function testCyclesThrough_OneTime_HasNoFollowingCycle(): void {
		$cycles = $this->windows->cyclesThrough($this->roomTask('2026-11-20'), $this->task(['recurrence' => Task::RECURRENCE_ONCE]), null, new \DateTimeZone(self::ZONE), $this->instant('2026-11-18 10:00'), '2027-01-01');

		self::assertSame(['2026-11-20'], $this->centres($cycles));
	}

	public function testClassification_IsDecidedAgainstTheEndOfTheWindow(): void {
		$cycle = new CurrentCycle('d2026-11-21', '2026-11-21', '2026-11-20', '2026-11-22');

		self::assertSame(CompletedTask::CLASS_ON_TIME, $cycle->classify('2026-11-16'), 'early is on time');
		self::assertSame(CompletedTask::CLASS_ON_TIME, $cycle->classify('2026-11-22'));
		self::assertSame(CompletedTask::CLASS_LATE, $cycle->classify('2026-11-23'));
		self::assertSame('2026-11-16', $cycle->weekStart(), 'a cycle counts in the week its window ends');
	}

	/** @return array<string, array{array<string, mixed>, string, string, int}> */
	public static function correctedDays(): array {
		return [
			'after completion, weekly: the next window moves with the day' => [['type' => 'weekly'], '2026-11-16', '2026-11-13', -3],
			'after completion, forward' => [['type' => 'daily', 'value' => 3], '2026-11-10', '2026-11-12', 2],
			// 31 Jan and 30 Jan both lead to the clamped end of February.
			'monthly, clamped to the end of the month' => [['type' => 'monthly'], '2026-01-31', '2026-01-30', 0],
			'monthly, across the clamp' => [['type' => 'monthly'], '2026-01-31', '2026-01-27', -1],
			'a fixed rule keeps its days' => [['mode' => Task::SCHEDULING_STATIC, 'weekdays' => ['saturday']], '2026-11-16', '2026-11-13', 0],
			'a one-time task has no next window' => [['recurrence' => Task::RECURRENCE_ONCE], '2026-11-16', '2026-11-13', 0],
			'a bonus task has no window' => [['bonus' => true], '2026-11-16', '2026-11-13', 0],
		];
	}

	/**
	 * ADR-021 decision 9: only an after-completion rule hangs its next
	 * window on the completion, so only there a corrected day moves it.
	 *
	 * @dataProvider correctedDays
	 */
	public function testShiftAfterCompletion_FollowsTheRule(array $rule, string $from, string $to, int $days): void {
		self::assertSame($days, $this->windows->shiftAfterCompletion($this->task($rule), $from, $to));
	}

	public function testHistoriesFor_MapsTheMapperRowsOntoHistories(): void {
		$mapper = $this->createMock(CompletedTaskMapper::class);
		$mapper->expects(self::once())->method('findCycleAnchorsByRoomTasks')->with([11, 12])->willReturn([
			11 => ['id' => 41, 'workDay' => '2026-11-10', 'cycleKey' => 'd2026-11-03', 'fixedCentre' => '2026-11-03'],
		]);

		$histories = (new WindowService(new RecurrenceCalculator(), $mapper))->historiesFor([11, 12]);

		self::assertSame([11], array_keys($histories));
		self::assertSame([41, '2026-11-10', 'd2026-11-03', '2026-11-03'], [$histories[11]->latestCompletionId, $histories[11]->latestWorkDay, $histories[11]->latestCycleKey, $histories[11]->fixedCentre]);
	}
}
