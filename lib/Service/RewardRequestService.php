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
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MemberPreferenceMapper;
use OCA\ChoreStrike\Db\RewardItem;
use OCA\ChoreStrike\Db\RewardItemMapper;
use OCA\ChoreStrike\Db\RewardRequest;
use OCA\ChoreStrike\Db\RewardRequestEvent;
use OCA\ChoreStrike\Db\RewardRequestEventMapper;
use OCA\ChoreStrike\Db\RewardRequestMapper;
use OCA\ChoreStrike\Db\WalletEntryMapper;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Command owner of the C4 reward-request lifecycle (CS-PLAN-070 parent
 * §3.2; CS-PLAN-078 §3.2-§3.5).
 *
 * One `CommandService` command per mutation with the WP7 under-lock
 * authorization guard: fresh actor identity, visibility and CURRENT
 * action authority are re-checked on execution AND replay (a demoted
 * owner or departed requester can never replay an old receipt); the
 * business version/funding/state preconditions live in the aggregate.
 *
 * Reserve at request creation, settle only at separately confirmed
 * delivery, refund only an owner-attested false fulfillment — every
 * financial effect exactly once, keyed by request ID + effect revision.
 * Funding hold (`B < R` per wallet) is orthogonal: approval/cancellation
 * stay possible under it, ALL unfulfilled settlements and new requests
 * are blocked until coverage is restored. Time, funding hold, archive
 * and catalog disablement never expire or reopen requests.
 *
 * Lock order (house-first aggregate): house → actor membership → wallet
 * (read, then locked before any request row) → reward request/event rows
 * → preference/digest rows. Audit: every effect appends one immutable
 * event (unique request + revision) and bumps the request version in the
 * same transaction as wallet, ledger, house version, receipt and digest
 * queue writes.
 */
class RewardRequestService {
	public const SCOPE_MINE = 'mine';
	public const SCOPE_HOUSE = 'house';

	/** Stable command action identifiers. */
	public const ACTION_CREATE = 'reward.request.create';
	public const ACTION_APPROVE = 'reward.request.approve';
	public const ACTION_REJECT = 'reward.request.reject';
	public const ACTION_CANCEL = 'reward.request.cancel';
	public const ACTION_FULFILL = 'reward.request.fulfill';
	public const ACTION_CORRECT = 'reward.request.correct';

	/** Closed reason-code set (bounded codes, never personal free text). */
	public const REASON_NOT_NEEDED = 'not_needed';
	public const REASON_CHANGED_MIND = 'changed_mind';
	public const REASON_UNAVAILABLE = 'unavailable';
	public const REASON_DUPLICATE = 'duplicate';
	public const REASON_NOT_DELIVERED = 'not_delivered';
	public const REASON_OTHER = 'other';
	public const REASONS = [
		self::REASON_NOT_NEEDED,
		self::REASON_CHANGED_MIND,
		self::REASON_UNAVAILABLE,
		self::REASON_DUPLICATE,
		self::REASON_NOT_DELIVERED,
		self::REASON_OTHER,
	];

	private CommandService $commands;
	private RewardRequestMapper $requestMapper;
	private RewardRequestEventMapper $eventMapper;
	private RewardItemMapper $itemMapper;
	private HouseMapper $houseMapper;
	private HouseUserMapper $houseUserMapper;
	private WalletMapper $walletMapper;
	private WalletEntryMapper $walletEntryMapper;
	private WalletService $walletService;
	private MemberPreferenceMapper $prefMapper;
	private DigestChangeMapper $digestChangeMapper;
	private DigestScheduleService $digestScheduleService;
	private IL10N $l10n;
	private AppLogger $appLogger;

	public function __construct(
		CommandService $commands,
		RewardRequestMapper $requestMapper,
		RewardRequestEventMapper $eventMapper,
		RewardItemMapper $itemMapper,
		HouseMapper $houseMapper,
		HouseUserMapper $houseUserMapper,
		WalletMapper $walletMapper,
		WalletEntryMapper $walletEntryMapper,
		WalletService $walletService,
		MemberPreferenceMapper $prefMapper,
		DigestChangeMapper $digestChangeMapper,
		DigestScheduleService $digestScheduleService,
		IL10N $l10n,
		AppLogger $appLogger,
	) {
		$this->commands = $commands;
		$this->requestMapper = $requestMapper;
		$this->eventMapper = $eventMapper;
		$this->itemMapper = $itemMapper;
		$this->houseMapper = $houseMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->walletMapper = $walletMapper;
		$this->walletEntryMapper = $walletEntryMapper;
		$this->walletService = $walletService;
		$this->prefMapper = $prefMapper;
		$this->digestChangeMapper = $digestChangeMapper;
		$this->digestScheduleService = $digestScheduleService;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
	}

	// ----------------------------------------------------------------------
	// Reads (never mutate)
	// ----------------------------------------------------------------------

	/**
	 * The viewer-safe request list (bounded stable-cursor page).
	 * scope=mine (default): own requests. scope=house: owner
	 * administration. Reads stay available while the catalog is hidden
	 * or disabled.
	 *
	 * @return array{requests: list<array<string, mixed>>}
	 */
	public function listForViewer(int $houseId, HouseUser $viewer, string $scope, ?string $state = null, ?int $cursorId = null, int $limit = RewardService::DEFAULT_LIMIT): array {
		if ($scope !== self::SCOPE_MINE && $scope !== self::SCOPE_HOUSE) {
			throw new \InvalidArgumentException($this->l10n->t('scope must be mine or house'));
		}
		if ((int)$viewer->getHouseId() !== $houseId) {
			throw new ForbiddenException($this->l10n->t('Not a member of this house'));
		}
		if ($scope === self::SCOPE_HOUSE) {
			if (!$viewer->isOwner()) {
				throw new ForbiddenException($this->l10n->t('Owner role required'));
			}
			$requests = $this->requestMapper->findByHouseId($houseId, $state, $cursorId, $limit);
		} elseif ($viewer->getWalletId() !== null) {
			$requests = $this->requestMapper->findByWalletId((int)$viewer->getWalletId(), $state, $cursorId, $limit);
		} else {
			$requests = [];
		}
		$isOwner = $viewer->isOwner();
		// Batch-prefetch the page's requester memberships and wallets (two
		// queries total, never per-request lookups).
		$wallets = [];
		foreach ($this->walletMapper->findAllByHouseId($houseId) as $wallet) {
			$wallets[(int)$wallet->getId()] = $wallet;
		}
		$membersByWallet = [];
		foreach ($this->houseUserMapper->findByHouseId($houseId) as $member) {
			if ($member->getWalletId() !== null) {
				$membersByWallet[(int)$member->getWalletId()] = $member;
			}
		}
		$context = ['wallets' => $wallets, 'membersByWallet' => $membersByWallet];
		return [
			'requests' => array_values(array_map(
				fn (RewardRequest $request): array => $this->serialize($request, $viewer, $isOwner, null, $context),
				$requests
			)),
		];
	}

	/**
	 * One request with its authorized audit history. Invisible targets
	 * (foreign member) answer 404 like nonexistent ones.
	 *
	 * @return array<string, mixed>
	 */
	public function showForViewer(int $houseId, HouseUser $viewer, int $requestId): array {
		if ((int)$viewer->getHouseId() !== $houseId) {
			throw new ForbiddenException($this->l10n->t('Not a member of this house'));
		}
		$request = $this->requireScopedRequest($requestId, $houseId);
		$isOwner = $viewer->isOwner();
		$isOwn = $viewer->getWalletId() !== null && (int)$request->getWalletId() === (int)$viewer->getWalletId();
		if (!$isOwner && !$isOwn) {
			throw new NotFoundException($this->l10n->t('Reward request not found'));
		}
		$events = $this->eventMapper->findByRequestId($requestId);
		return $this->serialize($request, $viewer, $isOwner, $events);
	}

	// ----------------------------------------------------------------------
	// Commands
	// ----------------------------------------------------------------------

	/**
	 * Create a request for self: validate the displayed terms against the
	 * live unarchived item under lock and freeze them (drift is 409,
	 * never a different silently reserved price). Child self → pending;
	 * adult/owner self → approved with current-role self-approval
	 * provenance. Requires coverage A >= P; R += P.
	 *
	 * @param array<string, mixed> $payload {itemId, itemVersion, priceMinor, commandId}
	 * @return array<string, mixed> The viewer-safe request DTO
	 */
	public function create(int $houseId, string $userId, array $payload): array {
		$member = $this->requireMember($houseId, $userId);
		$commandId = $this->requireCommandId($payload);
		$itemId = $this->requireInt($payload, 'itemId');
		$itemVersion = $this->requireInt($payload, 'itemVersion');
		$priceMinor = $this->requireInt($payload, 'priceMinor');
		if ($priceMinor <= 0) {
			throw new \InvalidArgumentException($this->l10n->t('priceMinor must be a positive whole number of hundredths'));
		}

		$outcome = $this->commands->runCommand(
			$houseId,
			(int)$member->getId(),
			strtolower($commandId),
			self::ACTION_CREATE,
			['itemId' => $itemId, 'itemVersion' => $itemVersion, 'priceMinor' => $priceMinor],
			function () use ($houseId, $member, $itemId, $itemVersion, $priceMinor, $commandId): array {
				$actor = $this->requireFreshMemberUnderLock($houseId, (int)$member->getId());
				if ($actor->getWalletId() === null) {
					throw new \LogicException('Actor has no bound wallet');
				}
				$house = $this->houseMapper->find($houseId);
				$item = $this->requireScopedItem($itemId, $houseId);
				if (!$house->getCatalogEnabled() || $item->getArchived()) {
					throw new ConflictException($this->l10n->t('The reward catalog is not available for new requests'));
				}
				if ($item->getVersion() !== $itemVersion || $item->getPriceMinor() !== $priceMinor) {
					throw new ConflictException($this->l10n->t('The displayed item terms changed — reload before requesting'));
				}
				// Reserve exactly the frozen price once (A >= P required);
				// the wallet lock precedes the request row per the order.
				$this->walletService->reserveReward((int)$actor->getWalletId(), $priceMinor);

				$now = new \DateTime();
				$isChild = $actor->isChild();
				$request = new RewardRequest();
				$request->setHouseId($houseId);
				$request->setWalletId((int)$actor->getWalletId());
				$request->setItemId($itemId);
				$request->setTitle($item->getTitle());
				$request->setDescription($item->getDescription());
				$request->setPriceMinor($priceMinor);
				$request->setState($isChild ? RewardRequest::STATE_PENDING : RewardRequest::STATE_APPROVED);
				$request->setVersion(0);
				if (!$isChild) {
					// Adult creation records current-role approval, not delivery.
					$request->setApprovedRoleRevision($actor->getRoleRevision());
					$request->setApprovedByHouseUserId((int)$actor->getId());
					$request->setApprovedByWalletId($actor->getWalletId());
					$request->setApprovedAt($now);
				}
				$request->setCreatedAt($now);
				$request->setUpdatedAt($now);
				$inserted = $this->requestMapper->insert($request);
				$this->appendEvent($inserted, RewardRequestEvent::ACTION_CREATE, null, $actor, null, $commandId);
				$this->houseMapper->bumpStateVersion($houseId);
				// New child requests reach the opted-in owners.
				if ($isChild) {
					$this->queueRewardChangeUnderLock($houseId, $inserted, $this->ownersOf($houseId), (int)$actor->getId(), $now);
				}
				return ['requestId' => (int)$inserted->getId(), 'requestVersion' => 0];
			},
			$this->createGuard($itemId),
		);

		$request = $this->requestMapper->find((int)$outcome['result']['requestId']);
		return $this->serialize($request, $member, $member->isOwner(), null);
	}

	/**
	 * Owner approves a current child's pending request; a current adult
	 * requester may explicitly self-approve. No B/R change; approval
	 * provenance (current requester role revision) is recorded.
	 *
	 * @param array<string, mixed> $payload {commandId, expectedVersion}
	 * @return array<string, mixed>
	 */
	public function approve(int $houseId, string $userId, int $requestId, array $payload): array {
		return $this->runLifecycleCommand($houseId, $userId, $requestId, $payload, self::ACTION_APPROVE,
			function (RewardRequest $request, HouseUser $actor, ?HouseUser $requester) : array {
				$request->setState(RewardRequest::STATE_APPROVED);
				$request->setApprovedRoleRevision($requester?->getRoleRevision());
				$request->setApprovedByHouseUserId((int)$actor->getId());
				$request->setApprovedByWalletId($actor->getWalletId());
				$request->setApprovedAt(new \DateTime());
				return [];
			});
	}

	/**
	 * Current owner rejects the current child's pending request.
	 * R -= P only — no booked-balance credit.
	 *
	 * @param array<string, mixed> $payload {commandId, expectedVersion, reasonCode?}
	 * @return array<string, mixed>
	 */
	public function reject(int $houseId, string $userId, int $requestId, array $payload): array {
		$reasonCode = $this->optionalReasonCode($payload);
		return $this->runLifecycleCommand($houseId, $userId, $requestId, $payload, self::ACTION_REJECT,
			function (RewardRequest $request, HouseUser $actor, ?HouseUser $requester) use ($reasonCode): array {
				$this->walletService->releaseReward((int)$request->getWalletId(), (int)$request->getPriceMinor());
				$request->setState(RewardRequest::STATE_REJECTED);
				return ['reasonCode' => $reasonCode];
			});
	}

	/**
	 * Requester (including a child) or current owner cancels an
	 * unfulfilled request — even underfunded. R -= P only.
	 *
	 * @param array<string, mixed> $payload {commandId, expectedVersion, reasonCode?}
	 * @return array<string, mixed>
	 */
	public function cancel(int $houseId, string $userId, int $requestId, array $payload): array {
		$reasonCode = $this->optionalReasonCode($payload);
		return $this->runLifecycleCommand($houseId, $userId, $requestId, $payload, self::ACTION_CANCEL,
			function (RewardRequest $request, HouseUser $actor, ?HouseUser $requester) use ($reasonCode): array {
				$this->walletService->releaseReward((int)$request->getWalletId(), (int)$request->getPriceMinor());
				$request->setState(RewardRequest::STATE_CANCELLED);
				return ['reasonCode' => $reasonCode];
			});
	}

	/**
	 * Confirm actual delivery: current owner for a child; adult requester
	 * or current owner for an adult. Requires the current approval and
	 * aggregate coverage B >= R; `B -= P` and `R -= P` together.
	 *
	 * @param array<string, mixed> $payload {commandId, expectedVersion, delivered}
	 * @return array<string, mixed>
	 */
	public function fulfill(int $houseId, string $userId, int $requestId, array $payload): array {
		if (($payload['delivered'] ?? null) !== true) {
			throw new \InvalidArgumentException($this->l10n->t('Fulfillment requires the explicit delivered confirmation'));
		}
		return $this->runLifecycleCommand($houseId, $userId, $requestId, $payload, self::ACTION_FULFILL,
			function (RewardRequest $request, HouseUser $actor, ?HouseUser $requester) use ($houseId): array {
				// The version bump doubles as the ledger effect revision.
				$entryId = $this->walletService->settleReward(
					$houseId,
					(int)$request->getId(),
					(int)$request->getVersion() + 1,
					(int)$request->getWalletId(),
					(int)$request->getPriceMinor(),
					(int)$actor->getId(),
				);
				$request->setState(RewardRequest::STATE_FULFILLED);
				$request->setSettlementEntryId($entryId);
				return ['financialEntryId' => $entryId];
			});
	}

	/**
	 * Current owner attests non-delivery of a fulfilled request: refund
	 * the ORIGINAL settlement debit exactly once against the server's
	 * stored entry (never the current item price or a client value).
	 * No new reservation; terminal corrected record.
	 *
	 * @param array<string, mixed> $payload {commandId, expectedVersion, notDelivered, reasonCode, settlementEntryId}
	 * @return array<string, mixed>
	 */
	public function correct(int $houseId, string $userId, int $requestId, array $payload): array {
		if (($payload['notDelivered'] ?? null) !== true) {
			throw new \InvalidArgumentException($this->l10n->t('Correction requires the explicit notDelivered confirmation'));
		}
		$reasonCode = $this->requiredReasonCode($payload);
		$settlementEntryId = $this->requireInt($payload, 'settlementEntryId');
		return $this->runLifecycleCommand($houseId, $userId, $requestId, $payload, self::ACTION_CORRECT,
			function (RewardRequest $request, HouseUser $actor, ?HouseUser $requester) use ($houseId, $reasonCode, $settlementEntryId): array {
				if ($request->getSettlementEntryId() === null || $settlementEntryId !== (int)$request->getSettlementEntryId()) {
					throw new ConflictException($this->l10n->t('The supplied settlement reference does not match this request'));
				}
				// Verify the server's original entry: wallet, source and the
				// exact original debit — never the current item price.
				try {
					$entry = $this->walletEntryMapper->find($settlementEntryId);
				} catch (DoesNotExistException $e) {
					throw new ConflictException($this->l10n->t('The supplied settlement reference does not match this request'));
				}
				if ((int)$entry->getWalletId() !== (int)$request->getWalletId()
					|| $entry->getSourceKind() !== WalletService::SOURCE_REWARD_SETTLEMENT
					|| (int)$entry->getSourceId() !== (int)$request->getId()
					|| (int)$entry->getDeltaMinor() !== -(int)$request->getPriceMinor()) {
					throw new ConflictException($this->l10n->t('The supplied settlement reference does not match this request'));
				}
				$refundEntryId = $this->walletService->refundReward(
					$houseId,
					(int)$request->getId(),
					(int)$request->getVersion() + 1,
					(int)$request->getWalletId(),
					(int)$request->getPriceMinor(),
					(int)$actor->getId(),
				);
				$request->setState(RewardRequest::STATE_CORRECTED);
				$request->setRefundEntryId($refundEntryId);
				return ['reasonCode' => $reasonCode, 'financialEntryId' => $refundEntryId];
			});
	}

	// ----------------------------------------------------------------------
	// Membership hooks (called under the house lock by HouseService)
	// ----------------------------------------------------------------------

	/**
	 * A real role change of a requester (CS-PLAN-078 §3.4): becoming a
	 * child moves every unfulfilled request to pending and clears the
	 * approval provenance; other transitions preserve valid approvals and
	 * only extend the audit trail. A no-op write never reaches this hook.
	 */
	public function handleRequesterRoleChangedUnderLock(int $houseId, HouseUser $requester, string $oldRole, int $oldRoleRevision, ?HouseUser $actor): void {
		if ($requester->getWalletId() === null) {
			return;
		}
		$requests = $this->requestMapper->findUnfulfilledByWalletId((int)$requester->getWalletId());
		if ($requests === []) {
			return;
		}
		$becameChild = $requester->isChild() && $oldRole !== 'child';
		$now = new \DateTime();
		// The requester (whose approval provenance was just rebased) and the
		// current owners, minus the actor; the owner set is loop-invariant
		// under the held house lock.
		$recipients = array_merge([$requester], $this->ownersOf($houseId));
		$actorId = $actor !== null ? (int)$actor->getId() : -1;
		foreach ($requests as $request) {
			$oldState = $request->getState();
			if ($becameChild) {
				if ($oldState === RewardRequest::STATE_APPROVED) {
					$request->setApprovedRoleRevision(null);
					$request->setApprovedByHouseUserId(null);
					$request->setApprovedByWalletId(null);
					$request->setApprovedAt(null);
				}
				$request->setState(RewardRequest::STATE_PENDING);
			}
			$request->setVersion((int)$request->getVersion() + 1);
			$request->setUpdatedAt($now);
			$this->requestMapper->update($request);
			$event = $this->buildEvent($request, RewardRequestEvent::ACTION_ROLE_CHANGE, $oldState, $actor, null, null);
			$event->setOldRoleRevision($oldRoleRevision);
			$event->setNewRoleRevision($requester->getRoleRevision());
			$this->eventMapper->append($event);
			$this->queueRewardChangeUnderLock($houseId, $request, $recipients, $actorId, $now);
		}
	}

	/**
	 * Ordinary departure (CS-PLAN-078 §3.4): cancel every unfulfilled
	 * request and release its reservation BEFORE the membership row is
	 * deleted — booked value and terminal history are preserved.
	 */
	public function handleMembershipRemovedUnderLock(int $houseId, int $walletId, ?HouseUser $actor): void {
		$requests = $this->requestMapper->findUnfulfilledByWalletId($walletId);
		if ($requests === []) {
			return;
		}
		$now = new \DateTime();
		$owners = $this->ownersOf($houseId);
		$actorId = $actor !== null ? (int)$actor->getId() : -1;
		foreach ($requests as $request) {
			$oldState = $request->getState();
			$this->walletService->releaseReward($walletId, (int)$request->getPriceMinor());
			$request->setState(RewardRequest::STATE_CANCELLED);
			$request->setVersion((int)$request->getVersion() + 1);
			$request->setUpdatedAt($now);
			$this->requestMapper->update($request);
			$this->appendEvent($request, RewardRequestEvent::ACTION_DEPARTURE_CANCEL, $oldState, $actor, null, null);
			// Every cancelled request queues its own organizational
			// reference for the current owners minus the acting owner.
			$this->queueRewardChangeUnderLock($houseId, $request, $owners, $actorId, $now);
		}
	}

	/**
	 * Authorized privacy erasure (CS-PLAN-078 §3.4, Q-070-02): release
	 * outstanding holds, purge the erased person's requests/events, and
	 * remove queued references to those requests from ALL recipients.
	 * References on OTHER retained records are anonymized by wallet +
	 * membership provenance (works for already-departed actors too).
	 * Repeated erasure is harmless.
	 */
	public function eraseActorUnderLock(int $houseId, ?int $houseUserId, int $walletId): void {
		$requests = $this->requestMapper->findByWalletIds([$walletId]);
		$requestIds = array_map(static fn (RewardRequest $request): int => (int)$request->getId(), $requests);

		foreach ($requests as $request) {
			if ($request->isUnfulfilled()) {
				$this->walletService->releaseReward($walletId, (int)$request->getPriceMinor());
			}
		}
		$this->eventMapper->deleteByRequestIds($requestIds);
		foreach ($requests as $request) {
			$this->requestMapper->delete($request);
		}
		if ($requestIds !== []) {
			$this->digestChangeMapper->deleteByObjectIds(
				$houseId,
				'reward_request',
				array_map(static fn (int $id): string => (string)$id, $requestIds)
			);
		}
		if ($houseUserId !== null) {
			$this->eventMapper->anonymizeActorByMembership($houseUserId);
			$this->requestMapper->anonymizeApproverByMembership($houseUserId);
		}
		$this->eventMapper->anonymizeActorByWallet($walletId);
		$this->requestMapper->anonymizeApproverByWallet($walletId);
	}

	// ----------------------------------------------------------------------
	// Command plumbing
	// ----------------------------------------------------------------------

	/**
	 * Shared lifecycle command body: normalize the receipt key, run the
	 * aggregate under the WP7 guard, serialize the FRESH current DTO
	 * (also on replay — the stored identity is never stale output).
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function runLifecycleCommand(int $houseId, string $userId, int $requestId, array $payload, string $action, \Closure $mutate): array {
		$member = $this->requireMember($houseId, $userId);
		$commandId = $this->requireCommandId($payload);
		$expectedVersion = $this->requireExpectedVersion($payload);
		$commandPayload = ['requestId' => $requestId, 'expectedVersion' => $expectedVersion];
		if (array_key_exists('reasonCode', $payload)) {
			$commandPayload['reasonCode'] = $payload['reasonCode'];
		}
		if ($action === self::ACTION_FULFILL) {
			$commandPayload['delivered'] = true;
		}
		if ($action === self::ACTION_CORRECT) {
			$commandPayload['notDelivered'] = true;
			$commandPayload['settlementEntryId'] = $this->requireInt($payload, 'settlementEntryId');
		}

		$outcome = $this->commands->runCommand(
			$houseId,
			(int)$member->getId(),
			strtolower($commandId),
			$action,
			$commandPayload,
			fn (): array => $this->executeLifecycleEffectUnderLock($houseId, $member, $requestId, $action, $expectedVersion, $commandId, $mutate),
			$this->lifecycleGuard($requestId, $action),
		);

		$request = $this->requestMapper->find((int)$outcome['result']['requestId']);
		return $this->serialize($request, $member, $member->isOwner(), null);
	}

	/**
	 * The lifecycle aggregate (runs under the house lock): preconditions,
	 * the action's mutation, the versioned audit append and the digest
	 * queue write — one receiptable effect.
	 *
	 * @return array{requestId: int, requestVersion: int}
	 */
	private function executeLifecycleEffectUnderLock(int $houseId, HouseUser $member, int $requestId, string $action, int $expectedVersion, string $commandId, \Closure $mutate): array {
		$actor = $this->requireFreshMemberUnderLock($houseId, (int)$member->getId());
		// Wallet lock precedes the request row (documented order);
		// the plain read only resolves the wallet binding while the
		// house lock already serializes same-house writers.
		$probe = $this->requireScopedRequest($requestId, $houseId);
		$this->walletMapper->findForUpdate((int)$probe->getWalletId());
		$request = $this->requireScopedRequestForUpdate($requestId, $houseId);
		if ($request->getVersion() !== $expectedVersion) {
			throw new ConflictException($this->l10n->t('The request changed since it was displayed'));
		}
		$requester = $this->requesterOf($request);
		$this->assertAuthority($actor, $requester, $action);
		$oldState = $request->getState();
		if ($action === self::ACTION_CANCEL && !$request->isUnfulfilled()) {
			throw new ConflictException($this->l10n->t('This request is already settled and can no longer be cancelled'));
		}
		if ($action === self::ACTION_APPROVE || $action === self::ACTION_REJECT) {
			$this->assertState($request, RewardRequest::STATE_PENDING);
		}
		if ($action === self::ACTION_FULFILL) {
			$this->assertState($request, RewardRequest::STATE_APPROVED);
		}
		if ($action === self::ACTION_CORRECT) {
			$this->assertState($request, RewardRequest::STATE_FULFILLED);
		}

		$extras = $mutate($request, $actor, $requester);

		$request->setVersion((int)$request->getVersion() + 1);
		$request->setUpdatedAt(new \DateTime());
		$this->requestMapper->update($request);
		$event = $this->appendEvent($request, $this->eventAction($action), $oldState, $actor, $extras['reasonCode'] ?? null, $commandId);
		if (isset($extras['financialEntryId'])) {
			$event->setFinancialEntryId((int)$extras['financialEntryId']);
			$this->eventMapper->update($event);
		}
		$this->houseMapper->bumpStateVersion($houseId);
		if ($action === self::ACTION_FULFILL || $action === self::ACTION_CORRECT) {
			$this->appLogger->info('Reward request settled or refunded', [
				'event' => $action === self::ACTION_FULFILL ? 'reward_request_settled' : 'reward_request_refunded',
				'service' => 'RewardRequestService',
				'houseId' => $houseId,
				'requestId' => $requestId,
				'effectRevision' => (int)$request->getVersion(),
				'amountMinor' => (int)$request->getPriceMinor(),
			]);
		}

		$this->queueRewardChangeUnderLock($houseId, $request, $this->digestRecipientsFor($action, $requester, $actor, $houseId), (int)$actor->getId(), new \DateTime());
		return ['requestId' => $requestId, 'requestVersion' => (int)$request->getVersion()];
	}

	/**
	 * The digest recipient set for one lifecycle effect: owner decisions
	 * reach the requester; requester cancellations reach the owners; a
	 * self-fulfillment is the requester's own business.
	 *
	 * @return HouseUser[]
	 */
	private function digestRecipientsFor(string $action, ?HouseUser $requester, HouseUser $actor, int $houseId): array {
		$actorIsRequester = $requester !== null && (int)$requester->getId() === (int)$actor->getId();
		if ($actorIsRequester) {
			return $action === self::ACTION_FULFILL ? [] : $this->ownersOf($houseId);
		}
		$recipients = $requester !== null ? [$requester] : [];
		if ($action === self::ACTION_CANCEL) {
			$recipients = array_merge($recipients, $this->ownersOf($houseId));
		}
		return $recipients;
	}

	/**
	 * The WP7 under-lock lifecycle guard: fresh actor identity, request
	 * visibility (invisible = 404) and current action authority — never
	 * state/version/funding, which a committed receipt already answered.
	 */
	private function lifecycleGuard(int $requestId, string $action): \Closure {
		return function (int $houseId, int $actorHouseUserId) use ($requestId, $action): void {
			$actor = $this->requireFreshMemberUnderLock($houseId, $actorHouseUserId);
			$request = $this->requireScopedRequest($requestId, $houseId);
			$isOwn = $actor->getWalletId() !== null && (int)$request->getWalletId() === (int)$actor->getWalletId();
			if (!$actor->isOwner() && !$isOwn) {
				throw new NotFoundException($this->l10n->t('Reward request not found'));
			}
			$this->assertAuthority($actor, $this->requesterOf($request), $action);
		};
	}

	/**
	 * The create guard: fresh identity + item visibility only (the
	 * catalog/item business preconditions live in the aggregate).
	 */
	private function createGuard(int $itemId): \Closure {
		return function (int $houseId, int $actorHouseUserId) use ($itemId): void {
			$this->requireFreshMemberUnderLock($houseId, $actorHouseUserId);
			$this->requireScopedItem($itemId, $houseId);
		};
	}

	/**
	 * The current-role action authority matrix (C4; hints never
	 * authorize — the aggregate re-checks state). Unknown actions throw.
	 */
	private function assertAuthority(HouseUser $actor, ?HouseUser $requester, string $action): void {
		$isOwner = $actor->isOwner();
		$isSelf = $requester !== null && (int)$requester->getId() === (int)$actor->getId();
		$requesterIsChild = $requester !== null && $requester->isChild();
		$requesterIsAdult = $requester !== null && !$requester->isChild();

		$allowed = match ($action) {
			self::ACTION_APPROVE => ($isOwner && $requesterIsChild) || ($isSelf && $requesterIsAdult),
			self::ACTION_REJECT => $isOwner && $requesterIsChild,
			self::ACTION_CANCEL => $isOwner || $isSelf,
			self::ACTION_FULFILL => $isOwner || ($isSelf && $requesterIsAdult),
			self::ACTION_CORRECT => $isOwner,
			default => throw new \InvalidArgumentException('Unknown reward action'),
		};
		if (!$allowed) {
			throw new ForbiddenException($this->l10n->t('You are not allowed to perform this action on this request'));
		}
	}

	private function eventAction(string $commandAction): string {
		return match ($commandAction) {
			self::ACTION_APPROVE => RewardRequestEvent::ACTION_APPROVE,
			self::ACTION_REJECT => RewardRequestEvent::ACTION_REJECT,
			self::ACTION_CANCEL => RewardRequestEvent::ACTION_CANCEL,
			self::ACTION_FULFILL => RewardRequestEvent::ACTION_FULFILL,
			self::ACTION_CORRECT => RewardRequestEvent::ACTION_CORRECT,
			default => throw new \InvalidArgumentException('Unknown reward action'),
		};
	}

	private function assertState(RewardRequest $request, string $expected): void {
		if ($request->getState() !== $expected) {
			throw new ConflictException($this->l10n->t('This request is no longer in the required state'));
		}
	}

	// ----------------------------------------------------------------------
	// Serialization
	// ----------------------------------------------------------------------

	/**
	 * The viewer-safe request DTO (CS-PLAN-078 §3.3): frozen terms,
	 * authorized requester reference/placeholder, current-role
	 * allowedActions plus the wallet's funding hold. The caller's OWN
	 * B/R/A wallet joins own requests/mutations only; owners acting for
	 * another person receive no target wallet totals.
	 *
	 * `$context` batches the list-path prefetch (wallets + requester
	 * memberships keyed by wallet id); detail/mutation paths leave it null.
	 *
	 * @param RewardRequestEvent[]|null $events
	 * @param array{wallets?: array<int, \OCA\ChoreStrike\Db\Wallet>, membersByWallet?: array<int, HouseUser>}|null $context
	 * @return array<string, mixed>
	 */
	private function serialize(RewardRequest $request, HouseUser $viewer, bool $isOwner, ?array $events, ?array $context = null): array {
		$walletId = (int)$request->getWalletId();
		$requester = $context !== null
			? ($context['membersByWallet'][$walletId] ?? null)
			: $this->requesterOf($request);
		$isOwn = $viewer->getWalletId() !== null && $walletId === (int)$viewer->getWalletId();

		$data = $request->jsonSerialize();
		$data['requester'] = $this->serializeActor(
			$requester !== null ? (int)$requester->getId() : null,
			$requester !== null ? $requester->getDisplayName() : null,
			$requester === null
		);
		$data['allowedActions'] = $this->allowedActions($isOwner, $isOwn, $requester, $request);

		$wallet = $context !== null
			? ($context['wallets'][$walletId] ?? $this->walletMapper->find($walletId))
			: $this->walletMapper->find($walletId);
		$data['fundingHold'] = $wallet->getBookedMinor() < $wallet->getReservedMinor();
		if ($isOwn) {
			$data['wallet'] = $this->walletService->serializeOwnWallet($wallet);
		}

		if ($events !== null) {
			// Memoize actor resolutions within this serialization (detail
			// histories with long role-change trails share actors).
			$actorCache = [];
			$data['audit'] = array_values(array_map(function (RewardRequestEvent $event) use (&$actorCache): array {
				$actor = $this->serializeActor(null, null, true);
				if ($event->getActorHouseUserId() !== null) {
					$actorId = (int)$event->getActorHouseUserId();
					if (array_key_exists($actorId, $actorCache)) {
						$actor = $actorCache[$actorId];
					} else {
						try {
							$actorMember = $this->houseUserMapper->find($actorId);
							$actor = $this->serializeActor((int)$actorMember->getId(), $actorMember->getDisplayName(), false);
						} catch (DoesNotExistException $e) {
							// Departed/erased actor: the placeholder never
							// recreates access or exposes identifiers.
						}
						$actorCache[$actorId] = $actor;
					}
				}
				return [
					'revision' => $event->getRevision(),
					'action' => $event->getAction(),
					'oldState' => $event->getOldState(),
					'newState' => $event->getNewState(),
					'actor' => $actor,
					'oldRoleRevision' => $event->getOldRoleRevision(),
					'newRoleRevision' => $event->getNewRoleRevision(),
					'reasonCode' => $event->getReasonCode(),
					'financialEntryId' => $event->getFinancialEntryId(),
					'createdAt' => $event->getCreatedAt()?->format('c'),
				];
			}, $events));
		}
		return $data;
	}

	/**
	 * @return array{houseUserId: int|null, displayName: string|null, isFormerMember: bool}
	 */
	private function serializeActor(?int $houseUserId, ?string $displayName, bool $former): array {
		return [
			'houseUserId' => $houseUserId,
			'displayName' => $displayName,
			'isFormerMember' => $former,
		];
	}

	/**
	 * Current-role action hints for the viewer (never authorization).
	 *
	 * @return list<string>
	 */
	private function allowedActions(bool $isOwner, bool $isOwn, ?HouseUser $requester, RewardRequest $request): array {
		$requesterIsChild = $requester !== null && $requester->isChild();
		$requesterIsAdult = $requester !== null && !$requester->isChild();
		$actions = [];
		switch ($request->getState()) {
			case RewardRequest::STATE_PENDING:
				if (($isOwner && $requesterIsChild) || ($isOwn && $requesterIsAdult)) {
					$actions[] = 'approve';
				}
				if ($isOwner && $requesterIsChild) {
					$actions[] = 'reject';
				}
				if ($isOwner || $isOwn) {
					$actions[] = 'cancel';
				}
				break;
			case RewardRequest::STATE_APPROVED:
				if ($isOwner || ($isOwn && $requesterIsAdult)) {
					$actions[] = 'fulfill';
				}
				if ($isOwner || $isOwn) {
					$actions[] = 'cancel';
				}
				break;
			case RewardRequest::STATE_FULFILLED:
				if ($isOwner) {
					$actions[] = 'correct';
				}
				break;
		}
		return $actions;
	}

	// ----------------------------------------------------------------------
	// Digest producer (WP7 §3.5)
	// ----------------------------------------------------------------------

	/**
	 * Queue the organizational digest reference for one request effect
	 * INSIDE the source transaction (CS-PLAN-078 §3.5): recipients are
	 * the given active requester/current owners minus the actor, only
	 * when their own organizational opt-in is already enabled (no
	 * historical backfill). Identity rows only — never title, price or
	 * reasons.
	 *
	 * @param HouseUser[] $recipients
	 */
	private function queueRewardChangeUnderLock(int $houseId, RewardRequest $request, array $recipients, int $actorHouseUserId, \DateTimeInterface $now): void {
		foreach ($recipients as $member) {
			if ((int)$member->getId() === $actorHouseUserId) {
				continue;
			}
			$pref = $this->prefMapper->findByHouseAndUser($houseId, (int)$member->getId());
			if ($pref === null || !$pref->getOrgDigestEnabled()) {
				continue;
			}
			$change = new DigestChange();
			$change->setHouseId($houseId);
			$change->setHouseUserId((int)$member->getId());
			$change->setObjectType('reward_request');
			$change->setObjectId((string)$request->getId());
			$change->setObjectRevision((int)$request->getVersion());
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
	 * @return HouseUser[]
	 */
	private function ownersOf(int $houseId): array {
		return array_values(array_filter(
			$this->houseUserMapper->findByHouseId($houseId),
			static fn (HouseUser $member): bool => $member->isOwner()
		));
	}

	// ----------------------------------------------------------------------
	// Lookup and validation helpers
	// ----------------------------------------------------------------------

	private function requireMember(int $houseId, string $userId): HouseUser {
		$member = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
		if ($member === null) {
			throw new ForbiddenException($this->l10n->t('Not a member of this house'));
		}
		return $member;
	}

	/**
	 * Fresh actor identity under the held house lock (deleted membership
	 * = denied; WP7 guard seam).
	 */
	private function requireFreshMemberUnderLock(int $houseId, int $actorHouseUserId): HouseUser {
		try {
			$actor = $this->houseUserMapper->findForUpdate($actorHouseUserId);
		} catch (DoesNotExistException $e) {
			throw new ForbiddenException($this->l10n->t('Not a member of this house'));
		}
		if ((int)$actor->getHouseId() !== $houseId) {
			throw new ForbiddenException($this->l10n->t('Not a member of this house'));
		}
		return $actor;
	}

	/**
	 * The request scoped to the house (nonexistent/foreign → 404).
	 *
	 * @throws NotFoundException
	 */
	private function requireScopedRequest(int $requestId, int $houseId): RewardRequest {
		try {
			$request = $this->requestMapper->find($requestId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Reward request not found'));
		}
		if ((int)$request->getHouseId() !== $houseId) {
			throw new NotFoundException($this->l10n->t('Reward request not found'));
		}
		return $request;
	}

	/**
	 * @throws NotFoundException
	 */
	private function requireScopedRequestForUpdate(int $requestId, int $houseId): RewardRequest {
		try {
			$request = $this->requestMapper->findForUpdate($requestId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Reward request not found'));
		}
		if ((int)$request->getHouseId() !== $houseId) {
			throw new NotFoundException($this->l10n->t('Reward request not found'));
		}
		return $request;
	}

	/**
	 * @throws NotFoundException
	 */
	private function requireScopedItem(int $itemId, int $houseId): RewardItem {
		try {
			$item = $this->itemMapper->find($itemId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Reward item not found'));
		}
		if ((int)$item->getHouseId() !== $houseId) {
			throw new NotFoundException($this->l10n->t('Reward item not found'));
		}
		return $item;
	}

	/**
	 * The requester's live membership (null after departure/erasure —
	 * the wallet id stays the stable provenance).
	 */
	private function requesterOf(RewardRequest $request): ?HouseUser {
		return $this->houseUserMapper->findByWalletId((int)$request->getWalletId());
	}

	/**
	 * Append the audit event for the request's CURRENT version.
	 */
	private function appendEvent(RewardRequest $request, string $action, ?string $oldState, ?HouseUser $actor, ?string $reasonCode, ?string $commandUuid): RewardRequestEvent {
		$event = $this->buildEvent($request, $action, $oldState, $actor, $reasonCode, $commandUuid);
		return $this->eventMapper->append($event);
	}

	private function buildEvent(RewardRequest $request, string $action, ?string $oldState, ?HouseUser $actor, ?string $reasonCode, ?string $commandUuid): RewardRequestEvent {
		$event = new RewardRequestEvent();
		$event->setRequestId((int)$request->getId());
		$event->setRevision((int)$request->getVersion());
		$event->setAction($action);
		$event->setOldState($oldState);
		$event->setNewState($request->getState());
		$event->setActorHouseUserId($actor !== null ? (int)$actor->getId() : null);
		$event->setActorWalletId($actor?->getWalletId());
		$event->setReasonCode($reasonCode);
		$event->setSourceCommandUuid($commandUuid);
		$event->setCreatedAt(new \DateTime());
		return $event;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function requireInt(array $payload, string $field): int {
		$value = $payload[$field] ?? null;
		if (!is_int($value)) {
			throw new \InvalidArgumentException($this->l10n->t('A valid %1$s is required', [$field]));
		}
		return $value;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function optionalReasonCode(array $payload): ?string {
		if (!array_key_exists('reasonCode', $payload) || $payload['reasonCode'] === null) {
			return null;
		}
		return $this->requiredReasonCode($payload);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function requiredReasonCode(array $payload): string {
		$reasonCode = $payload['reasonCode'] ?? null;
		if (!is_string($reasonCode) || $reasonCode === '' || !in_array($reasonCode, self::REASONS, true)) {
			throw new \InvalidArgumentException($this->l10n->t('A valid reasonCode is required'));
		}
		return $reasonCode;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function requireCommandId(array $payload): string {
		return CommandService::requireCommandUuid($payload, $this->l10n);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function requireExpectedVersion(array $payload): int {
		return CommandService::requireExpectedVersion($payload, $this->l10n);
	}
}
