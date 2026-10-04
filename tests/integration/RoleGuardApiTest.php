<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration;

use OCA\ChoreStrike\Controller\UserController;
use OCA\ChoreStrike\Exception\ConflictException;
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
	// CS-PLAN-073 WP2 role matrix (RF-073-WP2-10): agreement commands and
	// pause lifecycle gates against the real DB.
	// -----------------------------------------------------------------

	/**
	 * A dedicated WP2 house: owner + adult member + child (all with bound
	 * wallets) and one low-energy interval task owned by the owner.
	 *
	 * @return array{houseId: int, roomTaskId: int, taskId: int, occurrenceKey: string, expectedVersion: int, selectionToken: string}
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

		$occurrenceService = \OC::$server->get(\OCA\ChoreStrike\Service\OccurrenceService::class);
		$roomTask = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->find($roomTaskId);
		$selection = $occurrenceService->selectionFor($roomTask, new \DateTime(), $houseId);
		self::assertNotNull($selection);

		return [
			'houseId' => $houseId,
			'roomTaskId' => $roomTaskId,
			'taskId' => $taskId,
			'occurrenceKey' => $selection['occurrence']->toPublicKey(),
			'expectedVersion' => $selection['occurrence']->getVersion(),
			'selectionToken' => $occurrenceService->selectionToken(
				$selection['occurrence'],
				$selection['revision'],
				$selection['classification'],
				$houseId,
				null,
				$selection['workAppointment']
			),
		];
	}

	private function uuid2(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}

	/**
	 * Amend/catch-up/restart are OWNER-RECORDED agreements (plan §3.3.2):
	 * a visible member or child is denied 403 on every one of them — before
	 * any receipt or domain write.
	 */
	public function testAgreementCommands_MemberAndChild_Forbidden(): void {
		$f = $this->wp2House();
		$agreements = \OC::$server->get(\OCA\ChoreStrike\Service\AgreementService::class);

		foreach (['rg2_member', 'rg2_child'] as $userId) {
			try {
				$agreements->amend($f['houseId'], $userId, $f['roomTaskId'], [
					'commandId' => $this->uuid2(),
					'occurrenceId' => $f['occurrenceKey'],
					'expectedVersion' => $f['expectedVersion'],
					'selectionToken' => $f['selectionToken'],
					'consent' => true,
					'date' => (new \DateTime('+1 day'))->format('Y-m-d'),
				]);
				self::fail($userId . ' must not record amendments');
			} catch (\OCA\ChoreStrike\Exception\ForbiddenException $e) {
				self::assertStringContainsString('Owner role required', $e->getMessage());
			}
			try {
				$agreements->catchUp($f['houseId'], $userId, $f['roomTaskId'], [
					'commandId' => $this->uuid2(),
					'occurrenceId' => $f['occurrenceKey'],
					'expectedVersion' => $f['expectedVersion'],
					'selectionToken' => $f['selectionToken'],
					'consent' => true,
					'workDate' => (new \DateTime('+1 day'))->format('Y-m-d'),
				]);
				self::fail($userId . ' must not record catch-up appointments');
			} catch (\OCA\ChoreStrike\Exception\ForbiddenException $e) {
				self::assertStringContainsString('Owner role required', $e->getMessage());
			}
			try {
				$agreements->restart($f['houseId'], $userId, $f['roomTaskId'], [
					'commandId' => $this->uuid2(),
					'occurrenceId' => $f['occurrenceKey'],
					'expectedVersion' => $f['expectedVersion'],
					'consent' => true,
					'workDate' => (new \DateTime('+1 day'))->format('Y-m-d'),
				]);
				self::fail($userId . ' must not record restarts');
			} catch (\OCA\ChoreStrike\Exception\ForbiddenException $e) {
				self::assertStringContainsString('Owner role required', $e->getMessage());
			}
		}

		// Zero writes: the occurrence is untouched.
		$roomTask = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->find($f['roomTaskId']);
		$selection = \OC::$server->get(\OCA\ChoreStrike\Service\OccurrenceService::class)->selectionFor($roomTask, new \DateTime(), $f['houseId']);
		self::assertSame($f['occurrenceKey'], $selection['occurrence']->toPublicKey());
		self::assertSame($f['expectedVersion'], $selection['occurrence']->getVersion());
	}

	/**
	 * A private task of another member is invisible even to the house
	 * owner (D2) — the agreement command answers the 404 anti-enumeration
	 * class, never a role 403 that would confirm the task exists.
	 */
	public function testAgreementCommand_PrivateTask_InvisibleToOwner_NotFound(): void {
		$f = $this->wp2House();

		// A second room task: private, created and assigned to the member.
		$privateTaskId = $this->createTask($f['houseId'], 'Private member task', '', 5, 2);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('visibility_type', $qb->createNamedParameter('private'))
			->set('owner_user_id', $qb->createNamedParameter('rg2_member'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($privateTaskId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$floorRows = $this->db->executeQuery(
			'SELECT id FROM *PREFIX*chorestrike_floors WHERE house_id = ?',
			[$f['houseId']]
		)->fetchAll();
		$roomRows = $this->db->executeQuery(
			'SELECT id FROM *PREFIX*chorestrike_rooms WHERE floor_id = ?',
			[(int)$floorRows[0]['id']]
		)->fetchAll();
		$privateRoomTaskId = $this->assignTaskToRoom($privateTaskId, (int)$roomRows[0]['id'], 'rg2_member');

		$occurrenceService = \OC::$server->get(\OCA\ChoreStrike\Service\OccurrenceService::class);
		$roomTask = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->find($privateRoomTaskId);
		$selection = $occurrenceService->selectionFor($roomTask, new \DateTime(), $f['houseId']);
		self::assertNotNull($selection);

		$this->expectException(\OCA\ChoreStrike\Exception\NotFoundException::class);
		\OC::$server->get(\OCA\ChoreStrike\Service\AgreementService::class)->amend($f['houseId'], 'rg2_owner', $privateRoomTaskId, [
			'commandId' => $this->uuid2(),
			'occurrenceId' => $selection['occurrence']->toPublicKey(),
			'expectedVersion' => $selection['occurrence']->getVersion(),
			'selectionToken' => $occurrenceService->selectionToken(
				$selection['occurrence'],
				$selection['revision'],
				$selection['classification'],
				$f['houseId'],
				null,
				$selection['workAppointment']
			),
			'consent' => true,
			'date' => (new \DateTime('+1 day'))->format('Y-m-d'),
		]);
	}

	/**
	 * A child's shorten of its own PENDING request is rejected (a pending
	 * request is decided, not shortened — plan §3.4 row 6), and a member
	 * shortening a foreign pause is denied 403.
	 */
	public function testChildShortening_PendingRequest_Rejected_AndForeignShortening_Forbidden(): void {
		$f = $this->wp2House();
		$pauseService = \OC::$server->get(\OCA\ChoreStrike\Service\PauseService::class);
		$todayKey = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
		$range = [
			'startDate' => $todayKey,
			'endDate' => (new \DateTime($todayKey))->modify('+3 days')->format('Y-m-d'),
			'reasonCode' => \OCA\ChoreStrike\Db\Pause::REASON_TRAVEL,
		];

		$created = $pauseService->requestForSelf($f['houseId'], 'rg2_child', array_merge(['commandId' => $this->uuid2()], $range));
		self::assertSame(\OCA\ChoreStrike\Db\Pause::STATE_REQUESTED, $created['state']);

		try {
			$pauseService->shorten($f['houseId'], 'rg2_child', $created['pauseGroupId'], [
				'commandId' => $this->uuid2(),
				'expectedRevision' => $created['revision'],
				'endDate' => $todayKey,
			]);
			self::fail('a child may not shorten its own pending request');
		} catch (ConflictException $e) {
			self::assertStringContainsString('acknowledged', $e->getMessage());
		}

		// A member shortening a foreign (owner-acknowledged) pause: 403.
		$ownerPause = $pauseService->requestForSelf($f['houseId'], 'rg2_owner', array_merge(['commandId' => $this->uuid2()], $range));
		try {
			$pauseService->shorten($f['houseId'], 'rg2_member', $ownerPause['pauseGroupId'], [
				'commandId' => $this->uuid2(),
				'expectedRevision' => $ownerPause['revision'],
				'endDate' => $todayKey,
			]);
			self::fail('a member may not shorten a foreign pause');
		} catch (\OCA\ChoreStrike\Exception\ForbiddenException $e) {
			self::assertStringContainsString('Owner role required', $e->getMessage());
		}

		// The pending request kept its single revision.
		self::assertSame(1, $created['revision']);
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
