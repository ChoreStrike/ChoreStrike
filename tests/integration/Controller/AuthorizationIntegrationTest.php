<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Authorization integration tests exercising real DB via OC::$server.
 *
 * Verifies the post-CS-PLAN-002 security contract:
 * - Cross-house access (IDOR) â†’ 404 RESOURCE_NOT_FOUND (prevents enumeration)
 * - Forbidden actions â†’ 403 FORBIDDEN
 * - Owner-only endpoints reject member/child roles
 *
 * @see docs/plans/active/cs-plan-004-build-gate-and-docker-test-infra.md Phase 3
 */
class AuthorizationIntegrationTest extends IntegrationTestCase {
	private int $house1Id;
	private int $house2Id;
	private int $floor1Id;
	private int $room1Id;
	private int $task1Id;
	private int $roomTask1Id;
	private int $floor2Id;
	private int $room2Id;
	private int $task2Id;
	private int $roomTask2Id;

	protected function setUp(): void {
		parent::setUp();

		$this->house1Id = $this->createHouse('auth_owner', 'Auth House 1');
		$this->floor1Id = $this->createFloor($this->house1Id, 'Floor 1', 1);
		$this->room1Id = $this->createRoom($this->house1Id, $this->floor1Id, 'Kitchen');
		$this->task1Id = $this->createTask($this->house1Id, 'Dishes', 'Wash dishes', 5, 3);
		$this->roomTask1Id = $this->assignTaskToRoom($this->task1Id, $this->room1Id, 'auth_owner');

		$this->house2Id = $this->createHouse('auth_outsider', 'Auth House 2');
		$this->floor2Id = $this->createFloor($this->house2Id, 'Floor 1', 1);
		$this->room2Id = $this->createRoom($this->house2Id, $this->floor2Id, 'Bedroom');
		$this->task2Id = $this->createTask($this->house2Id, 'Vacuum', 'Vacuum floor', 4, 4);
		$this->roomTask2Id = $this->assignTaskToRoom($this->task2Id, $this->room2Id, 'auth_outsider');
	}

	// ========== AuthorizationService (real DB) ==========

	public function testGetCurrentUserIdReturnsAuthenticatedUser(): void {
		$this->actingAs('auth_owner');
		$authService = \OC::$server->get(AuthorizationService::class);

		self::assertSame('auth_owner', $authService->getCurrentUserId());
	}

	public function testRequireCurrentUserIdThrowsWhenNotAuthenticated(): void {
		$this->userSession->setUser(null);
		$authService = \OC::$server->get(AuthorizationService::class);

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Not authenticated');
		$authService->requireCurrentUserId();
	}

	public function testEnsureHouseMemberReturnsHouseUserForMember(): void {
		$this->actingAs('auth_owner');
		$authService = \OC::$server->get(AuthorizationService::class);

		$houseUser = $authService->ensureHouseMember($this->house1Id);
		self::assertSame('auth_owner', $houseUser->getNextcloudUserId());
	}

	public function testAssertHouseMemberThrowsForNonMember(): void {
		$this->actingAs('auth_stranger');
		$authService = \OC::$server->get(AuthorizationService::class);

		$this->expectException(ForbiddenException::class);
		$authService->assertHouseMember($this->house1Id);
	}

	public function testRequireHouseOwnerSucceedsForOwner(): void {
		$this->actingAs('auth_owner');
		$authService = \OC::$server->get(AuthorizationService::class);

		$houseUser = $authService->requireHouseOwner($this->house1Id);
		self::assertSame('owner', $houseUser->getRole());
	}

	public function testRequireHouseOwnerThrowsForMember(): void {
		$this->addHouseMember($this->house1Id, 'auth_member', 'Member', 'member');
		$this->actingAs('auth_member');
		$authService = \OC::$server->get(AuthorizationService::class);

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Owner role required');
		$authService->requireHouseOwner($this->house1Id);
	}

	public function testRequireHouseOwnerThrowsForChildRole(): void {
		$this->addHouseMember($this->house1Id, 'auth_child', 'Child', 'child');
		$this->actingAs('auth_child');
		$authService = \OC::$server->get(AuthorizationService::class);

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Owner role required');
		$authService->requireHouseOwner($this->house1Id);
	}

	/**
	 * CR-SVC-47: the first user on a fresh instance (empty house) is
	 * bootstrapped to owner by ensureHouseMember, and the owner check the
	 * getOrCreateHouse-based owner endpoints run right after passes. This is
	 * the fresh-install bootstrap the five owner-only endpoints rely on.
	 */
	public function testFirstUserIsBootstrappedToOwnerOnEmptyHouse(): void {
		$houseId = $this->createBareHouse('Fresh House');
		$this->actingAs('fresh_first_user');
		$authService = \OC::$server->get(AuthorizationService::class);

		$houseUser = $authService->ensureHouseMember($houseId);
		self::assertSame('owner', $houseUser->getRole(), 'first member of an empty house becomes owner');

		$owner = $authService->requireHouseOwner($houseId);
		self::assertSame('fresh_first_user', $owner->getNextcloudUserId());
		self::assertSame('owner', $owner->getRole());
	}

	// ========== IDOR Protection (validateRoomTaskOwnership) ==========

	/**
	 * Cross-house access to a room task must throw NotFoundException (404).
	 * Per P5-API-HARDENING: same message for non-existent and cross-house
	 * to prevent IDOR enumeration.
	 */
	public function testCrossHouseRoomTaskAccessThrowsNotFound(): void {
		$this->actingAs('auth_outsider');
		$roomTaskService = \OC::$server->get(RoomTaskService::class);

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Room task not found');
		$roomTaskService->validateRoomTaskOwnership($this->roomTask1Id, $this->house2Id);
	}

	/**
	 * Accessing a non-existent room task also throws NotFoundException.
	 */
	public function testNonExistentRoomTaskThrowsNotFound(): void {
		$this->actingAs('auth_owner');
		$roomTaskService = \OC::$server->get(RoomTaskService::class);

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Room task not found');
		$roomTaskService->validateRoomTaskOwnership(999999, $this->house1Id);
	}

	/**
	 * Owner can access their own room task without exception.
	 */
	public function testOwnerCanAccessOwnRoomTask(): void {
		$this->actingAs('auth_owner');
		$roomTaskService = \OC::$server->get(RoomTaskService::class);

		$roomTaskService->validateRoomTaskOwnership($this->roomTask1Id, $this->house1Id);
		self::assertTrue(true);
	}

	// ========== Security Regression: Private Task Cross-House ==========

	/**
	 * A private task (is_private=true) in House 1 must not be accessible
	 * from House 2 context ” same 404 as any cross-house IDOR.
	 */
	public function testPrivateTaskCrossHouseReturnsNotFound(): void {
		$this->setTaskPrivate($this->task1Id);
		$this->actingAs('auth_outsider');
		$roomTaskService = \OC::$server->get(RoomTaskService::class);

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Room task not found');
		$roomTaskService->validateRoomTaskOwnership($this->roomTask1Id, $this->house2Id);
	}

	// ========== Security Regression: Multi-Assign Cross-House ==========

	/**
	 * A room task with multiple assignees (chorestrike_task_assignees)
	 * must still be protected from cross-house access.
	 */
	public function testMultiAssignTaskCrossHouseReturnsNotFound(): void {
		$this->addTaskAssignee($this->roomTask1Id, 'auth_member');
		$this->actingAs('auth_outsider');
		$roomTaskService = \OC::$server->get(RoomTaskService::class);

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Room task not found');
		$roomTaskService->validateRoomTaskOwnership($this->roomTask1Id, $this->house2Id);
	}

	// ========== Security Regression: Owner-Only Endpoints ==========

	/**
	 * Member role must be rejected by requireHouseOwner.
	 * Controllers using this guard return 403/500 for non-owners.
	 */
	public function testOwnerOnlyEndpointsRejectMemberRole(): void {
		$this->addHouseMember($this->house1Id, 'auth_member2', 'Member2', 'member');
		$this->actingAs('auth_member2');
		$authService = \OC::$server->get(AuthorizationService::class);

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Owner role required');
		$authService->requireHouseOwner($this->house1Id);
	}

	// ========== Helpers ==========

	private function setTaskPrivate(int $taskId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('visibility_type', $qb->createNamedParameter('private'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId)));
		$qb->executeStatement();
	}

	private function addTaskAssignee(int $roomTaskId, string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_task_assignees')
			->values([
				'room_task_id' => $qb->createNamedParameter($roomTaskId),
				'user_id' => $qb->createNamedParameter($userId),
				'assigned_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			]);
		$qb->executeStatement();
	}
}
