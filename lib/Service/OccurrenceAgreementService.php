<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\Agreement;
use OCA\ChoreStrike\Db\AgreementMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\OccurrenceMember;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\ScheduleRevisionMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Exception\ConflictException;
use OCP\IL10N;

/**
 * Agreement writer of the occurrence timeline (CS-PLAN-072 WP1;
 * Q-072-01 Option A — the minimal common seam; RF-072-WP1-18 split of
 * the former OccurrenceService monolith).
 *
 * Records agreed date/window amendments (C2c) and participant
 * amendments (C2b/C2a handover) on the earliest unfulfilled occurrence
 * / the revision's default snapshot, with one agreement evidence row
 * per affected occurrence, and owns the ONE shared C2h whole-day
 * child-capacity counter (RF-072-WP1-11). No second writer exists at
 * this boundary: direct due-date/assignee writes and planner apply all
 * route through here.
 */
class OccurrenceAgreementService {
	private ScheduleRevisionMapper $revisionMapper;
	private OccurrenceMapper $occurrenceMapper;
	private OccurrenceMemberMapper $occMemberMapper;
	private AgreementMapper $agreementMapper;
	private RoomTaskMapper $roomTaskMapper;
	private HouseUserMapper $houseUserMapper;
	private IL10N $l10n;
	private OccurrenceRowFactory $rowFactory;
	private OccurrenceReconcileService $reconcile;
	private OccurrenceSelectionService $selection;
	private OccurrenceRevisionService $revisions;

	public function __construct(
		ScheduleRevisionMapper $revisionMapper,
		OccurrenceMapper $occurrenceMapper,
		OccurrenceMemberMapper $occMemberMapper,
		AgreementMapper $agreementMapper,
		RoomTaskMapper $roomTaskMapper,
		HouseUserMapper $houseUserMapper,
		IL10N $l10n,
		OccurrenceRowFactory $rowFactory,
		OccurrenceReconcileService $reconcile,
		OccurrenceSelectionService $selection,
		OccurrenceRevisionService $revisions,
	) {
		$this->revisionMapper = $revisionMapper;
		$this->occurrenceMapper = $occurrenceMapper;
		$this->occMemberMapper = $occMemberMapper;
		$this->agreementMapper = $agreementMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->l10n = $l10n;
		$this->rowFactory = $rowFactory;
		$this->reconcile = $reconcile;
		$this->selection = $selection;
		$this->revisions = $revisions;
	}

	/**
	 * Record an agreed date/window amendment (C2c) UNDER LOCK on the
	 * earliest unfulfilled occurrence (open window or future agreement —
	 * the planner and manual moves address the NEXT obligation): only
	 * before its old deadline, never a fulfilled one; identity is
	 * retained, the saved zone resolves the new boundaries, the agreement
	 * evidence row records old/new state.
	 */
	public function amendOccurrenceDateUnderLock(
		RoomTask $roomTask,
		?int $actorHouseUserId,
		?array $affectedWalletIds,
		string $newDateKey,
		\DateTime $now,
	): Occurrence {
		$target = null;
		foreach ($this->selection->agreedOccurrencesForTask($roomTask, null, null) as $entry) {
			$occurrence = $entry['occurrence'];
			if ($occurrence->getCompletionId() !== null || $occurrence->getCoveredByCompletionId() !== null) {
				continue;
			}
			if ($occurrence->getKind() === Occurrence::KIND_VOLUNTARY) {
				continue;
			}
			if ($target === null
				|| $occurrence->getWindowEnd() < $target['occurrence']->getWindowEnd()) {
				$target = $entry;
			}
		}
		if ($target === null) {
			throw new ConflictException($this->l10n->t('No unfulfilled occurrence can be amended'));
		}

		$occurrence = $target['occurrence'];
		$revision = $target['revision'];
		if ($now >= $occurrence->getWindowEnd()) {
			throw new ConflictException($this->l10n->t('The amendment deadline has passed'));
		}
		if ($occurrence->getId() === null) {
			// Virtual target: materialize through the same resolver before
			// mutating (no domain write on virtual state).
			throw new ConflictException($this->l10n->t('The task changed since it was displayed. Reload and try again.'));
		}

		$old = [
			'plannedDate' => $occurrence->getPlannedDate()->format('Y-m-d'),
			'windowStart' => $occurrence->getWindowStart()->format('c'),
			'windowEnd' => $occurrence->getWindowEnd()->format('c'),
		];

		$this->rowFactory->applyWindow($occurrence, $revision, $newDateKey);
		$occurrence->setVersion($occurrence->getVersion() + 1);
		$this->occurrenceMapper->update($occurrence);

		$this->agreementMapper->record(
			$occurrence->getId(),
			Agreement::KIND_DATE,
			$actorHouseUserId,
			$affectedWalletIds,
			$old,
			[
				'plannedDate' => $occurrence->getPlannedDate()->format('Y-m-d'),
				'windowStart' => $occurrence->getWindowStart()->format('c'),
				'windowEnd' => $occurrence->getWindowEnd()->format('c'),
			]
		);

		$this->reconcile->updateNextDueProjectionUnderLock($roomTask, $now);
		return $occurrence;
	}

	/**
	 * Record an agreed participant amendment (C2b/C2a handover,
	 * Q-072-01 Option A) UNDER LOCK: the new participant set becomes the
	 * revision's default snapshot (close + append — revisions are
	 * immutable), the affected future unfulfilled occurrences get the new
	 * occ_members plus one agreement evidence row each, and the C2h
	 * whole-day child limit is enforced for every added child on the
	 * target day before any write.
	 *
	 * @param string[] $newAssigneeUserIds Canonical new assignee user ids
	 * @return array{revision: \OCA\ChoreStrike\Db\ScheduleRevision, amended: int}
	 */
	public function amendParticipantsUnderLock(
		int $houseId,
		RoomTask $roomTask,
		Task $task,
		House $house,
		?int $actorHouseUserId,
		array $newAssigneeUserIds,
		\DateTime $now,
	): array {
		$participants = $this->revisions->buildDefaultMembers($houseId, $newAssigneeUserIds);

		// C2h whole-day child limit: every ADDED child must keep room under
		// its maxDailyTasks cap on each affected planned day (unlimited
		// children never gate here).
		$this->assertChildCapacityForParticipants($houseId, $roomTask, $house, $newAssigneeUserIds, $now);

		$revision = $this->revisions->reviseUnderLock($houseId, $roomTask, $task, $house->getTimezone(), $participants, $now);

		// Apply the new member set to every unfulfilled, uncovered
		// occurrence whose window has not ended (the pending and future
		// agreements), recording one agreement row per occurrence.
		$amended = 0;
		$walletIds = array_map(static fn (array $member): int => (int)$member['walletId'], $participants ?? []);
		foreach ($this->occurrenceMapper->findByRoomTask($roomTask->getId()) as $occurrence) {
			if ($occurrence->getCompletionId() !== null || $occurrence->getCoveredByCompletionId() !== null) {
				continue;
			}
			if ($occurrence->getWindowEnd() <= $now) {
				continue;
			}
			$oldMembers = array_map(
				static fn (OccurrenceMember $member): array => [
					'walletId' => $member->getWalletId(),
					'role' => $member->getRole(),
					'obligated' => $member->getObligated(),
					'sharePct' => $member->getSharePct(),
				],
				$this->occMemberMapper->findByOccurrence($occurrence->getId())
			);
			$this->occMemberMapper->replaceForOccurrence($occurrence->getId(), $participants ?? []);
			$occurrence->setVersion($occurrence->getVersion() + 1);
			$this->occurrenceMapper->update($occurrence);
			$this->agreementMapper->record(
				$occurrence->getId(),
				Agreement::KIND_SHARE,
				$actorHouseUserId,
				$walletIds,
				['members' => $oldMembers],
				['members' => $participants ?? []]
			);
			$amended++;
		}

		$this->reconcile->updateNextDueProjectionUnderLock($roomTask, $now);
		return ['revision' => $revision, 'amended' => $amended];
	}

	/**
	 * C2h whole-day child capacity check for participant amendments: for
	 * every ADDED child, its agreed occurrence load on each affected
	 * planned day must stay below the child's maxDailyTasks (null =
	 * unlimited). Load = OPEN (unfulfilled, uncovered, open-or-future
	 * window) occurrences of OTHER room tasks planned that day whose
	 * member snapshots include the child's wallet, plus this amendment's
	 * occurrences. RF-072-WP1-11: the ONE shared occurrence-based counter
	 * (open rows of the whole task set from indexed batch queries — no
	 * fulfilled-row overcharge, no N+1).
	 *
	 * @param string[] $newAssigneeUserIds
	 * @throws ConflictException when a child's day limit would be exceeded
	 */
	private function assertChildCapacityForParticipants(int $houseId, RoomTask $roomTask, House $house, array $newAssigneeUserIds, \DateTime $now): void {
		// "Added" children are those not carrying the task's CURRENT
		// default participant snapshot (the agreement authority).
		$previousWalletIds = [];
		try {
			$defaultMembers = $this->revisionMapper->findCurrentByRoomTask($roomTask->getId())->getDefaultMembers();
			foreach (is_array($defaultMembers) ? $defaultMembers : [] as $member) {
				if (is_array($member) && isset($member['walletId'])) {
					$previousWalletIds[(int)$member['walletId']] = true;
				}
			}
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			// No live revision — every child counts as added.
		}

		$childChecks = [];
		$membersByUserId = [];
		foreach ($this->houseUserMapper->findByHouseId($houseId) as $candidate) {
			$membersByUserId[$candidate->getNextcloudUserId()] = $candidate;
		}
		foreach ($newAssigneeUserIds as $userId) {
			$member = $membersByUserId[$userId] ?? null;
			if ($member === null) {
				throw new ConflictException($this->l10n->t('Assignees must be house members'));
			}
			$walletId = $member->getWalletId();
			if ($member->getRole() === 'child' && $walletId !== null && !isset($previousWalletIds[(int)$walletId])) {
				$preferences = $member->getSchedulingPreferences();
				$maxDaily = $preferences['maxDailyTasks'] ?? null;
				if (is_int($maxDaily) && $maxDaily > 0) {
					$childChecks[(int)$walletId] = $maxDaily;
				}
			}
		}
		if ($childChecks === []) {
			return;
		}

		// Open occurrence load per wallet/day from the shared batched
		// counter, split into OTHER tasks vs THIS task's still-open future
		// (the amendment applies the new member set to exactly those).
		$allIds = [];
		foreach ($this->roomTaskMapper->findByHouse($houseId, true) as $houseTask) {
			$allIds[] = $houseTask->getId();
		}
		$openByTask = [];
		foreach ($this->occurrenceMapper->findOpenByRoomTasks($allIds) as $open) {
			if ($open->getKind() !== Occurrence::KIND_SCHEDULED || $open->getWindowEnd() <= $now) {
				continue;
			}
			$openByTask[$open->getRoomTaskId()][] = $open;
		}
		$otherOpen = [];
		foreach ($openByTask as $taskId => $openOccurrences) {
			if ($taskId !== $roomTask->getId()) {
				array_push($otherOpen, ...$openOccurrences);
			}
		}
		$membersByOccurrence = $this->occMemberMapper->findByOccurrenceIds(array_map(
			static fn (Occurrence $open): int => (int)$open->getId(),
			$otherOpen
		));

		$loadByWalletAndDate = [];
		$thisTaskPerDate = [];
		foreach ($openByTask as $taskId => $openOccurrences) {
			foreach ($openOccurrences as $open) {
				$dateKey = $open->getPlannedDate()->format('Y-m-d');
				if ($taskId === $roomTask->getId()) {
					$thisTaskPerDate[$dateKey] = ($thisTaskPerDate[$dateKey] ?? 0) + 1;
					continue;
				}
				foreach ($membersByOccurrence[(int)$open->getId()] ?? [] as $member) {
					$loadByWalletAndDate[$member->getWalletId()][$dateKey]
						= ($loadByWalletAndDate[$member->getWalletId()][$dateKey] ?? 0) + 1;
				}
			}
		}

		foreach ($childChecks as $walletId => $maxDaily) {
			$dates = array_unique(array_merge(array_keys($thisTaskPerDate), array_keys($loadByWalletAndDate[$walletId] ?? [])));
			foreach ($dates as $dateKey) {
				$load = ($loadByWalletAndDate[$walletId][$dateKey] ?? 0) + ($thisTaskPerDate[$dateKey] ?? 0);
				if ($load > $maxDaily) {
					throw new ConflictException($this->l10n->t('Assigning this member would exceed their daily task limit'));
				}
			}
		}
	}

	/**
	 * The ONE shared C2h whole-day child-capacity counter
	 * (RF-072-WP1-11): OPEN (unfulfilled, uncovered, open-or-future
	 * window) scheduled occurrences per wallet and planned local day,
	 * counted from indexed batch queries over the given room-task set.
	 *
	 * @param int[] $roomTaskIds
	 * @return array<int, array<string, int>> walletId => planned-date key => occurrence count
	 */
	public function openOccurrenceDayLoadByWallet(array $roomTaskIds, \DateTime $now): array {
		$open = array_values(array_filter(
			$this->occurrenceMapper->findOpenByRoomTasks($roomTaskIds),
			static fn (Occurrence $row): bool => $row->getWindowEnd() > $now
		));
		$membersByOccurrence = $this->occMemberMapper->findByOccurrenceIds(array_map(
			static fn (Occurrence $row): int => (int)$row->getId(),
			$open
		));

		$load = [];
		foreach ($open as $row) {
			if ($row->getKind() !== Occurrence::KIND_SCHEDULED) {
				continue;
			}
			$dateKey = $row->getPlannedDate()->format('Y-m-d');
			foreach ($membersByOccurrence[(int)$row->getId()] ?? [] as $member) {
				$load[$member->getWalletId()][$dateKey] = ($load[$member->getWalletId()][$dateKey] ?? 0) + 1;
			}
		}
		return $load;
	}
}
