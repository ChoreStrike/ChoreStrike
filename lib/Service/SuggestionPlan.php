<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

/**
 * What one run of the live suggestion placed (ADR-022): the cycles on
 * their days, the overdue ones that only count against today, and the
 * budgets they were booked on.
 */
final class SuggestionPlan {
	/**
	 * Placed cycles in placement order.
	 *
	 * @var list<array{roomTaskId: int, cycleKey: ?string, day: string, memberIds: list<int>, energy: int, overload: bool, current: bool, windowStartOn: string, windowEndOn: string, postponeCount: int}>
	 */
	public array $placements = [];

	/**
	 * Overdue cycles: never placed, counted against today's energy of the
	 * member who would do them. While every candidate is absent today,
	 * unassigned work names nobody and assigned work keeps its assignees.
	 *
	 * @var list<array{roomTaskId: int, cycleKey: string, memberIds: list<int>, energy: int, windowStartOn: string, windowEndOn: string}>
	 */
	public array $overdue = [];

	/** Whether the run stopped at its step bound and left cycles unplaced. */
	public bool $truncated = false;

	public function __construct(
		public readonly SuggestionLedger $ledger,
	) {
	}

	/**
	 * The placements of one day.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function on(string $day): array {
		return array_values(array_filter($this->placements, static fn (array $placement): bool => $placement['day'] === $day));
	}
}
