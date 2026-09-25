<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMember;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\Pause;
use OCA\ChoreStrike\Db\PauseMapper;
use OCA\ChoreStrike\Db\ScheduleRevision;

/**
 * Lower pause evaluator of the WP2 pause lifecycle (CS-PLAN-073 §3.1).
 *
 * Resolves the effective recognized revisions/excuses for the timeline,
 * selection and capacity consumers. Deliberately LOW in the dependency
 * graph — mappers and pure date helpers only, never a command service —
 * so `OccurrenceService -> PauseService -> OccurrenceService` DI cycles
 * cannot exist. Merely reading a projection writes nothing.
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
	private PauseMapper $pauseMapper;
	private HouseUserMapper $houseUserMapper;
	private OccurrenceMemberMapper $occMemberMapper;

	public function __construct(
		PauseMapper $pauseMapper,
		HouseUserMapper $houseUserMapper,
		OccurrenceMemberMapper $occMemberMapper,
	) {
		$this->pauseMapper = $pauseMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->occMemberMapper = $occMemberMapper;
	}

	// ------------------------------------------------------------------
	// Query-backed resolution
	// ------------------------------------------------------------------

	/**
	 * Every pause revision of the house (all states — the evaluator and
	 * the fingerprint need the full revision history; coverage itself is
	 * derived from the recognized subset).
	 *
	 * @return Pause[]
	 */
	public function revisionsForHouse(int $houseId): array {
		return $this->pauseMapper->findByHouseId($houseId);
	}

	/**
	 * The recognized (acknowledged) revisions of the house. Coverage is
	 * the UNION across pause GROUPS of each group's governing revision
	 * ({@see governingRevisionsOn}): within one group the latest
	 * acknowledged decision is authoritative for the dates it governs, so
	 * a shortening actually removes its superseded future coverage
	 * (RF-073-WP2-23) while today's and earlier recognized excuses
	 * survive it; shortening one group never removes another group's
	 * excuse.
	 *
	 * @return Pause[]
	 */
	public function recognizedForHouse(int $houseId): array {
		return array_values(array_filter(
			$this->pauseMapper->findByHouseId($houseId),
			static fn (Pause $pause): bool => $pause->isRecognized()
		));
	}

	/**
	 * Opaque fingerprint over every pause revision of the house — the
	 * token/fingerprint input that invalidates selection tokens and plan
	 * previews on any pause/consent change (plan §3.5).
	 */
	public function housePauseFingerprint(int $houseId): string {
		$material = array_map(
			static fn (Pause $pause): array => [
				'g' => $pause->getPauseGroupId(),
				'r' => $pause->getRevision(),
				's' => $pause->getState(),
				'f' => $pause->getStartDate()?->format('Y-m-d'),
				't' => $pause->getEndDate()?->format('Y-m-d'),
				'w' => $pause->getSubjectWalletId(),
				'i' => $pause->getIsRetrospectiveIllness(),
			],
			$this->pauseMapper->findByHouseId($houseId)
		);
		return sha1((string)json_encode($material));
	}

	/**
	 * The wallet ids of the house's CURRENT members (Q-073-03: future
	 * personal-pause effects stop at departure; house pauses keep applying
	 * to every current member).
	 *
	 * @return array<int, bool> walletId => true
	 */
	public function currentWalletIds(int $houseId): array {
		$wallets = [];
		foreach ($this->houseUserMapper->findByHouseId($houseId) as $member) {
			if ($member->getWalletId() !== null) {
				$wallets[(int)$member->getWalletId()] = true;
			}
		}
		return $wallets;
	}

	/**
	 * Batched suspension derivation over selection entries (write-free).
	 * Loads the recognized pauses, the current wallets and the occurrence
	 * member snapshots once, then stamps each occurrence IN MEMORY with
	 * its derived suspension state/provenance (materialized and virtual
	 * rows converge — a stopped cron never changes the read answer) and
	 * returns the derived WORK APPOINTMENT per occurrence public key:
	 * the first active participant's agreed work date/zone, else the
	 * planned day in the occurrence's own saved zone (plan §1: real work
	 * appointments in the DTO/tokens). The appointment is a DERIVATION —
	 * it never lands on the entity's persisted fields.
	 *
	 * @param list<array{occurrence: Occurrence, revision: mixed}> $entries
	 * @return array<string, array{date: ?string, zone: string}> publicKey => appointment
	 */
	public function stampSuspensionOnEntries(int $houseId, array $entries): array {
		$appointments = [];
		if ($entries === []) {
			return $appointments;
		}
		$recognized = $this->recognizedForHouse($houseId);
		$currentWallets = $this->currentWalletIds($houseId);

		// RF-073-WP2-20: appointments derive from the member snapshots
		// INDEPENDENTLY of pause presence — a persisted catch-up work
		// appointment must reach the execution DTOs/tokens even when the
		// house has no recognized pause at all. The member load and the
		// stamping loop therefore always run; without recognized pauses
		// the derivation simply stamps SUSPENSION_NONE.
		$ids = [];
		foreach ($entries as $entry) {
			if ($entry['occurrence']->getId() !== null) {
				$ids[] = (int)$entry['occurrence']->getId();
			}
		}
		$membersByOccurrence = $this->occMemberMapper->findByOccurrenceIds($ids);

		foreach ($entries as $entry) {
			$occurrence = $entry['occurrence'];
			// RF-073-WP2-26: participant facts resolve from the CONCRETE
			// member rows for a materialized occurrence (never the current
			// definition defaults — a concrete handover/release is
			// authoritative) and from the associated saved revision's
			// default snapshot for a genuinely virtual one, so both twins
			// feed the identical suspension decision.
			if ($occurrence->getId() !== null) {
				$memberRows = $membersByOccurrence[(int)$occurrence->getId()] ?? [];
				$members = array_map(
					static fn (OccurrenceMember $member): array => $member->jsonSerialize(),
					$memberRows
				);
			} else {
				$memberRows = [];
				$revision = $entry['revision'] ?? null;
				$members = $revision instanceof ScheduleRevision && is_array($revision->getDefaultMembers())
					? $revision->getDefaultMembers()
					: [];
			}
			$this->stampSuspension($occurrence, $recognized, $members, $currentWallets);
			$appointments[$occurrence->toPublicKey()] = self::deriveAppointment($occurrence, $memberRows);
		}
		return $appointments;
	}

	/**
	 * The planned-day appointment of an occurrence in its own saved zone.
	 *
	 * @return array{date: ?string, zone: string}
	 */
	public static function defaultAppointment(Occurrence $occurrence): array {
		return [
			'date' => $occurrence->getPlannedDate()?->format('Y-m-d'),
			'zone' => $occurrence->getTimezone(),
		];
	}

	/**
	 * The agreed work appointment: the first ACTIVE participant's agreed
	 * work date/zone, else the planned day/occurrence zone.
	 *
	 * @param OccurrenceMember[] $members
	 * @return array{date: ?string, zone: string}
	 */
	public static function deriveAppointment(Occurrence $occurrence, array $members): array {
		foreach ($members as $member) {
			if ($member->getDisposition() === OccurrenceMember::DISPOSITION_ACTIVE && $member->getWorkDate() !== null) {
				return [
					'date' => $member->getWorkDate()->format('Y-m-d'),
					'zone' => $member->getWorkZone() ?? $occurrence->getTimezone(),
				];
			}
		}
		return self::defaultAppointment($occurrence);
	}

	/**
	 * Stamp one occurrence in memory with its derived neutral-suspension
	 * facts. Virtual twins (id null) derive from the caller-supplied
	 * member snapshot.
	 *
	 * @param array $members OccurrenceMember::jsonSerialize() shapes
	 * @param array<int, bool> $currentWallets
	 */
	public function stampSuspension(Occurrence $occurrence, array $recognized, array $members, array $currentWallets): void {
		$plannedKey = $occurrence->getPlannedDate()?->format('Y-m-d');
		if ($plannedKey === null || $occurrence->getKind() === Occurrence::KIND_VOLUNTARY) {
			$occurrence->setSuspensionState(Occurrence::SUSPENSION_NONE);
			$occurrence->setSuspensionEvidence(null);
			return;
		}
		// R3/RF-073-WP2-25: an ordinary recognition excuses this
		// occurrence only when its decision predates the ORIGINAL
		// exclusive deadline; the explicit retrospective-illness marker is
		// the sole exception.
		$excused = self::excusedWalletsOn($recognized, $plannedKey, $currentWallets, $occurrence->getWindowEnd());
		$decision = self::deriveSuspension($members, $excused);
		if ($decision['suspended']) {
			$occurrence->setSuspensionState(Occurrence::SUSPENSION_SUSPENDED);
			$occurrence->setSuspensionEvidence(array_values($decision['evidence']));
		} else {
			$occurrence->setSuspensionState(Occurrence::SUSPENSION_NONE);
			$occurrence->setSuspensionEvidence(null);
		}
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
	 * them. Pending/rejected revisions never govern (an undecided child
	 * shortening leaves acknowledged coverage unchanged).
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
	 * @param Pause[] $recognized Revision history (acknowledged revisions are selected internally)
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
			if (!$pause->coversDate($plannedDateKey) || !self::isEffectiveFor($pause, $plannedDateKey, $deadline)) {
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
	 * The per-group revision precedence of R2 (plan §3.4): within one
	 * pause group, the acknowledged revision whose next-day effective
	 * boundary ({@see effectiveBoundaryKey}) is the latest one at or
	 * before the candidate date GOVERNS that date — an acknowledged
	 * shortening actually removes its superseded future coverage, while
	 * dates before the boundary stay governed by the revision that was
	 * authoritative when the decision was made. A date before EVERY
	 * decision boundary (e.g. a mid-range approval) stays governed by the
	 * group's first acknowledged revision, subject to the timeliness gate.
	 * Different groups stay independent — the caller unions their
	 * governed coverage.
	 *
	 * @param Pause[] $revisions
	 * @return Pause[] At most one governing acknowledged revision per pause group
	 */
	public static function governingRevisionsOn(array $revisions, string $dateKey): array {
		$byGroup = [];
		foreach ($revisions as $pause) {
			if (!$pause->isRecognized()) {
				continue;
			}
			$byGroup[$pause->getPauseGroupId()][] = $pause;
		}
		$governing = [];
		foreach ($byGroup as $group) {
			usort($group, static fn (Pause $a, Pause $b): int => $a->getRevision() <=> $b->getRevision());
			$chosen = null;
			foreach ($group as $pause) {
				$boundary = self::effectiveBoundaryKey($pause);
				if ($boundary === null || $boundary <= $dateKey) {
					$chosen = $pause;
				}
			}
			$governing[] = $chosen ?? $group[0];
		}
		return $governing;
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

	/**
	 * The Q-073-01 suspension decision for one occurrence from its member
	 * snapshot and the pause-excused wallets:
	 *
	 * - ANY active OBLIGATED member (responsible/joint) covered by a
	 *   recognized pause → the whole occurrence is neutrally suspended
	 *   until an explicit feasible agreement resumes it (plan §3.4 row 7,
	 *   RF-073-WP2-24): agreed joint work cannot proceed while one
	 *   obligated partner is paused, there is NO automatic redistribution
	 *   of the paused share, and the suspended occurrence charges zero
	 *   live planned capacity while its share snapshot is retained;
	 * - the evidence lists the covering pause revisions (overlapping
	 *   pauses compose as a union — all provenance retained);
	 * - a HELPER covered by a pause → the helper's optional commitment is
	 *   released; the responsible person's obligation and every remaining
	 *   share stay unchanged (Q-073-01 resolved option) — a helper-only
	 *   pause never suspends the occurrence;
	 * - helpers alone never make an occurrence (non-)suspended.
	 *
	 * RF-073-WP2-03: the evidence is the frozen UNION of every covering
	 * pause provenance of every excused member — overlapping personal and
	 * house pauses all stay visible, none overwrites another.
	 *
	 * @param array $members OccurrenceMember::jsonSerialize() shapes
	 * @param array<int, list<array{pauseGroupId: int, revision: int, reasonCode: string}>> $excused
	 * @return array{suspended: bool, evidence: array<int, array{pauseGroupId: int, revision: int}>, excusedWalletIds: list<int>, releasedHelperWalletIds: list<int>}
	 */
	public static function deriveSuspension(array $members, array $excused): array {
		$evidence = [];
		$excusedWalletIds = [];
		$releasedHelpers = [];
		$obligatedExcused = 0;

		foreach ($members as $member) {
			if (!is_array($member) || !isset($member['walletId'])) {
				continue;
			}
			$walletId = (int)$member['walletId'];
			$role = (string)($member['role'] ?? OccurrenceMember::ROLE_RESPONSIBLE);
			$obligatedFlag = (bool)($member['obligated'] ?? true);
			// Released/excused historical rows are provenance, not live
			// duty — they never hold an occurrence open or suspended.
			$disposition = (string)($member['disposition'] ?? OccurrenceMember::DISPOSITION_ACTIVE);
			if ($disposition !== OccurrenceMember::DISPOSITION_ACTIVE) {
				continue;
			}
			if (!isset($excused[$walletId])) {
				continue;
			}
			$excusedWalletIds[] = $walletId;
			foreach ($excused[$walletId] as $covering) {
				$provenance = ['pauseGroupId' => $covering['pauseGroupId'], 'revision' => $covering['revision']];
				$evidence[$provenance['pauseGroupId'] . ':' . $provenance['revision']] = $provenance;
			}
			if ($role === OccurrenceMember::ROLE_HELPER) {
				$releasedHelpers[] = $walletId;
				continue;
			}
			if ($obligatedFlag) {
				$obligatedExcused++;
			}
		}

		return [
			'suspended' => $obligatedExcused > 0,
			'evidence' => array_values($evidence),
			'excusedWalletIds' => array_values(array_unique($excusedWalletIds)),
			'releasedHelperWalletIds' => array_values(array_unique($releasedHelpers)),
		];
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

	/**
	 * Whether a saved work appointment (`D_w` in zone `Z_w`) counts
	 * against a candidate day `(D, Z)`: the two half-open day intervals
	 * intersect (Q-073-02 rule 2).
	 */
	public static function appointmentIntersects(string $appointmentDateKey, string $appointmentZone, string $candidateDateKey, string $candidateZone): bool {
		[$aStart, $aEnd] = self::candidateDayInterval($appointmentDateKey, $appointmentZone);
		[$cStart, $cEnd] = self::candidateDayInterval($candidateDateKey, $candidateZone);
		return $aStart < $cEnd && $cStart < $aEnd;
	}
}
