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
use OCA\ChoreStrike\Db\ScheduleRevision;
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
	 * evidence row records old/new state. WP2: neutrally suspended
	 * occurrences are no amendment targets — a pause releases the duty
	 * instead of moving it — and the recorded consent rides the evidence
	 * row.
	 */
	public function amendOccurrenceDateUnderLock(
		RoomTask $roomTask,
		?int $actorHouseUserId,
		?array $affectedWalletIds,
		string $newDateKey,
		\DateTime $now,
		?array $consent = null,
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
			if ($occurrence->getSuspensionState() === Occurrence::SUSPENSION_SUSPENDED) {
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
		return $this->applyDateAmendmentUnderLock(
			$target['occurrence'],
			$target['revision'],
			$roomTask,
			$actorHouseUserId,
			$affectedWalletIds,
			$newDateKey,
			$now,
			$consent
		);
	}

	/**
	 * WP2 explicit-target date amendment UNDER LOCK (RF-073-WP2-01): moves
	 * EXACTLY the occurrence the command resolved from the client's public
	 * key — never a re-selected different one — and returns the moved row
	 * with its RESULTING version for the receipt refs. Same invariants as
	 * the self-selecting variant: unfulfilled, not neutrally suspended,
	 * before the old deadline, materialized.
	 */
	public function amendOccurrenceDateForTargetUnderLock(
		Occurrence $occurrence,
		ScheduleRevision $revision,
		RoomTask $roomTask,
		?int $actorHouseUserId,
		?array $affectedWalletIds,
		string $newDateKey,
		\DateTime $now,
		?array $consent = null,
	): Occurrence {
		if ($occurrence->getCompletionId() !== null || $occurrence->getCoveredByCompletionId() !== null) {
			throw new ConflictException($this->l10n->t('This occurrence is already completed'));
		}
		if ($occurrence->getKind() === Occurrence::KIND_VOLUNTARY) {
			throw new ConflictException($this->l10n->t('Voluntary work needs no date amendment'));
		}
		if ($occurrence->getSuspensionState() === Occurrence::SUSPENSION_SUSPENDED) {
			// Plan §3.4: a pause releases the duty instead of moving it.
			throw new ConflictException($this->l10n->t('A paused occurrence cannot be amended'));
		}
		return $this->applyDateAmendmentUnderLock(
			$occurrence,
			$revision,
			$roomTask,
			$actorHouseUserId,
			$affectedWalletIds,
			$newDateKey,
			$now,
			$consent
		);
	}

	/**
	 * The shared date-amendment tail: deadline gate, materialization gate,
	 * window re-derivation in the saved zone, version bump, evidence and
	 * projection refresh. Both the self-selecting (planner/manual next
	 * obligation) and the explicit-target (agreement command) variants
	 * write through here — one writer, one evidence shape.
	 */
	private function applyDateAmendmentUnderLock(
		Occurrence $occurrence,
		ScheduleRevision $revision,
		RoomTask $roomTask,
		?int $actorHouseUserId,
		?array $affectedWalletIds,
		string $newDateKey,
		\DateTime $now,
		?array $consent,
	): Occurrence {
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
			'weekStart' => $occurrence->getWeekStart()->format('Y-m-d'),
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
				'weekStart' => $occurrence->getWeekStart()->format('Y-m-d'),
				'windowStart' => $occurrence->getWindowStart()->format('c'),
				'windowEnd' => $occurrence->getWindowEnd()->format('c'),
			],
			$consent
		);

		$this->reconcile->updateNextDueProjectionUnderLock($roomTask, $now);
		return $occurrence;
	}

	/**
	 * WP2 single-occurrence participant amendment UNDER LOCK (plan §3.3):
	 * handover/share/date-share changes touch THIS occurrence only — the
	 * revision's future defaults stay untouched, so a one-time handover
	 * can never become a silent default change.
	 *
	 * RF-073-WP2-02: a former participant leaving the arrangement is
	 * PERSISTED as a neutral release (disposition `released`, obligation
	 * dropped, former role/share snapshot retained) instead of being
	 * deleted — the current rows stay a projection, the release remains
	 * visible evidence beside the agreement row. RF-073-WP2-18: the
	 * evidence kind is derived from the actual responsibility change —
	 * `handover` only when the active RESPONSIBLE set changes, otherwise
	 * `share`. An agreed catch-up work appointment rides the replaced
	 * rows (RF-073-WP2-04).
	 *
	 * @param list<array{walletId: int, userId?: string, role: string, obligated: bool, sharePct: int}> $participants
	 */
	public function amendOccurrenceMembersUnderLock(
		Occurrence $occurrence,
		RoomTask $roomTask,
		?int $actorHouseUserId,
		array $participants,
		array $consentSubjects,
		\DateTime $now,
	): Occurrence {
		if ($occurrence->getId() === null) {
			throw new ConflictException($this->l10n->t('The task changed since it was displayed. Reload and try again.'));
		}
		if ($occurrence->getCompletionId() !== null || $occurrence->getCoveredByCompletionId() !== null) {
			throw new ConflictException($this->l10n->t('This occurrence is already completed'));
		}

		$oldMemberRows = $this->occMemberMapper->findByOccurrence($occurrence->getId());
		$oldMembers = array_map(
			static fn (OccurrenceMember $member): array => $member->jsonSerialize(),
			$oldMemberRows
		);

		// The occurrence-level agreed work appointment (a persisted
		// catch-up) survives a participant change: continuing and new
		// active rows all carry it.
		[$appointmentDate, $appointmentZone] = $this->occurrenceAppointment($oldMembers);

		$newWallets = [];
		$activeRows = [];
		foreach ($participants as $participant) {
			$newWallets[(int)$participant['walletId']] = true;
			$activeRows[] = [
				'walletId' => (int)$participant['walletId'],
				'role' => (string)$participant['role'],
				'obligated' => (bool)$participant['obligated'],
				'sharePct' => (int)$participant['sharePct'],
				'workDate' => $appointmentDate,
				'workZone' => $appointmentZone,
				'disposition' => OccurrenceMember::DISPOSITION_ACTIVE,
			];
		}

		// Neutral releases: every former ACTIVE participant not in the new
		// arrangement keeps a released row (former role/share snapshot,
		// obligation dropped) — never a silent deletion. Previously
		// released/excused historical rows are preserved as they are; a
		// wallet re-entering the arrangement is covered by its new active
		// row (one row per wallet — the natural key).
		$releasedWalletIds = [];
		$releasedRows = [];
		foreach ($oldMembers as $member) {
			$walletId = (int)$member['walletId'];
			if (isset($newWallets[$walletId])) {
				continue;
			}
			$oldDisposition = (string)($member['disposition'] ?? OccurrenceMember::DISPOSITION_ACTIVE);
			if ($oldDisposition === OccurrenceMember::DISPOSITION_ACTIVE) {
				$releasedWalletIds[] = $walletId;
			}
			$releasedRows[] = [
				'walletId' => $walletId,
				'role' => (string)$member['role'],
				'obligated' => false,
				'sharePct' => (int)$member['sharePct'],
				'workDate' => $member['workDate'] ?? null,
				'workZone' => $member['workZone'] ?? null,
				'disposition' => $oldDisposition === OccurrenceMember::DISPOSITION_ACTIVE
					? OccurrenceMember::DISPOSITION_RELEASED
					: $oldDisposition,
			];
		}

		$this->occMemberMapper->replaceForOccurrence($occurrence->getId(), array_merge($activeRows, $releasedRows));
		$occurrence->setVersion($occurrence->getVersion() + 1);
		$this->occurrenceMapper->update($occurrence);

		$kind = $this->memberAmendmentKind($oldMembers, $activeRows);
		$walletIds = array_map(static fn (array $member): int => (int)$member['walletId'], $participants);
		$this->agreementMapper->record(
			$occurrence->getId(),
			$kind,
			$actorHouseUserId,
			$walletIds,
			['members' => array_values($oldMembers)],
			['members' => $activeRows, 'released' => $releasedWalletIds],
			['attestedBy' => $actorHouseUserId, 'subjects' => $consentSubjects]
		);

		$this->reconcile->updateNextDueProjectionUnderLock($roomTask, $now);
		return $occurrence;
	}

	/**
	 * The occurrence-level agreed work appointment from a member snapshot:
	 * the first ACTIVE row carrying a work date, else null (planned day).
	 *
	 * @param list<array<string, mixed>> $members
	 * @return array{0: string|null, 1: string|null}
	 */
	private function occurrenceAppointment(array $members): array {
		foreach ($members as $member) {
			if (($member['disposition'] ?? OccurrenceMember::DISPOSITION_ACTIVE) !== OccurrenceMember::DISPOSITION_ACTIVE) {
				continue;
			}
			if (($member['workDate'] ?? null) !== null) {
				return [(string)$member['workDate'], isset($member['workZone']) ? (string)$member['workZone'] : null];
			}
		}
		return [null, null];
	}

	/**
	 * RF-073-WP2-18: `handover` labels ONLY an actual responsibility
	 * transfer — the active RESPONSIBLE wallet set changed. Any other
	 * participant change (shares, joint set, added/released helper) is a
	 * `share` amendment.
	 *
	 * @param list<array<string, mixed>> $oldMembers
	 * @param list<array<string, mixed>> $newActiveRows
	 */
	private function memberAmendmentKind(array $oldMembers, array $newActiveRows): string {
		$responsible = static function (array $members): array {
			$wallets = [];
			foreach ($members as $member) {
				if (($member['disposition'] ?? OccurrenceMember::DISPOSITION_ACTIVE) !== OccurrenceMember::DISPOSITION_ACTIVE) {
					continue;
				}
				if ((string)($member['role'] ?? '') === OccurrenceMember::ROLE_RESPONSIBLE) {
					$wallets[] = (int)$member['walletId'];
				}
			}
			sort($wallets);
			return $wallets;
		};
		return $responsible($oldMembers) === $responsible($newActiveRows)
			? Agreement::KIND_SHARE
			: Agreement::KIND_HANDOVER;
	}

	/**
	 * WP2 catch-up writer UNDER LOCK (plan §3.3.4): change the CURRENT
	 * unfulfilled uncovered miss's work appointment (and participants)
	 * within capacity while retaining the original planned date, window
	 * and miss. The occurrence keeps its identity; a stale catch-up
	 * appointment can never defeat the C1 current-occurrence precedence
	 * (selection keeps following the planned window).
	 *
	 * RF-073-WP2-04: the agreed appointment INCLUDING its zone rides the
	 * replaced participant rows — a catch-up with participants no longer
	 * loses the work date/zone the same command just agreed. A participant
	 * change persists neutral releases like every member amendment
	 * (RF-073-WP2-02).
	 *
	 * @param list<array{walletId: int, role: string, obligated: bool, sharePct: int}> $participants
	 */
	public function setWorkAppointmentUnderLock(
		Occurrence $occurrence,
		RoomTask $roomTask,
		?int $actorHouseUserId,
		string $workDateKey,
		string $workZone,
		array $participants,
		array $consentSubjects,
		\DateTime $now,
	): Occurrence {
		if ($occurrence->getId() === null) {
			throw new ConflictException($this->l10n->t('The task changed since it was displayed. Reload and try again.'));
		}
		if ($occurrence->getCompletionId() !== null || $occurrence->getCoveredByCompletionId() !== null) {
			throw new ConflictException($this->l10n->t('This occurrence is already completed'));
		}

		$oldMembers = array_map(
			static fn (OccurrenceMember $member): array => $member->jsonSerialize(),
			$this->occMemberMapper->findByOccurrence($occurrence->getId())
		);
		[$oldAppointmentDate, $oldAppointmentZone] = $this->occurrenceAppointment($oldMembers);
		$oldAppointment = [
			'plannedDate' => $occurrence->getPlannedDate()->format('Y-m-d'),
			'workDate' => $oldAppointmentDate,
			'workZone' => $oldAppointmentZone,
		];

		if ($participants !== []) {
			$newWallets = [];
			$rows = [];
			foreach ($participants as $participant) {
				$newWallets[(int)$participant['walletId']] = true;
				$rows[] = [
					'walletId' => (int)$participant['walletId'],
					'role' => (string)$participant['role'],
					'obligated' => (bool)$participant['obligated'],
					'sharePct' => (int)$participant['sharePct'],
					'workDate' => $workDateKey,
					'workZone' => $workZone,
					'disposition' => OccurrenceMember::DISPOSITION_ACTIVE,
				];
			}
			foreach ($oldMembers as $member) {
				$walletId = (int)$member['walletId'];
				if (isset($newWallets[$walletId])) {
					continue;
				}
				$oldDisposition = (string)($member['disposition'] ?? OccurrenceMember::DISPOSITION_ACTIVE);
				$rows[] = [
					'walletId' => $walletId,
					'role' => (string)$member['role'],
					'obligated' => false,
					'sharePct' => (int)$member['sharePct'],
					// A released row is history — it keeps its snapshot,
					// the NEW appointment belongs to the active rows only.
					'workDate' => $member['workDate'] ?? null,
					'workZone' => $member['workZone'] ?? null,
					'disposition' => $oldDisposition === OccurrenceMember::DISPOSITION_ACTIVE
						? OccurrenceMember::DISPOSITION_RELEASED
						: $oldDisposition,
				];
			}
			$this->occMemberMapper->replaceForOccurrence($occurrence->getId(), $rows);
		} else {
			// No participant change: stamp the appointment on the ACTIVE
			// rows only — released/excused history keeps its snapshot.
			foreach ($this->occMemberMapper->findByOccurrence($occurrence->getId()) as $member) {
				if ($member->getDisposition() !== OccurrenceMember::DISPOSITION_ACTIVE) {
					continue;
				}
				$member->setWorkDate(RecurrenceCalculator::dayKeyToDate($workDateKey));
				$member->setWorkZone($workZone);
				$this->occMemberMapper->update($member);
			}
		}
		$occurrence->setVersion($occurrence->getVersion() + 1);
		$this->occurrenceMapper->update($occurrence);

		$this->agreementMapper->record(
			$occurrence->getId(),
			Agreement::KIND_CATCHUP,
			$actorHouseUserId,
			array_map(static fn (array $member): int => (int)$member['walletId'], $participants ?: $oldMembers),
			$oldAppointment,
			['plannedDate' => $occurrence->getPlannedDate()->format('Y-m-d'), 'workDate' => $workDateKey, 'workZone' => $workZone],
			['attestedBy' => $actorHouseUserId, 'subjects' => $consentSubjects]
		);

		$this->reconcile->updateNextDueProjectionUnderLock($roomTask, $now);
		return $occurrence;
	}

	/**
	 * WP2 linked interval restart writer UNDER LOCK (plan §3.3.5): create
	 * ONE agreed restart successor of a neutrally suspended interval
	 * predecessor through the existing revision/ordinal mechanism. The
	 * predecessor keeps its neutral history; the restart links back via
	 * `restart_of_id`; only the restart's real completion creates the
	 * next completion-relative interval.
	 */
	public function createLinkedRestartUnderLock(
		int $houseId,
		Occurrence $predecessor,
		RoomTask $roomTask,
		ScheduleRevision $revision,
		string $plannedDateKey,
		?int $actorHouseUserId,
		array $consentSubjects,
		\DateTime $now,
	): Occurrence {
		if ($predecessor->getId() === null) {
			throw new ConflictException($this->l10n->t('The task changed since it was displayed. Reload and try again.'));
		}
		// Portable uniqueness invariant: the locked predecessor plus the
		// open-restart scan prevents duplicate live restarts (plan §3.3.5).
		foreach ($this->occurrenceMapper->findByRoomTask($roomTask->getId()) as $row) {
			if ($row->getRestartOfId() === $predecessor->getId()
				&& $row->getCompletionId() === null
				&& $row->getCoveredByCompletionId() === null) {
				throw new ConflictException($this->l10n->t('A restart already exists for this occurrence'));
			}
		}
		$maxOrdinal = $this->reconcile->maxOrdinalOfRevision($revision->getId());
		$restart = $this->rowFactory->buildOccurrence($houseId, $roomTask->getId(), $revision, $maxOrdinal + 1, $plannedDateKey, $now);
		$restart->setRestartOfId($predecessor->getId());
		$restart = $this->occurrenceMapper->insertOrGet($restart);
		$this->reconcile->attachMembersUnderLock($revision, $restart);
		$this->revisionMapper->advanceFrontier($revision, RecurrenceCalculator::dayKeyToDate($plannedDateKey));

		$this->agreementMapper->record(
			$restart->getId(),
			Agreement::KIND_RESTART,
			$actorHouseUserId,
			null,
			['restartOf' => $predecessor->toPublicKey(), 'plannedDate' => $predecessor->getPlannedDate()->format('Y-m-d')],
			['plannedDate' => $restart->getPlannedDate()->format('Y-m-d')],
			['attestedBy' => $actorHouseUserId, 'subjects' => $consentSubjects]
		);

		$this->reconcile->updateNextDueProjectionUnderLock($roomTask, $now);
		return $restart;
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
	 * @param array{attestedBy: int, subjects: list<mixed>}|null $consent RF-073-WP2-06: the recorded owner attestation (planner apply)
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
		?array $consent = null,
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
				['members' => $participants ?? []],
				$consent
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
