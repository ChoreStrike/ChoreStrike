<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\DigestChangeMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MemberPreferenceMapper;
use OCA\ChoreStrike\Db\RewardItem;
use OCA\ChoreStrike\Db\RewardItemMapper;
use OCA\ChoreStrike\Db\RewardRequest;
use OCA\ChoreStrike\Db\RewardRequestEventMapper;
use OCA\ChoreStrike\Db\RewardRequestMapper;
use OCA\ChoreStrike\Db\Wallet;
use OCA\ChoreStrike\Db\WalletEntry;
use OCA\ChoreStrike\Db\WalletEntryMapper;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\CommandService;
use OCA\ChoreStrike\Service\DigestScheduleService;
use OCA\ChoreStrike\Service\RewardRequestService;
use OCA\ChoreStrike\Service\WalletService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the C4 request lifecycle (CS-PLAN-070 parent §3.2,
 * CS-PLAN-078 §3.2-§3.4): child/adult actor-state matrix, immutable
 * terms, exact one-shot money effects, role rebase, departure and
 * erasure hooks. Financial collaborators are mocked; the interleaving
 * evidence lives in the integration suites.
 */
class RewardRequestServiceTest extends TestCase {
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
	private RewardRequestService $service;

	private House $house;
	private Wallet $wallet;

	/** @var list<callable> Under-lock guards captured from commands. */
	private array $capturedGuards = [];
	/** @var list<RewardRequest> */
	private array $requests = [];
	/** @var array<int, HouseUser> */
	private array $membersById = [];
	/** @var array<string, HouseUser> members by "houseId:uid" */
	private array $membersByKey = [];
	/** @var array<int, RewardItem> */
	private array $itemsById = [];

	protected function setUp(): void {
		$this->commands = $this->createMock(CommandService::class);
		$this->requestMapper = $this->createMock(RewardRequestMapper::class);
		$this->eventMapper = $this->createMock(RewardRequestEventMapper::class);
		$this->itemMapper = $this->createMock(RewardItemMapper::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->walletMapper = $this->createMock(WalletMapper::class);
		$this->walletEntryMapper = $this->createMock(WalletEntryMapper::class);
		$this->walletService = $this->createMock(WalletService::class);
		$this->prefMapper = $this->createMock(MemberPreferenceMapper::class);
		$this->digestChangeMapper = $this->createMock(DigestChangeMapper::class);
		$this->capturedGuards = [];
		$this->requests = [];
		$this->membersById = [];
		$this->membersByKey = [];
		$this->itemsById = [];

		$this->house = new House();
		$this->house->setId(1);
		$this->house->setCatalogEnabled(true);
		$this->houseMapper->method('find')->willReturn($this->house);
		$this->houseMapper->method('bumpStateVersion')->willReturnCallback(function (int $id): void {
			$this->house->setStateVersion($this->house->getStateVersion() + 1);
		});

		$this->wallet = new Wallet();
		$this->wallet->setId(55);
		$this->wallet->setHouseId(1);
		$this->wallet->setBookedMinor(4000);
		$this->wallet->setReservedMinor(0);
		$this->wallet->setVersion(0);
		$this->walletMapper->method('findForUpdate')->willReturn($this->wallet);
		$this->walletMapper->method('find')->willReturn($this->wallet);

		$this->houseUserMapper->method('findForUpdate')->willReturnCallback(function (int $id): HouseUser {
			if (!isset($this->membersById[$id])) {
				throw new DoesNotExistException('gone');
			}
			return $this->membersById[$id];
		});
		$this->houseUserMapper->method('find')->willReturnCallback(function (int $id): HouseUser {
			if (!isset($this->membersById[$id])) {
				throw new DoesNotExistException('gone');
			}
			return $this->membersById[$id];
		});
		$this->houseUserMapper->method('findByHouseAndUser')->willReturnCallback(
			fn (int $houseId, string $uid): ?HouseUser => $this->membersByKey[$houseId . ':' . $uid] ?? null
		);
		$this->houseUserMapper->method('findByWalletId')->willReturnCallback(
			fn (int $walletId): ?HouseUser => $walletId === 55 ? $this->membersByKey['1:child'] ?? $this->membersByKey['1:adult'] ?? null : null
		);
		$this->houseUserMapper->method('findByHouseId')->willReturnCallback(
			fn (int $houseId): array => array_values(array_filter($this->membersById, fn (HouseUser $m): bool => $m->getHouseId() === $houseId))
		);

		$this->itemMapper->method('find')->willReturnCallback(function (int $id): RewardItem {
			if (!isset($this->itemsById[$id])) {
				throw new DoesNotExistException('gone');
			}
			return $this->itemsById[$id];
		});

		$this->requestMapper->method('insert')->willReturnCallback(function (RewardRequest $request): RewardRequest {
			$request->setId(100 + count($this->requests));
			$this->requests[] = $request;
			return $request;
		});
		$this->requestMapper->method('find')->willReturnCallback(function (int $id): RewardRequest {
			foreach ($this->requests as $request) {
				if ((int)$request->getId() === $id) {
					return $request;
				}
			}
			throw new DoesNotExistException('gone');
		});
		$this->requestMapper->method('findForUpdate')->willReturnCallback(function (int $id): RewardRequest {
			foreach ($this->requests as $request) {
				if ((int)$request->getId() === $id) {
					return $request;
				}
			}
			throw new DoesNotExistException('gone');
		});
		$this->requestMapper->method('update')->willReturnArgument(0);
		$this->eventMapper->method('append')->willReturnArgument(0);
		$this->eventMapper->method('update')->willReturnArgument(0);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf(preg_replace('/%[0-9]+\$[sd]/', '%s', $text), $params));

		$this->service = new RewardRequestService(
			$this->commands,
			$this->requestMapper,
			$this->eventMapper,
			$this->itemMapper,
			$this->houseMapper,
			$this->houseUserMapper,
			$this->walletMapper,
			$this->walletEntryMapper,
			$this->walletService,
			$this->prefMapper,
			$this->digestChangeMapper,
			$this->createMock(DigestScheduleService::class),
			$l10n,
			$this->createMock(AppLogger::class),
		);

		// Receipt contract stand-in: the WP7 guard runs BEFORE the
		// aggregate (execution and replay), exactly like the real seam.
		$this->commands->method('runCommand')->willReturnCallback(
			function (int $houseId, int $actorId, string $uuid, string $action, array $payload, callable $fn, ?callable $guard = null): array {
				if ($guard !== null) {
					$guard($houseId, $actorId);
				}
				return ['result' => $fn(), 'receipt' => null, 'replay' => false];
			}
		);
	}

	private function addChild(string $uid = 'child', int $id = 12): HouseUser {
		$member = new HouseUser();
		$member->setId($id);
		$member->setHouseId(1);
		$member->setNextcloudUserId($uid);
		$member->setDisplayName(ucfirst($uid));
		$member->setRole('child');
		$member->setRoleRevision(0);
		$member->setWalletId(55);
		$this->membersById[$id] = $member;
		$this->membersByKey['1:' . $uid] = $member;
		return $member;
	}

	private function addOwner(string $uid = 'owner', int $id = 11): HouseUser {
		$member = new HouseUser();
		$member->setId($id);
		$member->setHouseId(1);
		$member->setNextcloudUserId($uid);
		$member->setDisplayName(ucfirst($uid));
		$member->setRole('owner');
		$member->setRoleRevision(0);
		$member->setWalletId(66);
		$this->membersById[$id] = $member;
		$this->membersByKey['1:' . $uid] = $member;
		return $member;
	}

	private function addItem(int $id = 7, bool $archived = false): RewardItem {
		$item = new RewardItem();
		$item->setId($id);
		$item->setHouseId(1);
		$item->setTitle('Cinema night');
		$item->setPriceMinor(1250);
		$item->setArchived($archived);
		$item->setVersion(0);
		$this->itemsById[$id] = $item;
		return $item;
	}

	private const UUID = '11111111-1111-4111-8111-111111111111';

	private function createPayload(int $itemId = 7, int $itemVersion = 0, int $priceMinor = 1250): array {
		return ['itemId' => $itemId, 'itemVersion' => $itemVersion, 'priceMinor' => $priceMinor, 'commandId' => self::UUID];
	}

	/**
	 * ChildCreate_ReservesAndStaysPending (C4.1)
	 */
	public function testChildCreate_ReservesAndStaysPending(): void {
		$child = $this->addChild();
		$this->addOwner();
		$this->addItem();
		$this->walletService->expects($this->once())->method('reserveReward')->with(55, 1250);
		$this->prefMapper->method('findByHouseAndUser')->willReturn(null);

		$result = $this->service->create(1, 'child', $this->createPayload());

		self::assertSame(RewardRequest::STATE_PENDING, $result['state']);
		self::assertSame(1250, $result['priceMinor']);
		self::assertSame('Cinema night', $result['title'], 'the displayed terms are frozen');
		self::assertSame(0, $result['version']);
		self::assertSame(['cancel'], $result['allowedActions'], 'a child cannot approve or self-fulfill');
		self::assertFalse($result['fundingHold']);
		self::assertArrayHasKey('wallet', $result, 'own requests carry the caller B/R/A wallet');
	}

	/**
	 * ChildCreate_QueuesTheDigestForOptedInOwnersOnly (C5e)
	 */
	public function testChildCreate_QueuesTheDigestForOptedInOwnersOnly(): void {
		$child = $this->addChild();
		$owner = $this->addOwner();
		$this->addItem();
		$pref = new \OCA\ChoreStrike\Db\MemberPreference();
		$pref->setOrgDigestEnabled(true);
		$this->prefMapper->method('findByHouseAndUser')->willReturn($pref);
		$this->digestChangeMapper->expects($this->once())->method('queueOrCoalesce')->with(
			$this->callback(function (\OCA\ChoreStrike\Db\DigestChange $change) use ($owner): bool {
				return (int)$change->getHouseUserId() === (int)$owner->getId()
					&& $change->getObjectType() === 'reward_request';
			})
		);

		$this->service->create(1, 'child', $this->createPayload());
	}

	/**
	 * AdultCreate_SelfApprovesWithCurrentRoleProvenance (C4.2)
	 */
	public function testAdultCreate_SelfApprovesWithCurrentRoleProvenance(): void {
		$adult = $this->addChild('adult', 13);
		$adult->setRole('member');
		$this->addOwner();
		$this->addItem();
		$this->walletService->expects($this->once())->method('reserveReward')->with(55, 1250);

		$result = $this->service->create(1, 'adult', $this->createPayload());

		self::assertSame(RewardRequest::STATE_APPROVED, $result['state'], 'adult creation records current-role approval, not delivery');
		self::assertSame(['fulfill', 'cancel'], $result['allowedActions']);
	}

	/**
	 * CreateWithDisabledCatalog_Conflicts (C4.4)
	 */
	public function testCreateWithDisabledCatalog_Conflicts(): void {
		$this->addChild();
		$this->addOwner();
		$this->addItem();
		$this->house->setCatalogEnabled(false);
		$this->walletService->expects($this->never())->method('reserveReward');

		$this->expectException(ConflictException::class);
		$this->service->create(1, 'child', $this->createPayload());
	}

	/**
	 * CreateWithArchivedItem_Conflicts
	 */
	public function testCreateWithArchivedItem_Conflicts(): void {
		$this->addChild();
		$this->addOwner();
		$this->addItem(7, true);
		$this->expectException(ConflictException::class);
		$this->service->create(1, 'child', $this->createPayload());
	}

	/**
	 * CreateWithDisplayedTermsDrift_ConflictsWithoutReserving (C4.5)
	 */
	public function testCreateWithDisplayedTermsDrift_ConflictsWithoutReserving(): void {
		$this->addChild();
		$this->addOwner();
		$this->addItem();
		$this->walletService->expects($this->never())->method('reserveReward');

		$this->expectException(ConflictException::class);
		$this->service->create(1, 'child', $this->createPayload(itemVersion: 3));
	}

	/**
	 * CreateWithInsufficientFunds_PropagatesThe409
	 */
	public function testCreateWithInsufficientFunds_PropagatesThe409(): void {
		$this->addChild();
		$this->addOwner();
		$this->addItem();
		$this->walletService->method('reserveReward')->willThrowException(new ConflictException('Not enough available Coins for this request'));

		$this->expectException(ConflictException::class);
		$this->service->create(1, 'child', $this->createPayload());
	}

	/**
	 * OwnerApprovesPendingChildRequest (C4.1)
	 */
	public function testOwnerApprovesPendingChildRequest(): void {
		$child = $this->addChild();
		$owner = $this->addOwner();
		$this->addItem();
		$this->service->create(1, 'child', $this->createPayload());
		$requestId = (int)$this->requests[0]->getId();

		$result = $this->service->approve(1, 'owner', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 0]);

		self::assertSame(RewardRequest::STATE_APPROVED, $result['state']);
		self::assertSame(0, $this->wallet->getReservedMinor(), 'approval does not change B/R');
		self::assertSame(['fulfill', 'cancel'], $result['allowedActions']);
	}

	/**
	 * OwnerCannotApproveAnotherAdultsPendingRequest (C4 matrix)
	 */
	public function testOwnerCannotApproveAnotherAdultsPendingRequest(): void {
		$adult = $this->addChild('adult', 13);
		$adult->setRole('member');
		$this->addOwner();
		$this->addItem();
		$this->service->create(1, 'adult', $this->createPayload());
		$requestId = (int)$this->requests[0]->getId();

		$this->expectException(ForbiddenException::class);
		$this->service->approve(1, 'owner', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 0]);
	}

	/**
	 * PromotedAdultMaySelfApproveOwnPendingRequest (C4.9)
	 */
	public function testPromotedAdultMaySelfApproveOwnPendingRequest(): void {
		$child = $this->addChild();
		$this->addOwner();
		$this->addItem();
		$this->service->create(1, 'child', $this->createPayload());
		$requestId = (int)$this->requests[0]->getId();
		// The requester was promoted after creating the request.
		$child->setRole('member');

		$result = $this->service->approve(1, 'child', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 0]);

		self::assertSame(RewardRequest::STATE_APPROVED, $result['state'], 'explicit self-approval after promotion, not automatic redemption');
	}

	/**
	 * OwnerRejectsPendingChildRequestAndReleasesOnce (C4.1)
	 */
	public function testOwnerRejectsPendingChildRequestAndReleasesOnce(): void {
		$this->addChild();
		$this->addOwner();
		$this->addItem();
		$this->service->create(1, 'child', $this->createPayload());
		$requestId = (int)$this->requests[0]->getId();
		$this->walletService->expects($this->once())->method('releaseReward')->with(55, 1250);

		$result = $this->service->reject(1, 'owner', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 0, 'reasonCode' => 'not_needed']);

		self::assertSame(RewardRequest::STATE_REJECTED, $result['state']);
	}

	/**
	 * CancelApprovedRequestUnderFundingHoldReleasesOnce (C4.3)
	 */
	public function testCancelApprovedRequestUnderFundingHoldReleasesOnce(): void {
		$this->addChild();
		$owner = $this->addOwner();
		$this->addItem();
		$this->service->create(1, 'child', $this->createPayload());
		$requestId = (int)$this->requests[0]->getId();
		$this->service->approve(1, 'owner', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 0]);
		// Coverage collapses after the approval — cancellation stays possible.
		$this->wallet->setBookedMinor(1000);
		$this->wallet->setReservedMinor(1250);
		$this->walletService->expects($this->once())->method('releaseReward')->with(55, 1250);

		$result = $this->service->cancel(1, 'child', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 1]);

		self::assertSame(RewardRequest::STATE_CANCELLED, $result['state']);
		self::assertTrue($result['fundingHold']);
	}

	/**
	 * CancelOfSettledRequest_Conflicts
	 */
	public function testCancelOfSettledRequest_Conflicts(): void {
		$child = $this->addChild();
		$owner = $this->addOwner();
		$this->addItem();
		$this->service->create(1, 'child', $this->createPayload());
		$requestId = (int)$this->requests[0]->getId();
		$this->service->approve(1, 'owner', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 0]);
		$this->walletService->method('settleReward')->willReturn(9001);
		$this->service->fulfill(1, 'owner', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 1, 'delivered' => true]);

		$this->expectException(ConflictException::class);
		$this->service->cancel(1, 'child', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 2]);
	}

	/**
	 * StaleExpectedVersion_ConflictsWithoutEffect
	 */
	public function testStaleExpectedVersion_ConflictsWithoutEffect(): void {
		$this->addChild();
		$this->addOwner();
		$this->addItem();
		$this->service->create(1, 'child', $this->createPayload());
		$requestId = (int)$this->requests[0]->getId();
		$this->walletService->expects($this->never())->method('releaseReward');

		$this->expectException(ConflictException::class);
		$this->service->cancel(1, 'child', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 5]);
	}

	/**
	 * FulfillWithoutExplicitDeliveredConfirmation_IsRejected (§3.1)
	 */
	public function testFulfillWithoutExplicitDeliveredConfirmation_IsRejected(): void {
		$this->addChild();
		$owner = $this->addOwner();
		$this->addItem();
		$this->service->create(1, 'child', $this->createPayload());
		$requestId = (int)$this->requests[0]->getId();
		$this->service->approve(1, 'owner', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 0]);
		$this->walletService->expects($this->never())->method('settleReward');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->fulfill(1, 'owner', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 1]);
	}

	/**
	 * ChildCannotSelfFulfill
	 */
	public function testChildCannotSelfFulfill(): void {
		$this->addChild();
		$owner = $this->addOwner();
		$this->addItem();
		$this->service->create(1, 'child', $this->createPayload());
		$requestId = (int)$this->requests[0]->getId();
		$this->service->approve(1, 'owner', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 0]);
		$this->walletService->expects($this->never())->method('settleReward');

		$this->expectException(ForbiddenException::class);
		$this->service->fulfill(1, 'child', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 1, 'delivered' => true]);
	}

	/**
	 * OwnerFulfillSettlesOnceAgainstAggregateCoverage (C4.1/C3.11)
	 */
	public function testOwnerFulfillSettlesOnceAgainstAggregateCoverage(): void {
		$this->addChild();
		$owner = $this->addOwner();
		$this->addItem();
		$this->service->create(1, 'child', $this->createPayload());
		$requestId = (int)$this->requests[0]->getId();
		$this->service->approve(1, 'owner', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 0]);
		$this->walletService->expects($this->once())->method('settleReward')
			->with(1, $requestId, 2, 55, 1250, 11)
			->willReturn(9001);
		$this->wallet->setReservedMinor(1250);

		$result = $this->service->fulfill(1, 'owner', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 1, 'delivered' => true]);

		self::assertSame(RewardRequest::STATE_FULFILLED, $result['state']);
		self::assertSame(['correct'], $result['allowedActions'], 'the owner can still attest a false fulfillment');
	}

	/**
	 * CorrectVerifiesTheOriginalSettlementAndRefundsExactlyOnce (C4.11)
	 */
	public function testCorrectVerifiesTheOriginalSettlementAndRefundsExactlyOnce(): void {
		$this->addChild();
		$owner = $this->addOwner();
		$this->addItem();
		$this->service->create(1, 'child', $this->createPayload());
		$requestId = (int)$this->requests[0]->getId();
		$this->service->approve(1, 'owner', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 0]);
		$this->walletService->method('settleReward')->willReturn(9001);
		$this->service->fulfill(1, 'owner', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 1, 'delivered' => true]);

		$settlement = new WalletEntry();
		$settlement->setId(9001);
		$settlement->setWalletId(55);
		$settlement->setSourceKind(WalletService::SOURCE_REWARD_SETTLEMENT);
		$settlement->setSourceId($requestId);
		$settlement->setDeltaMinor(-1250);
		$this->walletEntryMapper->method('find')->willReturn($settlement);
		$this->walletService->expects($this->once())->method('refundReward')
			->with(1, $requestId, 3, 55, 1250, 11)
			->willReturn(9002);

		$result = $this->service->correct(1, 'owner', $requestId, [
			'commandId' => self::UUID, 'expectedVersion' => 2, 'notDelivered' => true,
			'reasonCode' => 'not_delivered', 'settlementEntryId' => 9001,
		]);

		self::assertSame(RewardRequest::STATE_CORRECTED, $result['state']);
		self::assertSame([], $result['allowedActions'], 'corrected is terminal — no new reservation');
	}

	/**
	 * CorrectWithForeignSettlementReference_Conflicts
	 */
	public function testCorrectWithForeignSettlementReference_Conflicts(): void {
		$this->addChild();
		$owner = $this->addOwner();
		$this->addItem();
		$this->service->create(1, 'child', $this->createPayload());
		$requestId = (int)$this->requests[0]->getId();
		$this->service->approve(1, 'owner', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 0]);
		$this->walletService->method('settleReward')->willReturn(9001);
		$this->service->fulfill(1, 'owner', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 1, 'delivered' => true]);

		$this->walletEntryMapper->expects($this->never())->method('find');
		$this->walletService->expects($this->never())->method('refundReward');

		$this->expectException(ConflictException::class);
		$this->service->correct(1, 'owner', $requestId, [
			'commandId' => self::UUID, 'expectedVersion' => 2, 'notDelivered' => true,
			'reasonCode' => 'not_delivered', 'settlementEntryId' => 42,
		]);
	}

	/**
	 * CorrectWithoutNotDeliveredConfirmation_IsRejected
	 */
	public function testCorrectWithoutNotDeliveredConfirmation_IsRejected(): void {
		$this->addChild();
		$owner = $this->addOwner();
		$this->addItem();
		$this->service->create(1, 'child', $this->createPayload());
		$requestId = (int)$this->requests[0]->getId();
		$this->service->approve(1, 'owner', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 0]);
		$this->walletService->method('settleReward')->willReturn(9001);
		$this->service->fulfill(1, 'owner', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 1, 'delivered' => true]);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->correct(1, 'owner', $requestId, [
			'commandId' => self::UUID, 'expectedVersion' => 2,
			'reasonCode' => 'not_delivered', 'settlementEntryId' => 9001,
		]);
	}

	/**
	 * RoleRebaseToChildMovesApprovedRequestsToPendingAndClearsApproval (C4.8)
	 */
	public function testRoleRebaseToChildMovesApprovedRequestsToPendingAndClearsApproval(): void {
		$adult = $this->addChild('adult', 13);
		$adult->setRole('member');
		$owner = $this->addOwner();
		$this->addItem();
		$this->service->create(1, 'adult', $this->createPayload());
		$request = $this->requests[0];
		self::assertSame(RewardRequest::STATE_APPROVED, $request->getState());
		$this->requestMapper->method('findUnfulfilledByWalletId')->with(55)->willReturn([$request]);
		$this->walletService->expects($this->never())->method('releaseReward');

		// The adult becomes a child (role revision advanced by the caller).
		$adult->setRole('child');
		$adult->setRoleRevision(2);
		$this->service->handleRequesterRoleChangedUnderLock(1, $adult, 'member', 1, $owner);

		self::assertSame(RewardRequest::STATE_PENDING, $request->getState(), 'fresh owner approval required after the rebase');
		self::assertNull($request->getApprovedRoleRevision(), 'prior approvals are invalidated');
		self::assertNull($request->getApprovedByHouseUserId());
		self::assertSame(1, $request->getVersion());
		self::assertSame(1250, $request->getPriceMinor(), 'the frozen price survives the rebase');
	}

	/**
	 * RoleChangePreservesApprovalsForNonChildTransitions (C4.9)
	 */
	public function testRoleChangePreservesApprovalsForNonChildTransitions(): void {
		$member = $this->addChild('adult', 13);
		$member->setRole('member');
		$owner = $this->addOwner();
		$this->addItem();
		$this->service->create(1, 'adult', $this->createPayload());
		$request = $this->requests[0];
		self::assertSame(RewardRequest::STATE_APPROVED, $request->getState());
		$this->requestMapper->method('findUnfulfilledByWalletId')->with(55)->willReturn([$request]);
		$this->walletService->expects($this->never())->method('releaseReward');

		$member->setRole('owner');
		$member->setRoleRevision(1);
		$this->service->handleRequesterRoleChangedUnderLock(1, $member, 'member', 0, $owner);

		self::assertSame(RewardRequest::STATE_APPROVED, $request->getState(), 'valid approvals survive adult↔owner transitions');
		self::assertSame(1250, $request->getPriceMinor());
		self::assertSame(1, $request->getVersion(), 'the audit trail records the provenance change');
	}

	/**
	 * DepartureCancelsEveryUnfulfilledRequestAndReleasesHolds (C4.6)
	 */
	public function testDepartureCancelsEveryUnfulfilledRequestAndReleasesHolds(): void {
		$child = $this->addChild();
		$owner = $this->addOwner();
		$this->addItem();
		$this->service->create(1, 'child', $this->createPayload());
		$this->service->approve(1, 'owner', (int)$this->requests[0]->getId(), ['commandId' => self::UUID, 'expectedVersion' => 0]);
		$request = $this->requests[0];
		$this->requestMapper->method('findUnfulfilledByWalletId')->with(55)->willReturn([$request]);
		$this->walletService->expects($this->once())->method('releaseReward')->with(55, 1250);

		$this->service->handleMembershipRemovedUnderLock(1, 55, $owner);

		self::assertSame(RewardRequest::STATE_CANCELLED, $request->getState());
		self::assertSame(2, $request->getVersion(), 'the departure append is audited (create + departure)');
	}

	/**
	 * ErasurePurgesRequestsEventsAndQueueReferences (Q-070-02)
	 */
	public function testErasurePurgesRequestsEventsAndQueueReferences(): void {
		$child = $this->addChild();
		$owner = $this->addOwner();
		$this->addItem();
		$this->service->create(1, 'child', $this->createPayload());
		$this->service->approve(1, 'owner', (int)$this->requests[0]->getId(), ['commandId' => self::UUID, 'expectedVersion' => 0]);
		$request = $this->requests[0];
		$this->requests = []; // the purge deletes the rows
		$this->requestMapper->method('findByWalletIds')->willReturn([$request]);
		$this->requestMapper->expects($this->once())->method('delete')->with($request);
		$this->eventMapper->expects($this->once())->method('deleteByRequestIds')->with([(int)$request->getId()]);
		$this->digestChangeMapper->expects($this->once())->method('deleteByObjectIds')->with(1, 'reward_request', [(string)$request->getId()]);
		$this->walletService->expects($this->once())->method('releaseReward')->with(55, 1250);

		$this->service->eraseActorUnderLock(1, (int)$child->getId(), 55);
	}

	/**
	 * ForeignMemberCannotSeeARequest (C4.10 — invisible = 404)
	 */
	public function testForeignMemberCannotSeeARequest(): void {
		$this->addChild();
		$owner = $this->addOwner();
		$stranger = new HouseUser();
		$stranger->setId(14);
		$stranger->setHouseId(1);
		$stranger->setNextcloudUserId('stranger');
		$stranger->setRole('member');
		$stranger->setWalletId(77);
		$this->membersById[14] = $stranger;
		$this->membersByKey['1:stranger'] = $stranger;
		$this->addItem();
		$this->service->create(1, 'child', $this->createPayload());
		$requestId = (int)$this->requests[0]->getId();

		$this->expectException(NotFoundException::class);
		$this->service->showForViewer(1, $stranger, $requestId);
	}

	/**
	 * ChildCannotSelfApproveOwnPendingRequest (C4 matrix denial)
	 */
	public function testChildCannotSelfApproveOwnPendingRequest(): void {
		$this->addChild();
		$this->addOwner();
		$this->addItem();
		$this->service->create(1, 'child', $this->createPayload());
		$requestId = (int)$this->requests[0]->getId();
		$this->walletService->expects($this->never())->method('releaseReward');

		$this->expectException(ForbiddenException::class);
		$this->service->approve(1, 'child', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 0]);
	}

	/**
	 * ChildCannotSelfRejectOwnPendingRequest (C4 matrix denial)
	 */
	public function testChildCannotSelfRejectOwnPendingRequest(): void {
		$this->addChild();
		$this->addOwner();
		$this->addItem();
		$this->service->create(1, 'child', $this->createPayload());
		$requestId = (int)$this->requests[0]->getId();
		$this->walletService->expects($this->never())->method('releaseReward');

		$this->expectException(ForbiddenException::class);
		$this->service->reject(1, 'child', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 0]);
	}

	/**
	 * NonOwnerCannotCorrectAFulfillment (C4 matrix denial)
	 */
	public function testNonOwnerCannotCorrectAFulfillment(): void {
		$this->addChild();
		$owner = $this->addOwner();
		$this->addItem();
		$this->service->create(1, 'child', $this->createPayload());
		$requestId = (int)$this->requests[0]->getId();
		$this->service->approve(1, 'owner', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 0]);
		$this->walletService->method('settleReward')->willReturn(9001);
		$this->service->fulfill(1, 'owner', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 1, 'delivered' => true]);
		$this->walletService->expects($this->never())->method('refundReward');

		// The requester (child) is visible but never a correction authority.
		$this->expectException(ForbiddenException::class);
		$this->service->correct(1, 'child', $requestId, [
			'commandId' => self::UUID, 'expectedVersion' => 2, 'notDelivered' => true,
			'reasonCode' => 'not_delivered', 'settlementEntryId' => 9001,
		]);
	}

	/**
	 * TheUnderLockGuardHidesARequestFromADemotedOwner (§3.4 — visibility
	 * precedes authority: after demotion the foreign request is 404, and
	 * the same guard runs on execution AND replay, so an old owner
	 * receipt can never be replayed into an effect).
	 */
	public function testTheUnderLockGuardHidesARequestFromADemotedOwner(): void {
		$child = $this->addChild();
		$owner = $this->addOwner();
		$this->addItem();
		$this->service->create(1, 'child', $this->createPayload());
		$requestId = (int)$this->requests[0]->getId();

		// The owner is demoted after the request was created: the foreign
		// request becomes invisible (404), never 403-enumerable.
		$owner->setRole('member');

		$this->expectException(NotFoundException::class);
		$this->service->approve(1, 'owner', $requestId, ['commandId' => self::UUID, 'expectedVersion' => 0]);
	}
}
