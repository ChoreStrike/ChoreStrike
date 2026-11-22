<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

/**
 * The order in which the live suggestion places cycles (ADR-022 decision
 * 2, rule 1): the earliest window end first; ties by higher energy, then
 * by room-task id, then by arrival.
 *
 * @extends \SplHeap<array{string, int, int, int, SuggestionJob}>
 */
final class SuggestionQueue extends \SplHeap {
	private int $sequence = 0;

	/** Queue a cycle in placement order. */
	public function push(SuggestionJob $job): void {
		$this->insert([$job->cycle->endOn, -$job->energy, $job->roomTaskId, $this->sequence++, $job]);
	}

	/** The cycle to place next. */
	public function pop(): SuggestionJob {
		return $this->extract()[4];
	}

	/**
	 * @param array{string, int, int, int, SuggestionJob} $value1
	 * @param array{string, int, int, int, SuggestionJob} $value2
	 */
	protected function compare(mixed $value1, mixed $value2): int {
		// The heap hands out its largest element: the one that sorts first.
		return [$value2[0], $value2[1], $value2[2], $value2[3]] <=> [$value1[0], $value1[1], $value1[2], $value1[3]];
	}
}
