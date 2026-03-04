<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\GameController;
use OCA\ChoreStrike\Service\GameService;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Entity\House;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GameController
 */
class GameControllerTest extends TestCase
{
    private GameController $controller;
    private $gameService;
    private $houseService;
    private $authService;
    private $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->gameService = $this->createMock(GameService::class);
        $this->houseService = $this->createMock(HouseService::class);
        $this->authService = $this->createMock(AuthorizationService::class);

        $this->controller = new GameController(
            $this->request,
            $this->gameService,
            $this->houseService,
            $this->authService
        );
    }

    /**
     * Test GET /game/state returns state successfully
     */
    public function testState_ReturnsStateSuccessfully(): void
    {
        $house = new House();
        $house->setId(1);

        $gameState = [
            'houseHealth' => 85,
            'activeMonsters' => 3,
            'completedTasks' => 42,
        ];

        $this->houseService->expects($this->once())
            ->method('getInstanceId')
            ->willReturn('instance-123');

        $this->houseService->expects($this->once())
            ->method('getOrCreateHouse')
            ->willReturn($house);

        $this->authService->expects($this->once())
            ->method('requireHouseMember')
            ->with(1);

        $this->gameService->expects($this->once())
            ->method('getGameState')
            ->with(1)
            ->willReturn($gameState);

        $response = $this->controller->state();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(85, $data['data']['houseHealth']);
    }

    /**
     * Test GET /game/leaderboard returns leaderboard
     */
    public function testLeaderboard_ReturnsLeaderboard(): void
    {
        $house = new House();
        $house->setId(1);

        $leaderboard = [
            ['userId' => 'user1', 'score' => 100],
            ['userId' => 'user2', 'score' => 75],
        ];

        $this->houseService->expects($this->once())
            ->method('getInstanceId')
            ->willReturn('instance-123');

        $this->houseService->expects($this->once())
            ->method('getOrCreateHouse')
            ->willReturn($house);

        $this->authService->expects($this->once())
            ->method('requireHouseMember')
            ->with(1);

        $this->gameService->expects($this->once())
            ->method('getLeaderboard')
            ->with(1)
            ->willReturn($leaderboard);

        $response = $this->controller->leaderboard();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']);
    }

    /**
     * Test GET /game/monsters returns monsters list
     */
    public function testMonsters_ReturnsMonsters(): void
    {
        $house = new House();
        $house->setId(1);

        $monsters = [
            ['id' => 1, 'type' => 'dust_bunny', 'roomId' => 1],
            ['id' => 2, 'type' => 'mess_monster', 'roomId' => 2],
        ];

        $this->houseService->expects($this->once())
            ->method('getInstanceId')
            ->willReturn('instance-123');

        $this->houseService->expects($this->once())
            ->method('getOrCreateHouse')
            ->willReturn($house);

        $this->authService->expects($this->once())
            ->method('requireHouseMember')
            ->with(1);

        $this->gameService->expects($this->once())
            ->method('getMonsters')
            ->with(1)
            ->willReturn($monsters);

        $response = $this->controller->monsters();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']);
    }
}
