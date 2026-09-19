<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration;

use OCA\ChoreStrike\Controller\UserController;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Service\HouseService;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Real-DB integration tests for the server-side role guards (BUG-SEC-002).
 *
 * Mirrors the CANNOT_REMOVE_OWNER pattern: seed one-owner and two-owner
 * setups, exercise the endpoint as an owner, and assert the stable
 * CANNOT_DEMOTE_SELF 403 / CANNOT_DEMOTE_LAST_OWNER 409 codes together
 * with unchanged rows.
 *
 * ADR-010: membership is invite-only, so the fixtures seed their
 * memberships explicitly in the shared instance house — the endpoint
 * resolves and scopes every lookup to that house (CS-PLAN-061 §3.1),
 * and a non-member caller is denied before any role logic runs.
 *
 * Note on the 409 path: via the endpoint the last-owner conflict is
 * unreachable — requireHouseOwner gates the caller to owners, and a house
 * with exactly one owner makes that owner the target, so the self-demotion
 * guard (403) fires first. The ConflictException invariant is therefore
 * pinned at the service level, where direct (non-controller) callers are
 * still protected.
 */
class RoleGuardApiTest extends IntegrationTestCase {
	/** @var int[] */
	private array $cleanupWalletIds = [];

	protected function tearDown(): void {
		foreach ($this->cleanupWalletIds as $walletId) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_wallets')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		parent::tearDown();
	}

	private function sharedHouseId(): int {
		$houseService = \OC::$server->get(HouseService::class);
		$house = $houseService->getOrCreateHouse($houseService->getInstanceId());

		return $house->getId();
	}

	/**
	 * Seed an explicit membership in the shared instance house (purging any
	 * leftover row from an earlier run first) — invite-only model.
	 */
	private function seedMembership(int $houseId, string $userId, string $role): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();

		$this->addHouseMember($houseId, $userId, $userId, $role);
	}

	/**
	 * UpdateRole_SelfDemotion_TwoOwnerHouse_Returns403AndKeepsRow
	 */
	public function testUpdateRole_SelfDemotion_TwoOwnerHouse_Returns403AndKeepsRow(): void {
		$houseId = $this->sharedHouseId();
		$this->seedMembership($houseId, 'rg_owner_a', 'owner');
		$this->seedMembership($houseId, 'rg_coowner_a', 'owner');

		$controller = $this->getController(UserController::class, 'rg_owner_a');
		$targetId = $this->findHouseUserId($houseId, 'rg_owner_a');

		$response = $controller->updateRole($targetId, 'member');

		self::assertSame(403, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('CANNOT_DEMOTE_SELF', $data['error']['code']);
		self::assertSame('owner', $this->fetchRole($targetId), 'the role row must be unchanged');
	}

	/**
	 * UpdateRole_SelfDemotion_OneOwnerHouse_Returns403AndKeepsRow
	 */
	public function testUpdateRole_SelfDemotion_OneOwnerHouse_Returns403AndKeepsRow(): void {
		$houseId = $this->sharedHouseId();
		$this->seedMembership($houseId, 'rg_solo_owner', 'owner');
		$this->seedMembership($houseId, 'rg_member_b', 'member');

		$controller = $this->getController(UserController::class, 'rg_solo_owner');
		$targetId = $this->findHouseUserId($houseId, 'rg_solo_owner');

		$response = $controller->updateRole($targetId, 'child');

		self::assertSame(403, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('CANNOT_DEMOTE_SELF', $data['error']['code']);
		self::assertSame('owner', $this->fetchRole($targetId), 'the role row must be unchanged');
	}

	/**
	 * UpdateRole_CoOwnerDemotion_TwoOwnerHouse_Succeeds
	 *
	 * Legit flow: while another owner remains, a co-owner can be demoted.
	 */
	public function testUpdateRole_CoOwnerDemotion_TwoOwnerHouse_Succeeds(): void {
		$houseId = $this->sharedHouseId();
		$this->seedMembership($houseId, 'rg_owner_c', 'owner');
		$this->seedMembership($houseId, 'rg_coowner_c', 'owner');

		$controller = $this->getController(UserController::class, 'rg_owner_c');
		$targetId = $this->findHouseUserId($houseId, 'rg_coowner_c');

		$response = $controller->updateRole($targetId, 'member');

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['success']);
		self::assertSame('member', $this->fetchRole($targetId), 'the co-owner role must be persisted');
	}

	/**
	 * UpdateRole_PromoteMember_Succeeds
	 */
	public function testUpdateRole_PromoteMember_Succeeds(): void {
		$houseId = $this->sharedHouseId();
		$this->seedMembership($houseId, 'rg_owner_d', 'owner');
		$this->seedMembership($houseId, 'rg_member_d', 'member');

		$controller = $this->getController(UserController::class, 'rg_owner_d');
		$targetId = $this->findHouseUserId($houseId, 'rg_member_d');

		$response = $controller->updateRole($targetId, 'owner');

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['success']);
		self::assertSame('owner', $this->fetchRole($targetId), 'the promotion must be persisted');
	}

	/**
	 * UpdateRole_InvalidRole_Returns400AndKeepsRow
	 */
	public function testUpdateRole_InvalidRole_Returns400AndKeepsRow(): void {
		$houseId = $this->sharedHouseId();
		$this->seedMembership($houseId, 'rg_owner_e', 'owner');
		$this->seedMembership($houseId, 'rg_member_e', 'member');

		$controller = $this->getController(UserController::class, 'rg_owner_e');
		$targetId = $this->findHouseUserId($houseId, 'rg_member_e');

		$response = $controller->updateRole($targetId, 'superadmin');

		self::assertSame(400, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('VALIDATION_ERROR', $data['error']['code']);
		self::assertSame('member', $this->fetchRole($targetId), 'the role row must be unchanged');
	}

	/**
	 * UpdateUserRole_LastOwnerDemotion_ThrowsConflictAndKeepsRow
	 *
	 * Service-level pin of the 409 invariant against the real DB: the
	 * caller is NOT the target (bypassing the endpoint's owner gate), so
	 * the last-owner guard is the one that fires.
	 */
	public function testUpdateUserRole_LastOwnerDemotion_ThrowsConflictAndKeepsRow(): void {
		$houseId = $this->createHouse('rg_last_owner', 'Role Guard House F');
		$this->addHouseMember($houseId, 'rg_caller_f', 'Caller F', 'member');

		$this->actingAs('rg_caller_f');
		$houseService = \OC::$server->get(HouseService::class);
		$targetId = $this->findHouseUserId($houseId, 'rg_last_owner');

		try {
			$houseService->updateUserRole($targetId, 'member', 'rg_caller_f');
			self::fail('demoting the last owner must be rejected');
		} catch (ConflictException $e) {
			self::assertStringContainsString('last house owner', $e->getMessage());
		}

		self::assertSame('owner', $this->fetchRole($targetId), 'the role row must be unchanged');
	}

	/**
	 * UpdateUserRole_RoleChange_PreservesWalletBinding (CS-PLAN-071)
	 *
	 * The financial identity is independent of the role: a role change must
	 * leave the membership's wallet binding and the wallet row untouched.
	 */
	public function testUpdateUserRole_RoleChange_PreservesWalletBinding(): void {
		$houseId = $this->createHouse('rg_wallet_owner', 'Role Guard Wallet House');
		$this->addHouseMember($houseId, 'rg_wallet_member', 'Wallet Member', 'member');

		$walletMapper = \OC::$server->get(\OCA\ChoreStrike\Db\WalletMapper::class);
		$wallet = $walletMapper->createOrGet($houseId, 'rg_wallet_member');
		$this->trackWalletForCleanup((int)$wallet->getId());

		$targetId = $this->findHouseUserId($houseId, 'rg_wallet_member');
		$this->bindWallet($targetId, (int)$wallet->getId());

		$this->actingAs('rg_wallet_owner');
		$houseService = \OC::$server->get(HouseService::class);
		$houseService->updateUserRole($targetId, 'child', 'rg_wallet_owner');

		self::assertSame('child', $this->fetchRole($targetId));
		self::assertSame((int)$wallet->getId(), $this->fetchWalletId($targetId), 'the wallet binding must survive the role change');
		self::assertSame(1, $this->countWallets($houseId, 'rg_wallet_member'), 'exactly one wallet identity exists');
	}

	private function trackWalletForCleanup(int $walletId): void {
		$this->cleanupWalletIds[] = $walletId;
	}

	private function bindWallet(int $houseUserId, int $walletId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_house_users')
			->set('wallet_id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private function fetchWalletId(int $houseUserId): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('wallet_id')
			->from('chorestrike_house_users')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$walletId = $result->fetchOne();
		$result->closeCursor();
		return $walletId === null ? null : (int)$walletId;
	}

	private function countWallets(int $houseId, string $ncUserId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_wallets')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($ncUserId)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function findHouseUserId(int $houseId, string $ncUserId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($ncUserId)));
		$result = $qb->executeQuery();
		$id = (int)$result->fetchOne();
		$result->closeCursor();

		self::assertGreaterThan(0, $id, "house user row for {$ncUserId} must exist");
		return $id;
	}

	private function fetchRole(int $houseUserId): string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('role')
			->from('chorestrike_house_users')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$role = $result->fetchOne();
		$result->closeCursor();

		self::assertNotFalse($role, "house user row {$houseUserId} must exist");
		return (string)$role;
	}
}
