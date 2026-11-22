<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

/**
 * One cycle in a plan of the live suggestion (ADR-022 decision 2): placed
 * on a day, or overdue and only counted against today.
 */
final class SuggestionPlacement {
	/**
	 * @param int $roomTaskId The room task the cycle belongs to
	 * @param string|null $cycleKey The completion target; null for a projected cycle, which is none
	 * @param string|null $day The house-local day the cycle is placed on; null for an overdue cycle
	 * @param list<int> $memberIds House-user ids the cycle names, the member who carries it first. An overdue
	 *                             cycle whose candidates are all absent today names its assignees, or nobody
	 * @param int $energy The cycle's energy in whole points, as the budgets count it
	 * @param bool $overload Whether the cycle fits no day of its window
	 * @param bool $current The room task's current cycle; false for a projection
	 * @param string $windowStartOn First house-local day of the cycle's window (`Y-m-d`)
	 * @param string $windowEndOn Last house-local day of the cycle's window (`Y-m-d`)
	 * @param int $postponeCount How often the cycle was postponed
	 */
	public function __construct(
		public readonly int $roomTaskId,
		public readonly ?string $cycleKey,
		public readonly ?string $day,
		public readonly array $memberIds,
		public readonly int $energy,
		public readonly bool $overload,
		public readonly bool $current,
		public readonly string $windowStartOn,
		public readonly string $windowEndOn,
		public readonly int $postponeCount,
	) {
	}

	/**
	 * A job as the plan holds it. Only the current cycle is a completion
	 * target.
	 *
	 * @param list<int> $memberIds The member who carries the cycle first
	 */
	public static function of(SuggestionJob $job, ?string $day, array $memberIds, bool $overload): self {
		return new self(
			$job->roomTaskId,
			$job->current ? $job->cycle->key : null,
			$day,
			$memberIds,
			$job->energy,
			$overload,
			$job->current,
			$job->cycle->startOn,
			$job->cycle->endOn,
			$job->cycle->postponeCount,
		);
	}

	/** Whether the cycle names a member. */
	public function names(int $memberId): bool {
		return in_array($memberId, $this->memberIds, true);
	}
}
