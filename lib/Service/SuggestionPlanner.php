<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

/**
 * The placement rules of the live suggestion (ADR-022 decision 2,
 * D-091-11). Pure: the same inputs always yield the same plan.
 *
 * Cycles are placed in the order of their window end (ties: higher
 * energy, then room-task id). Each goes to the member with the most
 * remaining energy on a day of its window: a preferred day first, else any
 * available day, else the last day of the window as overload. Overload
 * goes to a member available in the window before one who has those days
 * off, and past a child at its daily limit. A team task is carried by one
 * assignee's budget. A child's daily limit is a second budget. An overdue
 * cycle is not placed; it counts against today.
 *
 * After a cycle the planner places the one its rule yields next, as if
 * the work were done on the suggested day, so the later days of the
 * horizon carry their recurring work.
 */
class SuggestionPlanner {
	/** House-local days the suggestion looks ahead, today included (decision 1). */
	public const HORIZON_DAYS = 14;

	/** Upper bound of cycles handled in one run. */
	private const MAX_STEPS = 5000;

	/**
	 * @param list<SuggestionJob> $jobs The current cycles of the house
	 * @param array<int, array{memberIds: list<int>, overload: bool}>|null $pinned Today's stored placements by room
	 *                                                                             task; null on the day's first read,
	 *                                                                             when today is still open
	 * @param array<int, true> $joiners Room tasks that join today's list although the day is stored
	 * @param callable(SuggestionJob, string): ?CurrentCycle $successor The cycle that follows a job done on a day
	 */
	public function plan(SuggestionLedger $ledger, array $jobs, ?array $pinned, array $joiners, callable $successor): SuggestionPlan {
		$plan = new SuggestionPlan($ledger);
		$queue = $this->queueOf($plan, $jobs, $pinned ?? [], $successor);
		for ($steps = 0; !$queue->isEmpty() && $steps < self::MAX_STEPS; $steps++) {
			$this->step($plan, $queue, $queue->pop(), $pinned === null, $joiners, $successor);
		}
		// What is still queued is left out; the service reports it.
		$plan->truncated = !$queue->isEmpty();
		return $plan;
	}

	/**
	 * The cycles to place. Today's stored list is fixed: its entries take
	 * their energy before anything else, and only what follows them is
	 * queued.
	 *
	 * @param list<SuggestionJob> $jobs
	 * @param array<int, array{memberIds: list<int>, overload: bool}> $pinned
	 * @param callable(SuggestionJob, string): ?CurrentCycle $successor
	 */
	private function queueOf(SuggestionPlan $plan, array $jobs, array $pinned, callable $successor): SuggestionQueue {
		$queue = new SuggestionQueue();
		$today = $plan->ledger->todayKey;
		foreach ($jobs as $job) {
			$stored = $pinned[$job->roomTaskId] ?? null;
			if ($stored === null) {
				$queue->push($job);
				continue;
			}
			$this->record($plan, $job, $today, $stored['memberIds'], $stored['overload']);
			$this->follow($queue, $job, $today, $successor, $plan->ledger);
		}
		return $queue;
	}

	/**
	 * Handle the next cycle of the queue: an overdue one counts against
	 * today, any other is placed; then the cycle that follows it is queued.
	 *
	 * @param bool $todayOpen Whether today's list is not stored yet
	 * @param array<int, true> $joiners
	 * @param callable(SuggestionJob, string): ?CurrentCycle $successor
	 */
	private function step(SuggestionPlan $plan, SuggestionQueue $queue, SuggestionJob $job, bool $todayOpen, array $joiners, callable $successor): void {
		$today = $plan->ledger->todayKey;
		if ($job->current && $job->cycle->isOverdue($today)) {
			$this->countOverdue($plan, $job);
			$this->follow($queue, $job, $today, $successor, $plan->ledger);
			return;
		}
		$joins = isset($joiners[$job->roomTaskId]);
		$day = $this->place($plan, $job, $todayOpen || $joins, $job->current && !$todayOpen && $joins);
		$this->follow($queue, $job, $day ?? max($today, $job->cycle->plannedOn()), $successor, $plan->ledger);
	}

	/**
	 * Place one job inside its window and record it. A joiner goes on
	 * today alone; when nobody can take it today, it keeps the rest of its
	 * window from tomorrow on (decision 3).
	 *
	 * @return string|null The day it was placed on; null when nobody can take it
	 */
	private function place(SuggestionPlan $plan, SuggestionJob $job, bool $todayOpen, bool $joinsToday): ?string {
		$choice = $this->chooseIn($plan->ledger, $job, $this->windowDays($plan->ledger, $job, $todayOpen, $joinsToday));
		if ($choice === null && $joinsToday) {
			$choice = $this->chooseIn($plan->ledger, $job, $this->windowDays($plan->ledger, $job, false, false));
		}
		if ($choice === null) {
			return null;
		}
		$this->record($plan, $job, $choice['day'], $job->membersWith($choice['memberId']), !$choice['fits']);
		return $choice['day'];
	}

	/**
	 * @param list<string> $window
	 * @return array{memberId: int, day: string, fits: bool, available: bool, limited: bool, remaining: int}|null
	 */
	private function chooseIn(SuggestionLedger $ledger, SuggestionJob $job, array $window): ?array {
		return $window === [] ? null : $this->choose($ledger, $job, $window);
	}

	/**
	 * The days of the horizon a job can be placed on: inside its window,
	 * not before the work it follows, and never today once today's list is
	 * stored. A joiner is placed on today alone.
	 *
	 * @return list<string>
	 */
	private function windowDays(SuggestionLedger $ledger, SuggestionJob $job, bool $todayOpen, bool $joinsToday): array {
		$today = $ledger->todayKey;
		$from = max($job->cycle->startOn, $job->notBefore ?? '', $todayOpen ? $today : RecurrenceCalculator::dayAfter($today));
		$to = $joinsToday ? $today : min($job->cycle->endOn, $ledger->lastDay());
		return array_values(array_filter($ledger->days, static fn (string $day): bool => $day >= $from && $day <= $to));
	}

	/**
	 * Who carries the job and on which day: the candidate whose day has
	 * the most remaining energy, a day that fits before an overloaded one;
	 * ties go to the lower house-user id. When it fits nobody, a candidate
	 * available on a day of the window comes before one who has those days
	 * off, and one below a child's daily limit before one at it (decision
	 * 2, rules 2 and 5). Null when every candidate is absent on every day.
	 *
	 * @param list<string> $window
	 * @return array{memberId: int, day: string, fits: bool, available: bool, limited: bool, remaining: int}|null
	 */
	private function choose(SuggestionLedger $ledger, SuggestionJob $job, array $window): ?array {
		$best = null;
		foreach ($job->candidates as $memberId) {
			$choice = $ledger->knows($memberId) ? $this->dayFor($ledger, $memberId, $job->energy, $window) : null;
			if ($choice !== null && ($best === null || self::rank($choice) > self::rank($best))) {
				$best = $choice;
			}
		}
		return $best;
	}

	/**
	 * The order of choices: fits, then available, then below the limit,
	 * then the most remaining energy. Arrays compare element by element.
	 *
	 * @param array{fits: bool, available: bool, limited: bool, remaining: int} $choice
	 * @return list<int>
	 */
	private static function rank(array $choice): array {
		return [(int)$choice['fits'], (int)$choice['available'], (int)!$choice['limited'], $choice['remaining']];
	}

	/**
	 * The member's day for a job (decision 2, rule 3): the preferred day
	 * with the most remaining energy, else any day it fits with the most
	 * remaining energy, else the last available day as overload. Days of
	 * an absence are never suggested.
	 *
	 * @param list<string> $window
	 * @return array{memberId: int, day: string, fits: bool, available: bool, limited: bool, remaining: int}|null
	 */
	private function dayFor(SuggestionLedger $ledger, int $memberId, int $energy, array $window): ?array {
		$present = array_values(array_filter($window, static fn (string $day): bool => !$ledger->isAbsent($memberId, $day)));
		if ($present === []) {
			return null;
		}
		$fitting = array_values(array_filter($present, static fn (string $day): bool => $ledger->fits($memberId, $day, $energy)));
		$preferred = array_values(array_filter($fitting, static fn (string $day): bool => $ledger->isPreferred($memberId, $day)));
		$day = self::roomiest($ledger, $memberId, $preferred) ?? self::roomiest($ledger, $memberId, $fitting);
		$fits = $day !== null;
		$available = array_values(array_filter($present, static fn (string $candidate): bool => $ledger->isAvailable($memberId, $candidate)));
		if (!$fits) {
			$day = $available !== [] ? $available[count($available) - 1] : $present[count($present) - 1];
		}
		return [
			'memberId' => $memberId,
			'day' => $day,
			'fits' => $fits,
			'available' => $available !== [],
			'limited' => $ledger->atLimit($memberId, $day),
			'remaining' => $ledger->remainingMinor($memberId, $day),
		];
	}

	/**
	 * The day with the most remaining energy; the earliest on a tie.
	 *
	 * @param list<string> $days Ascending
	 */
	private static function roomiest(SuggestionLedger $ledger, int $memberId, array $days): ?string {
		$best = null;
		foreach ($days as $day) {
			if ($best === null || $ledger->remainingMinor($memberId, $day) > $ledger->remainingMinor($memberId, $best)) {
				$best = $day;
			}
		}
		return $best;
	}

	/**
	 * @param list<int> $memberIds The member who carries the cycle first
	 */
	private function record(SuggestionPlan $plan, SuggestionJob $job, string $day, array $memberIds, bool $overload): void {
		if ($memberIds !== [] && $plan->ledger->knows($memberIds[0])) {
			$plan->ledger->book($memberIds[0], $day, $job->energy);
		}
		$plan->place(SuggestionPlacement::of($job, $day, $memberIds, $overload));
	}

	/**
	 * An overdue cycle sits in the red block. It takes today's energy of
	 * the member who would do it, so nothing more is piled onto that day.
	 */
	private function countOverdue(SuggestionPlan $plan, SuggestionJob $job): void {
		$today = $plan->ledger->todayKey;
		$choice = $this->choose($plan->ledger, $job, [$today]);
		if ($choice !== null) {
			$plan->ledger->book($choice['memberId'], $today, $job->energy);
		}
		$members = $choice !== null ? $job->membersWith($choice['memberId']) : ($job->assigned ? $job->candidates : []);
		$plan->countOverdue(SuggestionPlacement::of($job, null, $members, false));
	}

	/**
	 * Queue the cycle that follows a job done on the given day, while its
	 * window still opens inside the horizon.
	 *
	 * @param callable(SuggestionJob, string): ?CurrentCycle $successor
	 */
	private function follow(SuggestionQueue $queue, SuggestionJob $job, string $doneOn, callable $successor, SuggestionLedger $ledger): void {
		$next = $successor($job, $doneOn);
		if ($next !== null && $next->startOn <= $ledger->lastDay()) {
			$queue->push($job->followedBy($next, $doneOn));
		}
	}
}
