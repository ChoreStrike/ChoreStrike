<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\UserController;
use OCA\ChoreStrike\Service\HouseService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for UserController API endpoints
 */
class UserControllerIntegrationTest extends TestCase {

	private UserController $controller;
	private HouseService $houseService;
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->houseService = $this->createMock(HouseService::class);

		$this->controller = new UserController(
			$this->request,
			$this->houseService
		);
	}

	/**
	 * Index_ReturnsHouseUsers_SuccessResponse
	 */
	public function testIndex_ReturnsHouseUsers_SuccessResponse(): void {
		$users = [
			[
				'userId' => 'user1',
				'displayName' => 'Alice',
				'role' => 'owner',
				'joinedAt' => '2026-01-15T10:00:00Z',
				'lastActive' => '2026-02-24T08:30:00Z',
			],
			[
				'userId' => 'user2',
				'displayName' => 'Bob',
				'role' => 'member',
				'joinedAt' => '2026-01-20T14:00:00Z',
				'lastActive' => '2026-02-24T16:45:00Z',
			],
			[
				'userId' => 'user3',
				'displayName' => 'Carol',
				'role' => 'child',
				'joinedAt' => '2026-02-01T09:00:00Z',
				'lastActive' => '2026-02-23T19:20:00Z',
			],
		];

		$this->houseService->method('getHouseUsers')
			->willReturn($users);

		$response = $this->controller->index();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(3, $data['data']);
		$this->assertEquals('Alice', $data['data'][0]['displayName']);
	}

	/**
	 * Invite_ValidEmail_ReturnsInvitedUser
	 */
	public function testInvite_ValidEmail_ReturnsInvitedUser(): void {
		$email = 'newuser@example.com';
		$role = 'member';
		$invitedUser = [
			'userId' => 'user4',
			'displayName' => 'newuser@example.com',
			'email' => $email,
			'role' => $role,
			'inviteStatus' => 'pending',
			'invitedAt' => '2026-02-24T18:00:00Z',
		];

		$this->houseService->method('inviteUser')
			->with($email, $role)
			->willReturn($invitedUser);

		$response = $this->controller->invite($email, $role);

		$this->assertEquals(201, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($email, $data['data']['email']);
		$this->assertEquals('pending', $data['data']['inviteStatus']);
	}

	/**
	 * Invite_InvalidEmail_ReturnsValidationError
	 */
	public function testInvite_InvalidEmail_ReturnsValidationError(): void {
		$invalidEmail = 'not-an-email';
		$role = 'member';

		$this->houseService->method('inviteUser')
			->with($invalidEmail, $role)
			->willThrowException(new \InvalidArgumentException('Invalid email address'));

		$response = $this->controller->invite($invalidEmail, $role);

		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
	}

	/**
	 * Remove_ExistingMember_ReturnsSuccess
	 */
	public function testRemove_ExistingMember_ReturnsSuccess(): void {
		$userId = 'user2';

		$this->houseService->method('removeUser')
			->with($userId)
			->willReturn(true);

		$response = $this->controller->remove($userId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('User removed successfully', $data['data']['message']);
	}

	/**
	 * Remove_Owner_ReturnsForbidden
	 */
	public function testRemove_Owner_ReturnsForbidden(): void {
		$ownerId = 'user1';

		$this->houseService->method('removeUser')
			->with($ownerId)
			->willThrowException(new \Exception('Cannot remove house owner'));

		$response = $this->controller->remove($ownerId);

		$this->assertEquals(403, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * UpdateRole_ValidRole_ReturnsUpdatedUser
	 */
	public function testUpdateRole_ValidRole_ReturnsUpdatedUser(): void {
		$userId = 'user2';
		$newRole = 'admin';
		$updatedUser = [
			'userId' => $userId,
			'displayName' => 'Bob',
			'role' => $newRole,
			'updatedAt' => '2026-02-24T18:00:00Z',
		];

		$this->houseService->method('updateUserRole')
			->with($userId, $newRole)
			->willReturn($updatedUser);

		$response = $this->controller->updateRole($userId, $newRole);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($newRole, $data['data']['role']);
	}

	/**
	 * UpdateRole_InvalidRole_ReturnsValidationError
	 */
	public function testUpdateRole_InvalidRole_ReturnsValidationError(): void {
		$userId = 'user2';
		$invalidRole = 'superadmin';

		$this->houseService->method('updateUserRole')
			->with($userId, $invalidRole)
			->willThrowException(new \InvalidArgumentException('Invalid role'));

		$response = $this->controller->updateRole($userId, $invalidRole);

		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Stats_ReturnsUserStatistics_SuccessResponse
	 */
	public function testStats_ReturnsUserStatistics_SuccessResponse(): void {
		$userId = 'user1';
		$stats = [
			'userId' => $userId,
			'displayName' => 'Alice',
			'totalTasksCompleted' => 45,
			'tasksThisWeek' => 8,
			'contributionScore' => 1250,
			'teamSparks' => 620,
			'averageTasksPerWeek' => 7.5,
			'favoriteRoom' => 'Kitchen',
			'joinedAt' => '2026-01-15T10:00:00Z',
		];

		$this->houseService->method('getUserStats')
			->with($userId)
			->willReturn($stats);

		$response = $this->controller->stats($userId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($stats, $data['data']);
	}
}
