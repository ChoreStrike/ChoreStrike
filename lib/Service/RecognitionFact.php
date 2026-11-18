<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

/**
 * One piece of due work as the recognitions count it (ADR-021 decision
 * 5): a stored occurrence from before the derived model, a closed cycle
 * or an open one. The rhythm, the weekly goal and the ranking read the
 * same facts and keep their own definitions on top of them.
 */
final class RecognitionFact {
	/** Done on time. */
	public const KEPT = 'kept';
	/** Done after its window. */
	public const LATE = 'late';
	/** The window ended without the work. */
	public const MISSED = 'missed';
	/** The window is open and the work is not done yet. */
	public const OPEN = 'open';
	/** The window has not opened yet. */
	public const UPCOMING = 'upcoming';

	/**
	 * @param string $day The day the fact counts on: the planned day of a stored
	 *                    occurrence, the last day of a cycle's window (`Y-m-d`)
	 * @param string $weekStart The house-local Monday of the week it counts in
	 * @param string $state self::KEPT | LATE | MISSED | OPEN | UPCOMING
	 * @param list<int> $responsible Wallet ids the fact counts for; empty = the household only
	 * @param array<int, true> $workers Wallets with a valid selected share of the completion
	 * @param array<int, true> $neutralFor Responsible wallets for which the work is neutral (absence)
	 * @param bool $houseNeutral Neutral for the household count
	 * @param bool $provisional The outcome can still change: the window has not ended
	 * @param int|null $roomTaskId Null for a stored occurrence (its visibility is frozen on it)
	 * @param bool|null $isPublic Frozen visibility of a stored occurrence; null = resolve from the task
	 */
	public function __construct(
		public readonly string $day,
		public readonly string $weekStart,
		public readonly string $state,
		public readonly array $responsible,
		public readonly array $workers,
		public readonly array $neutralFor,
		public readonly bool $houseNeutral,
		public readonly bool $provisional,
		public readonly ?int $roomTaskId = null,
		public readonly ?bool $isPublic = null,
	) {
	}

	/** Done on time, by anyone. */
	public function isKept(): bool {
		return $this->state === self::KEPT;
	}

	/** Kept by this member: done on time with a share of theirs. */
	public function isKeptBy(int $walletId): bool {
		return $this->isKept() && isset($this->workers[$walletId]);
	}

	/** Whether the fact counts for this member at all (responsible and not neutral). */
	public function countsFor(int $walletId): bool {
		return in_array($walletId, $this->responsible, true)
			&& (!isset($this->neutralFor[$walletId]) || $this->isKeptBy($walletId));
	}
}
