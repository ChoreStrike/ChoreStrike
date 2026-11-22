<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

/**
 * The budgets of one suggestion run (ADR-022 decision 1): what each
 * member has left on each day of the horizon. Energy is kept in
 * hundredths, the unit of the work shares, so today's done work is
 * subtracted exactly.
 */
final class SuggestionLedger {
	/** @var list<string> The days of the horizon, ascending */
	public readonly array $days;
	/** @var array<string, string> Day key to lowercase weekday name */
	private array $weekdays = [];
	/** @var array<int, SuggestionMember> */
	private array $members = [];
	/** @var array<int, array<string, int>> Member id to day to energy minor booked */
	private array $booked = [];
	/** @var array<int, array<string, int>> Member id to day to cycles booked */
	private array $counts = [];

	/**
	 * @param list<SuggestionMember> $members
	 * @param array<int, array<string, true>> $absentDays Member id to the days of the horizon they are absent on
	 * @param array<int, int> $doneMinor Member id to the energy minor of today's valid work
	 */
	public function __construct(
		public readonly string $todayKey,
		array $members,
		private array $absentDays,
		private array $doneMinor,
		int $horizonDays,
	) {
		$days = [];
		for ($offset = 0; $offset < $horizonDays; $offset++) {
			$day = RecurrenceCalculator::offsetDays($todayKey, $offset);
			$days[] = $day;
			$this->weekdays[$day] = strtolower(RecurrenceCalculator::dayKeyToDate($day)->format('l'));
		}
		$this->days = $days;
		foreach ($members as $member) {
			$this->members[$member->id] = $member;
		}
	}

	/** The last day of the horizon. */
	public function lastDay(): string {
		return $this->days[count($this->days) - 1];
	}

	/** Whether the member is absent on a day (a personal or a household absence). */
	public function isAbsent(int $memberId, string $day): bool {
		return isset($this->absentDays[$memberId][$day]);
	}

	/** Whether the member is available on the weekday of a day. */
	public function isAvailable(int $memberId, string $day): bool {
		return $this->members[$memberId]->isAvailableOn($this->weekdays[$day]);
	}

	/** Whether the weekday of a day is one the member prefers. */
	public function isPreferred(int $memberId, string $day): bool {
		return $this->members[$memberId]->prefers($this->weekdays[$day]);
	}

	/** The energy of the member's day before anything is placed. */
	public function budget(int $memberId, string $day): int {
		return $this->members[$memberId]->budgetOn($this->weekdays[$day]);
	}

	/** Today's valid work of the member, in energy minor. */
	public function doneMinor(int $memberId): int {
		return (int)($this->doneMinor[$memberId] ?? 0);
	}

	/** What the member has left on a day, in energy minor; negative once the day is overloaded. */
	public function remainingMinor(int $memberId, string $day): int {
		return $this->budget($memberId, $day) * 100
			- ($day === $this->todayKey ? $this->doneMinor($memberId) : 0)
			- ($this->booked[$memberId][$day] ?? 0);
	}

	/** Whether a cycle of the given energy fits the member's day: available, present, inside the budget and the limit. */
	public function fits(int $memberId, string $day, int $energy): bool {
		return $this->isAvailable($memberId, $day)
			&& !$this->isAbsent($memberId, $day)
			&& $this->remainingMinor($memberId, $day) >= $energy * 100
			&& !$this->atLimit($memberId, $day);
	}

	/** Whether a child's daily limit is reached on the member's day; never for a member without one. */
	public function atLimit(int $memberId, string $day): bool {
		$limit = $this->members[$memberId]->dailyLimit;
		return $limit !== null && ($this->counts[$memberId][$day] ?? 0) >= $limit;
	}

	/** Book a cycle of the given energy on the member's day. */
	public function book(int $memberId, string $day, int $energy): void {
		$this->booked[$memberId][$day] = ($this->booked[$memberId][$day] ?? 0) + $energy * 100;
		$this->counts[$memberId][$day] = ($this->counts[$memberId][$day] ?? 0) + 1;
	}

	/** The energy placed on the member's day. */
	public function placed(int $memberId, string $day): int {
		return intdiv($this->booked[$memberId][$day] ?? 0, 100);
	}

	/** Whether the member belongs to the house this run plans for. */
	public function knows(int $memberId): bool {
		return isset($this->members[$memberId]);
	}
}
