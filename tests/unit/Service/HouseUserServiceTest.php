<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\HouseUserService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

class HouseUserServiceTest extends TestCase {
	private HouseUserService $service;
	private HouseUserMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(HouseUserMapper::class);
		$this->service = new HouseUserService($this->mapper);
	}

	public function testAddUserToHouseCreatesNewUser(): void {
		$newUser = new HouseUser();
		$newUser->setId(1);
		$newUser->setHouseId(1);
		$newUser->setNextcloudUserId('user1');
		$newUser->setDisplayName('User One');
		$newUser->setRole('member');

		$this->mapper->expects($this->once())
			->method('insert')
			->willReturn($newUser);

		$result = $this->service->addUserToHouse(1, 'user1', 'User One', 'member');

		$this->assertEquals('user1', $result->getNextcloudUserId());
		$this->assertEquals('member', $result->getRole());
	}

	public function testRemoveUserFromHouseDeletesUser(): void {
		$user = new HouseUser();
		$user->setId(1);
		$user->setHouseId(1);
		$user->setNextcloudUserId('user1');

		$this->mapper->expects($this->once())
			->method('findByHouseAndUser')
			->with(1, 'user1')
			->willReturn($user);

		$this->mapper->expects($this->once())
			->method('delete')
			->with($user);

		$this->service->removeUserFromHouse(1, 'user1');
	}

	public function testUpdateUserRoleChangesRole(): void {
		$user = new HouseUser();
		$user->setId(1);
		$user->setHouseId(1);
		$user->setNextcloudUserId('user1');
		$user->setRole('member');

		$this->mapper->expects($this->once())
			->method('findByHouseAndUser')
			->with(1, 'user1')
			->willReturn($user);

		$this->mapper->expects($this->once())
			->method('update')
			->willReturn($user);

		$result = $this->service->updateUserRole(1, 'user1', 'owner');

		$this->assertEquals('owner', $result->getRole());
	}

	public function testGetUserInHouseReturnsUser(): void {
		$user = new HouseUser();
		$user->setId(1);
		$user->setHouseId(1);
		$user->setNextcloudUserId('user1');

		$this->mapper->expects($this->once())
			->method('findByHouseAndUser')
			->with(1, 'user1')
			->willReturn($user);

		$result = $this->service->getUserInHouse(1, 'user1');

		$this->assertEquals('user1', $result->getNextcloudUserId());
	}

	public function testGetHouseUsersReturnsAllUsers(): void {
		$user1 = new HouseUser();
		$user1->setNextcloudUserId('user1');
		$user1->setRole('owner');

		$user2 = new HouseUser();
		$user2->setNextcloudUserId('user2');
		$user2->setRole('member');

		$this->mapper->expects($this->once())
			->method('findByHouse')
			->with(1)
			->willReturn([$user1, $user2]);

		$result = $this->service->getHouseUsers(1);

		$this->assertCount(2, $result);
		$this->assertEquals('user1', $result[0]->getNextcloudUserId());
		$this->assertEquals('user2', $result[1]->getNextcloudUserId());
	}

	public function testIsOwnerReturnsTrueForOwner(): void {
		$user = new HouseUser();
		$user->setRole('owner');

		$this->mapper->expects($this->once())
			->method('findByHouseAndUser')
			->with(1, 'user1')
			->willReturn($user);

		$result = $this->service->isOwner(1, 'user1');

		$this->assertTrue($result);
	}

	public function testIsOwnerReturnsFalseForNonOwner(): void {
		$user = new HouseUser();
		$user->setRole('member');

		$this->mapper->expects($this->once())
			->method('findByHouseAndUser')
			->with(1, 'user1')
			->willReturn($user);

		$result = $this->service->isOwner(1, 'user1');

		$this->assertFalse($result);
	}

	public function testIsOwnerReturnsFalseForNonExistentUser(): void {
		$this->mapper->expects($this->once())
			->method('findByHouseAndUser')
			->with(1, 'user1')
			->willThrowException(new DoesNotExistException(''));

		$result = $this->service->isOwner(1, 'user1');

		$this->assertFalse($result);
	}

	public function testIsMemberReturnsTrueForMember(): void {
		$user = new HouseUser();
		$user->setRole('member');

		$this->mapper->expects($this->once())
			->method('findByHouseAndUser')
			->with(1, 'user1')
			->willReturn($user);

		$result = $this->service->isMember(1, 'user1');

		$this->assertTrue($result);
	}

	public function testIsChildReturnsTrueForChild(): void {
		$user = new HouseUser();
		$user->setRole('child');

		$this->mapper->expects($this->once())
			->method('findByHouseAndUser')
			->with(1, 'user1')
			->willReturn($user);

		$result = $this->service->isChild(1, 'user1');

		$this->assertTrue($result);
	}

	public function testGetOwnersReturnsOnlyOwners(): void {
		$owner = new HouseUser();
		$owner->setNextcloudUserId('owner1');
		$owner->setRole('owner');

		$this->mapper->expects($this->once())
			->method('findByHouseAndRole')
			->with(1, 'owner')
			->willReturn([$owner]);

		$result = $this->service->getOwners(1);

		$this->assertCount(1, $result);
		$this->assertEquals('owner', $result[0]->getRole());
	}

	public function testGetMembersReturnsOnlyMembers(): void {
		$member = new HouseUser();
		$member->setNextcloudUserId('member1');
		$member->setRole('member');

		$this->mapper->expects($this->once())
			->method('findByHouseAndRole')
			->with(1, 'member')
			->willReturn([$member]);

		$result = $this->service->getMembers(1);

		$this->assertCount(1, $result);
		$this->assertEquals('member', $result[0]->getRole());
	}

	public function testGetChildrenReturnsOnlyChildren(): void {
		$child = new HouseUser();
		$child->setNextcloudUserId('child1');
		$child->setRole('child');

		$this->mapper->expects($this->once())
			->method('findByHouseAndRole')
			->with(1, 'child')
			->willReturn([$child]);

		$result = $this->service->getChildren(1);

		$this->assertCount(1, $result);
		$this->assertEquals('child', $result[0]->getRole());
	}
}
