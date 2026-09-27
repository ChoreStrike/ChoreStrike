<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\GameController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Wallet;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\GameLogicService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\WalletService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GameController (WP3 cutover: /game/state returns the
 * caller's OWN wallet in minor units next to the viewer-safe member
 * progression).
 */
class GameControllerTest extends TestCase {
	private GameController $controller;
	private $gameLogicService;
	private $houseService;
	private $authService;
	private $walletService;
	private $walletMapper;
	private $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->gameLogicService = $this->createMock(GameLogicService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);
		$this->walletService = $this->createMock(WalletService::class);
		$this->walletMapper = $this->createMock(WalletMapper::class);

		$this->controller = new GameController(
			$this->request,
			$this->gameLogicService,
			$this->createMock(\OCA\ChoreStrike\Service\MedalService::class),
			$this->houseService,
			$this->authService,
			$this->walletService,
			$this->walletMapper,
			$this->createMock(AppLogger::class)
		);
	}

	/**
	 * Test GET /game/state returns state with the caller's own wallet
	 */
	public function testState_ReturnsStateSuccessfully(): void {
		$house = new House();
		$house->setId(1);

		$houseUser = new HouseUser();
		$houseUser->setId(11);
		$houseUser->setNextcloudUserId('alice-nc');
		$houseUser->setWalletId(501);
		$houseUser->setContributionMinor(3400);

		$gameState = [
			'users' => [
				['id' => 11, 'displayName' => 'Alice', 'contributionMinor' => 3400],
			],
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

		$wallet = new Wallet();
		$wallet->setId(501);

		$walletPayload = [
			'bookedMinor' => 3400,
			'reservedMinor' => 0,
			'availableMinor' => 3400,
			'fundingHold' => false,
		];

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->houseService->method('getWeeklyGoalProgress')->with(1)->willReturn($weeklyGoal);
		$this->authService->method('assertHouseMember')->willReturn($houseUser);

		$this->walletMapper->method('findByHouseAndUser')->with(1, 'alice-nc')->willReturn($wallet);
		$this->walletService->method('serializeOwnWallet')->with($wallet)->willReturn($walletPayload);

		$this->gameLogicService->expects($this->once())
			->method('getGameState')
			->with(1)
			->willReturn($gameState);

		$response = $this->controller->state();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame($gameState['users'], $data['data']['users']);
		$this->assertSame($weeklyGoal, $data['data']['weeklyGoal']);
		$this->assertSame($walletPayload, $data['data']['wallet']);
		$this->assertSame(3400, $data['data']['contributionMinor']);
		$this->assertSame(0, $data['data']['stateVersion']);
	}

	/**
	 * State_UnboundMember_ReturnsNullWalletWithoutLookup
	 *
	 * A member without a wallet binding (pre-WP0 legacy row) gets a null
	 * wallet block, not an error — the wallet is additive identity.
	 */
	public function testState_UnboundMember_ReturnsNullWalletWithoutLookup(): void {
		$house = new House();
		$house->setId(1);

		$houseUser = new HouseUser();
		$houseUser->setId(12);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->houseService->method('getWeeklyGoalProgress')->willReturn([
			'dueCount' => 0, 'completedCount' => 0, 'percent' => 0, 'targetPercent' => 80,
			'achieved' => false, 'weekStart' => '', 'weekEnd' => '',
		]);
		$this->authService->method('assertHouseMember')->willReturn($houseUser);
		$this->gameLogicService->method('getGameState')->willReturn(['users' => []]);

		$this->walletMapper->expects($this->never())->method('find');

		$response = $this->controller->state();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertNull($data['data']['wallet']);
		$this->assertSame(0, $data['data']['contributionMinor']);
	}
}
