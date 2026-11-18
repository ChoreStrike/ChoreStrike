<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\Pause;
use OCA\ChoreStrike\Db\PauseMapper;

/**
 * Lower pause evaluator of the WP2 pause lifecycle (CS-PLAN-073 §3.1).
 *
 * Resolves the effective recognized revisions/excuses for the
 * recognitions and the digest. Deliberately LOW in the dependency graph —
 * mappers and pure date helpers only, never a command service. Merely
 * reading a projection writes nothing. Since ADR-021 a pause writes
 * nothing onto tasks: {@see walletsAbsentThroughout} decides on read
 * whether a window was neutral for a member.
 *
 * Frozen Q-073-02 comparison rules (Standard A 2026-09-23):
 * 1. A candidate work day D in saved zone Z is the half-open UTC interval
 *    [D 00:00 Z, D+1 00:00 Z); completed instants are tested as instants.
 * 2. A saved work appointment counts against a candidate day iff the two
 *    half-open day intervals intersect (within one zone this degenerates
 *    to exact date-label equality; across zones it conservatively
 *    constrains both boundary days).
 * 3. Every fact keeps its historical date/week labels — no device-zone or
 *    current-zone re-bucketing.
 * 4. A recognized pause covers an unfulfilled obligation iff the
 *    occurrence's saved planned-date label lies within the pause's
 *    inclusive saved date labels (civil comparison, no zone translation).
 */
class PauseProjectionService {
	public function __construct(
		private PauseMapper $pauseMapper,
	) {
	}

	// ------------------------------------------------------------------
	// Query-backed resolution
	// ------------------------------------------------------------------

	/**
	 * The revisions of the house that can govern coverage: acknowledged
	 * ones and cancelled ones (ADR-020). Coverage is the UNION across pause
	 * GROUPS of each group's governing revision
	 * ({@see governingRevisionsOn}): within one group the latest decision
	 * is authoritative for the dates it governs, so a shortening or a
	 * deletion actually removes the superseded future coverage
	 * (RF-073-WP2-23) while today's and earlier recognized excuses
	 * survive it; changing one group never removes another group's
	 * excuse.
	 *
	 * @return Pause[]
	 */
	public function recognizedForHouse(int $houseId): array {
		return array_values(array_filter(
			$this->pauseMapper->findByHouseId($houseId),
			static fn (Pause $pause): bool => self::canGovern($pause)
		));
	}

	// ------------------------------------------------------------------
	// Pure derivation (frozen rules — unit-testable without a database)
	// ------------------------------------------------------------------

	/**
	 * The wallet ids excused by a recognized pause on the given planned
	 * date: personal pauses for that wallet (current member only) plus
	 * house-scope pauses (every current member).
	 *
	 * R2/RF-073-WP2-23: within each pause group ONLY the governing
	 * acknowledged revision ({@see governingRevisionsOn}) contributes —
	 * a shortening removes its superseded future coverage instead of
	 * letting the retained old revision keep it alive, while dates before
	 * the decision boundary stay covered by the revision that recognized
	 * them. Pending/rejected revisions never govern (an undecided
	 * shortening leaves acknowledged coverage unchanged). A governing
	 * CANCELLED revision means no coverage (ADR-020).
	 *
	 * R3/RF-073-WP2-25: an ordinary recognition excuses only when its
	 * decision predates the given exclusive `$deadline` (the occurrence's
	 * original window end); without an explicit deadline the day-level
	 * rule applies (the decision must predate the end of the candidate day
	 * in the pause's own saved zone). The explicit retrospective-illness
	 * marker is the sole exception that can neutralize a qualifying old
	 * miss. Both facts are persisted, so a later read/job never
	 * reinterprets a timely decision as late or vice versa.
	 *
	 * RF-073-WP2-03: pauses of DIFFERENT groups compose as a UNION —
	 * every wallet maps to the LIST of all covering pause provenances
	 * (a wallet under both a personal and a house pause, or under two
	 * personal groups, keeps every entry). Shortening one group removes
	 * only its own superseded coverage, never another group's excuse.
	 *
	 * @param Pause[] $recognized Revision history (governing revisions are selected internally)
	 * @param array<int, bool> $currentWallets
	 * @param \DateTime|null $deadline Original exclusive obligation deadline (occurrence window end) or null for the day-level rule
	 * @return array<int, list<array{pauseGroupId: int, revision: int, reasonCode: string}>> walletId => covering pause provenance list
	 */
	public static function excusedWalletsOn(array $recognized, string $plannedDateKey, array $currentWallets, ?\DateTime $deadline = null): array {
		$excused = [];
		$append = static function (int $walletId, array $provenance) use (&$excused): void {
			foreach ($excused[$walletId] ?? [] as $existing) {
				if ($existing['pauseGroupId'] === $provenance['pauseGroupId']
					&& $existing['revision'] === $provenance['revision']) {
					return;
				}
			}
			$excused[$walletId][] = $provenance;
		};
		foreach (self::governingRevisionsOn($recognized, $plannedDateKey) as $pause) {
			if (!$pause->isRecognized() || !$pause->coversDate($plannedDateKey)
				|| !self::isEffectiveFor($pause, $plannedDateKey, $deadline)) {
				continue;
			}
			$provenance = [
				'pauseGroupId' => $pause->getPauseGroupId(),
				'revision' => $pause->getRevision(),
				'reasonCode' => $pause->getReasonCode(),
			];
			if ($pause->getScope() === Pause::SCOPE_HOUSE) {
				foreach (array_keys($currentWallets) as $walletId) {
					$append((int)$walletId, $provenance);
				}
				continue;
			}
			$subjectId = $pause->getSubjectWalletId();
			if ($subjectId !== null && isset($currentWallets[(int)$subjectId])) {
				$append((int)$subjectId, $provenance);
			}
		}
		return $excused;
	}

	/**
	 * ADR-021 decision 5 (D-091-03): the wallets for which a window lies
	 * entirely inside an absence — covered on every day from its first to
	 * its last. Such a window is neutral for that member: neither kept nor
	 * missed. Decided on read from the governing revisions, so an absence
	 * entered or deleted later is honoured; the timeliness rule of
	 * {@see isEffectiveFor} belonged to stored occurrences and does not
	 * apply. Asked for the members responsible for the window, so a closed
	 * window keeps its neutrality when the member later leaves the house.
	 *
	 * @param Pause[] $recognized Revision history (governing revisions are selected per day)
	 * @param array<int, bool> $wallets The wallets asked about (walletId => true)
	 * @return array<int, true> walletId => true
	 */
	public static function walletsAbsentThroughout(array $recognized, string $startOn, string $endOn, array $wallets): array {
		if ($recognized === [] || $wallets === [] || $startOn > $endOn) {
			return [];
		}
		$absent = null;
		for ($day = $startOn; $day <= $endOn; $day = RecurrenceCalculator::dayAfter($day)) {
			$covered = [];
			foreach (self::governingRevisionsOn($recognized, $day) as $pause) {
				if (!$pause->isRecognized() || !$pause->coversDate($day)) {
					continue;
				}
				if ($pause->getScope() === Pause::SCOPE_HOUSE) {
					$covered += array_fill_keys(array_keys($wallets), true);
				} elseif ($pause->getSubjectWalletId() !== null && isset($wallets[(int)$pause->getSubjectWalletId()])) {
					$covered[(int)$pause->getSubjectWalletId()] = true;
				}
			}
			$absent = $absent === null ? $covered : array_intersect_key($absent, $covered);
			if ($absent === []) {
				return [];
			}
		}
		return $absent ?? [];
	}

	/**
	 * The per-group revision precedence of R2 (plan §3.4): within one
	 * pause group, the acknowledged or cancelled revision whose next-day
	 * effective boundary ({@see effectiveBoundaryKey}) is the latest one at
	 * or before the candidate date GOVERNS that date — an acknowledged
	 * shortening actually removes its superseded future coverage, while
	 * dates before the boundary stay governed by the revision that was
	 * authoritative when the decision was made. A date before EVERY
	 * decision boundary (e.g. a mid-range approval) stays governed by the
	 * group's first acknowledged revision, subject to the timeliness gate.
	 * Different groups stay independent — the caller unions their
	 * governed coverage.
	 *
	 * ADR-020: a CANCELLED revision takes part with the same boundary and
	 * means no coverage — a deleted absence frees the days from the next
	 * local day and keeps what it already excused. Every cancelled
	 * revision reads alike, also a pending request that a departure
	 * cancelled in place (D-090-21).
	 *
	 * @param Pause[] $revisions
	 * @return Pause[] At most one governing revision per pause group; a cancelled one covers nothing
	 */
	public static function governingRevisionsOn(array $revisions, string $dateKey): array {
		$byGroup = [];
		foreach ($revisions as $pause) {
			if (!self::canGovern($pause)) {
				continue;
			}
			$byGroup[$pause->getPauseGroupId()][] = $pause;
		}
		$governing = [];
		foreach ($byGroup as $group) {
			usort($group, static fn (Pause $a, Pause $b): int => $a->getRevision() <=> $b->getRevision());
			$chosen = null;
			$firstRecognized = null;
			foreach ($group as $pause) {
				if ($firstRecognized === null && $pause->isRecognized()) {
					$firstRecognized = $pause;
				}
				$boundary = self::effectiveBoundaryKey($pause);
				if ($boundary === null || $boundary <= $dateKey) {
					$chosen = $pause;
				}
			}
			$chosen ??= $firstRecognized;
			if ($chosen !== null) {
				$governing[] = $chosen;
			}
		}
		return $governing;
	}

	/** Acknowledged and cancelled revisions carry a decision that governs dates. */
	private static function canGovern(Pause $pause): bool {
		return $pause->isRecognized() || $pause->getState() === Pause::STATE_CANCELLED;
	}

	/**
	 * The first civil date a revision's own decision governs: the day
	 * AFTER the decision day in the pause's SAVED zone (plan §3.4 — a
	 * shortening/revocation is effective no earlier than the next local
	 * day; today's and earlier recognized excuses are preserved).
	 * Derived from persisted facts only (decision timestamp + saved
	 * zone), so it stays reproducible after later revisions, house-zone
	 * changes and job replays — never from evaluation wall time. Noon-based
	 * civil arithmetic keeps a DST transition from shifting the label.
	 */
	public static function effectiveBoundaryKey(Pause $pause): ?string {
		$decision = $pause->decisionTime();
		if ($decision === null) {
			return null;
		}
		$zone = new \DateTimeZone($pause->getTimezone() ?: 'UTC');
		$decisionDay = (clone $decision)->setTimezone($zone)->format('Y-m-d');
		return (new \DateTime($decisionDay . ' 12:00:00', $zone))->modify('+1 day')->format('Y-m-d');
	}

	/**
	 * The recognition-eligibility gate of R3 (plan §3.4): an ORDINARY
	 * recognition excuses an obligation only when its decision predates
	 * the original exclusive `$deadline` (the occurrence's saved window
	 * end — range inclusion alone is not eligibility). A pending/rejected
	 * request stays ineffective and crossing the deadline neither
	 * approves, rejects nor extends it; the decision time is the ACTUAL
	 * recognition decision, not a child's earlier request time. Only the
	 * explicit retrospective-illness marker can neutralize a qualifying
	 * unfulfilled historical obligation. Without an explicit deadline
	 * (day-level capacity flags) the decision must predate the end of the
	 * candidate day in the pause's own saved zone.
	 */
	public static function isEffectiveFor(Pause $pause, string $dateKey, ?\DateTime $deadline): bool {
		if ($pause->getIsRetrospectiveIllness()) {
			return true;
		}
		$decision = $pause->decisionTime();
		if ($decision === null) {
			// Defensive: a row without any decision fact cannot be proven
			// late; production rows always carry both NOT NULL timestamps.
			return true;
		}
		if ($deadline !== null) {
			return $decision < $deadline;
		}
		[, $dayEnd] = self::candidateDayInterval($dateKey, $pause->getTimezone() ?: 'UTC');
		return $decision < $dayEnd;
	}

	// ------------------------------------------------------------------
	// Frozen Q-073-02 day-interval mapping (pure)
	// ------------------------------------------------------------------

	/**
	 * The half-open UTC interval `[D 00:00 Z, D+1 00:00 Z)` of a candidate
	 * work day (Q-073-02 rule 1; DST-correct wall-clock resolution).
	 *
	 * @return array{\DateTime, \DateTime} UTC-normalized [start, end)
	 */
	public static function candidateDayInterval(string $dateKey, string $zone): array {
		$tz = new \DateTimeZone($zone);
		$start = new \DateTime($dateKey . ' 00:00:00', $tz);
		$end = (new \DateTime($dateKey . ' 00:00:00', $tz))->modify('+1 day');
		$utc = new \DateTimeZone('UTC');
		return [$start->setTimezone($utc), $end->setTimezone($utc)];
	}
}
