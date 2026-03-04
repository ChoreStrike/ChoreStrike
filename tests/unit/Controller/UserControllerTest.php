<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\UserController;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\House;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UserController
 */
class UserControllerTest extends TestCase
{
    private UserController $controller;
    private $houseUserMapper;
    private $houseService;
    private $userManager;
    private $authService;
    private $request;
    private IL10N $l10n;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->houseUserMapper = $this->createMock(HouseUserMapper::class);
        $this->houseService = $this->createMock(HouseService::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->authService = $this->createMock(AuthorizationService::class);
        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')
            ->willReturnCallback(static fn (string $text): string => $text);

        $this->controller = new UserController(
            $this->request,
            $this->houseUserMapper,
            $this->houseService,
            $this->userManager,
            $this->authService,
            $this->l10n
        );
    }

    /**
     * Test GET /users returns list successfully
     */
    public function testIndex_ReturnsUsersSuccessfully(): void
    {
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
            ->method('requireHouseMember')
            ->with(1);

        $this->houseUserMapper->expects($this->once())
            ->method('findByHouseId')
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
    public function testIndex_HandlesException(): void
    {
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
     * Test INVITE validates empty user ID
     */
    public function testInvite_ValidatesEmptyUserId(): void
    {
        $response = $this->controller->invite('');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());

        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
        $this->assertEquals('User ID is required', $data['error']['message']);
    }

    /**
     * Test INVITE validates invalid role
     */
    public function testInvite_ValidatesInvalidRole(): void
    {
        $response = $this->controller->invite('user1', 'invalid_role');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());

        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
    }

    /**
     * Test INVITE validates non-existent user
     */
    public function testInvite_ValidatesNonExistentUser(): void
    {
        $this->userManager->expects($this->once())
            ->method('get')
            ->with('nonexistent')
            ->willReturn(null);

        $response = $this->controller->invite('nonexistent');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());

        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('USER_NOT_FOUND', $data['error']['code']);
    }
}
