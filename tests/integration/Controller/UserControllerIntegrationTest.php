<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\UserController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\TitleService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for UserController API endpoints
 */
class UserControllerIntegrationTest extends TestCase {
	private UserController $controller;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IRequest $request;
	private TitleService $titleService;
	private IL10N $l10n;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);
		$this->titleService = $this->createMock(TitleService::class);
		$this->titleService->method('getCurrentTitle')->willReturn(null);
		$this->titleService->method('enrichWithCurrentTitle')
			->willReturnCallback(static function (array $data, HouseUser $user): array {
				$data['currentTitle'] = null;
				return $data;
			});
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')
			->willReturnCallback(static fn (string $text): string => $text);

		// RF-058-04: the controller owns no mapper/user-manager access —
		// membership persistence and scoped lookups live in HouseService.
		$this->controller = new UserController(
			$this->request,
			$this->houseService,
			$this->authService,
			$this->titleService,
			$this->createMock(AppLogger::class),
			$this->l10n
		);
	}

	/**
	 * Index_ReturnsSerializedHouseUsers_SuccessResponse
	 */
	public function testIndex_ReturnsSerializedHouseUsers_SuccessResponse(): void {
		$house = $this->createHouse();
		$users = [
			$this->createHouseUser(1, $house->getId(), 'user1', 'Alice', 'owner'),
			$this->createHouseUser(2, $house->getId(), 'user2', 'Bob', 'member'),
		];

		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');
		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->with('instance-123')
			->willReturn($house);
		// ADR-010/CS-PLAN-061: index is an ordinary endpoint — pure
		// membership assertion, no bootstrap auto-join.
		$this->authService->expects($this->once())
			->method('assertHouseMember')
			->with($house->getId());
		$this->houseService->expects($this->once())
			->method('getHouseMembers')
			->with($house->getId())
			->willReturn($users);

		$response = $this->controller->index();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(2, $data['data']);
		$this->assertEquals('Alice', $data['data'][0]['displayName']);
		$this->assertEquals('user1', $data['data'][0]['nextcloudUserId']);
	}

	/**
	 * Current_ReturnsSerializedCurrentHouseUser_SuccessResponse
	 */
	public function testCurrent_ReturnsSerializedCurrentHouseUser_SuccessResponse(): void {
		$house = $this->createHouse();
		$currentUser = $this->createHouseUser(3, $house->getId(), 'user3', 'Charlie', 'member');

		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');
		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->with('instance-123')
			->willReturn($house);
		$this->authService->expects($this->once())
			->method('ensureHouseMember')
			->with($house->getId())
			->willReturn($currentUser);

		$response = $this->controller->current();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('user3', $data['data']['nextcloudUserId']);
		$this->assertEquals('Charlie', $data['data']['displayName']);
		$this->assertEquals('member', $data['data']['role']);
	}

	/**
	 * Invite_ValidNextcloudUser_ReturnsInvitedUser
	 */
	public function testInvite_ValidNextcloudUser_ReturnsInvitedUser(): void {
		$house = $this->createHouse();
		$role = 'member';
		$houseUser = $this->createHouseUser(3, $house->getId(), 'newuser', 'New User', $role);

		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');
		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->with('instance-123')
			->willReturn($house);
		$this->authService->expects($this->once())
			->method('requireHouseOwner')
			->with($house->getId());
		$this->houseService->expects($this->once())
			->method('inviteMember')
			->with($house->getId(), 'newuser@example.com', $role)
			->willReturn($houseUser);

		$response = $this->controller->invite('newuser@example.com', $role);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('newuser', $data['data']['nextcloudUserId']);
		$this->assertEquals('New User', $data['data']['displayName']);
		$this->assertEquals('User invited to house', $data['message']);
	}

	/**
	 * Invite_NonExistingNextcloudUser_ReturnsNotFound
	 */
	public function testInvite_NonExistingNextcloudUser_ReturnsNotFound(): void {
		$house = $this->createHouse();
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->with('instance-123')->willReturn($house);

		$this->houseService->expects($this->once())
			->method('inviteMember')
			->with($house->getId(), 'missing-user@example.com', 'member')
			->willThrowException(new NotFoundException('No Nextcloud user found with this email address'));

		$response = $this->controller->invite('missing-user@example.com', 'member');

		$this->assertEquals(404, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('USER_NOT_FOUND', $data['error']['code']);
	}

	/**
	 * Invite_NonOwner_DeniedBeforeEmailLookup
	 *
	 * CS-PLAN-061 review fix: the owner gate runs BEFORE the email lookup —
	 * a non-owner must not be able to probe which Nextcloud accounts exist
	 * for a given email (the old order answered USER_NOT_FOUND vs FORBIDDEN
	 * depending on the email, an existence oracle).
	 */
	public function testInvite_NonOwner_DeniedBeforeEmailLookup(): void {
		$house = $this->createHouse();
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->with('instance-123')->willReturn($house);
		$this->authService->method('ensureHouseMember')->with($house->getId());
		$this->authService->expects($this->once())
			->method('requireHouseOwner')
			->with($house->getId())
			->willThrowException(new \OCA\ChoreStrike\Exception\ForbiddenException('Owner role required'));

		$this->houseService->expects($this->never())->method('inviteMember');

		$response = $this->controller->invite('probe@example.com', 'member');

		$this->assertEquals(403, $response->getStatus());
		$this->assertEquals('FORBIDDEN', $response->getData()['error']['code']);
	}

	/**
	 * Titles_NonMemberProbe_DeniedBeforeTargetLookup
	 *
	 * CS-PLAN-061 review fix: the membership gate runs BEFORE the target
	 * lookup — a non-member probing global house_user ids gets a uniform
	 * 403, never a 403/404 split that confirms which ids exist. (The
	 * former selectTitle surface retired with the WP4 single-series
	 * cutover; the read endpoint keeps the same gate order.)
	 */
	public function testTitles_NonMemberProbe_DeniedBeforeTargetLookup(): void {
		$house = $this->createHouse();
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->with('instance-123')->willReturn($house);
		$this->authService->expects($this->once())
			->method('assertHouseMember')
			->with($house->getId())
			->willThrowException(new \OCA\ChoreStrike\Exception\ForbiddenException('Not a member of this house'));

		$this->houseService->expects($this->never())->method('findHouseUserScopedToHouse');

		$response = $this->controller->titles(42);

		$this->assertEquals(403, $response->getStatus());
		$this->assertEquals('FORBIDDEN', $response->getData()['error']['code']);
	}

	/**
	 * Titles_MemberNonexistentTarget_Returns404
	 *
	 * For a member, a nonexistent and a foreign-house id both map to the
	 * same 404 USER_NOT_FOUND (scoped lookup, D3 anti-enumeration).
	 */
	public function testTitles_MemberNonexistentTarget_Returns404(): void {
		$house = $this->createHouse();
		$member = $this->createHouseUser(5, $house->getId(), 'member1', 'Member', 'member');

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->with('instance-123')->willReturn($house);
		$this->authService->method('assertHouseMember')->with($house->getId())->willReturn($member);
		$this->houseService->expects($this->once())
			->method('findHouseUserScopedToHouse')
			->with(999, $house->getId())
			->willReturn(null);

		$response = $this->controller->titles(999);

		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals('USER_NOT_FOUND', $response->getData()['error']['code']);
	}

	/**
	 * Remove_ExistingMember_ReturnsSuccess
	 */
	public function testRemove_ExistingMember_ReturnsSuccess(): void {
		$house = $this->createHouse();
		$houseUser = $this->createHouseUser(2, $house->getId(), 'user2', 'Bob', 'member');

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->with('instance-123')->willReturn($house);
		$this->authService->method('assertHouseMember')->with($house->getId());
		$this->houseService->expects($this->once())
			->method('findHouseUserScopedToHouse')
			->with(2, $house->getId())
			->willReturn($houseUser);
		$this->authService->expects($this->once())
			->method('requireHouseOwner')
			->with($houseUser->getHouseId());
		// CR-SVC-26: the aggregate removal lives in HouseService — the
		// controller must not delete mapper rows itself.
		$this->houseService->expects($this->once())
			->method('removeHouseMember')
			->with(2);

		$response = $this->controller->remove(2);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('User removed from house', $data['message']);
	}

	/**
	 * Remove_Owner_ReturnsForbidden
	 */
	public function testRemove_Owner_ReturnsForbidden(): void {
		$house = $this->createHouse();
		$owner = $this->createHouseUser(1, $house->getId(), 'owner', 'Alice', 'owner');

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->with('instance-123')->willReturn($house);
		$this->authService->method('assertHouseMember')->with($house->getId());
		$this->houseService->expects($this->once())
			->method('findHouseUserScopedToHouse')
			->with(1, $house->getId())
			->willReturn($owner);
		$this->authService->expects($this->once())
			->method('requireHouseOwner')
			->with($owner->getHouseId());
		// The owner-target guard lives in HouseService::removeHouseMember
		// and surfaces as the endpoint-specific CANNOT_REMOVE_OWNER 403.
		$this->houseService->expects($this->once())
			->method('removeHouseMember')
			->with(1)
			->willThrowException(new \OCA\ChoreStrike\Exception\ForbiddenException('Cannot remove the house owner'));

		$response = $this->controller->remove(1);

		$this->assertEquals(403, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('CANNOT_REMOVE_OWNER', $data['error']['code']);
	}

	/**
	 * UpdateRole_ValidRole_ReturnsUpdatedUser
	 *
	 * BUG-SEC-002: the mutation is delegated to HouseService::updateUserRole;
	 * the controller contains no setRole/update itself.
	 */
	public function testUpdateRole_ValidRole_ReturnsUpdatedUser(): void {
		$house = $this->createHouse();
		$houseUser = $this->createHouseUser(2, $house->getId(), 'user2', 'Bob', 'member');
		$updatedUser = $this->createHouseUser(2, $house->getId(), 'user2', 'Bob', 'child');

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->with('instance-123')->willReturn($house);
		$this->authService->method('assertHouseMember')->with($house->getId());
		$this->houseService->expects($this->once())
			->method('findHouseUserScopedToHouse')
			->with(2, $house->getId())
			->willReturn($houseUser);
		$this->authService->expects($this->once())
			->method('requireHouseOwner')
			->with($houseUser->getHouseId());
		$this->authService->expects($this->once())
			->method('requireCurrentUserId')
			->willReturn('owner1');
		$this->houseService->expects($this->once())
			->method('updateUserRole')
			->with(2, 'child', 'owner1')
			->willReturn($updatedUser);

		$response = $this->controller->updateRole(2, 'child');

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('child', $data['data']['role']);
	}

	/**
	 * UpdateRole_InvalidRole_ReturnsValidationError
	 */
	public function testUpdateRole_InvalidRole_ReturnsValidationError(): void {
		$house = $this->createHouse();
		$houseUser = $this->createHouseUser(2, $house->getId(), 'user2', 'Bob', 'member');

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->with('instance-123')->willReturn($house);
		$this->authService->method('assertHouseMember')->with($house->getId());
		$this->houseService->expects($this->once())
			->method('findHouseUserScopedToHouse')
			->with(2, $house->getId())
			->willReturn($houseUser);
		$this->authService->expects($this->once())
			->method('requireHouseOwner')
			->with($houseUser->getHouseId());
		$this->authService->expects($this->once())
			->method('requireCurrentUserId')
			->willReturn('owner1');
		$this->houseService->expects($this->once())
			->method('updateUserRole')
			->with(2, 'superadmin', 'owner1')
			->willThrowException(new \InvalidArgumentException('Invalid role. Must be owner, member, or child'));

		$response = $this->controller->updateRole(2, 'superadmin');

		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
	}

	/**
	 * UpdateRole_RoleChangeCarriesTheWP7RoleRevision
	 *
	 * CS-PLAN-070 WP7 (CS-PLAN-078 §3.4): a real role change through the
	 * API advances the membership's role-revision provenance — the value
	 * reward-request approval provenance binds to. The request-rebind
	 * hook itself is exercised against the real DB in
	 * RewardLifecycleTest.
	 */
	public function testUpdateRole_RoleChangeCarriesTheWP7RoleRevision(): void {
		$house = $this->createHouse();
		$houseUser = $this->createHouseUser(2, $house->getId(), 'user2', 'Bob', 'member');
		$houseUser->setRoleRevision(5);
		$updatedUser = $this->createHouseUser(2, $house->getId(), 'user2', 'Bob', 'child');
		$updatedUser->setRoleRevision(6);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->with('instance-123')->willReturn($house);
		$this->authService->method('assertHouseMember')->with($house->getId());
		$this->houseService->method('findHouseUserScopedToHouse')->willReturn($houseUser);
		$this->authService->method('requireHouseOwner')->with($house->getId());
		$this->authService->method('requireCurrentUserId')->willReturn('owner1');
		$this->houseService->expects($this->once())
			->method('updateUserRole')
			->with(2, 'child', 'owner1')
			->willReturn($updatedUser);

		$response = $this->controller->updateRole(2, 'child');

		$this->assertEquals(200, $response->getStatus());
		// roleRevision is internal provenance — never serialized to the
		// client, but the entity the service returns carries it.
		$this->assertArrayNotHasKey('roleRevision', $response->getData()['data']);
		$this->assertSame(6, $updatedUser->getRoleRevision());
	}

	private function createHouse(int $id = 1): House {
		$house = new House();
		$house->setId($id);
		$house->setNextcloudInstanceId('instance-123');
		$house->setName('Test House');

		return $house;
	}

	private function createHouseUser(int $id, int $houseId, string $nextcloudUserId, string $displayName, string $role): HouseUser {
		$houseUser = new HouseUser();
		$houseUser->setId($id);
		$houseUser->setHouseId($houseId);
		$houseUser->setNextcloudUserId($nextcloudUserId);
		$houseUser->setDisplayName($displayName);
		$houseUser->setRole($role);
		$houseUser->setJoinedAt(new \DateTime('2026-01-15T10:00:00Z'));

		return $houseUser;
	}
}
