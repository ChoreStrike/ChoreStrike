<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\Agreement;
use OCA\ChoreStrike\Db\AgreementMapper;
use OCA\ChoreStrike\Db\DigestChange;
use OCA\ChoreStrike\Db\DigestChangeMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\Pause;
use OCA\ChoreStrike\Db\PauseMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\IL10N;

/**
 * Command owner of the revisioned pause lifecycle (CS-PLAN-073 §3.4).
 *
 * Commands run as ONE `CommandService` command each (house lock first,
 * receipt replay per UUID): personal pause request/acknowledgment,
 * household pause, explicit owner approve/reject, explicit
 * retrospective-illness recognition and the next-day shortening/
 * revocation. Every mutation then persists the derived neutral
 * suspension effects idempotently against the pause revision
 * ({@see applyPauseEffectsUnderLock}) — materialized rows carry the
 * state, the write-free projection (PauseProjectionService) keeps
 * virtual rows and stopped-cron reads consistent without writes.
 *
 * Frozen rules (plan §3.4): pending/rejected requests have no execution
 * effect; expired requests stay pending until an authorized decision;
 * shortening is effective no earlier than the NEXT local day of the
 * pause's saved zone while today's and earlier recognized excuses are
 * preserved (the retained old revision keeps covering them); ordinary
 * approval never excuses an expired miss retroactively — only the
 * explicit retrospective-illness action does. No free text, no synthetic
 * completions, no automatic redistribution.
 */
class PauseService {
	/** Stable action identifiers of the pause commands. */
	public const ACTION_REQUEST = 'pause.request';
	public const ACTION_HOUSE = 'pause.house';
	public const ACTION_APPROVE = 'pause.approve';
	public const ACTION_REJECT = 'pause.reject';
	public const ACTION_RECOGNIZE_ILLNESS = 'pause.recognize-illness';
	public const ACTION_SHORTEN = 'pause.shorten';

	private CommandService $commands;
	private PauseMapper $pauseMapper;
	private PauseProjectionService $projection;
	private HouseMapper $houseMapper;
	private HouseUserMapper $houseUserMapper;
	private WalletMapper $walletMapper;
	private OccurrenceMapper $occurrenceMapper;
	private OccurrenceMemberMapper $occMemberMapper;
	private AgreementMapper $agreementMapper;
	private TransactionExecutor $transactions;
	private CalendarService $calendar;
	private DigestChangeMapper $digestChangeMapper;
	private DigestScheduleService $digestScheduleService;
	private IL10N $l10n;

	public function __construct(
		CommandService $commands,
		PauseMapper $pauseMapper,
		PauseProjectionService $projection,
		HouseMapper $houseMapper,
		HouseUserMapper $houseUserMapper,
		WalletMapper $walletMapper,
		OccurrenceMapper $occurrenceMapper,
		OccurrenceMemberMapper $occMemberMapper,
		AgreementMapper $agreementMapper,
		TransactionExecutor $transactions,
		CalendarService $calendar,
		DigestChangeMapper $digestChangeMapper,
		DigestScheduleService $digestScheduleService,
		IL10N $l10n,
	) {
		$this->commands = $commands;
		$this->pauseMapper = $pauseMapper;
		$this->projection = $projection;
		$this->houseMapper = $houseMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->walletMapper = $walletMapper;
		$this->occurrenceMapper = $occurrenceMapper;
		$this->occMemberMapper = $occMemberMapper;
		$this->agreementMapper = $agreementMapper;
		$this->transactions = $transactions;
		$this->calendar = $calendar;
		$this->digestChangeMapper = $digestChangeMapper;
		$this->digestScheduleService = $digestScheduleService;
		$this->l10n = $l10n;
	}

	/**
	 * The viewer-safe pause list (bounded). Personal pending requests are
	 * self/owner data, and the personal reason plus the retrospective-
	 * illness marker stay self/owner-only in EVERY state (plan §3.5,
	 * RF-073-WP2-28) — other members see only the neutral shared facts
	 * of the recognized/cancelled pauses. House-scope pauses are
	 * household data and stay fully visible.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listForViewer(int $houseId, string $viewerUserId): array {
		$members = [];
		$userByWallet = [];
		foreach ($this->houseUserMapper->findByHouseId($houseId) as $member) {
			$members[$member->getNextcloudUserId()] = $member;
			if ($member->getWalletId() !== null) {
				$userByWallet[(int)$member->getWalletId()] = $member->getNextcloudUserId();
			}
		}
		$viewer = $members[$viewerUserId] ?? null;
		if ($viewer === null) {
			throw new ForbiddenException($this->l10n->t('Not a member of this house'));
		}
		$isOwner = $viewer->isOwner();

		// One pass over the ordered revision history: the highest revision
		// per group is the current projection (no per-row re-query).
		$revisions = $this->pauseMapper->findByHouseId($houseId);
		$latestByGroup = [];
		foreach ($revisions as $pause) {
			$latestByGroup[$pause->getPauseGroupId()] = $pause;
		}

		$result = [];
		foreach ($latestByGroup as $pause) {
			$subjectUserId = null;
			if ($pause->getScope() === Pause::SCOPE_PERSONAL) {
				$subjectUserId = $userByWallet[(int)($pause->getSubjectWalletId() ?? 0)] ?? null;
			}
			$pendingPersonal = $pause->getState() === Pause::STATE_REQUESTED
				&& $pause->getScope() === Pause::SCOPE_PERSONAL;
			if ($pendingPersonal && !$isOwner && $subjectUserId !== $viewerUserId) {
				continue;
			}
			// RF-073-WP2-28: an EXPLICIT field whitelist, never the entity
			// serialized wholesale. The personal reason and the
			// retrospective-illness marker are self/owner data (plan
			// §3.5) — an unrelated member receives only the approved
			// neutral shared facts, in every pause state. A departed or
			// erased subject resolves to no user id and therefore never
			// regains personal metadata through its retained wallet.
			$entry = [
				'pauseGroupId' => $pause->getPauseGroupId(),
				'revision' => $pause->getRevision(),
				'scope' => $pause->getScope(),
				'subjectWalletId' => $pause->getSubjectWalletId(),
				'subjectUserId' => $subjectUserId,
				'state' => $pause->getState(),
				'startDate' => $pause->getStartDate()?->format('Y-m-d'),
				'endDate' => $pause->getEndDate()?->format('Y-m-d'),
				'timezone' => $pause->getTimezone(),
				'createdAt' => $pause->getCreatedAt()?->format('c'),
				'updatedAt' => $pause->getUpdatedAt()?->format('c'),
			];
			$isSelf = $subjectUserId !== null && $subjectUserId === $viewerUserId;
			if ($pause->getScope() === Pause::SCOPE_HOUSE || $isOwner || $isSelf) {
				$entry['reasonCode'] = $pause->getReasonCode();
				$entry['isRetrospectiveIllness'] = $pause->getIsRetrospectiveIllness();
			}
			$result[] = $entry;
		}
		usort($result, static fn (array $a, array $b): int => $a['pauseGroupId'] <=> $b['pauseGroupId']);
		return $result;
	}

	/**
	 * Personal pause for the CALLER (any member): children create a
	 * pending `requested` request; adults (owner/member) create an
	 * `acknowledged` pause directly (plan §3.4 table). Pending only —
	 * no changed duty, deadline, capacity or goal eligibility.
	 *
	 * Payload: {commandId, startDate, endDate, reasonCode}.
	 *
	 * @return array<string, mixed>
	 */
	public function requestForSelf(int $houseId, string $actorUserId, array $payload): array {
		$actor = $this->assertMember($houseId, $actorUserId);
		$validated = $this->validateRangePayload($payload, self::ACTION_REQUEST);

		['result' => $refs] = $this->commands->runCommand(
			$houseId,
			(int)$actor->getId(),
			$validated['commandId'],
			self::ACTION_REQUEST,
			$validated['receipt'],
			function () use ($houseId, &$actor, $validated): array {
				$state = $actor->isOwner() || $actor->getRole() === 'member' ? Pause::STATE_ACKNOWLEDGED : Pause::STATE_REQUESTED;
				$wallet = $this->requireWallet($houseId, $actor->getNextcloudUserId());
				$pause = new Pause();
				$pause->setHouseId($houseId);
				$pause->setPauseGroupId($this->nextGroupId());
				$pause->setScope(Pause::SCOPE_PERSONAL);
				$pause->setSubjectWalletId((int)$wallet->getId());
				$pause->setRequesterHouseUserId((int)$actor->getId());
				$pause->setState($state);
				$pause->setReasonCode($validated['reasonCode']);
				$this->applyRange($pause, $validated, $this->houseZone($houseId));
				$pause = $this->pauseMapper->appendRevision($pause);
				$this->applyPauseEffectsUnderLock($houseId);
				return ['pauseGroupId' => $pause->getPauseGroupId(), 'revision' => $pause->getRevision(), 'state' => $pause->getState()];
			},
			$this->actorGuard($actor)
		);
		return $refs;
	}

	/**
	 * Household pause (current owner only): acknowledged house scope,
	 * same neutral rules, no new access to private tasks.
	 *
	 * Payload: {commandId, startDate, endDate, reasonCode}.
	 *
	 * @return array<string, mixed>
	 */
	public function createHousePause(int $houseId, string $actorUserId, array $payload): array {
		$actor = $this->assertOwner($houseId, $actorUserId);
		$validated = $this->validateRangePayload($payload, self::ACTION_HOUSE);

		['result' => $refs] = $this->commands->runCommand(
			$houseId,
			(int)$actor->getId(),
			$validated['commandId'],
			self::ACTION_HOUSE,
			$validated['receipt'],
			function () use ($houseId, $actor, $validated): array {
				$pause = new Pause();
				$pause->setHouseId($houseId);
				$pause->setPauseGroupId($this->nextGroupId());
				$pause->setScope(Pause::SCOPE_HOUSE);
				$pause->setRequesterHouseUserId((int)$actor->getId());
				$pause->setDeciderHouseUserId((int)$actor->getId());
				$pause->setState(Pause::STATE_ACKNOWLEDGED);
				$pause->setReasonCode($validated['reasonCode']);
				$this->applyRange($pause, $validated, $this->houseZone($houseId));
				$pause = $this->pauseMapper->appendRevision($pause);
				$this->applyPauseEffectsUnderLock($houseId);
				// WP5 (C5m): a household pause affects every member —
				// transactional queue write for the opted-in digest.
				$this->queuePauseChange($houseId, (int)$actor->getId(), $pause, new \DateTime());
				return ['pauseGroupId' => $pause->getPauseGroupId(), 'revision' => $pause->getRevision(), 'state' => $pause->getState()];
			},
			$this->actorGuard($actor, true)
		);
		return $refs;
	}

	/**
	 * Explicit owner decision on a pending request (approve).
	 *
	 * Payload: {commandId, expectedRevision}.
	 *
	 * @return array<string, mixed>
	 */
	public function approve(int $houseId, string $actorUserId, int $pauseGroupId, array $payload): array {
		return $this->decide($houseId, $actorUserId, $pauseGroupId, $payload, self::ACTION_APPROVE, Pause::STATE_ACKNOWLEDGED);
	}

	/**
	 * Explicit owner decision on a pending request (reject). No automatic
	 * deadline extension; ordinary approval/rejection never excuses an
	 * expired miss.
	 *
	 * Payload: {commandId, expectedRevision}.
	 *
	 * @return array<string, mixed>
	 */
	public function reject(int $houseId, string $actorUserId, int $pauseGroupId, array $payload): array {
		return $this->decide($houseId, $actorUserId, $pauseGroupId, $payload, self::ACTION_REJECT, Pause::STATE_REJECTED);
	}

	/**
	 * Explicit retrospective-illness recognition (owner only): creates an
	 * acknowledged personal pause with the audit marker that may
	 * neutralize qualifying unfulfilled HISTORICAL obligations. Not an
	 * ordinary backdated amendment and not a fabricated completion.
	 *
	 * Payload: {commandId, subjectUserId, startDate, endDate}.
	 *
	 * @return array<string, mixed>
	 */
	public function recognizeIllness(int $houseId, string $actorUserId, array $payload): array {
		$actor = $this->assertOwner($houseId, $actorUserId);
		$subjectUserId = $payload['subjectUserId'] ?? null;
		if (!is_string($subjectUserId) || trim($subjectUserId) === '') {
			throw new \InvalidArgumentException($this->l10n->t('A subjectUserId is required'));
		}
		$validated = $this->validateRangePayload($payload, self::ACTION_RECOGNIZE_ILLNESS, ['subjectUserId']);
		$validated['subjectUserId'] = $subjectUserId;
		// The subject is part of the command identity: a reused UUID with a
		// different subject must conflict, never replay the original.
		$validated['receipt']['subjectUserId'] = $subjectUserId;

		['result' => $refs] = $this->commands->runCommand(
			$houseId,
			(int)$actor->getId(),
			$validated['commandId'],
			self::ACTION_RECOGNIZE_ILLNESS,
			$validated['receipt'],
			function () use ($houseId, $actor, $validated): array {
				// A foreign/unknown illness subject is the §3.5 404 class
				// (invisible or foreign target — never a 409 that confirms
				// the account exists; RF-073-WP2-18).
				if ($this->houseUserMapper->findByHouseAndUser($houseId, $validated['subjectUserId']) === null) {
					throw new NotFoundException($this->l10n->t('User not found'));
				}
				$wallet = $this->requireWallet($houseId, $validated['subjectUserId']);
				$pause = new Pause();
				$pause->setHouseId($houseId);
				$pause->setPauseGroupId($this->nextGroupId());
				$pause->setScope(Pause::SCOPE_PERSONAL);
				$pause->setSubjectWalletId((int)$wallet->getId());
				$pause->setRequesterHouseUserId((int)$actor->getId());
				$pause->setDeciderHouseUserId((int)$actor->getId());
				$pause->setState(Pause::STATE_ACKNOWLEDGED);
				$pause->setReasonCode(Pause::REASON_ILLNESS);
				$pause->setIsRetrospectiveIllness(true);
				$this->applyRange($pause, $validated, $this->houseZone($houseId));
				$pause = $this->pauseMapper->appendRevision($pause);
				$this->applyPauseEffectsUnderLock($houseId);
				// WP5 (C5m): retrospective recognition is a pause decision
				// affecting the subject — transactional queue write.
				$this->queuePauseChange($houseId, (int)$actor->getId(), $pause, new \DateTime());
				return ['pauseGroupId' => $pause->getPauseGroupId(), 'revision' => $pause->getRevision(), 'state' => $pause->getState()];
			},
			$this->actorGuard($actor, true)
		);
		return $refs;
	}

	/**
	 * Shorten / revoke an acknowledged pause (plan §3.4): appends a new
	 * revision whose coverage ends no earlier than TODAY in the pause's
	 * saved zone — today's and earlier recognized excuses are preserved
	 * by the retained old revision. Adult self and house-scope owner
	 * append an `acknowledged` revision directly; a child's change
	 * creates a pending revision that requires the owner decision.
	 *
	 * Payload: {commandId, endDate, expectedRevision}.
	 *
	 * @return array<string, mixed>
	 */
	public function shorten(int $houseId, string $actorUserId, int $pauseGroupId, array $payload): array {
		$actor = $this->assertMember($houseId, $actorUserId);
		$expectedRevision = $payload['expectedRevision'] ?? null;
		if (!is_numeric($expectedRevision) || (int)$expectedRevision < 1) {
			throw new \InvalidArgumentException($this->l10n->t('A valid expectedRevision is required'));
		}
		$commandId = $payload['commandId'] ?? null;
		if (!is_string($commandId) || trim($commandId) === '' || strlen($commandId) > 36) {
			throw new \InvalidArgumentException($this->l10n->t('A valid commandId (UUID) is required'));
		}
		$newEnd = $payload['endDate'] ?? null;
		if (!is_string($newEnd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newEnd)
			|| \DateTime::createFromFormat('Y-m-d', $newEnd)->format('Y-m-d') !== $newEnd) {
			throw new \InvalidArgumentException($this->l10n->t('endDate must be a valid Y-m-d date'));
		}
		$receipt = [
			'pauseGroupId' => $pauseGroupId,
			'expectedRevision' => (int)$expectedRevision,
			'endDate' => $newEnd,
			'commandId' => strtolower($commandId),
		];
		$expectedRevisionInt = (int)$expectedRevision;

		['result' => $refs] = $this->commands->runCommand(
			$houseId,
			(int)$actor->getId(),
			strtolower($commandId),
			self::ACTION_SHORTEN,
			$receipt,
			function () use ($houseId, &$actor, $pauseGroupId, $expectedRevisionInt, $newEnd): array {
				$current = $this->pauseMapper->findCurrentByGroup($pauseGroupId);
				if ($current->getHouseId() !== $houseId) {
					throw new NotFoundException($this->l10n->t('Pause not found'));
				}
				if ($current->getRevision() !== $expectedRevisionInt) {
					throw new ConflictException($this->l10n->t('The pause changed since it was displayed. Reload and try again.'));
				}
				// Role denial is VISIBLE (403 before the state conflict):
				// personal pauses — the subject shortens their own, the
				// owner may shorten anyone's; house scope is owner-only.
				$isOwner = $actor->isOwner();
				$this->assertCanShortenPause($houseId, $actor, $current);
				// Plan §3.4 row 6 scopes shortening/revocation to
				// ACKNOWLEDGED pauses (RF-073-WP2-18): a pending request
				// carries no recognized effect to shorten — it is decided
				// (approve/reject), not shortened.
				if ($current->getState() !== Pause::STATE_ACKNOWLEDGED) {
					throw new ConflictException($this->l10n->t('Only an acknowledged pause can be shortened'));
				}

				$zone = $current->getTimezone();
				$todayKey = $this->calendar->dayKey(new \DateTime(), new \DateTimeZone($zone));
				// Next-day boundary: never cut today's or earlier
				// recognized excuses (plan §3.4).
				$effectiveEnd = max($newEnd, $todayKey);
				$endKey = $current->getEndDate()->format('Y-m-d');
				$startKey = $current->getStartDate()->format('Y-m-d');
				if ($effectiveEnd >= $endKey) {
					throw new ConflictException($this->l10n->t('The pause already covers no later date'));
				}
				if ($effectiveEnd < $startKey) {
					throw new ConflictException($this->l10n->t('The shortened pause would lose its start date'));
				}

				// Child-requested shortenings stay pending until the owner
				// acknowledges them (plan §3.4 row 6).
				$isChild = $actor->getRole() === 'child';
				$state = ($isChild && !$isOwner) ? Pause::STATE_REQUESTED : Pause::STATE_ACKNOWLEDGED;
				$revision = new Pause();
				$revision->setHouseId($houseId);
				$revision->setPauseGroupId($pauseGroupId);
				$revision->setScope($current->getScope());
				$revision->setSubjectWalletId($current->getSubjectWalletId());
				$revision->setRequesterHouseUserId((int)$actor->getId());
				$revision->setDeciderHouseUserId(!$isChild || $isOwner ? (int)$actor->getId() : null);
				$revision->setState($state);
				$revision->setReasonCode($current->getReasonCode());
				$revision->setIsRetrospectiveIllness($current->getIsRetrospectiveIllness());
				$revision->setStartDate($current->getStartDate());
				$revision->setEndDate(\DateTime::createFromFormat('Y-m-d', $effectiveEnd) ?: $current->getEndDate());
				$revision->setTimezone($zone);
				$now = new \DateTime();
				$revision->setCreatedAt($now);
				$revision->setUpdatedAt($now);
				$revision = $this->pauseMapper->appendRevision($revision);
				$this->applyPauseEffectsUnderLock($houseId);
				// WP5 (C5m): an acknowledged shortening/cancellation is a
				// pause decision affecting the subject — transactional
				// queue write (a pending child revision is a request, not
				// a decision).
				if ($revision->getState() === Pause::STATE_ACKNOWLEDGED) {
					$this->queuePauseChange($houseId, (int)$actor->getId(), $revision, $now);
				}
				return ['pauseGroupId' => $pauseGroupId, 'revision' => $revision->getRevision(), 'state' => $revision->getState()];
			},
			$this->actorGuard($actor, false, $pauseGroupId)
		);
		return $refs;
	}

	// ------------------------------------------------------------------
	// Effects, departure and job entry
	// ------------------------------------------------------------------

	/**
	 * Queue the organizational digest change for a pause decision
	 * (CS-PLAN-070 WP5 §3.3, C5m): personal pauses affect their subject,
	 * household pauses affect every member. The deciding actor's own row
	 * is skipped. Identity rows only (pause group + revision); rights
	 * and relevance are rechecked at dispatch. Runs INSIDE the owning
	 * command transaction (the house lock is already held).
	 */
	private function queuePauseChange(int $houseId, int $actorHouseUserId, Pause $pause, \DateTimeInterface $now): void {
		$recipients = [];
		if ($pause->getScope() === Pause::SCOPE_PERSONAL) {
			$subject = $this->houseUserMapper->findByWalletId((int)($pause->getSubjectWalletId() ?? 0));
			if ($subject !== null) {
				$recipients[] = $subject;
			}
		} else {
			$recipients = $this->houseUserMapper->findByHouseId($houseId);
		}

		foreach ($recipients as $member) {
			if ((int)$member->getId() === $actorHouseUserId) {
				continue;
			}
			$change = new DigestChange();
			$change->setHouseId($houseId);
			$change->setHouseUserId((int)$member->getId());
			$change->setObjectType('pause');
			$change->setObjectId((string)$pause->getPauseGroupId());
			$change->setObjectRevision($pause->getRevision());
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
	 * Q-073-03 departure rule (called from the membership-removal
	 * aggregate UNDER LOCK): pending personal requests of the departing
	 * member are cancelled; already neutral history is preserved; future
	 * personal-pause effects stop through the projection's current-
	 * membership filter. Rejoin requires a new pause under current roles.
	 */
	public function handleMembershipRemovedUnderLock(int $houseId, int $walletId): void {
		$this->pauseMapper->cancelPendingPersonalForSubject($houseId, $walletId);
		$this->applyPauseEffectsUnderLock($houseId);
	}

	/**
	 * TimelineJob entry (no fabricated actor, no receipts): converge the
	 * persisted suspension facts with the projection after materialized
	 * rows changed behind any mutation. Bounded by the open-row set.
	 *
	 * @return array{houses: int, effects: int}
	 */
	public function reconcilePauseEffectsForJobs(?\DateTime $now = null): array {
		$house = $this->houseMapper->findOneForJobs();
		if ($house === null) {
			return ['houses' => 0, 'effects' => 0];
		}
		return $this->transactions->run(function () use ($house): array {
			$this->houseMapper->findForUpdate((int)$house->getId());
			$effects = $this->applyPauseEffectsUnderLock((int)$house->getId());
			return ['houses' => 1, 'effects' => $effects];
		});
	}

	/**
	 * Persist the derived neutral-suspension facts for every open
	 * scheduled occurrence of the house (idempotent: a diff-free pass
	 * writes nothing; a changed occurrence records ONE KIND_PAUSE
	 * evidence row against the covering pause). CALLER HOLDS the
	 * house-first transaction.
	 *
	 * @return int Changed occurrence count
	 */
	public function applyPauseEffectsUnderLock(int $houseId): int {
		$recognized = $this->projection->recognizedForHouse($houseId);
		$currentWallets = $this->projection->currentWalletIds($houseId);
		$changed = 0;

		foreach ($this->occurrenceMapper->findOpenByHouseId($houseId) as $occurrence) {
			$plannedKey = $occurrence->getPlannedDate()?->format('Y-m-d');
			if ($plannedKey === null) {
				continue;
			}
			$members = $this->occurrenceMembersOf($houseId, $occurrence);
			// R3/RF-073-WP2-25: the persistent effects use the SAME
			// recognition-eligibility decision as the virtual projection —
			// an ordinary acknowledgment after the original exclusive
			// deadline never neutralizes an expired miss.
			$excused = PauseProjectionService::excusedWalletsOn($recognized, $plannedKey, $currentWallets, $occurrence->getWindowEnd());
			$decision = PauseProjectionService::deriveSuspension($members, $excused);

			$newState = $decision['suspended'] ? Occurrence::SUSPENSION_SUSPENDED : Occurrence::SUSPENSION_NONE;
			$newEvidence = $decision['suspended'] ? array_values($decision['evidence']) : null;
			$oldState = $occurrence->getSuspensionState();
			$oldEvidence = $occurrence->getSuspensionEvidence();
			if ($oldState === $newState && json_encode($oldEvidence ?? null) === json_encode($newEvidence)) {
				continue;
			}

			$occurrence->setSuspensionState($newState);
			$occurrence->setSuspensionEvidence($newEvidence);
			$occurrence->setVersion($occurrence->getVersion() + 1);
			$this->occurrenceMapper->update($occurrence);
			$changed++;

			// RF-073-WP2-18: every covering pause of a union suspension
			// gets its OWN evidence row with its pause_ref — the union
			// provenance is referenceable per pause, not only inside the
			// first entry's JSON.
			$provenance = $decision['suspended'] ? $decision['evidence'] : [];
			if ($provenance === []) {
				$provenance = [['pauseGroupId' => null]];
			}
			foreach ($provenance as $entry) {
				$this->agreementMapper->record(
					(int)$occurrence->getId(),
					Agreement::KIND_PAUSE,
					null,
					$decision['excusedWalletIds'],
					['suspensionState' => $oldState, 'suspensionEvidence' => $oldEvidence],
					['suspensionState' => $newState, 'suspensionEvidence' => $newEvidence],
					null,
					$entry['pauseGroupId']
				);
			}
		}
		return $changed;
	}

	// ------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------

	private function decide(int $houseId, string $actorUserId, int $pauseGroupId, array $payload, string $action, string $decisionState): array {
		$actor = $this->assertOwner($houseId, $actorUserId);
		$expectedRevision = $payload['expectedRevision'] ?? null;
		if (!is_numeric($expectedRevision) || (int)$expectedRevision < 1) {
			throw new \InvalidArgumentException($this->l10n->t('A valid expectedRevision is required'));
		}
		$commandId = $payload['commandId'] ?? null;
		if (!is_string($commandId) || trim($commandId) === '' || strlen($commandId) > 36) {
			throw new \InvalidArgumentException($this->l10n->t('A valid commandId (UUID) is required'));
		}
		$receipt = [
			'pauseGroupId' => $pauseGroupId,
			'expectedRevision' => (int)$expectedRevision,
			'decision' => $decisionState,
			'commandId' => strtolower($commandId),
		];
		$expectedRevisionInt = (int)$expectedRevision;

		['result' => $refs] = $this->commands->runCommand(
			$houseId,
			(int)$actor->getId(),
			strtolower($commandId),
			$action,
			$receipt,
			function () use ($houseId, $actor, $pauseGroupId, $expectedRevisionInt, $decisionState): array {
				$current = $this->pauseMapper->findCurrentByGroup($pauseGroupId);
				if ($current->getHouseId() !== $houseId) {
					throw new NotFoundException($this->l10n->t('Pause not found'));
				}
				if ($current->getRevision() !== $expectedRevisionInt) {
					throw new ConflictException($this->l10n->t('The pause changed since it was displayed. Reload and try again.'));
				}
				if ($current->getState() !== Pause::STATE_REQUESTED) {
					throw new ConflictException($this->l10n->t('Only a pending pause request can be decided'));
				}
				// The decision stamps the pending revision in place — a
				// decision is not a new request (the revision history
				// keeps the requested row as evidence via updated_at).
				$current->setState($decisionState);
				$current->setDeciderHouseUserId((int)$actor->getId());
				$current->setUpdatedAt(new \DateTime());
				$this->pauseMapper->update($current);
				$this->applyPauseEffectsUnderLock($houseId);
				// WP5 (C5m): transactional queue write — the subject
				// learns about the owner decision through their opted-in
				// digest, never through a bell.
				$this->queuePauseChange($houseId, (int)$actor->getId(), $current, new \DateTime());
				return ['pauseGroupId' => $pauseGroupId, 'revision' => $current->getRevision(), 'state' => $current->getState()];
			},
			$this->actorGuard($actor, true, $pauseGroupId)
		);
		return $refs;
	}

	/**
	 * @param list<string> $allowedExtra
	 * @return array{commandId: string, receipt: array<string, mixed>, startDate: string, endDate: string, reasonCode: string}
	 */
	private function validateRangePayload(array $payload, string $action, array $allowedExtra = []): array {
		$allowed = array_merge(['commandId', 'startDate', 'endDate', 'reasonCode'], $allowedExtra);
		foreach (array_keys($payload) as $key) {
			if (!in_array($key, $allowed, true)) {
				throw new \InvalidArgumentException($this->l10n->t('Unknown field: %1$s', [(string)$key]));
			}
		}
		$commandId = $payload['commandId'] ?? null;
		if (!is_string($commandId) || trim($commandId) === '' || strlen($commandId) > 36) {
			throw new \InvalidArgumentException($this->l10n->t('A valid commandId (UUID) is required'));
		}
		$start = $payload['startDate'] ?? null;
		$end = $payload['endDate'] ?? null;
		if (!is_string($start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)
			|| \DateTime::createFromFormat('Y-m-d', $start)->format('Y-m-d') !== $start) {
			throw new \InvalidArgumentException($this->l10n->t('startDate must be a valid Y-m-d date'));
		}
		if (!is_string($end) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)
			|| \DateTime::createFromFormat('Y-m-d', $end)->format('Y-m-d') !== $end) {
			throw new \InvalidArgumentException($this->l10n->t('endDate must be a valid Y-m-d date'));
		}
		if ($start > $end) {
			throw new \InvalidArgumentException($this->l10n->t('startDate must not be after endDate'));
		}
		$reasonCode = $payload['reasonCode'] ?? Pause::REASON_OTHER;
		if (!in_array($reasonCode, Pause::REASON_CODES, true)) {
			throw new \InvalidArgumentException($this->l10n->t('Unknown reason code: %1$s', [(string)$reasonCode]));
		}
		return [
			'commandId' => strtolower($commandId),
			'receipt' => [
				'startDate' => $start,
				'endDate' => $end,
				'reasonCode' => $reasonCode,
			],
			'startDate' => $start,
			'endDate' => $end,
			'reasonCode' => $reasonCode,
		];
	}

	private function applyRange(Pause $pause, array $validated, string $zone): void {
		$pause->setStartDate(\DateTime::createFromFormat('Y-m-d', $validated['startDate']) ?: new \DateTime());
		$pause->setEndDate(\DateTime::createFromFormat('Y-m-d', $validated['endDate']) ?: new \DateTime());
		$pause->setTimezone($zone);
		$now = new \DateTime();
		$pause->setCreatedAt($now);
		$pause->setUpdatedAt($now);
	}

	private function nextGroupId(): int {
		return $this->pauseMapper->maxGroupId();
	}

	/** Runs before receipt lookup; only current authority, never pause state/revision. */
	private function actorGuard(HouseUser &$actor, bool $ownerOnly = false, ?int $pauseGroupId = null): \Closure {
		return function (int $houseId, int $actorId) use (&$actor, $ownerOnly, $pauseGroupId): void {
			$fresh = $this->assertMember($houseId, $actor->getNextcloudUserId());
			if ((int)$fresh->getId() !== $actorId) {
				throw new ForbiddenException($this->l10n->t('Not a member of this house'));
			}
			if ($ownerOnly && !$fresh->isOwner()) {
				throw new ForbiddenException($this->l10n->t('Owner role required'));
			}
			if ($pauseGroupId !== null) {
				try {
					$pause = $this->pauseMapper->findCurrentByGroup($pauseGroupId);
				} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
					throw new NotFoundException($this->l10n->t('Pause not found'));
				}
				if ($pause->getHouseId() !== $houseId) {
					throw new NotFoundException($this->l10n->t('Pause not found'));
				}
				if (!$ownerOnly) {
					$this->assertCanShortenPause($houseId, $fresh, $pause);
				}
			}
			$actor = $fresh;
		};
	}

	private function assertCanShortenPause(int $houseId, HouseUser $actor, Pause $pause): void {
		if ($actor->isOwner()) {
			return;
		}
		$subjectUserId = $pause->getScope() === Pause::SCOPE_PERSONAL
			? $this->userIdOfWallet($houseId, (int)($pause->getSubjectWalletId() ?? 0)) : null;
		if ($subjectUserId !== $actor->getNextcloudUserId()) {
			throw new ForbiddenException($this->l10n->t('Owner role required'));
		}
	}

	private function assertMember(int $houseId, string $userId): \OCA\ChoreStrike\Db\HouseUser {
		$member = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
		if ($member === null) {
			throw new ForbiddenException($this->l10n->t('Not a member of this house'));
		}
		return $member;
	}

	private function assertOwner(int $houseId, string $userId): \OCA\ChoreStrike\Db\HouseUser {
		$member = $this->assertMember($houseId, $userId);
		if (!$member->isOwner()) {
			throw new ForbiddenException($this->l10n->t('Owner role required'));
		}
		return $member;
	}

	private function requireWallet(int $houseId, string $userId): \OCA\ChoreStrike\Db\Wallet {
		$wallet = $this->walletMapper->findByHouseAndUser($houseId, $userId);
		if ($wallet === null) {
			throw new ConflictException($this->l10n->t('No wallet exists for this member'));
		}
		return $wallet;
	}

	private function userIdOfWallet(int $houseId, int $walletId): ?string {
		// Resolve through the CURRENT memberships: a departed subject
		// resolves to nobody (Q-073-03) and the read stays bounded by the
		// house member count.
		foreach ($this->houseUserMapper->findByHouseId($houseId) as $member) {
			if ((int)($member->getWalletId() ?? 0) === $walletId) {
				return $member->getNextcloudUserId();
			}
		}
		return null;
	}

	private function houseZone(int $houseId): string {
		return $this->calendar->getTimeZone($this->houseMapper->find($houseId))->getName();
	}

	/**
	 * Member snapshot facts of one occurrence (OccurrenceMember::jsonSerialize
	 * shapes) for the suspension derivation.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function occurrenceMembersOf(int $houseId, Occurrence $occurrence): array {
		return array_map(
			static fn (\OCA\ChoreStrike\Db\OccurrenceMember $member): array => $member->jsonSerialize(),
			$this->occMemberMapper->findByOccurrence((int)$occurrence->getId())
		);
	}
}
