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
	/** @var list<SuggestionPlacement> Placed cycles in placement order */
	private array $placements = [];

	/** @var array<string, list<SuggestionPlacement>> The placed cycles by day, each day in placement order */
	private array $byDay = [];

	/** @var list<SuggestionPlacement> */
	private array $overdue = [];

	/** Whether the run stopped at its step bound and left cycles unplaced. */
	public bool $truncated = false;

	public function __construct(
		public readonly SuggestionLedger $ledger,
	) {
	}

	/** Record a cycle on its day. */
	public function place(SuggestionPlacement $placement): void {
		$this->placements[] = $placement;
		$this->byDay[(string)$placement->day][] = $placement;
	}

	/**
	 * Record an overdue cycle: never placed, counted against today's
	 * energy of the member who would do it.
	 */
	public function countOverdue(SuggestionPlacement $placement): void {
		$this->overdue[] = $placement;
	}

	/**
	 * Every placed cycle, in placement order.
	 *
	 * @return list<SuggestionPlacement>
	 */
	public function placements(): array {
		return $this->placements;
	}

	/**
	 * The placements of one day, in placement order.
	 *
	 * @return list<SuggestionPlacement>
	 */
	public function on(string $day): array {
		return $this->byDay[$day] ?? [];
	}

	/**
	 * The overdue cycles. While every candidate is absent today,
	 * unassigned work names nobody and assigned work keeps its assignees.
	 *
	 * @return list<SuggestionPlacement>
	 */
	public function overdue(): array {
		return $this->overdue;
	}
}
