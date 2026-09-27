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
use OCA\ChoreStrike\Exception\RateLimitException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\MinigameService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MinigameController (WP3 cutover: scores-only — the
 * boost purchase and coin endpoints retired with the economy).
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
		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->controller = new MinigameController(
			$this->request,
			$this->houseService,
			$this->minigameService,
			$this->authService,
			$l10n,
			$this->createMock(AppLogger::class)
		);
	}

	/**
	 * BoostAndCoinSurfaces_Retired (T10/D7d)
	 *
	 * The former boost purchase and coin mint/read endpoints retired with
	 * the wallet cutover — the surfaces must not silently linger.
	 */
	public function testBoostAndCoinSurfaces_Retired(): void {
		$this->assertFalse(
			method_exists($this->controller, 'purchaseBoost'),
			'the retired boost purchase endpoint must stay removed'
		);
		$this->assertFalse(
			method_exists($this->controller, 'coins'),
			'the retired coin balance endpoint must stay removed'
		);
	}

	/**
	 * Test POST /minigames/{gameId}/scores returns the scores-only payload.
	 */
	public function testSaveScore_ReturnsScoresOnlyPayload(): void {
		$house = new House();
		$house->setId(1);

		$savedScore = new MinigameScore();
		$savedScore->setId(9);
		$savedScore->setGameId('snake');
		$savedScore->setScore(120);
		$savedScore->setCreatedAt(new \DateTime('2026-03-22T13:00:00Z'));

		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');
		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->with('instance-123')
			->willReturn($house);
		$this->authService->expects($this->once())
			->method('assertHouseMember')
			->with(1);
		$this->authService->expects($this->once())
			->method('getCurrentUserId')
			->willReturn('user1');
		$this->minigameService->expects($this->once())
			->method('saveScore')
			->with(1, 'user1', 'snake', 120)
			->willReturn($savedScore);

		$response = $this->controller->saveScore('snake', 120);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(9, $data['data']['id']);
		$this->assertEquals(120, $data['data']['score']);
		$this->assertEquals('snake', $data['data']['gameId']);
		// Scores-only contract: no monetary or boost outputs.
		$this->assertArrayNotHasKey('coinsEarned', $data['data']);
		$this->assertArrayNotHasKey('boostsUsed', $data['data']);
	}

	/**
	 * D7: the service's per-user daily save cap (RateLimitException) maps
	 * to a clean 429 MINIGAME_RATE_LIMITED — not a 500.
	 */
	public function testSaveScore_RateLimited_Returns429(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember');
		$this->authService->method('getCurrentUserId')->willReturn('user1');
		$this->minigameService->method('saveScore')
			->willThrowException(new RateLimitException('Daily score limit reached'));

		$response = $this->controller->saveScore('snake', 120);

		$this->assertEquals(429, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('MINIGAME_RATE_LIMITED', $data['error']['code']);
		$this->assertEquals('Too many score submissions today. Try again tomorrow.', $data['error']['message']);
	}

	/**
	 * An unknown gameId is rejected by the service with
	 * InvalidArgumentException and surfaces as a 400 validation envelope.
	 */
	public function testSaveScore_UnknownGameId_Returns400(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember');
		$this->authService->method('getCurrentUserId')->willReturn('user1');
		$this->minigameService->method('saveScore')
			->willThrowException(new \InvalidArgumentException('Unknown game: tetris'));

		$response = $this->controller->saveScore('tetris', 10);

		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
		$this->assertEquals('Unknown game: tetris', $data['error']['message']);
	}
}
