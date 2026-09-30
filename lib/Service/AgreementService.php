<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\DigestChange;
use OCA\ChoreStrike\Db\DigestChangeMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMember;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\ScheduleRevision;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\IL10N;

/**
 * Command-level agreement coordinator of the WP2 agreement lifecycle
 * (CS-PLAN-073 §3.3): owner-recorded single-occurrence amendments
 * (date/window/share/handover), catch-up work appointments and linked
 * interval restarts.
 *
 * Every command runs as ONE `CommandService` command (house lock first,
 * receipt replay per command UUID, facts + evidence + receipt commit or
 * roll back together). Authorization and visibility resolve BEFORE the
 * receipt replay; the domain checks (unfulfilled target, original
 * deadline, whole-day capacity of EVERY affected participant, explicit
 * recorded consent) run inside the locked aggregate through the one
 * occurrence writer, {@see OccurrenceAgreementService}. No second
 * agreement authority exists at this boundary.
 *
 * Error mapping (plan §3.5): malformed 400, visible role denial 403,
 * invisible or foreign target 404, stale version/deadline/business
 * conflict 409.
 */
class AgreementService {
	/** Stable action identifiers of the agreement commands. */
	public const ACTION_AMEND = 'occurrence.amend';
	public const ACTION_CATCH_UP = 'occurrence.catch-up';
	public const ACTION_RESTART = 'occurrence.restart';

	/** @var list<string> */
	private const VALID_ROLES = [
		OccurrenceMember::ROLE_RESPONSIBLE,
		OccurrenceMember::ROLE_JOINT,
		OccurrenceMember::ROLE_HELPER,
	];

	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private HouseMapper $houseMapper;
	private WalletMapper $walletMapper;
	private HouseUserMapper $houseUserMapper;
	private OccurrenceMemberMapper $occMemberMapper;
	private OccurrenceService $occurrenceService;
	private DailyCapacityService $dailyCapacity;
	private TaskAccessPolicy $taskAccessPolicy;
	private CommandService $commands;
	private CalendarService $calendar;
	private DigestChangeMapper $digestChangeMapper;
	private DigestScheduleService $digestScheduleService;
	private IL10N $l10n;

	public function __construct(
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		HouseMapper $houseMapper,
		WalletMapper $walletMapper,
		HouseUserMapper $houseUserMapper,
		OccurrenceMemberMapper $occMemberMapper,
		OccurrenceService $occurrenceService,
		DailyCapacityService $dailyCapacity,
		TaskAccessPolicy $taskAccessPolicy,
		CommandService $commands,
		CalendarService $calendar,
		DigestChangeMapper $digestChangeMapper,
		DigestScheduleService $digestScheduleService,
		IL10N $l10n,
	) {
		$this->roomTaskMapper = $roomTaskMapper;
		$this->taskMapper = $taskMapper;
		$this->houseMapper = $houseMapper;
		$this->walletMapper = $walletMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->occMemberMapper = $occMemberMapper;
		$this->occurrenceService = $occurrenceService;
		$this->dailyCapacity = $dailyCapacity;
		$this->taskAccessPolicy = $taskAccessPolicy;
		$this->commands = $commands;
		$this->calendar = $calendar;
		$this->digestChangeMapper = $digestChangeMapper;
		$this->digestScheduleService = $digestScheduleService;
		$this->l10n = $l10n;
	}

	/**
	 * Owner-recorded single-occurrence amendment: optional new date
	 * (`date`), optional explicit participant set (`participants` with
	 * roles/shares). Requires the explicit owner attestation (`consent`),
	 * an unfulfilled target and `now < oldWindowEnd` (checked BEFORE the
	 * proposed new one). A handover releases the former responsibility
	 * neutrally; a single-occurrence amendment never touches the
	 * revision's future defaults.
	 *
	 * Payload: {commandId, occurrenceId, expectedVersion, selectionToken,
	 * consent: true, date?: 'Y-m-d', participants?: [{userId, role,
	 * sharePct}]}.
	 *
	 * @return array<string, mixed> Result identity refs
	 */
	public function amend(int $houseId, string $actorUserId, int $roomTaskId, array $payload): array {
		$date = null;
		if (isset($payload['date'])) {
			if (!is_string($payload['date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['date'])
				|| \DateTime::createFromFormat('Y-m-d', $payload['date'])->format('Y-m-d') !== $payload['date']) {
				throw new \InvalidArgumentException($this->l10n->t('date must be a valid Y-m-d date'));
			}
			$date = $payload['date'];
		}
		$participants = isset($payload['participants']) ? $this->validateParticipants($payload['participants']) : null;
		if ($date === null && $participants === null) {
			throw new \InvalidArgumentException($this->l10n->t('An amendment needs a date or participants'));
		}
		$receipt = $this->validateReceiptBase($payload, ['date', 'participants'], true);
		// RF-073-WP2-19: EVERY normalized semantic input is part of the
		// receipt identity — the same UUID with a changed date or
		// participant set must conflict, never replay the original.
		$receipt['date'] = $date;
		$receipt['participants'] = $participants === null ? null : self::canonicalParticipants($participants);

		return $this->runOccurrenceCommand(
			$houseId,
			$actorUserId,
			$roomTaskId,
			self::ACTION_AMEND,
			$receipt,
			function (AgreementCommandContext $ctx) use ($date, $participants): array {
				$resolved = $this->resolveTarget($ctx);
				$occurrence = $resolved['occurrence'];
				$revision = $resolved['revision'];

				// Deadline check BEFORE considering the proposed new one
				// (plan §3.3.1): at exactly the exclusive end it conflicts.
				if ($ctx->now >= $occurrence->getWindowEnd()) {
					throw new ConflictException($this->l10n->t('The amendment deadline has passed'));
				}

				$consentSubjects = [];
				if ($participants !== null) {
					$built = $this->buildParticipantRows($ctx->houseId, $participants, $consentSubjects);
					// Whole-day capacity for the NEW arrangement on the
					// day it will actually bind: the proposed new date, or
					// the occurrence's effective work appointment
					// (plan §3.2/§3.3.3 — a handover installs a
					// capacity-checked responsibility; every affected
					// participant including concretely agreed helpers is
					// checked, RF-07). The occurrence's own replaced
					// allocation is removed first (RF-21 principle).
					$effectiveDay = $date
						?? $resolved['workAppointment']['date']
						?? $occurrence->getPlannedDate()?->format('Y-m-d');
					if ($effectiveDay !== null) {
						$this->assertRowsDayCapacity($ctx->houseId, $effectiveDay, $revision, $built['rows'], $consentSubjects, $occurrence);
					}
					$stateBefore = $occurrence->getSuspensionState();
					$evidenceBefore = json_encode($occurrence->getSuspensionEvidence() ?? null);
					$this->occurrenceService->amendOccurrenceMembersUnderLock(
						$occurrence,
						$ctx->roomTask,
						$ctx->actorHouseUserId,
						$built['rows'],
						array_values($consentSubjects),
						$ctx->now
					);
					// R6/RF-073-WP2-24: an explicit feasible agreement that
					// resumes a suspended joint occurrence must leave the
					// persisted facts agreeing with the accepted
					// arrangement — re-derive the effective suspension
					// from the NEW member rows and persist the drift
					// (release/excusal provenance rows stay untouched).
					$this->restampSuspensionAfterMemberWrite($ctx->houseId, $occurrence, $revision, $stateBefore, $evidenceBefore);
				}
				if ($date !== null) {
					if ($participants === null) {
						// Whole-day capacity for the CONCRETE current
						// arrangement on the new day (plan §3.2;
						// RF-073-WP2-27: after a single-occurrence
						// handover the validation follows the actual
						// participant rows, never the revision defaults).
						$this->assertArrangedDayCapacity($ctx->houseId, $date, $revision, $occurrence, $consentSubjects);
					}
					// RF-073-WP2-01: move EXACTLY the occurrence resolved
					// from the client's key — never a re-selected one —
					// and report the RESULTING version.
					$occurrence = $this->occurrenceService->amendOccurrenceDateForTargetUnderLock(
						$occurrence,
						$revision,
						$ctx->roomTask,
						$ctx->actorHouseUserId,
						array_map('intval', array_keys($consentSubjects)),
						$date,
						$ctx->now,
						['attestedBy' => $ctx->actorHouseUserId, 'subjects' => array_values($consentSubjects)]
					);
				}
				// WP5 (C5m): the organizational queue write joins THIS
				// command transaction — the affected members learn about
				// the amendment through their opted-in digest, never
				// through a bell.
				$this->queueAgreementChanges($ctx->houseId, (int)$ctx->actorHouseUserId, $occurrence, $consentSubjects, $ctx->now);
				return $this->resultRefs($occurrence);
			}
		);
	}

	/**
	 * Catch-up command: accepts only the CURRENT unfulfilled uncovered
	 * miss; changes its work appointment (and participants) within
	 * capacity, retaining the original planned date/window and miss
	 * (plan §3.3.4).
	 *
	 * Payload: {commandId, occurrenceId, expectedVersion, selectionToken,
	 * consent: true, workDate: 'Y-m-d', workZone?: IANA,
	 * participants?: [{userId, role, sharePct}]}.
	 *
	 * @return array<string, mixed>
	 */
	public function catchUp(int $houseId, string $actorUserId, int $roomTaskId, array $payload): array {
		$workDate = $payload['workDate'] ?? null;
		if (!is_string($workDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)
			|| \DateTime::createFromFormat('Y-m-d', $workDate)->format('Y-m-d') !== $workDate) {
			throw new \InvalidArgumentException($this->l10n->t('workDate must be a valid Y-m-d date'));
		}
		$workZone = $payload['workZone'] ?? null;
		if ($workZone !== null && (!is_string($workZone) || !$this->calendar->isValidTimeZone($workZone))) {
			throw new \InvalidArgumentException($this->l10n->t('workZone must be a valid IANA timezone'));
		}
		$participants = isset($payload['participants']) ? $this->validateParticipants($payload['participants']) : null;
		$receipt = $this->validateReceiptBase($payload, ['workDate', 'workZone', 'participants'], true);
		// RF-073-WP2-19: the appointment (with its zone) and the normalized
		// participant set are part of the receipt identity.
		$receipt['workDate'] = $workDate;
		$receipt['workZone'] = $workZone;
		$receipt['participants'] = $participants === null ? null : self::canonicalParticipants($participants);

		return $this->runOccurrenceCommand(
			$houseId,
			$actorUserId,
			$roomTaskId,
			self::ACTION_CATCH_UP,
			$receipt,
			function (AgreementCommandContext $ctx) use ($workDate, $workZone, $participants): array {
				$resolved = $this->resolveTarget($ctx);
				$occurrence = $resolved['occurrence'];
				$revision = $resolved['revision'];

				// Only the CURRENT unfulfilled uncovered miss is a
				// catch-up target: the canonical selection (with the WP2
				// suspension inputs) must still resolve to it.
				$selection = $this->occurrenceService->selectionFor($ctx->roomTask, $ctx->now, $ctx->houseId);
				if ($selection === null
					|| $selection['classification'] !== 'late'
					|| $selection['occurrence']->toPublicKey() !== $occurrence->toPublicKey()) {
					throw new ConflictException($this->l10n->t('The task changed since it was displayed. Reload and try again.'));
				}

				// Whole-day capacity of the people the appointment will
				// actually bind: the NEW participant set when the command
				// changes participants, otherwise the revision's committed
				// members (plan §3.2 — every affected participant,
				// including concretely agreed helpers, RF-07).
				$consentSubjects = [];
				$participantRows = [];
				if ($participants !== null) {
					$participantRows = $this->buildParticipantRows($ctx->houseId, $participants, $consentSubjects)['rows'];
					$this->assertRowsDayCapacity($ctx->houseId, $workDate, $revision, $participantRows, $consentSubjects, $occurrence);
				} else {
					// RF-073-WP2-27: the appointment binds the CONCRETE
					// current participants of the miss — after a handover
					// the validation follows the actual member rows, not
					// the revision defaults.
					$this->assertArrangedDayCapacity($ctx->houseId, $workDate, $revision, $occurrence, $consentSubjects);
				}
				$zone = $workZone ?? $this->calendar->getTimeZone($ctx->house)->getName();

				$stateBefore = $occurrence->getSuspensionState();
				$evidenceBefore = json_encode($occurrence->getSuspensionEvidence() ?? null);
				$this->occurrenceService->setWorkAppointmentUnderLock(
					$occurrence,
					$ctx->roomTask,
					$ctx->actorHouseUserId,
					$workDate,
					$zone,
					$participantRows,
					array_values($consentSubjects),
					$ctx->now
				);
				if ($participants !== null) {
					$this->restampSuspensionAfterMemberWrite($ctx->houseId, $occurrence, $revision, $stateBefore, $evidenceBefore);
				}
				// WP5 (C5m): transactional queue write for the affected
				// members' opted-in digest.
				$this->queueAgreementChanges($ctx->houseId, (int)$ctx->actorHouseUserId, $occurrence, $consentSubjects, $ctx->now);
				return $this->resultRefs($occurrence);
			}
		);
	}

	/**
	 * Restart command: accepts a neutrally suspended interval predecessor
	 * (even after its old deadline) and creates ONE linked agreed restart
	 * on the agreed work day. The predecessor keeps its neutral history;
	 * only the restart's real completion creates the next
	 * completion-relative interval (plan §3.3.5).
	 *
	 * Payload: {commandId, occurrenceId, expectedVersion, selectionToken?,
	 * consent: true, workDate: 'Y-m-d'}.
	 *
	 * @return array<string, mixed>
	 */
	public function restart(int $houseId, string $actorUserId, int $roomTaskId, array $payload): array {
		$workDate = $payload['workDate'] ?? null;
		if (!is_string($workDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)
			|| \DateTime::createFromFormat('Y-m-d', $workDate)->format('Y-m-d') !== $workDate) {
			throw new \InvalidArgumentException($this->l10n->t('workDate must be a valid Y-m-d date'));
		}
		$receipt = $this->validateReceiptBase($payload, ['workDate'], false);
		// RF-073-WP2-19: the agreed restart day is part of the receipt
		// identity — the same UUID with a different day conflicts.
		$receipt['workDate'] = $workDate;

		return $this->runOccurrenceCommand(
			$houseId,
			$actorUserId,
			$roomTaskId,
			self::ACTION_RESTART,
			$receipt,
			function (AgreementCommandContext $ctx) use ($workDate): array {
				$resolved = $this->resolveTarget($ctx);
				$occurrence = $resolved['occurrence'];
				$revision = $resolved['revision'];

				// Only a NEUTRALLY SUSPENDED interval predecessor restarts;
				// ordinary fixed anchors stay untouched through pauses.
				// RF-073-WP2-05: the suspension state is the EFFECTIVE
				// (projection-stamped) one — a legitimately suspended
				// virtual predecessor whose materialized row still carries
				// the materialization default restarts like every persisted
				// suspended row.
				if ($occurrence->getSuspensionState() !== Occurrence::SUSPENSION_SUSPENDED) {
					throw new ConflictException($this->l10n->t('Only a paused occurrence can be restarted'));
				}
				if ($revision->getCadenceMode() !== ScheduleRevision::CADENCE_INTERVAL) {
					throw new ConflictException($this->l10n->t('Only interval work needs an explicit restart'));
				}

				$consentSubjects = [];
				$this->assertRevisionDayCapacity($ctx->houseId, $workDate, $revision, $consentSubjects, $occurrence);

				$restart = $this->occurrenceService->createLinkedRestartUnderLock(
					$ctx->houseId,
					$occurrence,
					$ctx->roomTask,
					$revision,
					$workDate,
					$ctx->actorHouseUserId,
					array_values($consentSubjects),
					$ctx->now
				);
				// WP5 (C5m): transactional queue write for the affected
				// members' opted-in digest (the restart's own occurrence
				// identity).
				$this->queueAgreementChanges($ctx->houseId, (int)$ctx->actorHouseUserId, $restart, $consentSubjects, $ctx->now);
				return $this->resultRefs($restart);
			}
		);
	}

	// ------------------------------------------------------------------
	// Shared command plumbing
	// ------------------------------------------------------------------

	/**
	 * Queue the organizational digest change for every AFFECTED member of
	 * an agreement recording (CS-PLAN-070 WP5 §3.3, C5m): handover/date/
	 * share amendments touch the consent subjects — the actor's own row
	 * is skipped (they made the change). Identity rows only (occurrence
	 * id + revision); rights and relevance are rechecked at dispatch.
	 * Runs INSIDE the owning command transaction (the house lock is
	 * already held); retry/replay coalesces on the unique identity.
	 *
	 * @param array<int, array{walletId: int, userId: string}> $consentSubjects
	 */
	private function queueAgreementChanges(int $houseId, int $actorHouseUserId, Occurrence $occurrence, array $consentSubjects, \DateTimeInterface $now): void {
		foreach ($consentSubjects as $subject) {
			$member = $this->houseUserMapper->findByHouseAndUser($houseId, $subject['userId']);
			if ($member === null || (int)$member->getId() === $actorHouseUserId) {
				// Rows carry only recipients authorized at write time.
				continue;
			}
			$change = new DigestChange();
			$change->setHouseId($houseId);
			$change->setHouseUserId((int)$member->getId());
			$change->setObjectType('agreement');
			$change->setObjectId((string)$occurrence->getId());
			$change->setObjectRevision($occurrence->getVersion());
			$change->setCategory(DigestChange::CATEGORY_ORG);
			$change->setChangeTime(\DateTime::createFromInterface($now));
			$change->setTargetSlot($this->digestScheduleService->resolveTargetSlotUnderLock($houseId, (int)$member->getId(), $now));
			$change->setDisposition(DigestChange::DISPOSITION_QUEUED);
			$change->setCreatedAt(\DateTime::createFromInterface($now));
			$change->setUpdatedAt(\DateTime::createFromInterface($now));
			$this->digestChangeMapper->queueOrCoalesce($change);
		}
	}

	/**
	 * @param callable(AgreementCommandContext): array<string, mixed> $fn
	 * @return array<string, mixed>
	 */
	private function runOccurrenceCommand(int $houseId, string $actorUserId, int $roomTaskId, string $action, array $receiptPayload, callable $fn): array {
		// Authorize BEFORE receipt replay (plan §3.5): membership, target
		// visibility and the owner-recording rule run outside the receipt
		// transaction.
		$actor = $this->houseUserMapper->findByHouseAndUser($houseId, $actorUserId);
		if ($actor === null) {
			throw new ForbiddenException($this->l10n->t('Not a member of this house'));
		}
		$roomTask = $this->roomTaskMapper->findByIdsScopedToHouse([$roomTaskId], $houseId)[0] ?? null;
		if ($roomTask === null) {
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}
		$task = $this->taskMapper->find($roomTask->getTaskId());
		if (!$this->taskAccessPolicy->canViewTask($task, $actor)) {
			// Invisible/foreign target: the 404 anti-enumeration standard.
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}
		if (!$actor->isOwner()) {
			// Amendments are owner-recorded agreements (plan §3.3.2); the
			// task itself is visible, so this is a visible role denial.
			throw new ForbiddenException($this->l10n->t('Owner role required'));
		}

		$actorHouseUserId = (int)$actor->getId();
		['result' => $refs] = $this->commands->runCommand(
			$houseId,
			$actorHouseUserId,
			(string)$receiptPayload['commandId'],
			$action,
			$receiptPayload,
			function () use ($houseId, $roomTaskId, $actorHouseUserId, $receiptPayload, $fn): array {
				$ctx = new AgreementCommandContext();
				$ctx->houseId = $houseId;
				$ctx->actorHouseUserId = $actorHouseUserId;
				$ctx->roomTask = $this->roomTaskMapper->lockForUpdate($roomTaskId);
				$ctx->task = $this->taskMapper->find($ctx->roomTask->getTaskId());
				$ctx->house = $this->houseMapper->find($houseId);
				$ctx->now = new \DateTime();
				$ctx->occurrenceId = (string)$receiptPayload['occurrenceId'];
				$ctx->expectedVersion = (int)$receiptPayload['expectedVersion'];
				$ctx->selectionToken = (string)$receiptPayload['selectionToken'];
				return $fn($ctx);
			}
		);

		return $refs;
	}

	/**
	 * Resolve the public occurrence key UNDER LOCK (materializing a
	 * virtual agreed key), stamp the EFFECTIVE projection facts on the
	 * resolved row (RF-073-WP2-05: virtual and materialized targets follow
	 * the same suspension/appointment rules the reads show) and enforce the
	 * shared staleness gates: unfulfilled target, expected version and —
	 * RF-073-WP2-22 — the shown selection token recomputed from the
	 * authoritative facts under the lock. A drifted materialized row keeps
	 * its derived suspension state (persisted with a version bump AFTER the
	 * staleness gates, so the command's writes carry the effective facts).
	 *
	 * @return array{occurrence: Occurrence, revision: ScheduleRevision, workAppointment: array{date: ?string, zone: string}}
	 */
	private function resolveTarget(AgreementCommandContext $ctx): array {
		$resolved = $this->occurrenceService->resolveAgreedKeyUnderLock(
			$ctx->houseId,
			$ctx->roomTask,
			$ctx->occurrenceId,
			$ctx->now,
			true
		);
		$occurrence = $resolved['occurrence'];
		$revision = $resolved['revision'];
		if ($occurrence->getCompletionId() !== null || $occurrence->getCoveredByCompletionId() !== null) {
			throw new ConflictException($this->l10n->t('This occurrence is already completed'));
		}

		$persistedState = $occurrence->getSuspensionState();
		$persistedEvidence = json_encode($occurrence->getSuspensionEvidence() ?? null);
		$workAppointment = $this->occurrenceService->stampProjection($ctx->houseId, $occurrence, $revision);

		if ($occurrence->getVersion() !== $ctx->expectedVersion) {
			throw new ConflictException($this->l10n->t('The task changed since it was displayed. Reload and try again.'));
		}

		// RF-073-WP2-22: version equality alone cannot detect pause/input
		// drift on a read-only virtual target — recompute the effective
		// selection token under the lock and compare.
		if ($ctx->selectionToken !== '') {
			$authoritative = $this->occurrenceService->selectionToken(
				$occurrence,
				$revision,
				$this->effectiveClassification($occurrence, $ctx->now),
				$ctx->houseId,
				null,
				$workAppointment
			);
			if (!hash_equals($authoritative, $ctx->selectionToken)) {
				throw new ConflictException($this->l10n->t('The task changed since it was displayed. Reload and try again.'));
			}
		}

		// Persist the derived facts on a materialized row whose stored
		// state drifted behind the projection (the staleness gates above
		// already ran against the pre-bump version).
		if ($occurrence->getId() !== null
			&& ($occurrence->getSuspensionState() !== $persistedState
				|| json_encode($occurrence->getSuspensionEvidence() ?? null) !== $persistedEvidence)) {
			$this->occurrenceService->persistSuspensionStampUnderLock($occurrence);
		}

		$resolved['workAppointment'] = $workAppointment;
		return $resolved;
	}

	/**
	 * The classification a rendered occurrence block carried for this
	 * target (the read paths emit tokens only through the canonical
	 * selection): a voluntary row is `voluntary`, an expired window is the
	 * `late` catch-up card, anything else the `agreed` open/future target.
	 */
	private function effectiveClassification(Occurrence $occurrence, \DateTime $now): string {
		if ($occurrence->getKind() === Occurrence::KIND_VOLUNTARY) {
			return 'voluntary';
		}
		return $occurrence->getWindowEnd() <= $now ? 'late' : 'agreed';
	}

	/**
	 * The canonical receipt shape of a validated participant set
	 * (RF-073-WP2-19): sorted by user id so a reordered but semantically
	 * identical set replays, while any changed role/share/member conflicts.
	 *
	 * @param list<array{userId: string, role: string, sharePct: int, obligated: bool}> $participants
	 * @return list<array{userId: string, role: string, sharePct: int, obligated: bool}>
	 */
	private static function canonicalParticipants(array $participants): array {
		$canonical = $participants;
		usort($canonical, static fn (array $a, array $b): int => strcmp($a['userId'], $b['userId']));
		return $canonical;
	}

	// ------------------------------------------------------------------
	// Validation helpers
	// ------------------------------------------------------------------

	/**
	 * The closed receipt base shared by every agreement command: command
	 * UUID, occurrence key, expected version and the explicit consent
	 * attestation (an owner-recorded agreement, never a confirmation
	 * queue). `$requireToken` enforces the shown selection token.
	 *
	 * @param list<string> $extraFields
	 * @return array<string, mixed>
	 */
	private function validateReceiptBase(array $payload, array $extraFields, bool $requireToken): array {
		$allowed = array_merge(['commandId', 'occurrenceId', 'expectedVersion', 'selectionToken', 'consent'], $extraFields);
		foreach (array_keys($payload) as $key) {
			if (!in_array($key, $allowed, true)) {
				throw new \InvalidArgumentException($this->l10n->t('Unknown field: %1$s', [(string)$key]));
			}
		}
		$commandId = $payload['commandId'] ?? null;
		if (!is_string($commandId) || trim($commandId) === '' || strlen($commandId) > 36) {
			throw new \InvalidArgumentException($this->l10n->t('A valid commandId (UUID) is required'));
		}
		$occurrenceId = $payload['occurrenceId'] ?? null;
		if (!is_string($occurrenceId) || $occurrenceId === '') {
			throw new \InvalidArgumentException($this->l10n->t('An occurrenceId is required'));
		}
		try {
			Occurrence::parsePublicKey($occurrenceId);
		} catch (\InvalidArgumentException $e) {
			throw new \InvalidArgumentException($e->getMessage());
		}
		$expectedVersion = $payload['expectedVersion'] ?? null;
		if (!is_numeric($expectedVersion) || (int)$expectedVersion < 0 || (string)(int)$expectedVersion !== (string)$expectedVersion) {
			throw new \InvalidArgumentException($this->l10n->t('A valid expectedVersion is required'));
		}
		if (($payload['consent'] ?? null) !== true) {
			throw new \InvalidArgumentException($this->l10n->t('An explicit consent attestation is required'));
		}
		$selectionToken = $payload['selectionToken'] ?? null;
		if ($requireToken && (!is_string($selectionToken) || $selectionToken === '')) {
			throw new \InvalidArgumentException($this->l10n->t('A selectionToken is required'));
		}
		return [
			'commandId' => strtolower($commandId),
			'occurrenceId' => $occurrenceId,
			'expectedVersion' => (int)$expectedVersion,
			'selectionToken' => is_string($selectionToken) ? $selectionToken : '',
			'consent' => true,
		];
	}

	/**
	 * Validate the participant input shape: unique member user ids, valid
	 * roles, positive whole shares (exactness is enforced after the
	 * wallet resolution).
	 *
	 * @return list<array{userId: string, role: string, sharePct: int, obligated: bool}>
	 */
	private function validateParticipants(mixed $input): array {
		if (!is_array($input) || $input === []) {
			throw new \InvalidArgumentException($this->l10n->t('participants must be a non-empty array'));
		}
		if (count($input) > DailyCapacityService::MAX_PARTICIPANTS) {
			throw new \InvalidArgumentException($this->l10n->t('A plan may have at most %1$s participants', [(string)DailyCapacityService::MAX_PARTICIPANTS]));
		}
		$seen = [];
		$participants = [];
		foreach ($input as $entry) {
			if (!is_array($entry)) {
				throw new \InvalidArgumentException($this->l10n->t('Each participant must be an object'));
			}
			$userId = $entry['userId'] ?? null;
			if (!is_string($userId) || trim($userId) === '' || isset($seen[$userId])) {
				throw new \InvalidArgumentException($this->l10n->t('participants must not contain duplicates'));
			}
			$seen[$userId] = true;
			$role = $entry['role'] ?? OccurrenceMember::ROLE_RESPONSIBLE;
			if (!in_array($role, self::VALID_ROLES, true)) {
				throw new \InvalidArgumentException($this->l10n->t('Unknown participant role: %1$s', [(string)$role]));
			}
			$sharePct = $entry['sharePct'] ?? null;
			if (!is_int($sharePct) || $sharePct < 1 || $sharePct > 100) {
				throw new \InvalidArgumentException($this->l10n->t('Shares must be positive whole percents'));
			}
			$participants[] = [
				'userId' => $userId,
				'role' => $role,
				'sharePct' => $sharePct,
				'obligated' => $role !== OccurrenceMember::ROLE_HELPER,
			];
		}
		return $participants;
	}

	/**
	 * Build the concrete occ_members rows for an explicit participant
	 * set: exact share total (exactly 100 among the obligated members),
	 * wallet identity, helper rows carry no obligation. Neutral releases
	 * of former participants are derived and persisted by the writer
	 * itself (RF-073-WP2-02) — the current rows are a projection, the
	 * evidence log keeps the full before/after history.
	 *
	 * @param list<array{userId: string, role: string, sharePct: int, obligated: bool}> $participants
	 * @param array<int, array{walletId: int, userId: string}> $consentSubjects
	 * @return array{rows: list<array{walletId: int, role: string, obligated: bool, sharePct: int}>}
	 */
	private function buildParticipantRows(int $houseId, array $participants, array &$consentSubjects): array {
		$rows = [];
		$activeTotal = 0;
		foreach ($participants as $participant) {
			$wallet = $this->walletMapper->findByHouseAndUser($houseId, $participant['userId']);
			if ($wallet === null) {
				throw new ConflictException($this->l10n->t('Assignees must be house members'));
			}
			$walletId = (int)$wallet->getId();
			$rows[] = [
				'walletId' => $walletId,
				'role' => $participant['role'],
				'obligated' => $participant['obligated'],
				'sharePct' => $participant['sharePct'],
			];
			if ($participant['obligated']) {
				$activeTotal += $participant['sharePct'];
			}
			$consentSubjects[$walletId] = ['walletId' => $walletId, 'userId' => $participant['userId']];
		}
		if ($activeTotal !== 100) {
			throw new \InvalidArgumentException($this->l10n->t('Shares must total exactly 100 percent'));
		}
		return ['rows' => $rows];
	}

	/**
	 * Whole-day capacity gate for an explicit participant row set (the
	 * amended arrangement) on the candidate day, plan §3.2. RF-073-WP2-07:
	 * EVERY concretely agreed participant is checked — including helper
	 * commitments with their exact share; merely offering help creates no
	 * duty, but an agreed helper commitment consumes that person's day.
	 *
	 * @param list<array{walletId: int, role: string, obligated: bool, sharePct: int}> $rows
	 * @param array<int, array{walletId: int, userId: string}> $consentSubjects
	 */
	private function assertRowsDayCapacity(int $houseId, string $dayKey, ScheduleRevision $revision, array $rows, array &$consentSubjects, ?Occurrence $replaced = null): void {
		// The energy basis the writer will persist: the occurrence's own
		// frozen energy when a materialized occurrence is rearranged (the
		// capacity baseline charges occurrence energy, never mutable
		// task-definition energy), else the revision's agreed energy.
		$energy = ($replaced !== null && $replaced->getId() !== null)
			? $replaced->getEnergy()
			: $revision->getEnergy();
		$commitments = [];
		foreach ($rows as $row) {
			$userId = $consentSubjects[$row['walletId']]['userId'] ?? null;
			if ($userId === null) {
				continue;
			}
			// The member's exact share of the occurrence energy (hundredths).
			$commitments[] = [
				'userId' => $userId,
				'energyMinor' => max(1, (int)round($energy * $row['sharePct'])),
				'count' => 1,
			];
		}
		$this->dailyCapacity->assertCommitmentsFit($houseId, $dayKey, $commitments, $replaced);
	}

	/**
	 * Whole-day capacity gate for the CONCRETE current arrangement of an
	 * occurrence (plan §3.2/§3.3; RF-073-WP2-27): resolves the ACTIVE
	 * occurrence member rows under the house lock — a date-only amendment
	 * or a catch-up without a participant payload moves exactly the people
	 * and shares the occurrence writer will keep, never the revision
	 * defaults a single-occurrence handover replaced. The revision's
	 * default snapshot is consulted ONLY when deriving a virtual
	 * arrangement (no materialized rows). Released/excused historical rows
	 * are provenance, not a live allocation. Fills `$consentSubjects` with
	 * the actual affected responsible/joint people (helpers are capacity-
	 * checked but not consent subjects).
	 *
	 * @param array<int, array{walletId: int, userId: string}> $consentSubjects
	 */
	private function assertArrangedDayCapacity(int $houseId, string $dayKey, ScheduleRevision $revision, Occurrence $occurrence, array &$consentSubjects): void {
		if ($occurrence->getId() === null) {
			$this->assertRevisionDayCapacity($houseId, $dayKey, $revision, $consentSubjects, $occurrence);
			return;
		}
		$active = array_values(array_filter(
			array_map(
				static fn (OccurrenceMember $member): array => $member->jsonSerialize(),
				$this->occMemberMapper->findByOccurrence((int)$occurrence->getId())
			),
			static fn (array $row): bool => (string)($row['disposition'] ?? OccurrenceMember::DISPOSITION_ACTIVE) === OccurrenceMember::DISPOSITION_ACTIVE
		));
		if ($active === []) {
			$this->assertRevisionDayCapacity($houseId, $dayKey, $revision, $consentSubjects, $occurrence);
			return;
		}
		$userIdByWallet = [];
		foreach ($this->houseUserMapper->findByHouseId($houseId) as $member) {
			if ($member->getWalletId() !== null) {
				$userIdByWallet[(int)$member->getWalletId()] = $member->getNextcloudUserId();
			}
		}
		$energy = $occurrence->getEnergy();
		$commitments = [];
		foreach ($active as $row) {
			$walletId = (int)$row['walletId'];
			$userId = $userIdByWallet[$walletId] ?? null;
			$share = max(0, (int)($row['sharePct'] ?? 100));
			if ($userId === null || $share === 0) {
				continue;
			}
			$commitments[] = [
				'userId' => $userId,
				'energyMinor' => max(1, (int)round($energy * $share)),
				'count' => 1,
			];
			$role = (string)($row['role'] ?? OccurrenceMember::ROLE_RESPONSIBLE);
			if ((bool)($row['obligated'] ?? true) && $role !== OccurrenceMember::ROLE_HELPER) {
				$consentSubjects[$walletId] = ['walletId' => $walletId, 'userId' => $userId];
			}
		}
		$this->dailyCapacity->assertCommitmentsFit($houseId, $dayKey, $commitments, $occurrence);
	}

	/**
	 * Re-derive the effective suspension of an occurrence whose member
	 * rows were just replaced by an explicit agreement (R6: the next
	 * GET/job must agree with the accepted arrangement) and persist the
	 * stamp when it drifted. No-op for virtual rows; caller holds the
	 * house-first transaction.
	 */
	private function restampSuspensionAfterMemberWrite(int $houseId, Occurrence $occurrence, ScheduleRevision $revision, string $stateBefore, string $evidenceBefore): void {
		$this->occurrenceService->stampProjection($houseId, $occurrence, $revision);
		if ($occurrence->getSuspensionState() !== $stateBefore
			|| json_encode($occurrence->getSuspensionEvidence() ?? null) !== $evidenceBefore) {
			$this->occurrenceService->persistSuspensionStampUnderLock($occurrence);
		}
	}

	/**
	 * Whole-day capacity gate for the revision's current committed
	 * members on the candidate day (energy share + child limits +
	 * pause), plan §3.2. RF-073-WP2-07: concretely agreed helper
	 * commitments are checked with their exact share too. Fills
	 * `$consentSubjects` with the affected obligated people (the consent
	 * evidence set — helpers are checked, but the attestation covers the
	 * responsible/joint subjects).
	 *
	 * @param array<int, array{walletId: int, userId: string}> $consentSubjects
	 */
	private function assertRevisionDayCapacity(int $houseId, string $dayKey, ScheduleRevision $revision, array &$consentSubjects, ?Occurrence $replaced = null): void {
		$defaults = is_array($revision->getDefaultMembers()) ? $revision->getDefaultMembers() : [];
		if ($defaults === []) {
			return;
		}
		$energy = $revision->getEnergy();
		$commitments = [];
		foreach ($defaults as $member) {
			$userId = (string)($member['userId'] ?? '');
			if ($userId === '') {
				continue;
			}
			$walletId = (int)($member['walletId'] ?? 0);
			$commitments[] = [
				'userId' => $userId,
				'energyMinor' => max(1, $energy * (int)($member['sharePct'] ?? 100)),
				'count' => 1,
			];
			if ($member['obligated'] ?? true) {
				$consentSubjects[$walletId] = ['walletId' => $walletId, 'userId' => $userId];
			}
		}
		$this->dailyCapacity->assertCommitmentsFit($houseId, $dayKey, $commitments, $replaced);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function resultRefs(Occurrence $occurrence): array {
		return [
			'occurrenceId' => $occurrence->toPublicKey(),
			'expectedVersion' => $occurrence->getVersion(),
			'plannedDate' => $occurrence->getPlannedDate()?->format('Y-m-d'),
		];
	}
}
