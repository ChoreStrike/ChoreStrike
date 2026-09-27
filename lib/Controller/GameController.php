<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\GameLogicService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\MedalService;
use OCA\ChoreStrike\Service\WalletService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * API controller for game-related endpoints
 */
class GameController extends Controller {
	use ControllerLoggingTrait;

	private GameLogicService $gameLogicService;
	private MedalService $medalService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private WalletService $walletService;
	private WalletMapper $walletMapper;

	public function __construct(
		IRequest $request,
		GameLogicService $gameLogicService,
		MedalService $medalService,
		HouseService $houseService,
		AuthorizationService $authService,
		WalletService $walletService,
		WalletMapper $walletMapper,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->gameLogicService = $gameLogicService;
		$this->medalService = $medalService;
		$this->houseService = $houseService;
		$this->authService = $authService;
		$this->walletService = $walletService;
		$this->walletMapper = $walletMapper;
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * Get current game state — the caller's OWN wallet in minor units
	 * (WP3 §3.6: no Coin totals in the member list; foreign member/fairness
	 * DTOs contain no wallet balances).
	 *
	 * @NoAdminRequired
	 */
	public function state(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$gameState = $this->gameLogicService->getGameState($house->getId());
			$gameState['weeklyGoal'] = $this->houseService->getWeeklyGoalProgress($house->getId());
			$gameState['stateVersion'] = $house->getStateVersion();

			$wallet = $houseUser->getWalletId() !== null
				? $this->walletMapper->findByHouseAndUser((int)$house->getId(), $houseUser->getNextcloudUserId())
				: null;
			$gameState['wallet'] = $wallet !== null
				? $this->walletService->serializeOwnWallet($wallet)
				: null;
			$gameState['contributionMinor'] = $houseUser->getContributionMinor();

			return new JSONResponse([
				'success' => true,
				'data' => $gameState
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('state', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'GAME_STATE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
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
			$this->authService->assertHouseMember($house->getId());

			$medals = $this->medalService->getMedals($house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => $medals
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('medals', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'MEDALS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
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
			$this->authService->assertHouseMember($house->getId());

			$awardedMedals = $this->medalService->checkAndAwardMedals($house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => [
					'awardedMedals' => array_map(fn ($medal) => $medal->jsonSerialize(), $awardedMedals),
					'count' => count($awardedMedals)
				]
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('checkMedals', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'CHECK_MEDALS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}
}
