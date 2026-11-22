<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

/**
 * One cycle the live suggestion places (ADR-022 decision 2): a room
 * task's current cycle, or a cycle its rule yields after it.
 */
final class SuggestionJob {
	/**
	 * @param list<int> $candidates House-user ids that can carry the cycle, ascending: the assignees who may
	 *                              see the task, or everyone who may see an unassigned one
	 * @param bool $assigned Whether the candidates are the task's assignees
	 * @param bool $team A team task (several assignees): every assignee sees it on the day one of them carries it
	 * @param string|null $notBefore The first day it can be placed on (the day after the work it follows)
	 * @param bool $current The room task's current cycle; false for a projection
	 */
	public function __construct(
		public readonly int $roomTaskId,
		public readonly int $energy,
		public readonly array $candidates,
		public readonly bool $assigned,
		public readonly bool $team,
		public readonly CurrentCycle $cycle,
		public readonly ?string $notBefore = null,
		public readonly bool $current = true,
	) {
	}

	/** The cycle that follows this one, to be placed after the given day. */
	public function followedBy(CurrentCycle $next, string $doneOn): self {
		return new self($this->roomTaskId, $this->energy, $this->candidates, $this->assigned, $this->team, $next, RecurrenceCalculator::dayAfter($doneOn), false);
	}

	/**
	 * Who sees the cycle on its day: the member who carries it first, and
	 * with a team task every other assignee.
	 *
	 * @return list<int>
	 */
	public function membersWith(int $holderId): array {
		return $this->team
			? [$holderId, ...array_values(array_filter($this->candidates, static fn (int $id): bool => $id !== $holderId))]
			: [$holderId];
	}
}
