<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

/**
 * A member as the live suggestion reads them (ADR-022 decision 1): the
 * energy per weekday, the days they are available, the days they prefer
 * and, for a child, the owner-set daily limit.
 */
final class SuggestionMember {
	/**
	 * @param int $id House-user id
	 * @param string $userId Nextcloud user id
	 * @param array<string, int> $budget Energy per lowercase weekday name
	 * @param array<string, bool> $available Availability per lowercase weekday name
	 * @param list<string> $preferred Preferred lowercase weekday names
	 * @param int|null $dailyLimit A child's daily limit; null without one
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $userId,
		public readonly array $budget,
		public readonly array $available,
		public readonly array $preferred,
		public readonly ?int $dailyLimit,
	) {
	}

	/** Whether the member is available on a weekday; available unless set otherwise. */
	public function isAvailableOn(string $weekday): bool {
		return (bool)($this->available[$weekday] ?? true);
	}

	/** Whether the member prefers a weekday. */
	public function prefers(string $weekday): bool {
		return in_array($weekday, $this->preferred, true);
	}

	/** The energy of a weekday; nothing on a day the member is not available. */
	public function budgetOn(string $weekday): int {
		return $this->isAvailableOn($weekday) ? max(0, (int)($this->budget[$weekday] ?? 0)) : 0;
	}
}
