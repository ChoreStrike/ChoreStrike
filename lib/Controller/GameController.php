<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\GameLogicService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * API controller for game-related endpoints
 */
class GameController extends OCSController {

	private GameLogicService $gameLogicService;
	private HouseService $houseService;
	private AuthorizationService $authService;

	public function __construct(
		IRequest $request,
		GameLogicService $gameLogicService,
		HouseService $houseService,
		AuthorizationService $authService
	) {
		parent::__construct('chorestrike', $request);
		$this->gameLogicService = $gameLogicService;
		$this->houseService = $houseService;
		$this->authService = $authService;
	}

	/**
	 * Get current game state
	 *
	 * @NoAdminRequired
	 */
	public function state(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());
			
			$gameState = $this->gameLogicService->getGameState($house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => $gameState
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'GAME_STATE_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Get leaderboard
	 *
	 * @NoAdminRequired
	 */
	public function leaderboard(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());
			
			$leaderboard = $this->gameLogicService->getLeaderboard($house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => $leaderboard
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'LEADERBOARD_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Get medals
	 *
	 * @NoAdminRequired
	 */
	public function medals(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());
			
			$medals = $this->gameLogicService->getMedals($house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => $medals
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'MEDALS_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Check and award weekly medals
	 *
	 * @NoAdminRequired
	 */
	public function checkMedals(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());
			
			$awardedMedals = $this->gameLogicService->checkAndAwardMedals($house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => [
					'awardedMedals' => array_map(fn($medal) => $medal->jsonSerialize(), $awardedMedals),
					'count' => count($awardedMedals)
				]
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'CHECK_MEDALS_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}
}
