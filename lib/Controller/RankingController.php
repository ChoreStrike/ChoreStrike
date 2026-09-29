<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RankingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * API controller for the consent-filtered chore ranking
 * (CS-PLAN-070 WP4, C5i/C5l/D10b).
 *
 * Thin endpoint: authorization gates run here; the consent lifecycle and
 * the exact-ratio ranking live in RankingService. Consent/withdraw are
 * self-only mutations (children included) — the service rechecks the
 * membership under the house lock.
 */
class RankingController extends Controller {
	use ControllerLoggingTrait;

	private RankingService $rankingService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IL10N $l;

	public function __construct(
		IRequest $request,
		RankingService $rankingService,
		HouseService $houseService,
		AuthorizationService $authService,
		IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->rankingService = $rankingService;
		$this->houseService = $houseService;
		$this->authService = $authService;
		$this->l = $l;
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * The consent-filtered ranking for the current house week: house flag
	 * state, the caller's own consent state, visible entries (effective
	 * consents only) and pending consents (C5l). No Coins, speed,
	 * contribution or private-task data.
	 *
	 * @NoAdminRequired
	 */
	public function index(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$view = $this->rankingService->getRankingView((int)$house->getId(), $houseUser);

			return new JSONResponse([
				'success' => true,
				'data' => $view,
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('index', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'RANKING_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Give the CALLING person's ranking consent (children may decline or
	 * leave themselves — C5i). The generation activates next Monday 00:00
	 * in the house timezone; never retroactive.
	 *
	 * @NoAdminRequired
	 */
	public function consent(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$state = $this->rankingService->consent((int)$house->getId(), $houseUser);

			return new JSONResponse([
				'success' => true,
				'data' => $state,
				'message' => $this->l->t('Ranking consent recorded'),
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('consent', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'RANKING_CONSENT_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Withdraw the CALLING person's ranking consent. Removes visible
	 * participation immediately and invalidates the generation.
	 *
	 * @NoAdminRequired
	 */
	public function withdraw(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$this->rankingService->withdraw((int)$house->getId(), $houseUser);

			return new JSONResponse([
				'success' => true,
				'data' => ['state' => 'none', 'generation' => null, 'effectiveMonday' => null],
				'message' => $this->l->t('Ranking consent withdrawn'),
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('withdraw', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'RANKING_WITHDRAW_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}
}
