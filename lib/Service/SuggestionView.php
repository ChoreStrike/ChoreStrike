<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomTask;

/**
 * One read of the live suggestion of a house: the plan with the inputs
 * it was placed from, and what the readers take from it — today's list,
 * the count of the daily summary, a member's load and the read contract
 * of a range. House-wide: a reader that shows entries hands in the room
 * tasks its viewer may see (ADR-010).
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
			if ($placement->current) {
				$entries[$placement->roomTaskId] = [
					'cycleKey' => (string)$placement->cycleKey,
					'userIds' => $this->context->userIdsOf($placement->memberIds),
					'overload' => $placement->overload,
				];
			}
		}
		return $entries;
	}

	/**
	 * What the daily summary counts for a member (ADR-022 decision 7):
	 * the work suggested to them for today plus their overdue tasks.
	 * Nothing on a day the member is not available or is absent — an
	 * overdue task of an absent assignee still names them.
	 */
	public function countFor(HouseUser $member): int {
		$memberId = (int)$member->getId();
		$today = $this->context->todayKey;
		if (!$this->isAvailableToday($memberId) || $this->plan->ledger->isAbsent($memberId, $today)) {
			return 0;
		}
		$mine = static fn (SuggestionPlacement $entry): bool => $entry->names($memberId);
		return count(array_filter($this->plan->on($today), static fn (SuggestionPlacement $entry): bool => $entry->current && $mine($entry)))
			+ count(array_filter($this->plan->overdue(), $mine));
	}

	/**
	 * Whether the plan placed work on the member today by their weekday
	 * availability; false for someone the plan does not know. An absence
	 * is a reader's own question.
	 */
	public function isAvailableToday(int $memberId): bool {
		$ledger = $this->plan->ledger;
		return $ledger->knows($memberId) && $ledger->isAvailable($memberId, $this->context->todayKey);
	}

	/** A child's daily limit as the plan was placed with it; null for a member without one. */
	public function limitOf(int $memberId): ?int {
		return $this->plan->ledger->limitOf($memberId);
	}

	/**
	 * The placed energy of a member's day against its energy; null for
	 * someone the plan does not know.
	 *
	 * @return array{userId: string, day: string, budget: int, placed: int, doneMinor: int, preferred: bool, absent: bool}|null
	 */
	public function loadOf(int $memberId, string $day): ?array {
		$ledger = $this->plan->ledger;
		if (!$ledger->knows($memberId) || !isset($this->context->members[$memberId])) {
			return null;
		}
		return [
			'userId' => (string)$this->context->members[$memberId]->getNextcloudUserId(),
			'day' => $day,
			'budget' => $ledger->budget($memberId, $day),
			'placed' => $ledger->placed($memberId, $day),
			'doneMinor' => $day === $this->context->todayKey ? $ledger->doneMinor($memberId) : 0,
			'preferred' => $ledger->isPreferred($memberId, $day),
			'absent' => $ledger->isAbsent($memberId, $day),
		];
	}

	/**
	 * The read contract of a range of days (API reference, Scheduling):
	 * per day the entries the viewer may see, the overdue work when the
	 * range starts today, and the load of the viewer — of every member
	 * for the owner, who manages the budgets.
	 *
	 * @param array<int, RoomTask> $visible The room tasks the viewer may see, by id
	 * @param array<int, Room> $rooms Their rooms, by id
	 * @param string $from First house-local day, inside the horizon
	 * @param string $to Last house-local day
	 * @return array{days: list<array{day: string, entries: list<array<string, mixed>>}>, overdue: list<array<string, mixed>>, members: list<array<string, mixed>>}
	 */
	public function range(HouseUser $viewer, array $visible, array $rooms, string $from, string $to): array {
		$days = array_values(array_filter($this->plan->ledger->days, static fn (string $day): bool => $day >= $from && $day <= $to));
		$describe = fn (array $entries): array => array_values(array_map(
			fn (SuggestionPlacement $entry): array => $this->describe($entry, $visible[$entry->roomTaskId], $rooms),
			array_filter($entries, static fn (SuggestionPlacement $entry): bool => isset($visible[$entry->roomTaskId]))
		));

		return [
			'days' => array_map(fn (string $day): array => ['day' => $day, 'entries' => $describe($this->plan->on($day))], $days),
			'overdue' => $from === $this->context->todayKey ? $describe($this->plan->overdue()) : [],
			'members' => $this->loads($viewer, $days),
		];
	}

	/**
	 * One entry of the read contract, with what a calendar row shows.
	 *
	 * @param array<int, Room> $rooms
	 * @return array<string, mixed>
	 */
	private function describe(SuggestionPlacement $entry, RoomTask $roomTask, array $rooms): array {
		return [
			'roomTaskId' => $entry->roomTaskId,
			'cycleKey' => $entry->cycleKey,
			'userIds' => $this->context->userIdsOf($entry->memberIds),
			'energy' => $entry->energy,
			'overload' => $entry->overload,
			'windowStartOn' => $entry->windowStartOn,
			'windowEndOn' => $entry->windowEndOn,
			'taskName' => (string)$this->context->taskOf($entry->roomTaskId)?->getName(),
			'roomId' => $roomTask->getRoomId(),
			'roomName' => ($rooms[$roomTask->getRoomId()] ?? null)?->getName(),
		];
	}

	/**
	 * The loads a viewer reads: the own one, every member's for the owner.
	 *
	 * @param list<string> $days
	 * @return list<array<string, mixed>>
	 */
	private function loads(HouseUser $viewer, array $days): array {
		$loads = [];
		foreach (array_keys($this->context->members) as $memberId) {
			if ($memberId !== (int)$viewer->getId() && !$viewer->isOwner()) {
				continue;
			}
			foreach ($days as $day) {
				$load = $this->loadOf($memberId, $day);
				if ($load !== null) {
					$loads[] = $load;
				}
			}
		}
		return $loads;
	}
}
