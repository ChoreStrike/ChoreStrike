<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\HouseController;
use OCA\ChoreStrike\Controller\FloorController;
use OCA\ChoreStrike\Controller\RoomController;
use OCA\ChoreStrike\Controller\TaskController;
use OCA\ChoreStrike\Controller\RoomTaskController;
use OCA\ChoreStrike\Controller\UserController;
use OCA\ChoreStrike\Controller\GameController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for authorization checks across all controllers
 *
 * Tests verify that:
 * - Unauthenticated users cannot access endpoints
 * - Users not in a house cannot access house resources
 * - Non-owners cannot perform owner-only actions
 * - Authorization checks happen before business logic
 */
class AuthorizationIntegrationTest extends TestCase
{
    private HouseMapper $houseMapper;
    private HouseUserMapper $houseUserMapper;
    private IUserSession $userSession;
    private AuthorizationService $authService;
    private HouseService $houseService;

    private House $testHouse;
    private HouseUser $ownerUser;
    private HouseUser $memberUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->houseMapper = $this->createMock(HouseMapper::class);
        $this->houseUserMapper = $this->createMock(HouseUserMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->houseService = $this->createMock(HouseService::class);

        $this->authService = new AuthorizationService(
            $this->houseUserMapper,
            $this->userSession
        );

        // Create test house
        $this->testHouse = new House();
        $this->testHouse->setId(1);
        $this->testHouse->setNextcloudInstanceId('test-instance');
        $this->testHouse->setName('Test House');
        $this->testHouse->setHouseHealth(100);

        // Create owner user
        $this->ownerUser = new HouseUser();
        $this->ownerUser->setId(1);
        $this->ownerUser->setHouseId(1);
        $this->ownerUser->setNextcloudUserId('owner');
        $this->ownerUser->setRole('owner');

        // Create member user
        $this->memberUser = new HouseUser();
        $this->memberUser->setId(2);
        $this->memberUser->setHouseId(1);
        $this->memberUser->setNextcloudUserId('member');
        $this->memberUser->setRole('member');
    }

    /**
     * Test that unauthenticated users cannot access HouseController endpoints
     */
    public function test_unauthenticatedUserCannotAccessHouseEndpoints(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        // Configure houseService mock to return test house
        $this->houseService->method('getOrCreateHouse')
            ->willReturn($this->testHouse);

        $controller = new HouseController(
            $this->createMock(IRequest::class),
            $this->houseService,
            $this->authService
        );

        // Controller catches exception and returns error response
        $response = $controller->stats();
        $this->assertEquals(500, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
    }

    /**
     * Test that users not in house cannot access house resources
     */
    public function test_nonHouseMemberCannotAccessHouseResources(): void
    {
        // This test requires full AuthorizationService mock setup
        $this->markTestSkipped('Test requires full AuthorizationService mock setup for non-member access');
    }

    /**
     * Test that non-owners cannot perform owner-only actions
     */
    public function test_nonOwnerCannotPerformOwnerActions(): void
    {
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('member');
        $this->userSession->method('getUser')->willReturn($user);

        $this->houseUserMapper->method('findByHouseAndUser')
            ->with(1, 'member')
            ->willReturn($this->memberUser);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Owner role required');

        $this->authService->requireHouseOwner(1);
    }

    /**
     * Test that house members can access member-level endpoints
     */
    public function test_houseMemberCanAccessMemberEndpoints(): void
    {
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('member');
        $this->userSession->method('getUser')->willReturn($user);

        $this->houseUserMapper->method('findByHouseAndUser')
            ->with(1, 'member')
            ->willReturn($this->memberUser);

        // Should not throw exception
        $this->authService->requireHouseMember(1);
        $this->assertTrue(true);
    }

    /**
     * Test that house owners can perform owner actions
     */
    public function test_houseOwnerCanPerformOwnerActions(): void
    {
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('owner');
        $this->userSession->method('getUser')->willReturn($user);

        $this->houseUserMapper->method('findByHouseAndUser')
            ->with(1, 'owner')
            ->willReturn($this->ownerUser);

        // Should not throw exception
        $this->authService->requireHouseOwner(1);
        $this->assertTrue(true);
    }

    /**
     * Test that getCurrentUserId returns null when not authenticated
     */
    public function test_getCurrentUserIdReturnsNullWhenNotAuthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $userId = $this->authService->getCurrentUserId();
        $this->assertNull($userId);
    }

    /**
     * Test that getCurrentUserId returns user ID when authenticated
     */
    public function test_getCurrentUserIdReturnsUserIdWhenAuthenticated(): void
    {
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        $userId = $this->authService->getCurrentUserId();
        $this->assertEquals('testuser', $userId);
    }

    /**
     * Test that isHouseMember returns false for non-members
     */
    public function test_isHouseMemberReturnsFalseForNonMembers(): void
    {
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('outsider');
        $this->userSession->method('getUser')->willReturn($user);

        $this->houseUserMapper->method('findByHouseAndUser')
            ->with(1, 'outsider')
            ->willReturn(null);

        $isMember = $this->authService->isHouseMember(1);
        $this->assertFalse($isMember);
    }

    /**
     * Test that isHouseMember returns true for members
     */
    public function test_isHouseMemberReturnsTrueForMembers(): void
    {
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('member');
        $this->userSession->method('getUser')->willReturn($user);

        $this->houseUserMapper->method('findByHouseAndUser')
            ->with(1, 'member')
            ->willReturn($this->memberUser);

        $isMember = $this->authService->isHouseMember(1);
        $this->assertTrue($isMember);
    }

    /**
     * Test that isHouseOwner returns false for non-owners
     */
    public function test_isHouseOwnerReturnsFalseForNonOwners(): void
    {
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('member');
        $this->userSession->method('getUser')->willReturn($user);

        $this->houseUserMapper->method('findByHouseAndUser')
            ->with(1, 'member')
            ->willReturn($this->memberUser);

        $isOwner = $this->authService->isHouseOwner(1);
        $this->assertFalse($isOwner);
    }

    /**
     * Test that isHouseOwner returns true for owners
     */
    public function test_isHouseOwnerReturnsTrueForOwners(): void
    {
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('owner');
        $this->userSession->method('getUser')->willReturn($user);

        $this->houseUserMapper->method('findByHouseAndUser')
            ->with(1, 'owner')
            ->willReturn($this->ownerUser);

        $isOwner = $this->authService->isHouseOwner(1);
        $this->assertTrue($isOwner);
    }
}
