<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;

/**
 * One read of the live suggestion of a house: the plan with the inputs
 * it was placed from. House-wide and not filtered by visibility; whoever
 * serializes it filters by the viewer's task visibility (ADR-010).
 */
class SuggestionView {
	public function __construct(
		public readonly SuggestionContext $context,
		public readonly SuggestionPlan $plan,
	) {
	}

	/**
	 * Today's list: the current cycles suggested for today, by room task.
	 *
	 * @return array<int, array{cycleKey: string, userIds: list<string>, overload: bool}>
	 */
	public function today(): array {
		$entries = [];
		foreach ($this->plan->on($this->context->todayKey) as $placement) {
			if ($placement['current']) {
				$entries[$placement['roomTaskId']] = [
					'cycleKey' => (string)$placement['cycleKey'],
					'userIds' => $this->context->userIdsOf($placement['memberIds']),
					'overload' => $placement['overload'],
				];
			}
		}
		return $entries;
	}

	/**
	 * What the daily summary counts for a member (ADR-022 decision 7):
	 * the work suggested to them for today plus their overdue tasks.
	 */
	public function countFor(HouseUser $member): int {
		$memberId = (int)$member->getId();
		$mine = static fn (array $entry): bool => in_array($memberId, $entry['memberIds'], true);
		return count(array_filter($this->plan->on($this->context->todayKey), static fn (array $placement): bool => $placement['current'] && $mine($placement)))
			+ count(array_filter($this->plan->overdue, $mine));
	}
}
