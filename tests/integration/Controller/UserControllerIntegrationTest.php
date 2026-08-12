<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\UserController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\TitleService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for UserController API endpoints
 */
class UserControllerIntegrationTest extends TestCase {
	private UserController $controller;
	private HouseService $houseService;
	private HouseUserMapper $houseUserMapper;
	private IUserManager $userManager;
	private AuthorizationService $authService;
	private IRequest $request;
	private TitleService $titleService;
	private IL10N $l10n;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->userManager = $this->createMock(IUserManager::class);
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

		$this->controller = new UserController(
			$this->request,
			$this->houseUserMapper,
			$this->houseService,
			$this->userManager,
			$this->authService,
			$this->titleService,
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
		$this->authService->expects($this->once())
			->method('ensureHouseMember')
			->with($house->getId());
		$this->houseUserMapper->expects($this->once())
			->method('findByHouseId')
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
		$nextcloudUser = $this->createMock(IUser::class);
		$nextcloudUser->method('getUID')->willReturn('newuser');
		$nextcloudUser->method('getDisplayName')->willReturn('New User');
		$role = 'member';
		$houseUser = $this->createHouseUser(3, $house->getId(), 'newuser', 'New User', $role);

		$this->userManager->expects($this->once())
			->method('getByEmail')
			->with('newuser@example.com')
			->willReturn([$nextcloudUser]);
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
		$this->houseUserMapper->expects($this->once())
			->method('createOrGet')
			->with($house->getId(), 'newuser', 'New User', $role)
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
		$this->userManager->expects($this->once())
			->method('getByEmail')
			->with('missing-user@example.com')
			->willReturn([]);

		$response = $this->controller->invite('missing-user@example.com', 'member');

		$this->assertEquals(404, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('USER_NOT_FOUND', $data['error']['code']);
	}

	/**
	 * Remove_ExistingMember_ReturnsSuccess
	 */
	public function testRemove_ExistingMember_ReturnsSuccess(): void {
		$houseUser = $this->createHouseUser(2, 1, 'user2', 'Bob', 'member');

		$this->houseUserMapper->expects($this->once())
			->method('find')
			->with(2)
			->willReturn($houseUser);
		$this->authService->expects($this->once())
			->method('requireHouseOwner')
			->with($houseUser->getHouseId());
		$this->houseUserMapper->expects($this->once())
			->method('delete')
			->with($houseUser);

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
		$owner = $this->createHouseUser(1, 1, 'owner', 'Alice', 'owner');

		$this->houseUserMapper->expects($this->once())
			->method('find')
			->with(1)
			->willReturn($owner);
		$this->authService->expects($this->once())
			->method('requireHouseOwner')
			->with($owner->getHouseId());

		$response = $this->controller->remove(1);

		$this->assertEquals(403, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('CANNOT_REMOVE_OWNER', $data['error']['code']);
	}

	/**
	 * UpdateRole_ValidRole_ReturnsUpdatedUser
	 */
	public function testUpdateRole_ValidRole_ReturnsUpdatedUser(): void {
		$houseUser = $this->createHouseUser(2, 1, 'user2', 'Bob', 'member');

		$this->houseUserMapper->expects($this->once())
			->method('find')
			->with(2)
			->willReturn($houseUser);
		$this->authService->expects($this->once())
			->method('requireHouseOwner')
			->with($houseUser->getHouseId());
		$this->houseUserMapper->expects($this->once())
			->method('update')
			->with($this->callback(static function (HouseUser $updatedUser): bool {
				return $updatedUser->getRole() === 'child';
			}))
			->willReturnCallback(static fn (HouseUser $updatedUser): HouseUser => $updatedUser);

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
		$houseUser = $this->createHouseUser(2, 1, 'user2', 'Bob', 'member');

		$this->houseUserMapper->expects($this->once())
			->method('find')
			->with(2)
			->willReturn($houseUser);
		$this->authService->expects($this->once())
			->method('requireHouseOwner')
			->with($houseUser->getHouseId());
		$this->houseUserMapper->expects($this->never())
			->method('update');

		$response = $this->controller->updateRole(2, 'superadmin');

		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
	}

	/**
	 * Update_SelfProfileUpdate_ReturnsUpdatedUser
	 */
	public function testUpdate_SelfProfileUpdate_ReturnsUpdatedUser(): void {
		$house = $this->createHouse();
		$houseUser = $this->createHouseUser(2, 1, 'user2', 'Bob', 'member');
		$currentUser = $this->createHouseUser(2, 1, 'user2', 'Bob', 'member');

		$this->houseUserMapper->expects($this->once())
			->method('find')
			->with(2)
			->willReturn($houseUser);
		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');
		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->with('instance-123')
			->willReturn($house);
		$this->authService->expects($this->once())
			->method('ensureHouseMember')
			->with($houseUser->getHouseId())
			->willReturn($currentUser);
		$this->houseUserMapper->expects($this->once())
			->method('update')
			->with($this->callback(static function (HouseUser $updatedUser): bool {
				return $updatedUser->getDisplayName() === 'Bobby'
					&& $updatedUser->getSchedulingPreferences() === ['weekdays' => ['monday' => true]];
			}))
			->willReturnCallback(static fn (HouseUser $updatedUser): HouseUser => $updatedUser);

		$response = $this->controller->update(2, 'Bobby', ['weekdays' => ['monday' => true]]);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('Bobby', $data['data']['displayName']);
		$this->assertEquals(['weekdays' => ['monday' => true]], $data['data']['schedulingPreferences']);
	}

	/**
	 * Stats_ReturnsUserStatistics_SuccessResponse
	 */
	public function testStats_ReturnsUserStatistics_SuccessResponse(): void {
		$houseUser = $this->createHouseUser(1, 1, 'user1', 'Alice', 'owner');
		$houseUser->setContributionScore(1250);
		$houseUser->setCoins(620);
		$houseUser->setCurrentStreak(8);
		$houseUser->setLastActivity(new \DateTime('2026-02-24T18:00:00Z'));

		$this->houseUserMapper->expects($this->once())
			->method('find')
			->with(1)
			->willReturn($houseUser);
		$this->authService->expects($this->once())
			->method('ensureHouseMember')
			->with($houseUser->getHouseId());

		$response = $this->controller->stats(1);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('user1', $data['data']['user']['nextcloudUserId']);
		$this->assertEquals(1250, $data['data']['stats']['contributionScore']);
		$this->assertEquals(620, $data['data']['stats']['coins']);
		$this->assertEquals(8, $data['data']['stats']['currentStreak']);
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
