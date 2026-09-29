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
use OCA\ChoreStrike\Service\RhythmService;
use OCA\ChoreStrike\Service\WalletService;
use OCA\ChoreStrike\Service\WeeklyGoalService;
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
	private RhythmService $rhythmService;
	private WeeklyGoalService $weeklyGoalService;

	public function __construct(
		IRequest $request,
		GameLogicService $gameLogicService,
		MedalService $medalService,
		HouseService $houseService,
		AuthorizationService $authService,
		WalletService $walletService,
		WalletMapper $walletMapper,
		RhythmService $rhythmService,
		WeeklyGoalService $weeklyGoalService,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->gameLogicService = $gameLogicService;
		$this->medalService = $medalService;
		$this->houseService = $houseService;
		$this->authService = $authService;
		$this->walletService = $walletService;
		$this->walletMapper = $walletMapper;
		$this->rhythmService = $rhythmService;
		$this->weeklyGoalService = $weeklyGoalService;
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * Get current game state — the caller's OWN wallet in minor units
	 * (WP3 §3.6: no Coin totals in the member list; foreign member/fairness
	 * DTOs contain no wallet balances).
	 *
	 * CS-PLAN-070 WP4 (parent §3.4 read contract): the caller's own entry
	 * carries the rhythm projection (confirmed/current/best + the
	 * pending-hold indicator) and the personal weekly-goal slice;
	 * the house `weeklyGoal` block carries eligible/onTime/neutral and the
	 * provisional flag. Everything is a PURE READ — on an already
	 * initialized house this endpoint performs no award writes (minting
	 * lives in the TimelineJob closed-week reconciliation and the explicit
	 * POST check-medals).
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

			$now = new \DateTime();
			$evaluation = $this->rhythmService->evaluateForMembers(
				(int)$house->getId(),
				[$houseUser],
				$now
			)[(int)$houseUser->getId()] ?? ['currentStreak' => 0, 'longestStreak' => 0, 'pending' => false];
			$gameState['rhythm'] = [
				// C1f: the evaluation confirms only closed successful days —
				// the confirmed and the displayed current streak are the
				// same valid-history value; `pending` marks the held day.
				'confirmed' => $evaluation['currentStreak'],
				'current' => $evaluation['currentStreak'],
				'best' => $evaluation['longestStreak'],
				'pending' => $evaluation['pending'],
			];
			$gameState['myWeeklyGoal'] = $this->weeklyGoalService->progressForMember($house, $houseUser, $now);

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
