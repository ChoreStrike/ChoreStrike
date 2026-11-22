<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Service\CurrentCycle;
use OCA\ChoreStrike\Service\RecurrenceCalculator;
use OCA\ChoreStrike\Service\SuggestionJob;
use OCA\ChoreStrike\Service\SuggestionLedger;
use OCA\ChoreStrike\Service\SuggestionMember;
use OCA\ChoreStrike\Service\SuggestionPlan;
use OCA\ChoreStrike\Service\SuggestionPlanner;
use PHPUnit\Framework\TestCase;

/**
 * The placement rules of the live suggestion (CS-PLAN-091 WP-C, ADR-022
 * decision 2, D-091-11). Today is Monday 2026-11-23; the expected days are
 * frozen regression data.
 */
class SuggestionPlannerTest extends TestCase {
	private const TODAY = '2026-11-23';
	private const WEEK = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

	private SuggestionPlanner $planner;

	protected function setUp(): void {
		$this->planner = new SuggestionPlanner();
	}

	/** @param array{budget?: int|array<string, int>, off?: list<string>, preferred?: list<string>, limit?: int} $options */
	private function member(int $id, array $options = []): SuggestionMember {
		$budget = $options['budget'] ?? 10;
		return new SuggestionMember(
			$id,
			'user' . $id,
			is_array($budget) ? $budget + array_fill_keys(self::WEEK, 10) : array_fill_keys(self::WEEK, $budget),
			array_fill_keys($options['off'] ?? [], false) + array_fill_keys(self::WEEK, true),
			$options['preferred'] ?? [],
			$options['limit'] ?? null,
		);
	}

	private static function day(int $offset): string {
		return RecurrenceCalculator::offsetDays(self::TODAY, $offset);
	}

	/**
	 * A job whose window runs from one day offset to another.
	 *
	 * @param list<int> $candidates
	 * @param array{assigned?: bool, team?: bool, notBefore?: string} $options
	 */
	private function job(int $roomTaskId, int $energy, array $candidates, int $from, int $to, array $options = []): SuggestionJob {
		$cycle = new CurrentCycle('d' . self::day($from), self::day($from), self::day($from), self::day($to));
		return new SuggestionJob($roomTaskId, $energy, $candidates, $options['assigned'] ?? true, $options['team'] ?? false, $cycle, $options['notBefore'] ?? null);
	}

	/**
	 * @param list<SuggestionMember> $members
	 * @param list<SuggestionJob> $jobs
	 * @param array{absent?: array<int, array<string, true>>, done?: array<int, int>, pinned?: array<int, array{memberIds: list<int>, overload: bool}>, joiners?: array<int, true>, successor?: callable} $options
	 */
	private function plan(array $members, array $jobs, array $options = []): SuggestionPlan {
		$ledger = new SuggestionLedger(self::TODAY, $members, $options['absent'] ?? [], $options['done'] ?? [], SuggestionPlanner::HORIZON_DAYS);
		return $this->planner->plan(
			$ledger,
			$jobs,
			$options['pinned'] ?? null,
			$options['joiners'] ?? [],
			$options['successor'] ?? static fn (): ?CurrentCycle => null,
		);
	}

	/** @return array<int, string> Room task id to the day it was placed on */
	private static function daysOf(SuggestionPlan $plan): array {
		$days = [];
		foreach ($plan->placements as $placement) {
			if ($placement['current']) {
				$days[$placement['roomTaskId']] = $placement['day'];
			}
		}
		return $days;
	}

	public function testPlan_Order_EarliestWindowEndThenHigherEnergyThenId(): void {
		$plan = $this->plan([$this->member(1)], [
			$this->job(4, 1, [1], 0, 3),
			$this->job(3, 1, [1], 0, 1),
			$this->job(2, 3, [1], 0, 1),
			$this->job(1, 1, [1], 0, 1),
		]);

		self::assertSame([2, 1, 3, 4], array_column($plan->placements, 'roomTaskId'));
	}

	public function testPlan_OneAssignee_SpreadsOverTheDaysWithTheMostRemainingEnergy(): void {
		$plan = $this->plan([$this->member(1)], [
			$this->job(1, 4, [1], 0, 2),
			$this->job(2, 4, [1], 0, 2),
			$this->job(3, 4, [1], 0, 2),
		]);

		self::assertSame([1 => self::day(0), 2 => self::day(1), 3 => self::day(2)], self::daysOf($plan));
		self::assertSame(4, $plan->ledger->placed(1, self::day(1)));
		self::assertSame([false, false, false], array_column($plan->placements, 'overload'));
	}

	public function testPlan_PreferredDay_IsFilledFirstWhileItFits(): void {
		$members = [$this->member(1, ['preferred' => ['wednesday'], 'budget' => 5])];
		$plan = $this->plan($members, [
			$this->job(1, 3, [1], 0, 3),
			$this->job(2, 3, [1], 0, 3),
		]);

		// Wednesday takes the first cycle; the second no longer fits there and goes to the roomiest other day.
		self::assertSame([1 => self::day(2), 2 => self::day(0)], self::daysOf($plan));
	}

	public function testPlan_UnavailableAndAbsentDays_AreNeverSuggested(): void {
		$members = [$this->member(1, ['off' => ['monday']])];
		$plan = $this->plan($members, [$this->job(1, 2, [1], 0, 2)], ['absent' => [1 => [self::day(1) => true]]]);

		self::assertSame([1 => self::day(2)], self::daysOf($plan));
	}

	public function testPlan_AbsentForTheWholeWindow_SuggestsNothing(): void {
		$absent = [1 => [self::day(0) => true, self::day(1) => true]];
		$plan = $this->plan([$this->member(1)], [$this->job(1, 2, [1], 0, 1)], ['absent' => $absent]);

		self::assertSame([], $plan->placements);
	}

	public function testPlan_NoDayFits_LastDayOfTheWindowAsOverload(): void {
		$plan = $this->plan([$this->member(1, ['budget' => 5, 'off' => ['wednesday']])], [$this->job(1, 8, [1], 0, 2)]);

		// The last day the member is available on, never an earlier one.
		self::assertSame([1 => self::day(1)], self::daysOf($plan));
		self::assertTrue($plan->placements[0]['overload']);
	}

	public function testPlan_Unassigned_GoesToTheMemberWithTheMostRemainingEnergyTiesByLowerId(): void {
		$members = [$this->member(1, ['budget' => 6]), $this->member(2, ['budget' => 6]), $this->member(3, ['budget' => 9])];
		$plan = $this->plan($members, [
			$this->job(1, 4, [1, 2, 3], 0, 0, ['assigned' => false]),
			$this->job(2, 1, [1, 2, 3], 0, 0, ['assigned' => false]),
			$this->job(3, 1, [1, 2, 3], 0, 0, ['assigned' => false]),
		]);

		// 3 has the most; then 1 and 2 tie at 6 above 3's remaining 5; then 2 leads.
		self::assertSame([[3], [1], [2]], array_column($plan->placements, 'memberIds'));
	}

	public function testPlan_NobodyFits_AMemberWhoIsAvailableComesBeforeOneWithTheDayOff(): void {
		// Today is Monday: member 1 has it off, member 2 is already over the day's energy.
		$members = [$this->member(1, ['off' => ['monday']]), $this->member(2, ['budget' => 2])];
		$plan = $this->plan($members, [
			$this->job(1, 4, [2], 0, 0),
			$this->job(2, 3, [1, 2], 0, 0, ['assigned' => false]),
			$this->job(3, 3, [1, 2], 0, 0, ['team' => true]),
		]);

		self::assertSame([[2], [2], [2, 1]], array_column($plan->placements, 'memberIds'));
		self::assertSame([true, true, true], array_column($plan->placements, 'overload'));
		self::assertSame(0, $plan->ledger->placed(1, self::TODAY), 'nothing lands on a free day while someone available can take it');
	}

	public function testPlan_NobodyFits_AChildAtItsLimitComesAfterAnOverloadedAdult(): void {
		$members = [$this->member(1, ['budget' => 2]), $this->member(2, ['limit' => 1])];
		$plan = $this->plan($members, [
			$this->job(1, 5, [2], 0, 0),
			$this->job(2, 3, [1], 0, 0),
			$this->job(3, 2, [1, 2], 0, 0, ['assigned' => false]),
		]);

		$carriers = array_column($plan->placements, 'memberIds', 'roomTaskId');
		// The child still has energy left, but its one slot is taken: the unassigned work goes to someone else.
		self::assertSame([1], $carriers[3]);
		self::assertSame([false, true, true], array_column($plan->placements, 'overload'));
	}

	public function testPlan_TeamTask_IsCarriedByOneBudgetAndShownToEveryAssignee(): void {
		$members = [$this->member(1), $this->member(2, ['budget' => 12])];
		$plan = $this->plan($members, [$this->job(1, 4, [1, 2], 0, 0, ['team' => true])]);

		self::assertSame([2, 1], $plan->placements[0]['memberIds']);
		self::assertSame(4, $plan->ledger->placed(2, self::TODAY));
		self::assertSame(0, $plan->ledger->placed(1, self::TODAY), 'the other assignee\'s budget neither blocks nor counts it');
	}

	public function testPlan_ChildLimit_IsASecondBudget(): void {
		$members = [$this->member(1, ['limit' => 1])];
		$plan = $this->plan($members, [
			$this->job(1, 1, [1], 0, 1),
			$this->job(2, 1, [1], 0, 1),
			$this->job(3, 1, [1], 0, 1),
		]);

		self::assertSame([1 => self::day(0), 2 => self::day(1), 3 => self::day(1)], self::daysOf($plan));
		self::assertSame([false, false, true], array_column($plan->placements, 'overload'), 'the third fits no day of the window');
	}

	public function testPlan_OverdueCycle_IsNotPlacedAndTakesTodaysEnergy(): void {
		$plan = $this->plan([$this->member(1, ['budget' => 5])], [
			$this->job(1, 4, [1], -5, -2),
			$this->job(2, 3, [1], 0, 1),
		]);

		self::assertSame([2 => self::day(1)], self::daysOf($plan), 'nothing more is piled onto today');
		self::assertSame([1], array_column($plan->overdue, 'roomTaskId'));
		self::assertSame([1], $plan->overdue[0]['memberIds']);
		self::assertSame(4, $plan->ledger->placed(1, self::TODAY));
	}

	public function testPlan_OverdueUnassignedWhileEveryoneIsAbsent_CountsForNobody(): void {
		$plan = $this->plan([$this->member(1)], [$this->job(1, 4, [1], -5, -2, ['assigned' => false])], ['absent' => [1 => [self::TODAY => true]]]);

		self::assertSame([], $plan->overdue[0]['memberIds']);
		self::assertSame(0, $plan->ledger->placed(1, self::TODAY));
	}

	public function testPlan_TodaysDoneWork_IsSubtractedFromToday(): void {
		$plan = $this->plan([$this->member(1, ['budget' => 5])], [$this->job(1, 3, [1], 0, 1)], ['done' => [1 => 250]]);

		self::assertSame([1 => self::day(1)], self::daysOf($plan));
		self::assertSame(250, $plan->ledger->remainingMinor(1, self::TODAY));
	}

	public function testPlan_StoredDay_KeepsItsEntriesAndMovesEverythingElseFromTomorrowOn(): void {
		$jobs = [
			$this->job(1, 4, [1], 0, 2),
			$this->job(2, 1, [1], 0, 2),
			$this->job(3, 1, [1], 0, 0),
		];
		$plan = $this->plan([$this->member(1)], $jobs, ['pinned' => [1 => ['memberIds' => [1], 'overload' => false]]]);

		// 1 stays on today; 2 may not join today although today is the roomiest day; 3 has no day left.
		self::assertSame([1 => self::day(0), 2 => self::day(1)], self::daysOf($plan));
		self::assertSame(4, $plan->ledger->placed(1, self::TODAY));
	}

	public function testPlan_Joiner_IsPlacedOnTheStoredToday(): void {
		$plan = $this->plan([$this->member(1, ['budget' => 2])], [$this->job(1, 3, [1], 0, 4)], ['pinned' => [], 'joiners' => [1 => true]]);

		self::assertSame([1 => self::day(0)], self::daysOf($plan));
		self::assertTrue($plan->placements[0]['overload'], 'it joins today also when today has no room for it');
	}

	public function testPlan_JoinerNobodyCanTakeToday_KeepsTheRestOfItsWindow(): void {
		$options = ['pinned' => [], 'joiners' => [1 => true], 'absent' => [1 => [self::TODAY => true]]];
		$plan = $this->plan([$this->member(1)], [$this->job(1, 2, [1], 0, 3)], $options);

		self::assertSame([1 => self::day(1)], self::daysOf($plan));
		self::assertFalse($plan->placements[0]['overload']);
	}

	public function testPlan_NotBefore_SkipsTheDayOfTheWorkItFollows(): void {
		$plan = $this->plan([$this->member(1)], [$this->job(1, 1, [1], 0, 1, ['notBefore' => self::day(1)])]);

		self::assertSame([1 => self::day(1)], self::daysOf($plan));
	}

	public function testPlan_Successors_FillTheHorizonAndOnlyTheCurrentCycleIsATarget(): void {
		// A daily rule: the next cycle is the day after the work.
		$successor = static function (SuggestionJob $job, string $doneOn): CurrentCycle {
			$next = RecurrenceCalculator::dayAfter($doneOn);
			return new CurrentCycle('p' . $next, $next, $next, $next);
		};
		$plan = $this->plan([$this->member(1)], [$this->job(1, 2, [1], 0, 0)], ['successor' => $successor]);

		self::assertCount(SuggestionPlanner::HORIZON_DAYS, $plan->placements);
		self::assertSame('d' . self::TODAY, $plan->placements[0]['cycleKey']);
		self::assertSame([null], array_values(array_unique(array_column(array_slice($plan->placements, 1), 'cycleKey'))));
		self::assertSame(self::day(13), $plan->placements[13]['day']);
		self::assertSame(2, $plan->ledger->placed(1, self::day(7)));
	}

	public function testPlan_StepBound_IsReported(): void {
		$daily = static function (SuggestionJob $job, string $doneOn): CurrentCycle {
			$next = RecurrenceCalculator::dayAfter($doneOn);
			return new CurrentCycle('p' . $next, $next, $next, $next);
		};
		$jobs = array_map(fn (int $id): SuggestionJob => $this->job($id, 1, [1], 0, 0), range(1, 400));

		// 400 daily tasks need 5,600 steps over the horizon: more than one run handles.
		self::assertTrue($this->plan([$this->member(1, ['budget' => 20])], $jobs, ['successor' => $daily])->truncated);
		self::assertFalse($this->plan([$this->member(1)], [$this->job(1, 1, [1], 0, 0)], ['successor' => $daily])->truncated);
	}

	public function testPlan_SuccessorOfAnOverdueCycle_FollowsToday(): void {
		$seen = [];
		$successor = static function (SuggestionJob $job, string $doneOn) use (&$seen): ?CurrentCycle {
			$seen[] = $doneOn;
			return null;
		};
		$this->plan([$this->member(1)], [$this->job(1, 2, [1], -9, -7)], ['successor' => $successor]);

		self::assertSame([self::TODAY], $seen);
	}

	public function testPlan_SameInputs_SamePlan(): void {
		$members = [$this->member(1, ['preferred' => ['friday']]), $this->member(2, ['budget' => 7, 'limit' => 2])];
		$jobs = [
			$this->job(1, 3, [1, 2], 0, 4, ['assigned' => false]),
			$this->job(2, 2, [2], 1, 3),
			$this->job(3, 5, [1, 2], 0, 6, ['team' => true]),
			$this->job(4, 1, [1], -3, -1),
		];

		self::assertSame($this->plan($members, $jobs)->placements, $this->plan($members, array_reverse($jobs))->placements);
	}
}
