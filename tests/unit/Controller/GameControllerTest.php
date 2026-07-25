<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\GameController;
use OCA\ChoreStrike\Service\GameLogicService;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Db\House;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GameController
 */
class GameControllerTest extends TestCase
{
    private GameController $controller;
    private $gameLogicService;
    private $houseService;
    private $authService;
    private $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->gameLogicService = $this->createMock(GameLogicService::class);
        $this->houseService = $this->createMock(HouseService::class);
        $this->authService = $this->createMock(AuthorizationService::class);

        $this->controller = new GameController(
            $this->request,
            $this->gameLogicService,
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
            'completedTasks' => 42,
        ];

        $weeklyGoal = [
            'dueCount' => 5,
            'completedCount' => 4,
            'percent' => 80,
            'targetPercent' => 80,
            'achieved' => true,
            'weekStart' => '2026-07-13T00:00:00+00:00',
            'weekEnd' => '2026-07-19T23:59:59+00:00',
        ];

        $this->houseService->method('getInstanceId')->willReturn('instance-123');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->houseService->method('getWeeklyGoalProgress')->with(1)->willReturn($weeklyGoal);
        $this->authService->method('ensureHouseMember');

        $this->gameLogicService->expects($this->once())
            ->method('getGameState')
            ->with(1)
            ->willReturn($gameState);

        $response = $this->controller->state();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(85, $data['data']['houseHealth']);
        $this->assertSame($weeklyGoal, $data['data']['weeklyGoal']);
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

        $this->houseService->method('getInstanceId')->willReturn('instance-123');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->method('ensureHouseMember');

        $this->gameLogicService->expects($this->once())
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
}
