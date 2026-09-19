<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\HouseController;
use OCA\ChoreStrike\Controller\UserController;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for invite-only house membership (ADR-010, CS-PLAN-061
 * §3.1, decision D1).
 *
 * Contract:
 * - The FIRST authenticated user reaching a designated bootstrap endpoint
 *   (user#current here) becomes the house's one owner.
 * - The SECOND authenticated user gets 403 and NO membership row — no
 *   auto-join ever fires once members exist.
 * - An owner-invited member gets 200.
 * - A REMOVED member stays removed: 403 on reload and direct API calls —
 *   removal is durable because no ordinary read resurrects the row.
 */
class InviteOnlyMembershipTest extends IntegrationTestCase {
	private int $houseId;

	protected function setUp(): void {
		parent::setUp();

		$this->actingAs('io_bootstrapper');
		$houseService = \OC::$server->get(HouseService::class);
		$this->houseId = $houseService->getOrCreateHouse($houseService->getInstanceId())->getId();
		$this->purgeInstanceHouseMemberships();
	}

	// ========== Bootstrap ==========

	public function testFirstUserViaUserCurrent_BecomesOwner_ExactlyOne(): void {
		$controller = $this->getController(UserController::class, 'io_first');

		$response = $controller->current();

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success']);
		self::assertSame('owner', $data['data']['role']);

		$members = $this->fetchMemberships();
		self::assertCount(1, $members, 'exactly one membership after the bootstrap');
		self::assertSame('io_first', $members[0]['nextcloud_user_id']);
		self::assertSame('owner', $members[0]['role']);
	}

	public function testSecondUserViaUserCurrent_Returns403_AndCreatesNoRow(): void {
		// Bootstrap the first owner.
		$this->getController(UserController::class, 'io_owner')->current();

		// The second user on a fresh session must be denied.
		$controller = $this->getController(UserController::class, 'io_stranger');
		$response = $controller->current();

		self::assertSame(403, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('FORBIDDEN', $data['error']['code']);

		// And NO membership row was created for the stranger.
		$members = $this->fetchMemberships();
		self::assertCount(1, $members, 'the stranger must not have been auto-joined');
		self::assertSame('io_owner', $members[0]['nextcloud_user_id']);
	}

	// ========== Invited Member ==========

	/**
	 * Create/refresh the invited user WITH the invite email — user#invite
	 * resolves Nextcloud accounts by email (UserController::invite).
	 */
	private function ensureUserWithEmail(string $userId, string $email): void {
		$this->actingAs($userId);
		$user = \OC::$server->get(\OCP\IUserManager::class)->get($userId);
		if ($user === null) {
			$user = \OC::$server->get(\OCP\IUserManager::class)->createUser($userId, 'test-password-' . $userId);
		}
		$user->setEMailAddress($email);
	}

	public function testInvitedMemberGets200(): void {
		$this->getController(UserController::class, 'io_inviter')->current();
		$this->ensureUserWithEmail('io_invited', 'io_invited@example.com');

		$ownerController = $this->getController(UserController::class, 'io_inviter');
		$inviteResponse = $ownerController->invite('io_invited@example.com', 'member');
		self::assertSame(200, $inviteResponse->getStatus(), 'the owner can invite');

		$memberController = $this->getController(UserController::class, 'io_invited');
		$memberResponse = $memberController->current();

		self::assertSame(200, $memberResponse->getStatus());
		self::assertSame('member', $memberResponse->getData()['data']['role']);
	}

	// ========== Durable Removal ==========

	public function testRemovedMember_StaysRemoved_AcrossReloadAndDirectCalls(): void {
		$this->getController(UserController::class, 'io_remover')->current();
		$this->ensureUserWithEmail('io_removed', 'io_removed@example.com');

		$ownerController = $this->getController(UserController::class, 'io_remover');
		$inviteResponse = $ownerController->invite('io_removed@example.com', 'member');
		self::assertSame(200, $inviteResponse->getStatus());
		$houseUserId = (int)$inviteResponse->getData()['data']['id'];

		// Sanity: the invited member can read the house.
		$before = $this->getController(HouseController::class, 'io_removed')->get();
		self::assertSame(200, $before->getStatus());

		// Remove the member — the session is process-global, so re-enter as
		// the owner before acting on their behalf.
		$this->actingAs('io_remover');
		$removeResponse = $ownerController->remove($houseUserId);
		self::assertSame(200, $removeResponse->getStatus());

		// Durable removal: the next boot call 403s...
		$reloadResponse = $this->getController(UserController::class, 'io_removed')->current();
		self::assertSame(403, $reloadResponse->getStatus(), 'user#current must not resurrect the membership');

		// ...and ordinary reads/mutations 403 too — the old auto-join would
		// have silently re-created the row here.
		$houseResponse = $this->getController(HouseController::class, 'io_removed')->get();
		self::assertSame(403, $houseResponse->getStatus());

		$members = $this->fetchMemberships();
		self::assertCount(1, $members, 'no membership row may reappear after removal');
		self::assertSame('io_remover', $members[0]['nextcloud_user_id']);
	}

	// ========== Wallet Identity (CS-PLAN-071) ==========

	/**
	 * RemovedMember_WalletPreservedButGrantsNoAccess_ReinviteReattaches
	 *
	 * CS-PLAN-071 WP0: removal keeps the wallet row (dormant financial
	 * identity, NOT an access credential — the 403s above prove that) and
	 * a re-invitation reattaches the SAME wallet id.
	 */
	public function testRemovedMember_WalletPreservedButGrantsNoAccess_ReinviteReattaches(): void {
		$this->getController(UserController::class, 'io_wallet_owner')->current();
		$this->ensureUserWithEmail('io_wallet_member', 'io_wallet_member@example.com');

		$ownerController = $this->getController(UserController::class, 'io_wallet_owner');
		$inviteResponse = $ownerController->invite('io_wallet_member@example.com', 'member');
		self::assertSame(200, $inviteResponse->getStatus());
		$houseUserId = (int)$inviteResponse->getData()['data']['id'];

		$firstWalletId = $this->fetchWalletId($houseUserId);
		self::assertNotNull($firstWalletId, 'the invite must create and bind the wallet');

		// Remove (wallet stays), re-invite (wallet reattaches).
		$this->actingAs('io_wallet_owner');
		self::assertSame(200, $ownerController->remove($houseUserId)->getStatus());
		self::assertSame(1, $this->countWallets((int)$firstWalletId), 'removal must preserve the wallet row');

		$reinviteResponse = $ownerController->invite('io_wallet_member@example.com', 'member');
		self::assertSame(200, $reinviteResponse->getStatus());
		$reattached = $this->fetchWalletId((int)$reinviteResponse->getData()['data']['id']);
		self::assertSame($firstWalletId, $reattached, 'the re-invitation must reattach the preserved wallet');
	}

	// ========== Helpers ==========

	private function fetchWalletId(int $houseUserId): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('wallet_id')
			->from('chorestrike_house_users')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($houseUserId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$walletId = $result->fetchOne();
		$result->closeCursor();
		return $walletId === null ? null : (int)$walletId;
	}

	private function countWallets(int $walletId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_wallets')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($walletId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function purgeInstanceHouseMemberships(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($this->houseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * @return array<int, array{nextcloud_user_id: string, role: string}>
	 */
	private function fetchMemberships(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('nextcloud_user_id', 'role')
			->from('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($this->houseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}
}
