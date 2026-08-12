<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\MinigameController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\MinigameScore;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\MinigameService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MinigameController
 */
class MinigameControllerTest extends TestCase {
	private MinigameController $controller;
	private MinigameService $minigameService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->minigameService = $this->createMock(MinigameService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);

		$this->controller = new MinigameController(
			$this->request,
			$this->houseService,
			$this->minigameService,
			$this->authService
		);
	}

	/**
	 * Test GET /minigames/coins returns current coins balance.
	 */
	public function testCoins_ReturnsCurrentCoinsSuccessfully(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');
		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->with('instance-123')
			->willReturn($house);
		$this->authService->expects($this->once())
			->method('ensureHouseMember')
			->with(1);
		$this->authService->expects($this->once())
			->method('getCurrentUserId')
			->willReturn('user1');
		$this->minigameService->expects($this->once())
			->method('getUserCoins')
			->with(1, 'user1')
			->willReturn(42);

		$response = $this->controller->coins();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(42, $data['data']['currentCoins']);
	}

	/**
	 * Test POST /minigames/{gameId}/scores returns earned coins payload.
	 */
	public function testSaveScore_ReturnsCoinsEarnedPayload(): void {
		$house = new House();
		$house->setId(1);

		$savedScore = new MinigameScore();
		$savedScore->setId(9);
		$savedScore->setGameId('snake');
		$savedScore->setScore(120);
		$savedScore->setBoostsUsed(['slow_motion']);
		$savedScore->setCreatedAt(new \DateTime('2026-03-22T13:00:00Z'));

		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');
		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->with('instance-123')
			->willReturn($house);
		$this->authService->expects($this->once())
			->method('ensureHouseMember')
			->with(1);
		$this->authService->expects($this->once())
			->method('getCurrentUserId')
			->willReturn('user1');
		$this->minigameService->expects($this->once())
			->method('saveScore')
			->with(1, 'user1', 'snake', 120, ['slow_motion'])
			->willReturn($savedScore);

		$response = $this->controller->saveScore('snake', 120, ['slow_motion']);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(9, $data['data']['id']);
		$this->assertEquals(12, $data['data']['coinsEarned']);
		$this->assertEquals(['slow_motion'], $data['data']['boostsUsed']);
	}
}
