<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;

/**
 * The minimum planned-day rhythm evaluator (CS-PLAN-070 WP3, Q-074-01
 * Option A correction-closure slice; C1f approved semantics, bounded).
 *
 * The member's confirmed streak is ALWAYS re-derived from valid history —
 * never restored from a snapshot and never guarded against a stale cache
 * (a later completion may leave `currentStreak != recordedAfter`, so a
 * comparison preserves a false run; valid-history evaluation is the C3
 * requirement). The history starts at the house's first day of derived
 * windows: what a member had earned before continues as stored
 * (`streak_base`, D-091-04) and no earlier week is evaluated again.
 *
 * Per day a window ends on, every cycle the member is responsible for
 * must be kept — done on time by a valid selected share of that member:
 *  - all kept               → the day is a success (one streak step);
 *  - any not done with its window still open → the day is pending
 *    (held, neither step nor break);
 *  - any not kept after its window → a miss (breaks the run; a later
 *    success starts a NEW run, C1f).
 * Days without own cycles are neutral (no step, no break), and so is a
 * window that lay entirely inside an absence (D-091-03).
 *
 * A chronological scan holds later success behind the first pending day.
 * A later miss clears that hold and begins a new run on subsequent valid
 * days. A fixed-size cursor page bounds memory/queries while retaining
 * lifetime history for corrected record and award eligibility. Persistence
 * runs inside the caller's transaction on already locked membership rows.
 *
 * CS-PLAN-070 WP4 (parent §3.4 read contract): the evaluation result also
 * carries the pending-hold indicator (`pending` — a confirmed streak with
 * a day still pending), so reads can show "confirmed streak, day pending"
 * without re-implementing C1f. The completion transaction and TimelineJob
 * remain the only writers; evaluation stays pure.
 */
class RhythmService {
	/** A page, not a time cutoff: old valid runs must survive corrections. */
	public const HISTORY_PAGE_SIZE = 300;

	private RecognitionFactsService $facts;
	private HouseMapper $houseMapper;
	private HouseUserMapper $houseUserMapper;
	private TransactionExecutor $transactions;

	public function __construct(
		RecognitionFactsService $facts,
		HouseMapper $houseMapper,
		HouseUserMapper $houseUserMapper,
		TransactionExecutor $transactions,
	) {
		$this->facts = $facts;
		$this->houseMapper = $houseMapper;
		$this->houseUserMapper = $houseUserMapper;
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
	 * @return array<int, array{currentStreak: int, longestStreak: int, pending: bool}> member id => values
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
	 * @return array<int, array{currentStreak: int, longestStreak: int, pending: bool}>
	 */
	public function evaluateForMembers(int $houseId, array $members, \DateTime $now): array {
		$memberByWallet = [];
		$state = [];
		foreach ($members as $member) {
			// D-091-04: the streak a member held at the cutover is where the
			// derived model continues; a member without one starts at zero.
			$state[(int)$member->getId()] = [
				'currentStreak' => (int)($member->getStreakBase() ?? 0),
				'longestStreak' => (int)($member->getLongestStreakBase() ?? 0),
				'pending' => false,
			];
			if ($member->getWalletId() !== null) {
				$memberByWallet[(int)$member->getWalletId()] = (int)$member->getId();
			}
		}
		if ($memberByWallet === []) {
			return $state;
		}

		$house = $this->houseMapper->find($houseId);
		$absences = $this->facts->absences($houseId);
		$open = $this->facts->openFacts($house, $now, null, null, false, $absences);
		usort($open, static fn (RecognitionFact $a, RecognitionFact $b): int => $a->day <=> $b->day);
		$day = null;
		$dayOutcomes = [];
		$feed = function (RecognitionFact $fact) use (&$state, &$day, &$dayOutcomes, $memberByWallet): void {
			if ($fact->day !== $day && $day !== null) {
				$this->confirmDay($state, $dayOutcomes);
				$dayOutcomes = [];
			}
			$day = $fact->day;
			foreach ($fact->responsible as $walletId) {
				$memberId = $memberByWallet[$walletId] ?? null;
				$outcome = self::outcomeFor($fact, $walletId);
				if ($memberId !== null && $outcome !== null) {
					$dayOutcomes[$memberId] = min($dayOutcomes[$memberId] ?? 1, $outcome);
				}
			}
		};

		// Closed cycles page by page, with the open ones merged in by day.
		$afterDay = null;
		$afterId = 0;
		do {
			$page = $this->facts->closedFactsPage($house, $afterDay, $afterId, self::HISTORY_PAGE_SIZE, $absences);
			foreach ($page['facts'] as $fact) {
				while ($open !== [] && $open[0]->day < $fact->day) {
					$feed(array_shift($open));
				}
				$feed($fact);
			}
			$afterDay = $page['lastDay'] ?? $afterDay;
			$afterId = $page['lastId'];
		} while ($page['count'] === self::HISTORY_PAGE_SIZE);
		foreach ($open as $fact) {
			$feed($fact);
		}
		$this->confirmDay($state, $dayOutcomes);
		return $state;
	}

	/**
	 * What one fact means for a responsible member's day: 1 kept, 0 still
	 * open, -1 a miss; null when it does not count (the window has not
	 * opened, or it lay inside an absence).
	 */
	private static function outcomeFor(RecognitionFact $fact, int $walletId): ?int {
		if ($fact->state === RecognitionFact::UPCOMING || !$fact->countsFor($walletId)) {
			return null;
		}
		if ($fact->isKeptBy($walletId)) {
			return 1;
		}
		return $fact->state === RecognitionFact::OPEN ? 0 : -1;
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
}
