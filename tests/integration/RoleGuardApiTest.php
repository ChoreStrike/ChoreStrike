<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration;

use OCA\ChoreStrike\Controller\UserController;
use OCA\ChoreStrike\Exception\ForbiddenException;
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

	/** @var int[] Pause groups seeded into the shared instance house. */
	private array $cleanupPauseGroupIds = [];

	protected function tearDown(): void {
		foreach ($this->cleanupWalletIds as $walletId) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_wallets')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		foreach ($this->cleanupPauseGroupIds as $pauseGroupId) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_pauses')
				->where($qb->expr()->eq('pause_group_id', $qb->createNamedParameter($pauseGroupId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_agreements')
				->where($qb->expr()->eq('pause_ref', $qb->createNamedParameter($pauseGroupId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		$this->cleanupPauseGroupIds = [];
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
	 * UpdateUserRole_NonOwnerDirectCall_ThrowsForbiddenAndKeepsRow (CS-PLAN-081 H3)
	 *
	 * CR-SEC-05 service-side owner authority: a direct (non-controller)
	 * service call with a member session is denied BEFORE any write — the
	 * role guards (self-demotion, last-owner) never see the request. The
	 * last-owner Conflict itself is unreachable with an owner session (an
	 * owner caller who is not the target implies a second owner) and stays
	 * pinned at unit level (HouseServiceTest).
	 */
	public function testUpdateUserRole_NonOwnerDirectCall_ThrowsForbiddenAndKeepsRow(): void {
		$houseId = $this->createHouse('rg_last_owner', 'Role Guard House F');
		$this->addHouseMember($houseId, 'rg_caller_f', 'Caller F', 'member');

		$this->actingAs('rg_caller_f');
		$houseService = \OC::$server->get(HouseService::class);
		$targetId = $this->findHouseUserId($houseId, 'rg_last_owner');

		try {
			$houseService->updateUserRole($targetId, 'member');
			self::fail('a direct non-owner role change must be rejected service-side');
		} catch (ForbiddenException $e) {
			self::assertStringContainsString('Owner role required', $e->getMessage());
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
		$houseService->updateUserRole($targetId, 'child');

		self::assertSame('child', $this->fetchRole($targetId));
		self::assertSame((int)$wallet->getId(), $this->fetchWalletId($targetId), 'the wallet binding must survive the role change');
		self::assertSame(1, $this->countWallets($houseId, 'rg_wallet_member'), 'exactly one wallet identity exists');
	}

	// -----------------------------------------------------------------
	// CS-PLAN-073 WP2 role matrix (RF-073-WP2-10): the pause lifecycle
	// gates against the real DB (the agreement commands went with ADR-021).
	// -----------------------------------------------------------------

	/**
	 * A dedicated WP2 house: owner + adult member + child (all with bound
	 * wallets) and one low-energy interval task owned by the owner.
	 *
	 * @return array{houseId: int, roomTaskId: int, taskId: int}
	 */
	private function wp2House(): array {
		$houseId = $this->createHouse('rg2_owner', 'WP2 Role House');
		$this->addHouseMember($houseId, 'rg2_member', 'Member', 'member');
		$this->addHouseMember($houseId, 'rg2_child', 'Child', 'child');
		$houseUserMapper = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class);
		$walletService = \OC::$server->get(\OCA\ChoreStrike\Service\WalletService::class);
		foreach (['rg2_owner', 'rg2_member', 'rg2_child'] as $userId) {
			$walletService->ensureBinding($houseId, $houseUserMapper->findByNextcloudUserId($houseId, $userId));
		}
		$floorId = $this->createFloor($houseId, 'Ground', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Role dishes', '', 5, 2);
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'rg2_owner');

		return ['houseId' => $houseId, 'roomTaskId' => $roomTaskId, 'taskId' => $taskId];
	}

	private function uuid2(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}

	/**
	 * ADR-020 on the wire (DELETE /pauses/{id}): a child enters an absence
	 * without a request and deletes it; anyone else but the owner is
	 * denied 403; a stale revision answers 409.
	 */
	public function testPauseDestroy_WireResponse_SubjectAndOwnerOnly(): void {
		// The controller resolves the shared instance house.
		$houseId = $this->sharedHouseId();
		$houseUserMapper = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class);
		$walletService = \OC::$server->get(\OCA\ChoreStrike\Service\WalletService::class);
		foreach (['rg2_owner' => 'owner', 'rg2_member' => 'member', 'rg2_child' => 'child'] as $userId => $role) {
			$this->seedMembership($houseId, $userId, $role);
			$walletService->ensureBinding($houseId, $houseUserMapper->findByNextcloudUserId($houseId, $userId));
		}
		$todayKey = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
		$range = ['startDate' => $todayKey, 'endDate' => (new \DateTime($todayKey))->modify('+3 days')->format('Y-m-d')];
		$call = function (string $userId, string $method, array $params, ?int $id = null): \OCP\AppFramework\Http\JSONResponse {
			$controller = $this->getController(\OCA\ChoreStrike\Controller\PauseController::class, $userId);
			$this->injectRequestParams($controller, $params);
			return $id === null ? $controller->$method() : $controller->$method($id);
		};

		$created = $call('rg2_child', 'create', array_merge(['commandId' => $this->uuid2()], $range));
		self::assertSame(200, $created->getStatus());
		$pause = $created->getData()['data'];
		self::assertSame(\OCA\ChoreStrike\Db\Pause::STATE_ACKNOWLEDGED, $pause['state'], 'a child needs no request');
		$groupId = (int)$pause['pauseGroupId'];
		$this->cleanupPauseGroupIds[] = $groupId;

		$denied = $call('rg2_member', 'destroy', ['commandId' => $this->uuid2(), 'expectedRevision' => 1], $groupId);
		self::assertSame(403, $denied->getStatus(), 'a member may not delete a foreign absence');
		$stale = $call('rg2_child', 'destroy', ['commandId' => $this->uuid2(), 'expectedRevision' => 9], $groupId);
		self::assertSame(409, $stale->getStatus());
		self::assertSame(400, $call('rg2_child', 'destroy', ['commandId' => $this->uuid2()], $groupId)->getStatus());

		$deleted = $call('rg2_child', 'destroy', ['commandId' => $this->uuid2(), 'expectedRevision' => 1], $groupId);
		self::assertSame(200, $deleted->getStatus());
		self::assertSame(\OCA\ChoreStrike\Db\Pause::STATE_CANCELLED, $deleted->getData()['data']['state']);

		$houseAbsence = $call('rg2_owner', 'create', array_merge(['commandId' => $this->uuid2(), 'scope' => 'house'], $range))->getData()['data'];
		$this->cleanupPauseGroupIds[] = (int)$houseAbsence['pauseGroupId'];
		foreach (['rg2_member', 'rg2_child'] as $userId) {
			$response = $call($userId, 'destroy', ['commandId' => $this->uuid2(), 'expectedRevision' => 1], (int)$houseAbsence['pauseGroupId']);
			self::assertSame(403, $response->getStatus(), "$userId may not delete a household absence");
		}
		self::assertSame(200, $call('rg2_owner', 'destroy', ['commandId' => $this->uuid2(), 'expectedRevision' => 1], (int)$houseAbsence['pauseGroupId'])->getStatus());
	}

	/**
	 * Retrospective illness recognition is an explicit OWNER action —
	 * a member is denied 403 before any pause fact exists.
	 */
	public function testRecognizeIllness_NonOwner_Forbidden(): void {
		$f = $this->wp2House();
		$todayKey = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');

		$this->expectException(\OCA\ChoreStrike\Exception\ForbiddenException::class);
		\OC::$server->get(\OCA\ChoreStrike\Service\PauseService::class)->recognizeIllness($f['houseId'], 'rg2_member', [
			'commandId' => $this->uuid2(),
			'subjectUserId' => 'rg2_child',
			'startDate' => $todayKey,
			'endDate' => (new \DateTime($todayKey))->modify('+1 day')->format('Y-m-d'),
			'reasonCode' => \OCA\ChoreStrike\Db\Pause::REASON_ILLNESS,
		]);
	}

	/**
	 * RF-073-WP2-28: the ACTUAL wire response of GET /pauses (real
	 * controller, real service, real DB — a mocked controller test cannot
	 * prove field filtering). The personal reason and the retrospective-
	 * illness marker are self/owner data in every state; unrelated adults
	 * and children receive only the neutral shared facts, and the
	 * whitelist drops internal surrogate ids.
	 */
	public function testPauseIndex_WireResponse_PersonalMetadataIsSelfOwnerOnly(): void {
		$houseId = $this->sharedHouseId();
		$this->seedMembership($houseId, 'rgp_owner', 'owner');
		$this->seedMembership($houseId, 'rgp_adult', 'member');
		$this->seedMembership($houseId, 'rgp_child', 'child');
		$this->seedMembership($houseId, 'rgp_sibling', 'child');
		$houseUserMapper = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class);
		$walletService = \OC::$server->get(\OCA\ChoreStrike\Service\WalletService::class);
		foreach (['rgp_owner', 'rgp_adult', 'rgp_child', 'rgp_sibling'] as $userId) {
			$walletService->ensureBinding($houseId, $houseUserMapper->findByNextcloudUserId($houseId, $userId));
		}

		$todayKey = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
		$pauseService = \OC::$server->get(\OCA\ChoreStrike\Service\PauseService::class);
		$created = $pauseService->recognizeIllness($houseId, 'rgp_owner', [
			'commandId' => $this->uuid2(),
			'subjectUserId' => 'rgp_child',
			'startDate' => $todayKey,
			'endDate' => (new \DateTime($todayKey))->modify('+1 day')->format('Y-m-d'),
			'reasonCode' => \OCA\ChoreStrike\Db\Pause::REASON_ILLNESS,
		]);
		$this->cleanupPauseGroupIds[] = (int)$created['pauseGroupId'];

		$rowFor = function (string $viewerUserId) use ($created): array {
			$controller = $this->getController(\OCA\ChoreStrike\Controller\PauseController::class, $viewerUserId);
			$this->injectRequestParams($controller, []);
			$response = $controller->index();
			self::assertSame(200, $response->getStatus());
			foreach ($response->getData()['data'] as $row) {
				if ((int)$row['pauseGroupId'] === (int)$created['pauseGroupId']) {
					return $row;
				}
			}
			self::fail('the acknowledged pause must be visible to ' . $viewerUserId);
		};

		foreach (['rgp_adult', 'rgp_sibling'] as $unrelated) {
			$row = $rowFor($unrelated);
			self::assertArrayNotHasKey('reasonCode', $row, $unrelated . ' must not receive the personal reason');
			self::assertArrayNotHasKey('isRetrospectiveIllness', $row, $unrelated . ' must not receive the illness marker');
			self::assertArrayNotHasKey('id', $row, 'the whitelist drops the surrogate row id');
			// The neutral shared facts stay visible (scheduling
			// clarification without disclosure).
			self::assertSame('acknowledged', $row['state']);
			self::assertSame($todayKey, $row['startDate']);
			self::assertSame('personal', $row['scope']);
			self::assertSame('rgp_child', $row['subjectUserId']);
			self::assertArrayHasKey('endDate', $row);
			self::assertArrayHasKey('timezone', $row);
		}

		$ownerRow = $rowFor('rgp_owner');
		self::assertSame(\OCA\ChoreStrike\Db\Pause::REASON_ILLNESS, $ownerRow['reasonCode'], 'the owner receives the personal metadata');
		self::assertTrue($ownerRow['isRetrospectiveIllness']);

		$selfRow = $rowFor('rgp_child');
		self::assertSame(\OCA\ChoreStrike\Db\Pause::REASON_ILLNESS, $selfRow['reasonCode'], 'the subject receives its own metadata');
		self::assertTrue($selfRow['isRetrospectiveIllness']);
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
