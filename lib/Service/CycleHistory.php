<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

/**
 * What the valid completions of a room task contribute to its current
 * cycle (ADR-021 decision 1): the latest one anchors an after-completion
 * rule, the latest fulfilled rule day moves a fixed rule on.
 */
final class CycleHistory {
	/**
	 * @param int|null $latestCompletionId The latest valid completion
	 * @param string|null $latestWorkDay Its house-local work day (`Y-m-d`)
	 * @param string|null $latestCycleKey The cycle it fulfilled; null on rows from before the derived model
	 * @param string|null $fixedCentre The latest rule day any valid completion fulfilled (`Y-m-d`)
	 */
	public function __construct(
		public readonly ?int $latestCompletionId = null,
		public readonly ?string $latestWorkDay = null,
		public readonly ?string $latestCycleKey = null,
		public readonly ?string $fixedCentre = null,
	) {
	}

	/** Whether the room task has a valid completion with a known work day. */
	public function hasCompletion(): bool {
		return $this->latestCompletionId !== null && $this->latestWorkDay !== null;
	}
}
