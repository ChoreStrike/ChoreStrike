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
	 * Test POST /minigames/boosts/{boostId}/purchase returns the curated
	 * validation message with the endpoint-specific error code (CR-SVC-49).
	 */
	public function testPurchaseBoost_InvalidBoost_Returns400WithCuratedMessage(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember');
		$this->authService->method('getCurrentUserId')->willReturn('user1');
		$this->minigameService->method('purchaseBoost')
			->willThrowException(new \InvalidArgumentException('Unknown boost: mystery-box'));

		$response = $this->controller->purchaseBoost('mystery-box');

		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('PURCHASE_BOOST_ERROR', $data['error']['code']);
		$this->assertEquals('Unknown boost: mystery-box', $data['error']['message']);
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
			->willReturn(['score' => $savedScore, 'coinsEarned' => 12]);

		$response = $this->controller->saveScore('snake', 120, ['slow_motion']);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(9, $data['data']['id']);
		$this->assertEquals(12, $data['data']['coinsEarned']);
		$this->assertEquals(['slow_motion'], $data['data']['boostsUsed']);
	}

	/**
	 * SaveScore_UsesServiceCoinsEarned_NoClientInputRecomputation
	 *
	 * CR-SVC-51 regression: when the service awards 0 coins (e.g. missing
	 * house_user row) the response must report 0 — the old controller
	 * recomputed floor(score/10) from raw client input and claimed 12 for
	 * a 120-point score the service did not award coins for.
	 */
	public function testSaveScore_UsesServiceCoinsEarned_NoClientInputRecomputation(): void {
		$house = new House();
		$house->setId(1);

		$savedScore = new MinigameScore();
		$savedScore->setId(10);
		$savedScore->setGameId('snake');
		$savedScore->setScore(120);
		$savedScore->setCreatedAt(new \DateTime('2026-03-22T13:00:00Z'));

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember');
		$this->authService->method('getCurrentUserId')->willReturn('user1');
		// Divergence case: score 120 would floor to 12 coins, but the
		// service actually awarded 0.
		$this->minigameService->method('saveScore')
			->willReturn(['score' => $savedScore, 'coinsEarned' => 0]);

		$response = $this->controller->saveScore('snake', 120, []);

		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame(0, $data['data']['coinsEarned']);
	}
}
