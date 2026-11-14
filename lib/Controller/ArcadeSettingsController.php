<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\ValidationException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\ArcadeAccessService;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * The owner's arcade controls (CS-PLAN-090 WP-J, D-090-63/66, ADR-019):
 * per-game thresholds, the reset of one member and the progress list. Every
 * route is owner-only, checked here and again in the service.
 */
class ArcadeSettingsController extends Controller {
	use ControllerLoggingTrait;

	public function __construct(
		IRequest $request,
		private HouseService $houseService,
		private AuthorizationService $authService,
		private ArcadeAccessService $arcadeAccess,
		private IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * Save every game's threshold: `{ "thresholds": { "<gameId>": int } }`.
	 * Answers with the owner's catalog.
	 *
	 * @NoAdminRequired
	 */
	public function updateThresholds(): JSONResponse {
		return $this->ownerCommand('updateThresholds', function (int $houseId): array {
			$thresholds = $this->request->getParam('thresholds');
			if (!is_array($thresholds) || ($thresholds !== [] && array_is_list($thresholds))) {
				throw new ValidationException($this->l->t('thresholds must map game ids to whole numbers'));
			}
			return $this->arcadeAccess->setThresholds($houseId, $thresholds);
		});
	}

	/**
	 * Reset one member's arcade progress for all games. Answers with the
	 * progress list.
	 *
	 * @NoAdminRequired
	 * @param string $userId The member's Nextcloud user id
	 */
	public function resetMember(string $userId): JSONResponse {
		return $this->ownerCommand('resetMember', function (int $houseId) use ($userId): array {
			$this->authService->assertUserInHouse($houseId, $userId);
			return $this->arcadeAccess->resetMember($houseId, $userId);
		});
	}

	/**
	 * Each member's finished chores since their last reset and its time.
	 *
	 * @NoAdminRequired
	 */
	public function progress(): JSONResponse {
		return $this->ownerCommand('progress', fn (int $houseId): array => $this->arcadeAccess->getProgress($houseId));
	}

	/**
	 * Shared envelope: membership and owner role first (403), then the
	 * command; a broken rule is a 400, anything else a logged 500.
	 *
	 * @param callable(int): array $command Receives the house id
	 */
	private function ownerCommand(string $action, callable $command): JSONResponse {
		try {
			$houseId = $this->houseService->getOrCreateHouse($this->houseService->getInstanceId())->getId();
			$this->authService->assertHouseMember($houseId);
			$this->authService->requireHouseOwner($houseId);

			return new JSONResponse([
				'success' => true,
				'data' => $command($houseId),
			]);
		} catch (ValidationException $e) {
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException($action, $e);
			return ControllerErrorFormatter::serverErrorResponse($e, 'ARCADE_SETTINGS_ERROR');
		}
	}
}
