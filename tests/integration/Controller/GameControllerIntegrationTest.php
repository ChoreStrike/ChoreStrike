<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\GameController;
use OCA\ChoreStrike\Service\GameLogicService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for GameController API endpoints
 */
class GameControllerIntegrationTest extends TestCase {

	private GameController $controller;
	private GameLogicService $gameLogicService;
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->gameLogicService = $this->createMock(GameLogicService::class);

		$this->controller = new GameController(
			$this->request,
			$this->gameLogicService
		);
	}

	/**
	 * State_ReturnsGameState_SuccessResponse
	 */
	public function testState_ReturnsGameState_SuccessResponse(): void {
		$gameState = [
			'houseHealth' => 82,
			'totalTeamSparks' => 1250,
			'activeCombos' => [
				['type' => 'speed', 'multiplier' => 2, 'timeRemaining' => 120],
			],
			'monsters' => [
				1 => [
					['id' => '1-0', 'type' => 'dust-bunny', 'position' => ['x' => 20, 'y' => 30]],
					['id' => '1-1', 'type' => 'slime-blob', 'position' => ['x' => 60, 'y' => 70]],
				],
			],
			'currentWeek' => [
				'start' => '2026-02-23',
				'end' => '2026-03-01',
			],
		];

		$this->gameLogicService->method('getGameState')
			->willReturn($gameState);

		$response = $this->controller->state();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($gameState, $data['data']);
	}

	/**
	 * Leaderboard_ReturnsRankedUsers_SuccessResponse
	 */
	public function testLeaderboard_ReturnsRankedUsers_SuccessResponse(): void {
		$leaderboard = [
			[
				'userId' => 'user1',
				'displayName' => 'Alice',
				'contributionScore' => 450,
				'teamSparks' => 230,
				'tasksCompleted' => 12,
				'rank' => 1,
			],
			[
				'userId' => 'user2',
				'displayName' => 'Bob',
				'contributionScore' => 380,
				'teamSparks' => 190,
				'tasksCompleted' => 10,
				'rank' => 2,
			],
			[
				'userId' => 'user3',
				'displayName' => 'Carol',
				'contributionScore' => 320,
				'teamSparks' => 160,
				'tasksCompleted' => 8,
				'rank' => 3,
			],
		];

		$this->gameLogicService->method('getLeaderboard')
			->willReturn($leaderboard);

		$response = $this->controller->leaderboard();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(3, $data['data']);
		$this->assertEquals('Alice', $data['data'][0]['displayName']);
	}

	/**
	 * Medals_ReturnsAllMedals_SuccessResponse
	 */
	public function testMedals_ReturnsAllMedals_SuccessResponse(): void {
		$medals = [
			[
				'id' => 1,
				'userId' => 'user1',
				'medalType' => 'team_anchor',
				'value' => 12,
				'awardedAt' => '2026-02-24T10:00:00Z',
			],
			[
				'id' => 2,
				'userId' => 'user1',
				'medalType' => 'flow_keeper',
				'value' => 5,
				'awardedAt' => '2026-02-24T10:00:00Z',
			],
			[
				'id' => 3,
				'userId' => 'user2',
				'medalType' => 'team_player',
				'value' => 6,
				'awardedAt' => '2026-02-23T15:30:00Z',
			],
		];

		$this->gameLogicService->method('getMedals')
			->willReturn($medals);

		$response = $this->controller->medals();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(3, $data['data']);
	}

	/**
	 * CheckMedals_AwardsNewMedals_ReturnsAwardedMedals
	 */
	public function testCheckMedals_AwardsNewMedals_ReturnsAwardedMedals(): void {
		$newMedals = [
			[
				'id' => 4,
				'userId' => 'user1',
				'medalType' => 'room_guardian',
				'value' => 4,
				'awardedAt' => '2026-02-24T18:00:00Z',
			],
		];

		$this->gameLogicService->method('checkAndAwardMedals')
			->willReturn($newMedals);

		$response = $this->controller->checkMedals();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($newMedals, $data['data']);
		$this->assertEquals('room_guardian', $data['data'][0]['medalType']);
	}

	/**
	 * HandleTaskCompletion_ProcessesCompletion_ReturnsScoreAndSparks
	 */
	public function testHandleTaskCompletion_ProcessesCompletion_ReturnsScoreAndSparks(): void {
		$userId = 'user1';
		$roomTaskId = 5;
		$result = [
			'contributionScore' => 35,
			'teamSparks' => 18,
			'comboTriggered' => true,
			'comboType' => 'speed',
			'comboMultiplier' => 2,
			'monstersDefeated' => 1,
		];

		$this->gameLogicService->method('handleTaskCompletion')
			->with($userId, $roomTaskId)
			->willReturn($result);

		$response = $this->controller->handleTaskCompletion($userId, $roomTaskId);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(35, $data['data']['contributionScore']);
		$this->assertEquals(18, $data['data']['teamSparks']);
		$this->assertTrue($data['data']['comboTriggered']);
	}
}
