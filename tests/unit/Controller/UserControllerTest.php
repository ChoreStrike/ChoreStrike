<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\UserController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\TitleService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UserController
 */
class UserControllerTest extends TestCase {
	private UserController $controller;
	private $houseService;
	private $authService;
	private $request;
	private $titleService;
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

		// RF-058-04: the controller owns no mapper access — membership
		// persistence and scoped lookups live in HouseService.
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
	 * Stub the instance-house preamble (getInstanceId + getOrCreateHouse +
	 * assertHouseMember) every non-bootstrap endpoint now runs first
	 * (CS-PLAN-061 §3.1).
	 */
	private function expectInstanceHouse(int $houseId = 3): void {
		$house = new House();
		$house->setId($houseId);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')
			->with($houseId)
			->willReturn(new HouseUser());
	}

	/**
	 * Test GET /users returns list successfully
	 */
	public function testIndex_ReturnsUsersSuccessfully(): void {
		$house = new House();
		$house->setId(1);

		$user1 = new HouseUser();
		$user1->setId(1);
		$user1->setNextcloudUserId('user1');

		$user2 = new HouseUser();
		$user2->setId(2);
		$user2->setNextcloudUserId('user2');

		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');

		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->with('instance-123')
			->willReturn($house);

		$this->authService->expects($this->once())
			->method('assertHouseMember')
			->with(1);

		$this->houseService->expects($this->once())
			->method('getHouseMembers')
			->with(1)
			->willReturn([$user1, $user2]);

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(2, $data['data']);
	}

	/**
	 * Test GET /users handles exceptions
	 */
	public function testIndex_HandlesException(): void {
		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willThrowException(new \Exception('Database error'));

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(500, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('USERS_ERROR', $data['error']['code']);
	}

	/**
	 * Test GET /users/current returns the authenticated house user.
	 */
	public function testCurrent_ReturnsAuthenticatedHouseUser(): void {
		$house = new House();
		$house->setId(5);

		$houseUser = new HouseUser();
		$houseUser->setId(9);
		$houseUser->setHouseId(5);
		$houseUser->setNextcloudUserId('owner');
		$houseUser->setDisplayName('Owner User');
		$houseUser->setRole('owner');
		$houseUser->setJoinedAt(new \DateTime('2026-03-01T08:00:00Z'));

		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-current');

		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->with('instance-current')
			->willReturn($house);

		$this->authService->expects($this->once())
			->method('ensureHouseMember')
			->with(5)
			->willReturn($houseUser);

		$response = $this->controller->current();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('owner', $data['data']['nextcloudUserId']);
		$this->assertEquals('Owner User', $data['data']['displayName']);
	}

	/**
	 * Test INVITE validates empty email
	 */
	public function testInvite_ValidatesEmptyEmail(): void {
		$response = $this->controller->invite('');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
		$this->assertEquals('Email is required', $data['error']['message']);
	}

	/**
	 * Test INVITE validates invalid role
	 */
	public function testInvite_ValidatesInvalidRole(): void {
		$response = $this->controller->invite('test@example.com', 'invalid_role');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
	}

	/**
	 * Test INVITE validates non-existent user by email
	 */
	public function testInvite_ValidatesNonExistentUser(): void {
		$house = new House();
		$house->setId(1);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);

		// RF-058-04: the email lookup + membership write live in the service.
		$this->houseService->expects($this->once())
			->method('inviteMember')
			->with(1, 'nonexistent@example.com', 'member')
			->willThrowException(new NotFoundException('No Nextcloud user found with this email address'));

		$response = $this->controller->invite('nonexistent@example.com');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(404, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('USER_NOT_FOUND', $data['error']['code']);
	}

	/**
	 * Test INVITE successfully invites user by email
	 */
	public function testInvite_InvitesUserByEmailSuccessfully(): void {
		$house = new House();
		$house->setId(1);

		$houseUser = new HouseUser();
		$houseUser->setId(1);
		$houseUser->setHouseId(1);
		$houseUser->setNextcloudUserId('user1');
		$houseUser->setDisplayName('Test User');
		$houseUser->setRole('member');
		$houseUser->setJoinedAt(new \DateTime('2026-03-01T08:00:00Z'));

		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');

		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->with('instance-123')
			->willReturn($house);

		$this->authService->expects($this->once())
			->method('requireHouseOwner')
			->with(1);

		$this->houseService->expects($this->once())
			->method('inviteMember')
			->with(1, 'test@example.com', 'member')
			->willReturn($houseUser);

		$response = $this->controller->invite('test@example.com', 'member');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('user1', $data['data']['nextcloudUserId']);
		$this->assertEquals('Test User', $data['data']['displayName']);
	}

	/**
	 * Test DELETE /users/{id} delegates the aggregate removal to the
	 * service (CR-SVC-26) — the controller contains no mapper deletes.
	 */
	public function testRemove_DelegatesToHouseServiceAndReturnsSuccess(): void {
		$this->expectInstanceHouse(3);

		$member = new HouseUser();
		$member->setId(7);
		$member->setHouseId(3);
		$member->setNextcloudUserId('member1');
		$member->setRole('member');

		$this->houseService->expects($this->once())
			->method('findHouseUserScopedToHouse')
			->with(7, 3)
			->willReturn($member);

		$this->authService->expects($this->once())
			->method('requireHouseOwner')
			->with(3);

		$this->houseService->expects($this->once())
			->method('removeHouseMember')
			->with(7);

		$response = $this->controller->remove(7);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * Test DELETE /users/{id} maps the service's owner-target guard to the
	 * endpoint-specific CANNOT_REMOVE_OWNER 403 (response contract unchanged).
	 */
	public function testRemove_OwnerTarget_ReturnsCannotRemoveOwner403(): void {
		$this->expectInstanceHouse(3);

		$owner = new HouseUser();
		$owner->setId(7);
		$owner->setHouseId(3);
		$owner->setNextcloudUserId('owner1');
		$owner->setRole('owner');

		$this->houseService->expects($this->once())
			->method('findHouseUserScopedToHouse')
			->with(7, 3)
			->willReturn($owner);

		$this->authService->expects($this->once())
			->method('requireHouseOwner')
			->with(3);

		$this->houseService->expects($this->once())
			->method('removeHouseMember')
			->with(7)
			->willThrowException(new \OCA\ChoreStrike\Exception\CannotRemoveOwnerException('Cannot remove the house owner'));

		$response = $this->controller->remove(7);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(403, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('CANNOT_REMOVE_OWNER', $data['error']['code']);
	}

	/**
	 * Test DELETE /users/{id} denies non-owner callers with the standard
	 * 403 FORBIDDEN shape.
	 */
	public function testRemove_NonOwnerCaller_ReturnsForbidden403(): void {
		$this->expectInstanceHouse(3);

		$member = new HouseUser();
		$member->setId(7);
		$member->setHouseId(3);
		$member->setRole('member');

		$this->houseService->expects($this->once())
			->method('findHouseUserScopedToHouse')
			->with(7, 3)
			->willReturn($member);

		$this->authService->expects($this->once())
			->method('requireHouseOwner')
			->with(3)
			->willThrowException(new \OCA\ChoreStrike\Exception\ForbiddenException('Owners only'));

		$this->houseService->expects($this->never())->method('removeHouseMember');

		$response = $this->controller->remove(7);

		$this->assertEquals(403, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('FORBIDDEN', $data['error']['code']);
	}

	/**
	 * Test DELETE /users/{id} returns 404 for an unknown target (same
	 * shape as update()/titles()). The service-scoped lookup
	 * (RF-058-04) maps both a nonexistent and a foreign-house id to the
	 * USER_NOT_FOUND envelope.
	 */
	public function testRemove_UnknownUser_Returns404(): void {
		$this->expectInstanceHouse(3);

		// The scoped lookup lives in the service (RF-058-04): a miss is
		// simply null.
		$this->houseService->expects($this->once())
			->method('findHouseUserScopedToHouse')
			->with(999, 3)
			->willReturn(null);

		$response = $this->controller->remove(999);

		$this->assertEquals(404, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('USER_NOT_FOUND', $data['error']['code']);
	}

	/**
	 * Test DELETE /users/{id} returns 404 (not 403/500) for a target that
	 * belongs to a foreign house: the scoped lookup never lets the request
	 * reach the owner gate, so ids cannot be probed across houses.
	 */
	public function testRemove_ForeignHouseUser_Returns404(): void {
		$this->expectInstanceHouse(3);

		$foreigner = new HouseUser();
		$foreigner->setId(8);
		$foreigner->setHouseId(99);
		$foreigner->setNextcloudUserId('outsider');

		// Foreign-house ids resolve to null inside the service-scoped
		// lookup (RF-058-04) — the owner gate is never reached.
		$this->houseService->expects($this->once())
			->method('findHouseUserScopedToHouse')
			->with(8, 3)
			->willReturn(null);

		$this->authService->expects($this->never())->method('requireHouseOwner');
		$this->houseService->expects($this->never())->method('removeHouseMember');

		$response = $this->controller->remove(8);

		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals('USER_NOT_FOUND', $response->getData()['error']['code']);
	}

	/**
	 * Build a HouseUser for the updateRole mapping tests (BUG-SEC-002).
	 */
	private function houseUserForUpdateRole(int $id, string $ncUserId, string $role): HouseUser {
		$houseUser = new HouseUser();
		$houseUser->setId($id);
		$houseUser->setHouseId(3);
		$houseUser->setNextcloudUserId($ncUserId);
		$houseUser->setDisplayName('Target User');
		$houseUser->setRole($role);
		$houseUser->setJoinedAt(new \DateTime('2026-03-01T08:00:00Z'));
		return $houseUser;
	}

	/**
	 * Test PUT /users/{id}/role delegates the mutation to HouseService
	 * (BUG-SEC-002) — the controller contains no setRole/update itself.
	 */
	public function testUpdateRole_DelegatesToHouseServiceAndReturnsSuccess(): void {
		$this->expectInstanceHouse(3);

		$member = $this->houseUserForUpdateRole(7, 'member1', 'member');

		$this->houseService->expects($this->once())
			->method('findHouseUserScopedToHouse')
			->with(7, 3)
			->willReturn($member);

		$this->authService->expects($this->once())
			->method('requireHouseOwner')
			->with(3);

		$updated = $this->houseUserForUpdateRole(7, 'member1', 'child');
		$this->houseService->expects($this->once())
			->method('updateUserRole')
			->with(7, 'child')
			->willReturn($updated);

		$response = $this->controller->updateRole(7, 'child');

		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('child', $data['data']['role']);
	}

	/**
	 * Test PUT /users/{id}/role maps the service's whitelist rejection to
	 * VALIDATION_ERROR 400.
	 */
	public function testUpdateRole_InvalidRole_ReturnsValidationError400(): void {
		$this->expectInstanceHouse(3);

		$member = $this->houseUserForUpdateRole(7, 'member1', 'member');

		$this->houseService->expects($this->once())
			->method('findHouseUserScopedToHouse')
			->with(7, 3)
			->willReturn($member);

		$this->authService->expects($this->once())
			->method('requireHouseOwner')
			->with(3);

		$this->houseService->expects($this->once())
			->method('updateUserRole')
			->with(7, 'superadmin')
			->willThrowException(new \InvalidArgumentException('Invalid role. Must be owner, member, or child'));

		$response = $this->controller->updateRole(7, 'superadmin');

		$this->assertEquals(400, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
	}

	/**
	 * Test PUT /users/{id}/role maps the service's self-demotion guard to
	 * the endpoint-specific CANNOT_DEMOTE_SELF 403.
	 */
	public function testUpdateRole_SelfDemotion_ReturnsCannotDemoteSelf403(): void {
		$this->expectInstanceHouse(3);

		$owner = $this->houseUserForUpdateRole(7, 'owner1', 'owner');

		$this->houseService->expects($this->once())
			->method('findHouseUserScopedToHouse')
			->with(7, 3)
			->willReturn($owner);

		$this->authService->expects($this->once())
			->method('requireHouseOwner')
			->with(3);

		$this->houseService->expects($this->once())
			->method('updateUserRole')
			->with(7, 'member')
			->willThrowException(new \OCA\ChoreStrike\Exception\CannotDemoteSelfException('Cannot demote yourself'));

		$response = $this->controller->updateRole(7, 'member');

		$this->assertEquals(403, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('CANNOT_DEMOTE_SELF', $data['error']['code']);
	}

	public function testUpdateRole_ServiceOwnerDenial_ReturnsGenericForbidden(): void {
		$this->expectInstanceHouse(3);
		$this->houseService->method('findHouseUserScopedToHouse')->willReturn($this->houseUserForUpdateRole(7, 'other-owner', 'owner'));
		$this->houseService->method('updateUserRole')->willThrowException(new \OCA\ChoreStrike\Exception\ForbiddenException('Owner role required'));
		$response = $this->controller->updateRole(7, 'member');
		$this->assertSame(403, $response->getStatus());
		$this->assertSame('FORBIDDEN', $response->getData()['error']['code']);
	}

	/**
	 * Test PUT /users/{id}/role maps the service's last-owner guard to the
	 * endpoint-specific CANNOT_DEMOTE_LAST_OWNER 409.
	 */
	public function testUpdateRole_LastOwnerDemotion_ReturnsCannotDemoteLastOwner409(): void {
		$this->expectInstanceHouse(3);

		$owner = $this->houseUserForUpdateRole(7, 'owner1', 'owner');

		$this->houseService->expects($this->once())
			->method('findHouseUserScopedToHouse')
			->with(7, 3)
			->willReturn($owner);

		$this->authService->expects($this->once())
			->method('requireHouseOwner')
			->with(3);

		$this->houseService->expects($this->once())
			->method('updateUserRole')
			->with(7, 'member')
			->willThrowException(new \OCA\ChoreStrike\Exception\ConflictException('Cannot demote the last house owner'));

		$response = $this->controller->updateRole(7, 'member');

		$this->assertEquals(409, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('CANNOT_DEMOTE_LAST_OWNER', $data['error']['code']);
	}

	/**
	 * Test PUT /users/{id}/role denies non-owner callers with the standard
	 * 403 FORBIDDEN shape.
	 */
	public function testUpdateRole_NonOwnerCaller_ReturnsForbidden403(): void {
		$this->expectInstanceHouse(3);

		$member = $this->houseUserForUpdateRole(7, 'member1', 'member');

		$this->houseService->expects($this->once())
			->method('findHouseUserScopedToHouse')
			->with(7, 3)
			->willReturn($member);

		$this->authService->expects($this->once())
			->method('requireHouseOwner')
			->with(3)
			->willThrowException(new \OCA\ChoreStrike\Exception\ForbiddenException('Owners only'));

		$this->houseService->expects($this->never())->method('updateUserRole');

		$response = $this->controller->updateRole(7, 'child');

		$this->assertEquals(403, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('FORBIDDEN', $data['error']['code']);
	}

	/**
	 * Test PUT /users/{id}/role returns 404 for an unknown target.
	 */
	public function testUpdateRole_UnknownUser_Returns404(): void {
		$this->expectInstanceHouse(3);

		$this->houseService->expects($this->once())
			->method('findHouseUserScopedToHouse')
			->with(999, 3)
			->willReturn(null);

		$this->houseService->expects($this->never())->method('updateUserRole');

		$response = $this->controller->updateRole(999, 'child');

		$this->assertEquals(404, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('USER_NOT_FOUND', $data['error']['code']);
	}

	/**
	 * CS-PLAN-081 H3 (Q-081-02): the membership mutation endpoints carry the
	 * native per-user throttle contract — 60 calls per 300 elapsed seconds,
	 * separately per endpoint — as a public UserRateLimit attribute.
	 *
	 * @dataProvider throttledEndpointProvider
	 */
	public function testMembershipEndpoints_CarryTheApprovedRateLimit(string $method): void {
		$reflection = new \ReflectionMethod(UserController::class, $method);
		$attributes = $reflection->getAttributes(\OCP\AppFramework\Http\Attribute\UserRateLimit::class);

		$this->assertCount(1, $attributes, "$method must declare exactly one UserRateLimit attribute");
		$limit = $attributes[0]->newInstance();
		$this->assertSame(60, $limit->getLimit(), "$method limit must match the approved 60 calls");
		$this->assertSame(300, $limit->getPeriod(), "$method period must match the approved 300 elapsed seconds");
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function throttledEndpointProvider(): array {
		return [
			'invite' => ['invite'],
			'remove' => ['remove'],
			'updateRole' => ['updateRole'],
		];
	}
}
