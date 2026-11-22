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
 * Resolves the effective recognized revisions for the recognitions and
 * the live suggestion, whose budgets the daily summary counts with
 * (D-091-147). Deliberately LOW in the
 * dependency graph — mappers and pure date helpers only, never a command
 * service. Merely reading a projection writes nothing. Since ADR-021 a
 * pause writes nothing onto tasks: {@see walletsAbsentThroughout} decides
 * on read whether a window, or a single day, was neutral for a member.
 * It is the one absence derivation; the per-occurrence excuse with its
 * timeliness rule went with the stored occurrences (RF-091-14).
 *
 * Comparison rules that stay (Q-073-02, Standard A 2026-09-23): every
 * fact keeps its historical date labels — no device-zone or current-zone
 * re-bucketing — and a recognized pause covers a day iff the day lies
 * within the pause's inclusive saved date labels (civil comparison, no
 * zone translation).
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
	// Pure derivation (unit-testable without a database)
	// ------------------------------------------------------------------

	/**
	 * ADR-021 decision 5 (D-091-03): the wallets for which a window lies
	 * entirely inside an absence — covered on every day from its first to
	 * its last. Such a window is neutral for that member: neither kept nor
	 * missed. Decided on read from the governing revisions, so an absence
	 * entered or deleted later is honoured. Asked for the members
	 * responsible for the window, so a closed window keeps its neutrality
	 * when the member later leaves the house. One day is a window too: the
	 * live suggestion asks per day of its horizon.
	 *
	 * Different pause groups compose as a UNION: shortening or deleting one
	 * group removes only its own superseded coverage, never another
	 * group's.
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
	 * group's first acknowledged revision.
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
}
