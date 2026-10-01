<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\RewardRequest;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RewardRequestService;
use OCA\ChoreStrike\Service\RewardService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Integration tests for the C4 reward-request lifecycle against the real
 * database (CS-PLAN-070 parent §3.2 acceptance scenarios C4.1-9; CS-PLAN-078
 * §3.2-§3.4): real command receipts, real wallet/ledger rows, real house
 * locks. Concurrency interleavings live in RewardConcurrencyTest; the HTTP
 * contract in the controller suites.
 */
class RewardLifecycleTest extends IntegrationTestCase {
	/**
	 * Seed: one house, owner + adult member + child, wallet bindings,
	 * live catalog item (12.50 Coins), catalog gate enabled.
	 *
	 * @return array{houseId: int, ownerUid: string, adultUid: string, childUid: string, itemId: int}
	 */
	private function seedFamily(): array {
		$houseId = $this->createHouse('rw_owner', 'Reward House');
		$this->addHouseMember($houseId, 'rw_adult', 'Adult', 'member');
		$this->addHouseMember($houseId, 'rw_child', 'Child', 'child');

		// Fund the fixture wallets through the real ledger path (the only
		// way A >= P can hold under the production reserve rule).
		$ownerId = $this->findHouseUserId($houseId, 'rw_owner');
		$this->creditTestWallet($houseId, $this->walletIdByUser($houseId, 'rw_child'), 10000, $ownerId);
		$this->creditTestWallet($houseId, $this->walletIdByUser($houseId, 'rw_adult'), 10000, $ownerId);

		$houseService = \OC::$server->get(HouseService::class);
		$this->actingAs('rw_owner');
		// The gate command targets the FIXTURE house — read its version
		// directly (getOrCreateHouse would resolve the bootstrap house).
		$fixtureHouse = \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($houseId);
		$houseService->setCatalogEnabled($houseId, true, $fixtureHouse->getStateVersion(), $this->uuid());

		$rewardService = \OC::$server->get(RewardService::class);
		$item = $rewardService->createItem($houseId, [
			'title' => 'Cinema night',
			'description' => 'Including popcorn',
			'priceMinor' => 1250,
			'commandId' => $this->uuid(),
		]);

		return ['houseId' => $houseId, 'itemId' => (int)$item['id'], 'adultUid' => 'rw_adult', 'childUid' => 'rw_child', 'ownerUid' => 'rw_owner'];
	}

	/**
	 * ChildLifecycle_RequestApproveFulfill_SettlesExactlyOnce (C4.1)
	 */
	public function testChildLifecycle_RequestApproveFulfill_SettlesExactlyOnce(): void {
		$f = $this->seedFamily();
		$requests = \OC::$server->get(RewardRequestService::class);

		// Child self → pending, reservation mirrored on the wallet.
		$walletBefore = $this->walletByUser($f['houseId'], $f['childUid']);
		$bookedBefore = $walletBefore->getBookedMinor();
		$created = $requests->create($f['houseId'], $f['childUid'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid(),
		]);
		$requestId = (int)$created['id'];
		self::assertSame(RewardRequest::STATE_PENDING, $created['state']);
		$wallet = $this->walletByUser($f['houseId'], $f['childUid']);
		self::assertSame(1250, $wallet->getReservedMinor(), 'R += P');
		self::assertSame($bookedBefore, $wallet->getBookedMinor(), 'B unchanged by the reservation');
		self::assertSame(['cancel'], $created['allowedActions']);

		// Owner approval — provenance on the row.
		$approved = $requests->approve($f['houseId'], $f['ownerUid'], $requestId, ['commandId' => $this->uuid(), 'expectedVersion' => 0]);
		self::assertSame(RewardRequest::STATE_APPROVED, $approved['state']);
		$row = $this->requestRow($requestId);
		self::assertEquals(0, $row['approved_role_revision'], 'approval binds the requester role revision');
		self::assertNotNull($row['approved_by_house_user_id']);
		self::assertNull($row['settlement_entry_id'], 'approval settles nothing');

		// Owner fulfillment — B -= P and R -= P together, ledger exactly once.
		$fulfilled = $requests->fulfill($f['houseId'], $f['ownerUid'], $requestId, [
			'commandId' => $this->uuid(), 'expectedVersion' => 1, 'delivered' => true,
		]);
		self::assertSame(RewardRequest::STATE_FULFILLED, $fulfilled['state']);
		$wallet = $this->walletByUser($f['houseId'], $f['childUid']);
		self::assertSame($bookedBefore - 1250, $wallet->getBookedMinor(), 'B -= P');
		self::assertSame(0, $wallet->getReservedMinor());
		$row = $this->requestRow($requestId);
		self::assertNotNull($row['settlement_entry_id']);
		$entry = $this->walletEntryRow((int)$row['settlement_entry_id']);
		self::assertSame('reward_settlement', $entry['source_kind']);
		self::assertSame($requestId, (int)$entry['source_id']);
		self::assertSame(2, (int)$entry['source_revision'], 'the effect revision is the fulfill event revision');
		self::assertSame(-1250, (int)$entry['delta_minor']);

		// B always equals the ledger sum.
		self::assertSame($wallet->getBookedMinor(), $this->ledgerSumByWalletId((int)$wallet->getId()));

		// The audit chain: create → approve → fulfill.
		$detail = $requests->showForViewer($f['houseId'], $this->memberByUser($f['houseId'], $f['ownerUid']), $requestId);
		self::assertSame(
			['create', 'approve', 'fulfill'],
			array_map(static fn (array $event): string => $event['action'], $detail['audit'])
		);
		self::assertSame([0, 1, 2], array_map(static fn (array $event): int => $event['revision'], $detail['audit']));
	}

	/**
	 * AdultLifecycle_SelfApprovesAndSelfFulfills (C4.2)
	 */
	public function testAdultLifecycle_SelfApprovesAndSelfFulfills(): void {
		$f = $this->seedFamily();
		$requests = \OC::$server->get(RewardRequestService::class);

		$created = $requests->create($f['houseId'], $f['adultUid'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid(),
		]);
		self::assertSame(RewardRequest::STATE_APPROVED, $created['state'], 'adult creation records current-role approval, not delivery');
		$row = $this->requestRow((int)$created['id']);
		self::assertNotNull($row['approved_at']);
		self::assertSame(0, (int)$row['approved_role_revision']);

		$fulfilled = $requests->fulfill($f['houseId'], $f['adultUid'], (int)$created['id'], [
			'commandId' => $this->uuid(), 'expectedVersion' => 0, 'delivered' => true,
		]);
		self::assertSame(RewardRequest::STATE_FULFILLED, $fulfilled['state']);
		self::assertSame(10000 - 1250, $this->bookedMinorByUser($f['houseId'], $f['adultUid']), 'B -= P from the funded 10000');
	}

	/**
	 * CancelUnderFundedHold_ReleasesAndNeverReopens (C4.3 + C3.10)
	 */
	public function testCancelUnderFundedHold_ReleasesAndNeverReopens(): void {
		$f = $this->seedFamily();
		$requests = \OC::$server->get(RewardRequestService::class);
		$created = $requests->create($f['houseId'], $f['childUid'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid(),
		]);
		$requestId = (int)$created['id'];
		$requests->approve($f['houseId'], $f['ownerUid'], $requestId, ['commandId' => $this->uuid(), 'expectedVersion' => 0]);

		// Coverage collapses (another legitimate negative credit drains B).
		$wallet = $this->walletByUser($f['houseId'], $f['childUid']);
		$wallet->setBookedMinor(-500);
		\OC::$server->get(\OCA\ChoreStrike\Db\WalletMapper::class)->update($wallet);

		// The hold blocks ALL unfulfilled settlements...
		try {
			$requests->fulfill($f['houseId'], $f['ownerUid'], $requestId, ['commandId' => $this->uuid(), 'expectedVersion' => 1, 'delivered' => true]);
			self::fail('a funding hold must block fulfillment');
		} catch (ConflictException $e) {
			self::assertStringContainsString('underfunded', $e->getMessage());
		}
		// ...and new requests for the UNDERFUNDED wallet...
		try {
			$requests->create($f['houseId'], $f['childUid'], ['itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid()]);
			self::fail('a funding hold must block new requests for that wallet');
		} catch (ConflictException $e) {
			self::assertStringContainsString('available', $e->getMessage());
		}
		// ...but cancellation stays possible and releases the hold.
		$cancelled = $requests->cancel($f['houseId'], $f['childUid'], $requestId, ['commandId' => $this->uuid(), 'expectedVersion' => 1]);
		self::assertSame(RewardRequest::STATE_CANCELLED, $cancelled['state']);
		self::assertSame(0, $this->walletByUser($f['houseId'], $f['childUid'])->getReservedMinor());
	}

	/**
	 * Reject_ReleasesReservationWithoutBookedCredit (C4.1 owner rejection)
	 */
	public function testReject_ReleasesReservationWithoutBookedCredit(): void {
		$f = $this->seedFamily();
		$requests = \OC::$server->get(RewardRequestService::class);
		$bookedBefore = $this->walletByUser($f['houseId'], $f['childUid'])->getBookedMinor();
		$created = $requests->create($f['houseId'], $f['childUid'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid(),
		]);

		$rejected = $requests->reject($f['houseId'], $f['ownerUid'], (int)$created['id'], [
			'commandId' => $this->uuid(), 'expectedVersion' => 0, 'reasonCode' => 'not_needed',
		]);
		self::assertSame(RewardRequest::STATE_REJECTED, $rejected['state']);
		$wallet = $this->walletByUser($f['houseId'], $f['childUid']);
		self::assertSame(0, $wallet->getReservedMinor(), 'R -= P');
		self::assertSame($bookedBefore, $wallet->getBookedMinor(), 'a rejection never mints booked value');
		self::assertSame($bookedBefore, $this->ledgerSumByWalletId((int)$wallet->getId()), 'no ledger entry for a rejection');
	}

	/**
	 * Correction_RefundsOriginalDebitExactlyOnce (C4.11)
	 */
	public function testCorrection_RefundsOriginalDebitExactlyOnce(): void {
		$f = $this->seedFamily();
		$requests = \OC::$server->get(RewardRequestService::class);
		$created = $requests->create($f['houseId'], $f['childUid'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid(),
		]);
		$requestId = (int)$created['id'];
		$requests->approve($f['houseId'], $f['ownerUid'], $requestId, ['commandId' => $this->uuid(), 'expectedVersion' => 0]);
		$fulfilled = $requests->fulfill($f['houseId'], $f['ownerUid'], $requestId, ['commandId' => $this->uuid(), 'expectedVersion' => 1, 'delivered' => true]);
		$row = $this->requestRow($requestId);
		$settlementEntryId = (int)$row['settlement_entry_id'];

		// The owner repriced the item meanwhile — the refund stays the
		// ORIGINAL settlement amount, never the new price.
		\OC::$server->get(RewardService::class)->updateItem($f['houseId'], $f['itemId'], [
			'priceMinor' => 9999, 'expectedVersion' => 0, 'commandId' => $this->uuid(),
		]);

		$corrected = $requests->correct($f['houseId'], $f['ownerUid'], $requestId, [
			'commandId' => $this->uuid(), 'expectedVersion' => 2, 'notDelivered' => true,
			'reasonCode' => 'not_delivered', 'settlementEntryId' => $settlementEntryId,
		]);
		self::assertSame(RewardRequest::STATE_CORRECTED, $corrected['state']);
		self::assertSame(10000, $this->walletByUser($f['houseId'], $f['childUid'])->getBookedMinor(), 'the original debit is credited back exactly (10000 - 1250 + 1250)');
		$row = $this->requestRow($requestId);
		self::assertNotNull($row['refund_entry_id'], 'the refund reference is stored');
		self::assertNotSame($settlementEntryId, (int)$row['refund_entry_id']);
		$refund = $this->walletEntryRow((int)$row['refund_entry_id']);
		self::assertSame('reward_refund', $refund['source_kind']);
		self::assertSame(1250, (int)$refund['delta_minor'], 'never the repriced 9999');

		// A second correction attempt on the terminal record conflicts.
		try {
			$requests->correct($f['houseId'], $f['ownerUid'], $requestId, [
				'commandId' => $this->uuid(), 'expectedVersion' => 3, 'notDelivered' => true,
				'reasonCode' => 'not_delivered', 'settlementEntryId' => $settlementEntryId,
			]);
			self::fail('a corrected request is terminal');
		} catch (ConflictException $e) {
		}
	}

	/**
	 * AdultBecomesChild_RebasesApprovedRequestsToPending (C4.8)
	 */
	public function testAdultBecomesChild_RebasesApprovedRequestsToPending(): void {
		$f = $this->seedFamily();
		$requests = \OC::$server->get(RewardRequestService::class);
		$created = $requests->create($f['houseId'], $f['adultUid'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid(),
		]);
		$requestId = (int)$created['id'];
		self::assertSame(RewardRequest::STATE_APPROVED, $this->requestRow($requestId)['state']);
		$houseUserMapper = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class);
		$adultBefore = $houseUserMapper->findByNextcloudUserId($f['houseId'], $f['adultUid']);

		$this->actingAs('rw_owner');
		\OC::$server->get(HouseService::class)->updateUserRole((int)$adultBefore->getId(), 'child', 'rw_owner');

		$row = $this->requestRow($requestId);
		self::assertSame(RewardRequest::STATE_PENDING, $row['state'], 'fresh owner approval required after the rebase');
		self::assertNull($row['approved_role_revision'], 'prior approval invalidated');
		self::assertNull($row['approved_by_house_user_id']);
		self::assertSame(1250, $this->walletByUser($f['houseId'], $f['adultUid'])->getReservedMinor(), 'the reservation is preserved');

		// The owner approves the rebased request — explicit, fresh approval.
		$approved = $requests->approve($f['houseId'], $f['ownerUid'], $requestId, ['commandId' => $this->uuid(), 'expectedVersion' => 1]);
		self::assertSame(RewardRequest::STATE_APPROVED, $approved['state']);
		$audit = $requests->showForViewer($f['houseId'], $this->memberByUser($f['houseId'], $f['ownerUid']), $requestId)['audit'];
		$roleEvent = $audit[1];
		self::assertSame('role_change', $roleEvent['action']);
		self::assertSame('approved', $roleEvent['oldState']);
		self::assertSame('pending', $roleEvent['newState']);
	}

	/**
	 * NoOpRoleWrite_DoesNotInvalidateAFreshApproval (C4.8 parent rule)
	 */
	public function testNoOpRoleWrite_DoesNotInvalidateAFreshApproval(): void {
		$f = $this->seedFamily();
		$requests = \OC::$server->get(RewardRequestService::class);
		$created = $requests->create($f['houseId'], $f['childUid'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid(),
		]);
		$requestId = (int)$created['id'];
		$requests->approve($f['houseId'], $f['ownerUid'], $requestId, ['commandId' => $this->uuid(), 'expectedVersion' => 0]);

		// A no-op child write.
		$this->actingAs('rw_owner');
		$child = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)->findByNextcloudUserId($f['houseId'], $f['childUid']);
		\OC::$server->get(HouseService::class)->updateUserRole((int)$child->getId(), 'child', 'rw_owner');

		$row = $this->requestRow($requestId);
		self::assertSame(RewardRequest::STATE_APPROVED, $row['state'], 'the fresh approval survives');
		self::assertNotNull($row['approved_by_house_user_id']);
	}

	/**
	 * DepartureCancelsUnfulfilledAndPreservesWalletValue (C4.6)
	 */
	public function testDepartureCancelsUnfulfilledAndPreservesWalletValue(): void {
		$f = $this->seedFamily();
		$requests = \OC::$server->get(RewardRequestService::class);
		// One fulfilled (settled) + one pending request for the child.
		$settled = $requests->create($f['houseId'], $f['childUid'], ['itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid()]);
		$pending = $requests->create($f['houseId'], $f['childUid'], ['itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid()]);
		$requests->approve($f['houseId'], $f['ownerUid'], (int)$settled['id'], ['commandId' => $this->uuid(), 'expectedVersion' => 0]);
		$requests->fulfill($f['houseId'], $f['ownerUid'], (int)$settled['id'], ['commandId' => $this->uuid(), 'expectedVersion' => 1, 'delivered' => true]);

		$this->actingAs('rw_owner');
		\OC::$server->get(HouseService::class)->removeHouseMember($this->findHouseUserId($f['houseId'], $f['childUid']));

		$pendingRow = $this->requestRow((int)$pending['id']);
		self::assertSame(RewardRequest::STATE_CANCELLED, $pendingRow['state'], 'the unfulfilled request was cancelled');
		$settledRow = $this->requestRow((int)$settled['id']);
		self::assertSame(RewardRequest::STATE_FULFILLED, $settledRow['state'], 'completed history is preserved');
		$wallet = $this->walletByUser($f['houseId'], $f['childUid']);
		self::assertSame(0, $wallet->getReservedMinor(), 'the hold was released');
		self::assertSame(10000 - 1250, $wallet->getBookedMinor(), 'booked value preserved for the retained wallet');
		self::assertSame($wallet->getBookedMinor(), $this->ledgerSumByWalletId((int)$wallet->getId()));
	}

	/**
	 * Rejoin_FreshRequestsUnderCurrentRoles_TerminalHistoryStays (C4.7)
	 */
	public function testRejoin_FreshRequestsUnderCurrentRoles_TerminalHistoryStays(): void {
		$f = $this->seedFamily();
		$requests = \OC::$server->get(RewardRequestService::class);
		$created = $requests->create($f['houseId'], $f['childUid'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid(),
		]);

		$this->actingAs('rw_owner');
		\OC::$server->get(HouseService::class)->removeHouseMember($this->findHouseUserId($f['houseId'], $f['childUid']));
		// Rejoin.
		$this->addHouseMember($f['houseId'], $f['childUid'], 'Child', 'child');

		// The cancelled history stays visible to the rejoined requester.
		$mine = $requests->listForViewer($f['houseId'], $this->memberByUser($f['houseId'], $f['childUid']), RewardRequestService::SCOPE_MINE);
		self::assertSame([RewardRequest::STATE_CANCELLED], array_column($mine['requests'], 'state'));

		// A fresh request starts under CURRENT roles: pending again.
		$fresh = $requests->create($f['houseId'], $f['childUid'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid(),
		]);
		self::assertSame(RewardRequest::STATE_PENDING, $fresh['state']);
		self::assertSame(1250, $this->walletByUser($f['houseId'], $f['childUid'])->getReservedMinor(), 'only the fresh request holds');
	}

	/**
	 * DisabledCatalog_HidesActiveScopeButKeepsProcessing (C4.4)
	 */
	public function testDisabledCatalog_HidesActiveScopeButKeepsProcessing(): void {
		$f = $this->seedFamily();
		$requests = \OC::$server->get(RewardRequestService::class);
		$rewardService = \OC::$server->get(RewardService::class);
		$created = $requests->create($f['houseId'], $f['childUid'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid(),
		]);
		$requestId = (int)$created['id'];
		$houseService = \OC::$server->get(HouseService::class);
		$fixtureHouse = \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($f['houseId']);

		// Disable the gate.
		$result = $houseService->setCatalogEnabled($f['houseId'], false, $fixtureHouse->getStateVersion(), $this->uuid());
		self::assertFalse($result['catalogEnabled']);

		// Active scope is empty for members; admin scope still lists.
		$active = $rewardService->listItems($f['houseId'], $this->memberByUser($f['houseId'], $f['childUid']), RewardService::SCOPE_ACTIVE);
		self::assertFalse($active['catalogEnabled']);
		self::assertSame([], $active['items']);
		$admin = $rewardService->listItems($f['houseId'], $this->memberByUser($f['houseId'], $f['ownerUid']), RewardService::SCOPE_ADMIN);
		self::assertCount(1, $admin['items'], 'owners keep administering items while off');

		// New requests are denied...
		try {
			$requests->create($f['houseId'], $f['childUid'], ['itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid()]);
			self::fail('a disabled gate denies new requests');
		} catch (ConflictException $e) {
		}
		// ...but the existing request stays processable and visible.
		$approved = $requests->approve($f['houseId'], $f['ownerUid'], $requestId, ['commandId' => $this->uuid(), 'expectedVersion' => 0]);
		self::assertSame(RewardRequest::STATE_APPROVED, $approved['state']);
		$mine = $requests->listForViewer($f['houseId'], $this->memberByUser($f['houseId'], $f['childUid']), RewardRequestService::SCOPE_MINE);
		self::assertCount(1, $mine['requests']);
	}

	/**
	 * ArchivedItem_FreezesExistingRequestsAndBlocksNewOnes (C4.5)
	 */
	public function testArchivedItem_FreezesExistingRequestsAndBlocksNewOnes(): void {
		$f = $this->seedFamily();
		$requests = \OC::$server->get(RewardRequestService::class);
		$rewardService = \OC::$server->get(RewardService::class);
		$created = $requests->create($f['houseId'], $f['childUid'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid(),
		]);
		$requestId = (int)$created['id'];

		// Archive after the request exists.
		$archived = $rewardService->updateItem($f['houseId'], $f['itemId'], [
			'archived' => true, 'expectedVersion' => 0, 'commandId' => $this->uuid(),
		]);
		self::assertTrue($archived['archived'], 'archiving keeps the promised terms, never deletes them');

		try {
			$requests->create($f['houseId'], $f['adultUid'], ['itemId' => $f['itemId'], 'itemVersion' => 1, 'priceMinor' => 1250, 'commandId' => $this->uuid()]);
			self::fail('an archived item denies new requests');
		} catch (ConflictException $e) {
		}

		// The existing request keeps its frozen terms and processes.
		$row = $this->requestRow($requestId);
		self::assertSame('Cinema night', $row['title']);
		self::assertSame(1250, (int)$row['price_minor']);
		$approved = $requests->approve($f['houseId'], $f['ownerUid'], $requestId, ['commandId' => $this->uuid(), 'expectedVersion' => 0]);
		self::assertSame(RewardRequest::STATE_APPROVED, $approved['state']);
	}

	/**
	 * DisplayedTermsDrift_Conflicts409 (C4.5 price change)
	 */
	public function testDisplayedTermsDrift_Conflicts409(): void {
		$f = $this->seedFamily();
		\OC::$server->get(RewardService::class)->updateItem($f['houseId'], $f['itemId'], [
			'priceMinor' => 2000, 'expectedVersion' => 0, 'commandId' => $this->uuid(),
		]);

		try {
			\OC::$server->get(RewardRequestService::class)->create($f['houseId'], $f['childUid'], [
				'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid(),
			]);
			self::fail('stale displayed terms must conflict');
		} catch (ConflictException $e) {
			self::assertStringContainsString('terms', $e->getMessage());
		}
		// A matching fresh page succeeds and freezes the NEW price.
		$created = \OC::$server->get(RewardRequestService::class)->create($f['houseId'], $f['childUid'], [
			'itemId' => $f['itemId'], 'itemVersion' => 1, 'priceMinor' => 2000, 'commandId' => $this->uuid(),
		]);
		self::assertSame(2000, $created['priceMinor']);
	}

	/**
	 * OwnerCannotApproveAnotherAdultsPendingRequest_ButMayCancel (C4 matrix)
	 */
	public function testOwnerCannotApproveAnotherAdultsPendingRequest_ButMayCancel(): void {
		$f = $this->seedFamily();
		$requests = \OC::$server->get(RewardRequestService::class);
		$created = $requests->create($f['houseId'], $f['adultUid'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid(),
		]);
		$requestId = (int)$created['id'];
		self::assertSame(RewardRequest::STATE_APPROVED, $created['state'], 'the adult request is already approved');

		try {
			$requests->approve($f['houseId'], $f['ownerUid'], $requestId, ['commandId' => $this->uuid(), 'expectedVersion' => 0]);
			self::fail('an owner does not approve a different adult request');
		} catch (ForbiddenException $e) {
		}
		// The owner CAN still cancel the approved request (traceable owner
		// cancellation) — the reservation releases.
		$cancelled = $requests->cancel($f['houseId'], $f['ownerUid'], $requestId, ['commandId' => $this->uuid(), 'expectedVersion' => 0]);
		self::assertSame(RewardRequest::STATE_CANCELLED, $cancelled['state']);
		self::assertSame(0, $this->walletByUser($f['houseId'], $f['adultUid'])->getReservedMinor());
	}

	/**
	 * ForeignMemberCannotSeeOrAct (C4.10 — 404, not 403)
	 */
	public function testForeignMemberCannotSeeOrAct(): void {
		$f = $this->seedFamily();
		$this->addHouseMember($f['houseId'], 'rw_stranger', 'Stranger', 'member');
		$requests = \OC::$server->get(RewardRequestService::class);
		$created = $requests->create($f['houseId'], $f['childUid'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid(),
		]);
		$requestId = (int)$created['id'];

		$denied = 0;
		try {
			$requests->showForViewer($f['houseId'], $this->memberByUser($f['houseId'], 'rw_stranger'), $requestId);
			self::fail('a foreign member cannot see the request exists');
		} catch (NotFoundException $e) {
			$denied++;
		}
		try {
			$requests->cancel($f['houseId'], 'rw_stranger', $requestId, ['commandId' => $this->uuid(), 'expectedVersion' => 0]);
			self::fail('a foreign member cannot act on the request');
		} catch (NotFoundException $e) {
			$denied++;
		}
		self::assertSame(2, $denied, 'both reads and writes answer the invisible 404');
	}

	/**
	 * ReservationsReconcileWithWalletCache (T0/T6 invariant)
	 */
	public function testReservationsReconcileWithWalletCache(): void {
		$f = $this->seedFamily();
		$requests = \OC::$server->get(RewardRequestService::class);
		$requests->create($f['houseId'], $f['childUid'], ['itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid()]);
		$requests->create($f['houseId'], $f['adultUid'], ['itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid()]);

		foreach ([$f['childUid'], $f['adultUid']] as $uid) {
			$wallet = $this->walletByUser($f['houseId'], $uid);
			self::assertSame(
				$this->unfulfilledSumByWalletId((int)$wallet->getId()),
				$wallet->getReservedMinor(),
				"$uid: wallets.reserved_minor equals the unfulfilled request sum"
			);
			self::assertSame($wallet->getBookedMinor(), $this->ledgerSumByWalletId((int)$wallet->getId()));
		}
	}

	/**
	 * IdempotentRetry_SameCommandUuid_ReplaysTheFreshState (§3.4)
	 */
	public function testIdempotentRetry_SameCommandUuid_ReplaysTheFreshState(): void {
		$f = $this->seedFamily();
		$requests = \OC::$server->get(RewardRequestService::class);
		$commandId = $this->uuid();
		$created = $requests->create($f['houseId'], $f['childUid'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $commandId,
		]);
		$requestId = (int)$created['id'];
		$requests->approve($f['houseId'], $f['ownerUid'], $requestId, ['commandId' => $this->uuid(), 'expectedVersion' => 0]);

		// Replay the ORIGINAL create command after the state advanced.
		$replayed = $requests->create($f['houseId'], $f['childUid'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $commandId,
		]);
		self::assertSame($requestId, (int)$replayed['id'], 'the stored identity replays');
		self::assertSame(RewardRequest::STATE_APPROVED, $replayed['state'], 'the replayed DTO is the FRESH current state');
		self::assertSame(1250, $this->walletByUser($f['houseId'], $f['childUid'])->getReservedMinor(), 'the replay reserves nothing new');
	}

	/**
	 * OwnerFulfillsAdultsApprovedRequest (C4 matrix — the owner
	 * administrative path for adult requests)
	 */
	public function testOwnerFulfillsAdultsApprovedRequest(): void {
		$f = $this->seedFamily();
		$requests = \OC::$server->get(RewardRequestService::class);
		$created = $requests->create($f['houseId'], $f['adultUid'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid(),
		]);
		self::assertSame(RewardRequest::STATE_APPROVED, $created['state']);

		$fulfilled = $requests->fulfill($f['houseId'], $f['ownerUid'], (int)$created['id'], [
			'commandId' => $this->uuid(), 'expectedVersion' => 0, 'delivered' => true,
		]);

		self::assertSame(RewardRequest::STATE_FULFILLED, $fulfilled['state']);
		self::assertSame(10000 - 1250, $this->bookedMinorByUser($f['houseId'], $f['adultUid']));
	}

	/**
	 * ApproveAndFulfillReplay_SameCommandUuid_ReplaysFreshState (§3.4)
	 */
	public function testApproveAndFulfillReplay_SameCommandUuid_ReplaysFreshState(): void {
		$f = $this->seedFamily();
		$requests = \OC::$server->get(RewardRequestService::class);
		$created = $requests->create($f['houseId'], $f['childUid'], [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 1250, 'commandId' => $this->uuid(),
		]);
		$requestId = (int)$created['id'];

		$approveCommandId = $this->uuid();
		$approved = $requests->approve($f['houseId'], $f['ownerUid'], $requestId, ['commandId' => $approveCommandId, 'expectedVersion' => 0]);
		self::assertSame(RewardRequest::STATE_APPROVED, $approved['state']);
		$replayedApprove = $requests->approve($f['houseId'], $f['ownerUid'], $requestId, ['commandId' => $approveCommandId, 'expectedVersion' => 0]);
		self::assertSame(RewardRequest::STATE_APPROVED, $replayedApprove['state'], 'the approve receipt replays the fresh DTO');
		self::assertSame(1, (int)$this->requestRow($requestId)['version'], 'the replay wrote no second effect');

		$fulfillCommandId = $this->uuid();
		$fulfilled = $requests->fulfill($f['houseId'], $f['ownerUid'], $requestId, ['commandId' => $fulfillCommandId, 'expectedVersion' => 1, 'delivered' => true]);
		self::assertSame(RewardRequest::STATE_FULFILLED, $fulfilled['state']);
		$replayedFulfill = $requests->fulfill($f['houseId'], $f['ownerUid'], $requestId, ['commandId' => $fulfillCommandId, 'expectedVersion' => 1, 'delivered' => true]);
		self::assertSame(RewardRequest::STATE_FULFILLED, $replayedFulfill['state']);
		// Exactly one settlement: B moved once, the ledger has one entry.
		self::assertSame(10000 - 1250, $this->bookedMinorByUser($f['houseId'], $f['childUid']));
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_wallet_entries')
			->where($qb->expr()->eq('source_kind', $qb->createNamedParameter('reward_settlement')))
			->andWhere($qb->expr()->eq('source_id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		self::assertSame(1, (int)$result->fetchOne(), 'exactly one settlement ledger entry despite the replay');
		$result->closeCursor();
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private function uuid(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}

	private function requestRow(int $requestId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_reward_requests')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		self::assertIsArray($row, "reward request $requestId must exist");
		return $row;
	}

	private function walletEntryRow(int $entryId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_wallet_entries')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($entryId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		self::assertIsArray($row, "wallet entry $entryId must exist");
		return $row;
	}

	private function walletByUser(int $houseId, string $uid): \OCA\ChoreStrike\Db\Wallet {
		return \OC::$server->get(\OCA\ChoreStrike\Db\WalletMapper::class)->findByHouseAndUser($houseId, $uid);
	}

	private function memberByUser(int $houseId, string $uid): \OCA\ChoreStrike\Db\HouseUser {
		return \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)->findByNextcloudUserId($houseId, $uid);
	}

	private function walletIdByUser(int $houseId, string $uid): int {
		return (int)$this->walletByUser($houseId, $uid)->getId();
	}

	private function findHouseUserId(int $houseId, string $uid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($uid)));
		$result = $qb->executeQuery();
		$id = (int)$result->fetchOne();
		$result->closeCursor();
		return $id;
	}

	private function bookedMinorByUser(int $houseId, string $uid): int {
		return $this->walletByUser($houseId, $uid)->getBookedMinor();
	}

	private function ledgerSumByWalletId(int $walletId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->sum('delta_minor'))
			->from('chorestrike_wallet_entries')
			->where($qb->expr()->eq('wallet_id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$sum = $result->fetchOne();
		$result->closeCursor();
		return $sum === null ? 0 : (int)$sum;
	}

	private function unfulfilledSumByWalletId(int $walletId): int {
		return \OC::$server->get(\OCA\ChoreStrike\Db\RewardRequestMapper::class)->sumUnfulfilledPriceByWalletId($walletId);
	}

	/**
	 * @return int[]
	 */
	private function walletIdsByHouse(int $houseId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('chorestrike_wallets')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $ids;
	}
}
