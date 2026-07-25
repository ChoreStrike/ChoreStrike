<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the AuthorizationService exception model (CS-PLAN-011 S6/S7).
 *
 * Verifies the split between the auto-joining {@see AuthorizationService::ensureHouseMember()}
 * and the pure {@see AuthorizationService::assertHouseMember()}, and that every
 * "require"/"assert" method throws {@see ForbiddenException} (→ 403) rather
 * than a bare \RuntimeException (→ 500).
 */
class AuthorizationServiceExceptionTest extends TestCase
{
    private HouseUserMapper $houseUserMapper;
    private IUserSession $userSession;
    private IUser $user;
    private AuthorizationService $service;

    protected function setUp(): void
    {
        $this->houseUserMapper = $this->createMock(HouseUserMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->user = $this->createMock(IUser::class);

        $this->service = new AuthorizationService($this->houseUserMapper, $this->userSession);
    }

    private function actingAs(string $uid, string $displayName = 'Tester'): void
    {
        $this->user->method('getUID')->willReturn($uid);
        $this->user->method('getDisplayName')->willReturn($displayName);
        $this->userSession->method('getUser')->willReturn($this->user);
    }

    private function makeHouseUser(string $role, string $uid): HouseUser
    {
        // HouseUser extends Nextcloud's Entity, whose accessors are magic
        // (@method/__call), so PHPUnit cannot mock getNextcloudUserId/isOwner.
        // Build a real entity and rely on the magic setters + the real
        // isOwner() (which checks $this->role === 'owner').
        $houseUser = new HouseUser();
        $houseUser->setRole($role);
        $houseUser->setNextcloudUserId($uid);
        return $houseUser;
    }

    // ---------- requireCurrentUserId ----------

    public function testRequireCurrentUserId_WhenAnonymous_ThrowsForbidden(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Not authenticated');
        $this->service->requireCurrentUserId();
    }

    public function testRequireCurrentUserId_WhenAuthenticated_ReturnsUserId(): void
    {
        $this->actingAs('alice');
        $this->assertSame('alice', $this->service->requireCurrentUserId());
    }

    // ---------- ensureHouseMember (auto-join) ----------

    public function testEnsureHouseMember_WhenExistingMember_ReturnsMembershipWithoutCreating(): void
    {
        $this->actingAs('alice');
        $membership = $this->makeHouseUser('member', 'alice');

        $this->houseUserMapper
            ->expects($this->once())
            ->method('findByHouseAndUser')
            ->with(1, 'alice')
            ->willReturn($membership);
        $this->houseUserMapper->expects($this->never())->method('createOrGet');

        $result = $this->service->ensureHouseMember(1);
        $this->assertSame($membership, $result);
    }

    public function testEnsureHouseMember_WhenFirstUser_AutoCreatesOwner(): void
    {
        $this->actingAs('alice');
        $created = $this->makeHouseUser('owner', 'alice');

        $this->houseUserMapper->method('findByHouseAndUser')->willReturn(null);
        $this->houseUserMapper->expects($this->once())->method('findByHouseId')->willReturn([]);
        $this->houseUserMapper
            ->expects($this->once())
            ->method('createOrGet')
            ->with(1, 'alice', $this->anything(), 'owner')
            ->willReturn($created);

        $result = $this->service->ensureHouseMember(1);
        $this->assertSame('owner', $result->getRole());
    }

    public function testEnsureHouseMember_WhenSubsequentUser_AutoCreatesMember(): void
    {
        $this->actingAs('bob');
        $created = $this->makeHouseUser('member', 'bob');

        $this->houseUserMapper->method('findByHouseAndUser')->willReturn(null);
        $this->houseUserMapper->expects($this->once())->method('findByHouseId')->willReturn([$this->makeHouseUser('owner', 'alice')]);
        $this->houseUserMapper
            ->expects($this->once())
            ->method('createOrGet')
            ->with(1, 'bob', $this->anything(), 'member')
            ->willReturn($created);

        $result = $this->service->ensureHouseMember(1);
        $this->assertSame('member', $result->getRole());
    }

    public function testEnsureHouseMember_WhenAnonymous_ThrowsForbidden(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Not authenticated');
        $this->service->ensureHouseMember(1);
    }

    // ---------- assertHouseMember (pure check) ----------

    public function testAssertHouseMember_WhenMember_ReturnsMembership(): void
    {
        $this->actingAs('alice');
        $membership = $this->makeHouseUser('member', 'alice');

        $this->houseUserMapper->method('findByHouseAndUser')->willReturn($membership);
        $this->houseUserMapper->expects($this->never())->method('createOrGet');

        $this->assertSame($membership, $this->service->assertHouseMember(1));
    }

    public function testAssertHouseMember_WhenNotMember_ThrowsForbiddenWithoutCreating(): void
    {
        $this->actingAs('alice');
        $this->houseUserMapper->method('findByHouseAndUser')->willReturn(null);
        $this->houseUserMapper->expects($this->never())->method('createOrGet');
        $this->houseUserMapper->expects($this->never())->method('findByHouseId');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Not a member of this house');
        $this->service->assertHouseMember(1);
    }

    public function testAssertHouseMember_WhenAnonymous_ThrowsForbidden(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(ForbiddenException::class);
        $this->service->assertHouseMember(1);
    }

    // ---------- requireHouseOwner ----------

    public function testRequireHouseOwner_WhenOwner_ReturnsMembership(): void
    {
        $this->actingAs('alice');
        $this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->makeHouseUser('owner', 'alice'));

        $result = $this->service->requireHouseOwner(1);
        $this->assertSame('owner', $result->getRole());
    }

    public function testRequireHouseOwner_WhenMember_ThrowsForbidden(): void
    {
        $this->actingAs('bob');
        $this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->makeHouseUser('member', 'bob'));

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Owner role required');
        $this->service->requireHouseOwner(1);
    }

    // ---------- assertUserInHouse (cross-house guard) ----------

    public function testAssertUserInHouse_WhenTargetIsMember_DoesNotThrow(): void
    {
        $this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->makeHouseUser('member', 'carol'));

        $this->service->assertUserInHouse(1, 'carol');
        $this->assertTrue(true);
    }

    public function testAssertUserInHouse_WhenTargetIsNotMember_ThrowsForbidden(): void
    {
        $this->houseUserMapper->method('findByHouseAndUser')->willReturn(null);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('User is not a member of this house');
        $this->service->assertUserInHouse(1, 'outsider');
    }
}
