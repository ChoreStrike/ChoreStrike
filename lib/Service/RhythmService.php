<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\OccurrenceMember;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Db\WorkShareMapper;

/**
 * The minimum planned-day rhythm evaluator (CS-PLAN-070 WP3, Q-074-01
 * Option A correction-closure slice; C1f approved semantics, bounded).
 *
 * The member's confirmed streak is ALWAYS re-derived from valid history —
 * never restored from a snapshot and never guarded against a stale cache
 * (a later completion may leave `currentStreak != recordedAfter`, so a
 * comparison preserves a false run; valid-history evaluation is the C3
 * requirement).
 *
 * Per saved planned day, every active agreed obligation must be fulfilled
 * inside its saved window by a valid selected share for that member:
 *  - all fulfilled          → the day is a success (one streak step);
 *  - any unfulfilled, window still open or planned today → the day is
 *    pending (held, neither step nor break);
 *  - any unfulfilled with an expired window → a miss (breaks the run;
 *    a later success starts a NEW run, C1f).
 * Days without personal obligations are neutral (no step, no break).
 *
 * A chronological scan holds later success behind the first pending day.
 * A later miss clears that hold and begins a new run on subsequent valid
 * days. A fixed-size cursor page bounds memory/queries while retaining
 * lifetime history for corrected record and award eligibility. Persistence
 * runs inside the caller's transaction on already locked membership rows.
 */
class RhythmService {
	/** A page, not a time cutoff: old valid runs must survive corrections. */
	public const HISTORY_PAGE_SIZE = 300;

	private OccurrenceMapper $occurrenceMapper;
	private OccurrenceMemberMapper $occMemberMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private HouseMapper $houseMapper;
	private HouseUserMapper $houseUserMapper;
	private CalendarService $calendar;
	private WorkShareMapper $workShareMapper;
	private TransactionExecutor $transactions;

	public function __construct(
		OccurrenceMapper $occurrenceMapper,
		OccurrenceMemberMapper $occMemberMapper,
		CompletedTaskMapper $completedTaskMapper,
		HouseMapper $houseMapper,
		HouseUserMapper $houseUserMapper,
		CalendarService $calendar,
		WorkShareMapper $workShareMapper,
		TransactionExecutor $transactions,
	) {
		$this->occurrenceMapper = $occurrenceMapper;
		$this->occMemberMapper = $occMemberMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->houseMapper = $houseMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->calendar = $calendar;
		$this->workShareMapper = $workShareMapper;
		$this->transactions = $transactions;
	}

	/**
	 * Cron advances the projection after both timeline materialization and pause
	 * convergence. The house lock is first; membership locks follow in ID order.
	 */
	public function refreshForJobs(\DateTime $now): void {
		$house = $this->houseMapper->findOneForJobs();
		if ($house === null) {
			return;
		}
		$this->refreshForHouse((int)$house->getId(), $now);
	}

	public function refreshForHouse(int $houseId, \DateTime $now): void {
		$this->transactions->run(function () use ($houseId, $now): void {
			$this->houseMapper->findForUpdate($houseId);
			$members = $this->houseUserMapper->findByHouseId($houseId);
			usort($members, static fn (HouseUser $a, HouseUser $b): int => $a->getId() <=> $b->getId());
			$locked = [];
			foreach ($members as $member) {
				$locked[] = $this->houseUserMapper->findForUpdate((int)$member->getId());
			}
			$this->recomputeForMembers($houseId, $locked, $now);
		});
	}

	/**
	 * Re-derive current/longest streak from valid history for the given
	 * locked memberships and persist changed values.
	 *
	 * @param int $houseId
	 * @param HouseUser[] $members Locked membership rows
	 * @param \DateTime $now Evaluation instant
	 * @return array<int, array{currentStreak: int, longestStreak: int}> member id => values
	 */
	public function recomputeForMembers(int $houseId, array $members, \DateTime $now): array {
		if ($members === []) {
			return [];
		}
		$result = $this->evaluateForMembers($houseId, $members, $now);

		foreach ($members as $member) {
			$memberId = (int)$member->getId();
			$evaluation = $result[$memberId];

			$changed = false;
			if ($member->getCurrentStreak() !== $evaluation['currentStreak']) {
				$member->setCurrentStreak($evaluation['currentStreak']);
				$changed = true;
			}
			if ($member->getLongestStreak() !== $evaluation['longestStreak']) {
				// A correction may SHRINK the record only when the corrected
				// valid history never reached it (C3g) — the recomputed
				// longest IS the valid-history maximum.
				$member->setLongestStreak($evaluation['longestStreak']);
				$changed = true;
			}
			// Docbed contract: re-derive AND persist changed values — the
			// caller holds the member locks; this is the single writer.
			if ($changed) {
				$this->houseUserMapper->update($member);
			}
		}
		return $result;
	}

	/**
	 * @param HouseUser[] $members
	 * @return array<int, array{currentStreak: int, longestStreak: int}>
	 */
	public function evaluateForMembers(int $houseId, array $members, \DateTime $now): array {
		$walletByMember = [];
		$state = [];
		foreach ($members as $member) {
			$state[(int)$member->getId()] = ['currentStreak' => 0, 'longestStreak' => 0, 'pending' => false];
			if ($member->getWalletId() !== null) {
				$walletByMember[(int)$member->getWalletId()] = (int)$member->getId();
			}
		}
		if ($walletByMember === []) {
			return array_map(static fn (array $entry): array => ['currentStreak' => 0, 'longestStreak' => 0], $state);
		}
		$tz = $this->calendar->getTimeZone($this->houseMapper->find($houseId));
		$through = (new \DateTime($this->calendar->dayKey($now, $tz), $tz))->modify('+1 day')->format('Y-m-d');
		$afterDay = null;
		$afterId = 0;
		$day = null;
		$dayOutcomes = [];
		do {
			$page = $this->occurrenceMapper->findPlannedPage($houseId, $through, $afterDay, $afterId, self::HISTORY_PAGE_SIZE);
			$ids = array_map(static fn (Occurrence $row): int => (int)$row->getId(), $page);
			$membersByOccurrence = $this->occMemberMapper->findByOccurrenceIds($ids);
			$completionIds = array_values(array_unique(array_filter(array_map(
				static fn (Occurrence $row): ?int => $row->getCompletionId(), $page
			))));
			$completions = $this->completedTaskMapper->findValidByIds($completionIds);
			$shares = $this->workShareMapper->findSelectedByCompletionIds(array_keys($completions));
			foreach ($page as $occurrence) {
				$planned = $occurrence->getPlannedDate()?->format('Y-m-d');
				if ($planned !== $day && $day !== null) {
					$this->confirmDay($state, $dayOutcomes);
					$dayOutcomes = [];
				}
				$day = $planned;
				if ($planned === null || ($occurrence->getDayStart() !== null && $occurrence->getDayStart() > $now)) {
					continue;
				}
				$completion = $completions[(int)($occurrence->getCompletionId() ?? 0)] ?? null;
				$suspended = $occurrence->getSuspensionState() === Occurrence::SUSPENSION_SUSPENDED;
				$wallets = [];
				foreach ($shares[(int)($occurrence->getCompletionId() ?? 0)] ?? [] as $share) {
					if ($share->getWalletId() !== null) {
						$wallets[(int)$share->getWalletId()] = true;
					}
				}
				$onTime = $this->onTime($occurrence, $completion);
				foreach ($membersByOccurrence[(int)$occurrence->getId()] ?? [] as $memberRow) {
					$memberId = $walletByMember[(int)$memberRow->getWalletId()] ?? null;
					if ($memberId === null || !$memberRow->getObligated() || $memberRow->getDisposition() !== OccurrenceMember::DISPOSITION_ACTIVE) {
						continue;
					}
					$fulfilled = $onTime && isset($wallets[(int)$memberRow->getWalletId()]);
					if ($suspended && !$fulfilled) {
						continue;
					}
					$outcome = $fulfilled ? 1 : (($occurrence->getWindowEnd() ?? (new \DateTime($planned, new \DateTimeZone($occurrence->getTimezone() ?: 'UTC')))->modify('+1 day')) <= $now ? -1 : 0);
					$dayOutcomes[$memberId] = min($dayOutcomes[$memberId] ?? 1, $outcome);
				}
			}
			if ($page !== []) {
				$last = end($page);
				$afterDay = $last->getPlannedDate()->format('Y-m-d');
				$afterId = (int)$last->getId();
			}
		} while (count($page) === self::HISTORY_PAGE_SIZE);
		$this->confirmDay($state, $dayOutcomes);
		return array_map(static fn (array $entry): array => [
			'currentStreak' => $entry['currentStreak'],
			'longestStreak' => $entry['longestStreak'],
		], $state);
	}

	/** @param array<int, array{currentStreak: int, longestStreak: int, pending: bool}> $state */
	private function confirmDay(array &$state, array $outcomes): void {
		foreach ($outcomes as $memberId => $outcome) {
			if ($outcome === -1) {
				$state[$memberId]['currentStreak'] = 0;
				$state[$memberId]['pending'] = false;
			} elseif ($outcome === 0) {
				$state[$memberId]['pending'] = true;
			} elseif (!$state[$memberId]['pending']) {
				$state[$memberId]['currentStreak']++;
				$state[$memberId]['longestStreak'] = max($state[$memberId]['longestStreak'], $state[$memberId]['currentStreak']);
			}
		}
	}

	public function onTime(Occurrence $occurrence, ?CompletedTask $completion): bool {
		return $completion !== null
			&& $completion->getCompletionClass() === CompletedTask::CLASS_AGREED
			&& ($occurrence->getWindowStart() === null || $completion->getCompletedAt() >= $occurrence->getWindowStart())
			&& ($occurrence->getWindowEnd() === null || $completion->getCompletedAt() < $occurrence->getWindowEnd());
	}
}
