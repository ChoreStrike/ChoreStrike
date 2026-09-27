<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\OccurrenceMember;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\ScheduleRevision;
use OCA\ChoreStrike\Db\ScheduleRevisionMapper;
use OCA\ChoreStrike\Db\WorkShareMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCP\IL10N;

/**
 * The ONE reusable whole-day capacity evaluator (CS-PLAN-073 WP2 §3.2).
 *
 * For each person/work day, capacity derives from the actual valid work
 * (frozen completion effort) plus the outstanding active commitments
 * (agreed occurrence shares, including concretely agreed helper
 * commitments and tasks outside any planner selection). Occurrence
 * identity deduplicates: a fulfilled obligation is never counted as both
 * planned and completed; each distinct occurrence counts once toward a
 * person's task limit.
 *
 * Energy is integer hundredths (`E * P` minor units); remaining and
 * overload are exact, never truncated. All comparisons follow the frozen
 * Q-073-02 saved-zone mapping: completed instants are tested as instants
 * against the candidate day's half-open UTC interval, saved work
 * appointments count when their own day intervals intersect the candidate
 * day, and every fact keeps its historical labels.
 *
 * Consumers: the planner preview/apply (SchedulePlanService), the
 * agreement validation (AgreementService) and the offer filtering. The
 * evaluator is a lower read service — no command service dependency, no
 * writes of its own.
 */
class DailyCapacityService {
	/** Energy hundredths per whole unit (exact shares, plan §3.1). */
	public const ENERGY_MINOR = 100;

	/** Maximum positive whole-percent participants of one executable plan. */
	public const MAX_PARTICIPANTS = 100;

	private HouseMapper $houseMapper;
	private RoomTaskMapper $roomTaskMapper;
	private ScheduleRevisionMapper $revisionMapper;
	private OccurrenceMapper $occurrenceMapper;
	private OccurrenceMemberMapper $occMemberMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private WorkShareMapper $workShareMapper;
	private HouseUserMapper $houseUserMapper;
	private PauseProjectionService $pauseProjection;
	private RecurrenceCalculator $calculator;
	private CalendarService $calendar;
	private IL10N $l10n;

	public function __construct(
		HouseMapper $houseMapper,
		RoomTaskMapper $roomTaskMapper,
		ScheduleRevisionMapper $revisionMapper,
		OccurrenceMapper $occurrenceMapper,
		OccurrenceMemberMapper $occMemberMapper,
		CompletedTaskMapper $completedTaskMapper,
		WorkShareMapper $workShareMapper,
		HouseUserMapper $houseUserMapper,
		PauseProjectionService $pauseProjection,
		RecurrenceCalculator $calculator,
		CalendarService $calendar,
		IL10N $l10n,
	) {
		$this->houseMapper = $houseMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->revisionMapper = $revisionMapper;
		$this->occurrenceMapper = $occurrenceMapper;
		$this->occMemberMapper = $occMemberMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->workShareMapper = $workShareMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->pauseProjection = $pauseProjection;
		$this->calculator = $calculator;
		$this->calendar = $calendar;
		$this->l10n = $l10n;
	}

	/**
	 * Scoped shared-collection pass (RF-073-WP2-16): inside the callback,
	 * `evaluateDays`/`plannedBaseline` reuse ONE batched collection per
	 * (house, day-set) instead of re-running the full pass per call — the
	 * planner preview evaluates the same window twice. The cache exists
	 * ONLY for the duration of the callback and is never carried across
	 * passes, so no writer boundary can ever read a stale collection.
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	public function withSharedCollection(callable $fn) {
		$previous = $this->sharedCollections;
		$this->sharedCollections = [];
		try {
			return $fn();
		} finally {
			$this->sharedCollections = $previous;
		}
	}

	/** @var array<string, array>|null Non-null only inside withSharedCollection(). */
	private ?array $sharedCollections = null;

	/**
	 * @param string[] $dayKeys
	 * @return array{0: array<string, array<string, int>>, 1: array<string, array<string, int>>, 2: array<string, array<string, int>>, 3: array<string, array<string, int>>, 4: array<int, array<string, bool>>, 5: list<HouseUser>, 6: array<int, array<string, array<string, int>>>}
	 */
	private function collectScoped(int $houseId, array $dayKeys): array {
		if ($this->sharedCollections === null) {
			$house = $this->houseMapper->find($houseId);
			return $this->collect($house, $this->calendar->getTimeZone($house), $dayKeys);
		}
		$keys = $dayKeys;
		sort($keys);
		$memoKey = $houseId . '|' . implode(',', $keys);
		if (!isset($this->sharedCollections[$memoKey])) {
			$house = $this->houseMapper->find($houseId);
			// Collect over the SORTED set: the completion-window bounds
			// derive from the first/last day key, so the shared entry must
			// not depend on the caller's iteration order.
			$this->sharedCollections[$memoKey] = $this->collect($house, $this->calendar->getTimeZone($house), $keys);
		}
		return $this->sharedCollections[$memoKey];
	}

	/**
	 * Evaluate every member for the given house-local candidate days.
	 *
	 * @param string[] $dayKeys Sorted house-local Y-m-d candidate days
	 * @return array<string, array<string, array<string, mixed>>> dayKey => userId => member-day evaluation
	 */
	public function evaluateDays(int $houseId, array $dayKeys): array {
		if ($dayKeys === []) {
			return [];
		}
		[$completedMinor, $completedCounts, $plannedMinor, $plannedCounts, $pausedByWalletDay, $members]
			= $this->collectScoped($houseId, $dayKeys);
		$report = [];
		foreach ($dayKeys as $dayKey) {
			$perMember = [];
			foreach ($members as $member) {
				$userId = $member->getNextcloudUserId();
				$completed = $completedMinor[$userId][$dayKey] ?? 0;
				$planned = $plannedMinor[$userId][$dayKey] ?? 0;
				$budget = $this->energyBudgetFor($member, $dayKey);
				$budgetMinor = $budget * self::ENERGY_MINOR;
				$preferences = $member->getSchedulingPreferences() ?? [];
				$maxDaily = $preferences['maxDailyTasks'] ?? null;
				$maxDaily = is_int($maxDaily) && $maxDaily > 0 ? $maxDaily : null;
				$taskCount = ($plannedCounts[$userId][$dayKey] ?? 0) + ($completedCounts[$userId][$dayKey] ?? 0);
				$walletId = $member->getWalletId();
				$paused = $walletId !== null && ($pausedByWalletDay[(int)$walletId][$dayKey] ?? false);
				$available = $this->isMemberAvailableOn($member, $dayKey);

				$reasons = [];
				if ($paused) {
					$reasons[] = 'PAUSED';
				}
				if (!$available) {
					$reasons[] = 'NOT_AVAILABLE';
				}
				if ($completed + $planned > $budgetMinor) {
					$reasons[] = 'OVER_CAPACITY';
				}
				if ($maxDaily !== null && $taskCount >= $maxDaily) {
					$reasons[] = 'CHILD_LIMIT';
				}

				$perMember[$userId] = [
					'houseUserId' => (int)$member->getId(),
					'userId' => $userId,
					'walletId' => $walletId !== null ? (int)$walletId : null,
					'role' => (string)$member->getRole(),
					'available' => $available,
					'paused' => $paused,
					'budget' => $budget,
					'budgetMinor' => $budgetMinor,
					'completedEnergyMinor' => $completed,
					'plannedEnergyMinor' => $planned,
					'remainingEnergyMinor' => max(0, $budgetMinor - $completed - $planned),
					'taskCount' => $taskCount,
					'maxDailyTasks' => $maxDaily,
					'overloaded' => $completed + $planned > $budgetMinor,
					'reasons' => $reasons,
				];
			}
			$report[$dayKey] = $perMember;
		}
		return $report;
	}

	/**
	 * Baseline capacity maps for the planner's mutable proposal loop
	 * (minor units): userId => dayKey => minor / distinct-occurrence
	 * count. Completed effort enters the planner's remaining computation
	 * through the evaluation report; the proposal loop itself only
	 * mutates planned state. The per-task attribution lets a replacement
	 * plan REMOVE exactly the old allocation before testing the new one
	 * (plan §3.2).
	 *
	 * @param string[] $dayKeys
	 * @return array{0: array<string, array<string, int>>, 1: array<string, array<string, int>>, 2: array<int, array<string, array<string, int>>>}
	 */
	public function plannedBaseline(int $houseId, array $dayKeys): array {
		if ($dayKeys === []) {
			return [[], [], []];
		}
		[, , $plannedMinor, $counts, , , $byTask] = $this->collectScoped($houseId, $dayKeys);
		return [$plannedMinor, $counts, $byTask];
	}

	/**
	 * Validate that every participant's NEW commitment fits its whole day
	 * (plan §3.2: availability, pause and energy budget plus the
	 * owner-set child limits — never a placement based on only one member
	 * of a shared plan). Callers run this under the house lock inside the
	 * owning transaction. A commitment may carry whole `energy` or exact
	 * `energyMinor` (integer hundredths, e.g. the member's agreed share).
	 *
	 * RF-073-WP2-21: `$replacedOccurrence` is the occurrence whose member
	 * arrangement/appointment the command REPLACES — its current per-member
	 * allocation on the candidate day is removed from the evaluated load
	 * BEFORE the new commitments are tested, so a same-day replacement
	 * (handover, share change, catch-up re-appointment) is never tested
	 * against its own existing allocation.
	 *
	 * @param string $dayKey Candidate work day (house-local label)
	 * @param list<array{userId: string, energy?: int, energyMinor?: int, count?: int}> $commitments
	 * @throws ConflictException when any participant cannot fit
	 */
	public function assertCommitmentsFit(int $houseId, string $dayKey, array $commitments, ?Occurrence $replacedOccurrence = null): void {
		$evaluations = $this->evaluateDays($houseId, [$dayKey])[$dayKey] ?? [];
		$released = $this->replacedOccurrenceLoad($houseId, $dayKey, $replacedOccurrence);
		foreach ($commitments as $commitment) {
			$userId = (string)($commitment['userId'] ?? '');
			$evaluation = $evaluations[$userId] ?? null;
			if ($evaluation === null) {
				throw new ConflictException($this->l10n->t('Assignees must be house members'));
			}
			$energyMinor = isset($commitment['energyMinor'])
				? max(1, (int)$commitment['energyMinor'])
				: max(1, (int)($commitment['energy'] ?? 1)) * self::ENERGY_MINOR;
			$count = max(1, (int)($commitment['count'] ?? 1));
			if ($evaluation['paused']) {
				throw new ConflictException($this->l10n->t('This member is paused on that day'));
			}
			if (!$evaluation['available']) {
				throw new ConflictException($this->l10n->t('This member is not available on that day'));
			}
			$plannedMinor = max(0, $evaluation['plannedEnergyMinor'] - ($released[$userId]['minor'] ?? 0));
			$taskCount = max(0, $evaluation['taskCount'] - ($released[$userId]['count'] ?? 0));
			if ($evaluation['budgetMinor'] < $evaluation['completedEnergyMinor'] + $plannedMinor + $energyMinor) {
				throw new ConflictException($this->l10n->t('Assigning this member would exceed their daily energy budget'));
			}
			$maxDaily = $evaluation['maxDailyTasks'];
			if ($maxDaily !== null && $maxDaily < $taskCount + $count) {
				throw new ConflictException($this->l10n->t('Assigning this member would exceed their daily task limit'));
			}
		}
	}

	/**
	 * The per-member allocation the evaluated baseline currently charges
	 * for ONE occurrence on the candidate day (the amount a replacement
	 * command removes before its check). Mirrors the collection rules
	 * exactly: a suspended occurrence consumes nothing (no removal), only
	 * ACTIVE non-excused member shares on intersecting saved-zone work days
	 * count, occurrence-identity deduplicated by definition (one row set).
	 *
	 * @return array<string, array{minor: int, count: int}> userId => released load
	 */
	private function replacedOccurrenceLoad(int $houseId, string $dayKey, ?Occurrence $occurrence): array {
		if ($occurrence === null || $occurrence->getId() === null) {
			return [];
		}
		if ($occurrence->getSuspensionState() === Occurrence::SUSPENSION_SUSPENDED) {
			return [];
		}
		$house = $this->houseMapper->find($houseId);
		$houseZone = $this->calendar->getTimeZone($house)->getName();
		$userIdByWallet = [];
		foreach ($this->houseUserMapper->findByHouseId($houseId) as $member) {
			if ($member->getWalletId() !== null) {
				$userIdByWallet[(int)$member->getWalletId()] = $member->getNextcloudUserId();
			}
		}

		$members = $this->occMemberMapper->findByOccurrence((int)$occurrence->getId());
		$memberFacts = array_map(
			static fn (OccurrenceMember $member): array => $member->jsonSerialize(),
			$members
		);
		// A paused (excused) member's share is NOT in the baseline — it
		// must not be subtracted either.
		$plannedKey = $occurrence->getPlannedDate()?->format('Y-m-d');
		$excused = $plannedKey !== null
			? PauseProjectionService::excusedWalletsOn(
				$this->pauseProjection->recognizedForHouse($houseId),
				$plannedKey,
				$this->pauseProjection->currentWalletIds($houseId),
				$occurrence->getWindowEnd()
			)
			: [];
		$decision = PauseProjectionService::deriveSuspension($memberFacts, $excused);
		if ($decision['suspended']) {
			return [];
		}
		$excusedSet = array_fill_keys($decision['excusedWalletIds'], true);

		$load = [];
		foreach ($members as $member) {
			if ($member->getDisposition() !== OccurrenceMember::DISPOSITION_ACTIVE) {
				continue;
			}
			$walletId = (int)$member->getWalletId();
			$userId = $userIdByWallet[$walletId] ?? null;
			if ($userId === null || isset($excusedSet[$walletId])) {
				continue;
			}
			$share = max(0, $member->getSharePct());
			if ($share === 0) {
				continue;
			}
			$workDay = $member->getWorkDate()?->format('Y-m-d') ?? $plannedKey;
			$workZone = $member->getWorkZone() ?? $occurrence->getTimezone();
			if ($workDay === null || $workDay === '' || $workZone === null || $workZone === '') {
				continue;
			}
			if (!PauseProjectionService::appointmentIntersects($workDay, $workZone, $dayKey, $houseZone)) {
				continue;
			}
			$load[$userId]['minor'] = ($load[$userId]['minor'] ?? 0) + ($occurrence->getEnergy() * $share);
			$load[$userId]['count'] = ($load[$userId]['count'] ?? 0) + 1;
		}
		return $load;
	}

	// ------------------------------------------------------------------
	// Internal collection (one batched pass per evaluation)
	// ------------------------------------------------------------------

	/**
	 * @param string[] $dayKeys
	 * @return array{0: array<string, array<string, int>>, 1: array<string, array<string, int>>, 2: array<string, array<string, int>>, 3: array<string, array<string, int>>, 4: array<int, array<string, bool>>, 5: list<HouseUser>, 6: array<int, array<string, array<string, int>>>}
	 */
	private function collect(House $house, \DateTimeZone $tz, array $dayKeys): array {
		$houseId = (int)$house->getId();
		$houseZone = $tz->getName();

		$members = $this->houseUserMapper->findByHouseId($houseId);
		$membersByUserId = [];
		$userIdByHouseUser = [];
		$userIdByWallet = [];
		foreach ($members as $member) {
			$membersByUserId[$member->getNextcloudUserId()] = $member;
			$userIdByHouseUser[(int)$member->getId()] = $member->getNextcloudUserId();
			if ($member->getWalletId() !== null) {
				$userIdByWallet[(int)$member->getWalletId()] = $member->getNextcloudUserId();
			}
		}

		// Completed effort (WP3 §3.2 capacity seam): each participant's
		// SELECTED frozen work share of every valid completion, instants
		// tested against candidate intervals (Q-073-02 rule 1: each instant
		// falls in exactly one candidate day per zone). Early work consumes
		// its actual work day, not the future planned day. A fulfilled
		// occurrence is counted once per actual participant — never as
		// outstanding planned capacity for an absent assignee.
		$completedMinor = [];
		$completedCounts = [];
		$fromBound = PauseProjectionService::candidateDayInterval($dayKeys[0], $houseZone)[0];
		$toBound = PauseProjectionService::candidateDayInterval($dayKeys[count($dayKeys) - 1], $houseZone)[1];
		$seenCompletionByUserDay = [];
		foreach ($this->workShareMapper->findSelectedSharesBetweenByHouse($houseId, $fromBound, $toBound) as $row) {
			$userId = $row['walletId'] !== null ? ($userIdByWallet[$row['walletId']] ?? null) : null;
			if ($userId === null) {
				continue;
			}
			foreach ($dayKeys as $dayKey) {
				[$start, $end] = PauseProjectionService::candidateDayInterval($dayKey, $houseZone);
				if ($row['completedAt'] >= $start && $row['completedAt'] < $end) {
					$minor = $row['energyMinor'];
					$completedMinor[$userId][$dayKey] = ($completedMinor[$userId][$dayKey] ?? 0) + $minor;
					// Count each participated occurrence once per person.
					$seenKey = $userId . '|' . $dayKey . '|' . $row['completionId'];
					if (!isset($seenCompletionByUserDay[$seenKey])) {
						$seenCompletionByUserDay[$seenKey] = true;
						$completedCounts[$userId][$dayKey] = ($completedCounts[$userId][$dayKey] ?? 0) + 1;
					}
					break;
				}
			}
		}

		// Recognized pause coverage per candidate day (union over the
		// acknowledged revisions; personal effects stop at departure).
		$recognized = $this->pauseProjection->recognizedForHouse($houseId);
		$currentWallets = $this->pauseProjection->currentWalletIds($houseId);
		$pausedByWalletDay = [];
		$excusedByDay = [];
		foreach ($dayKeys as $dayKey) {
			$excused = PauseProjectionService::excusedWalletsOn($recognized, $dayKey, $currentWallets);
			$excusedByDay[$dayKey] = $excused;
			foreach (array_keys($excused) as $walletId) {
				$pausedByWalletDay[$walletId][$dayKey] = true;
			}
		}

		// Outstanding commitments: open unsuspended obligations with their
		// agreed shares — including tasks outside any planner selection.
		$plannedMinor = [];
		$counts = [];
		$byTask = [];

		$open = $this->occurrenceMapper->findOpenByHouseId($houseId);
		$membersByOccurrence = $this->occMemberMapper->findByOccurrenceIds(array_map(
			static fn (Occurrence $row): int => (int)$row->getId(),
			$open
		));
		$coveredByTask = [];
		foreach ($open as $occurrence) {
			$plannedKey = $occurrence->getPlannedDate()?->format('Y-m-d');
			$coveredByTask[$occurrence->getRoomTaskId()][$plannedKey] = true;
			$occZone = $occurrence->getTimezone();
			$memberFacts = array_map(
				static fn (OccurrenceMember $member): array => $member->jsonSerialize(),
				$membersByOccurrence[(int)$occurrence->getId()] ?? []
			);
			$excused = $plannedKey !== null ? ($excusedByDay[$plannedKey] ?? []) : [];
			$decision = PauseProjectionService::deriveSuspension($memberFacts, $excused);
			if ($decision['suspended']) {
				// A suspended plan retains its snapshot but consumes no
				// live planned capacity (plan §3.1).
				continue;
			}
			$excusedSet = array_fill_keys($decision['excusedWalletIds'], true);
			foreach ($memberFacts as $member) {
				if (($member['disposition'] ?? OccurrenceMember::DISPOSITION_ACTIVE) !== OccurrenceMember::DISPOSITION_ACTIVE) {
					continue;
				}
				$walletId = (int)($member['walletId'] ?? 0);
				$userId = $userIdByWallet[$walletId] ?? null;
				if ($userId === null || isset($excusedSet[$walletId])) {
					continue;
				}
				$share = max(0, (int)($member['sharePct'] ?? 100));
				if ($share === 0) {
					continue;
				}
				$workDay = (string)($member['workDate'] ?? $plannedKey);
				$workZone = (string)($member['workZone'] ?? $occZone);
				if ($workDay === '' || $workZone === '') {
					continue;
				}
				$minor = $occurrence->getEnergy() * $share;
				foreach ($dayKeys as $dayKey) {
					if (PauseProjectionService::appointmentIntersects($workDay, $workZone, $dayKey, $houseZone)) {
						$plannedMinor[$userId][$dayKey] = ($plannedMinor[$userId][$dayKey] ?? 0) + $minor;
						$counts[$userId][$dayKey] = ($counts[$userId][$dayKey] ?? 0) + 1;
						$byTask[$occurrence->getRoomTaskId()][$userId][$dayKey]
							= ($byTask[$occurrence->getRoomTaskId()][$userId][$dayKey] ?? 0) + $minor;
					}
				}
			}
		}

		// Frontier projections beyond the materialized open set (the same
		// occurrence enumeration the calendar reads consume; revision
		// default members carry the exact equal shares).
		$currentRevisions = [];
		foreach ($this->revisionMapper->findByHouseId($houseId) as $revision) {
			if ($revision->getClosedAt() === null) {
				$currentRevisions[$revision->getRoomTaskId()] = $revision;
			}
		}
		foreach ($this->roomTaskMapper->findByHouse($houseId, true) as $roomTask) {
			if ($roomTask->getDeletedAt() !== null) {
				continue;
			}
			$revision = $currentRevisions[$roomTask->getId()] ?? null;
			if ($revision === null || $revision->getIsVoluntary()
				|| $revision->getCadenceMode() !== ScheduleRevision::CADENCE_FIXED) {
				continue;
			}
			$defaultMembers = is_array($revision->getDefaultMembers()) ? $revision->getDefaultMembers() : [];
			if ($defaultMembers === []) {
				continue;
			}
			foreach ($this->calculator->enumerate($revision, $dayKeys[0], $dayKeys[count($dayKeys) - 1]) as $entry) {
				$dateKey = $entry['date'];
				if (isset($coveredByTask[$roomTask->getId()][$dateKey])) {
					continue;
				}
				$excused = $excusedByDay[$dateKey] ?? [];
				$decision = PauseProjectionService::deriveSuspension($defaultMembers, $excused);
				if ($decision['suspended']) {
					continue;
				}
				$excusedSet = array_fill_keys($decision['excusedWalletIds'], true);
				$energy = $revision->getEnergy();
				foreach ($defaultMembers as $member) {
					$walletId = (int)($member['walletId'] ?? 0);
					$userId = $userIdByWallet[$walletId] ?? null;
					if ($userId === null || isset($excusedSet[$walletId])) {
						continue;
					}
					$share = max(0, (int)($member['sharePct'] ?? 100));
					if ($share === 0) {
						continue;
					}
					$minor = $energy * $share;
					$plannedMinor[$userId][$dateKey] = ($plannedMinor[$userId][$dateKey] ?? 0) + $minor;
					$counts[$userId][$dateKey] = ($counts[$userId][$dateKey] ?? 0) + 1;
					$byTask[$roomTask->getId()][$userId][$dateKey]
						= ($byTask[$roomTask->getId()][$userId][$dateKey] ?? 0) + $minor;
				}
			}
		}

		return [$completedMinor, $completedCounts, $plannedMinor, $counts, $pausedByWalletDay, $members, $byTask];
	}

	private function isMemberAvailableOn(HouseUser $member, string $dayKey): bool {
		$preferences = $member->getSchedulingPreferences() ?? [];
		$availability = $preferences['weekdayAvailability'] ?? [];
		$day = strtolower(\DateTime::createFromFormat('Y-m-d', $dayKey)->format('l'));
		return $availability[$day] ?? true;
	}

	private function energyBudgetFor(HouseUser $member, string $dayKey): int {
		$preferences = $member->getSchedulingPreferences() ?? [];
		$budget = $preferences['energyBudget'] ?? [];
		$day = strtolower(\DateTime::createFromFormat('Y-m-d', $dayKey)->format('l'));
		if (isset($budget[$day])) {
			return (int)$budget[$day];
		}
		return in_array($day, ['saturday', 'sunday'], true) ? 15 : 10;
	}
}
