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
use OCA\ChoreStrike\Service\HouseSetupService;
use OCA\ChoreStrike\Service\TemplateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * API controller for the first-run setup wizard (CS-PLAN-027).
 *
 * Endpoints:
 *  - GET  /api/v1/setup/catalog  room types + leveled templates (wizard + picker)
 *  - POST /api/v1/setup/apply    create floors/rooms/tasks in one transaction
 *
 * Authorization (decision D8): both endpoints require house membership
 * (ensureHouseMember), consistent with floor/room/room-task creation. The
 * first-run gate (the house must have no floors yet, D3) bounds the blast
 * radius of `apply`.
 */
class SetupController extends Controller {
	private TemplateService $templateService;
	private HouseSetupService $houseSetupService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IL10N $l;
	private ?AppLogger $appLogger;

	public function __construct(
		IRequest $request,
		TemplateService $templateService,
		HouseSetupService $houseSetupService,
		HouseService $houseService,
		AuthorizationService $authService,
		IL10N $l,
		?AppLogger $appLogger = null,
	) {
		parent::__construct('chorestrike', $request);
		$this->templateService = $templateService;
		$this->houseSetupService = $houseSetupService;
		$this->houseService = $houseService;
		$this->authService = $authService;
		$this->l = $l;
		$this->appLogger = $appLogger;
	}

	/**
	 * Get the localized setup catalog (levels, room types, leveled templates).
	 *
	 * @NoAdminRequired
	 */
	public function catalog(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => $this->templateService->getCatalog(),
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->appLogger?->error('Setup catalog failed', [
				'service' => 'SetupController',
				'event' => 'setup.catalog.error',
				'exception' => get_class($e),
			]);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'SETUP_CATALOG_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e),
				],
			], 500);
		}
	}

	/**
	 * Apply the wizard payload transactionally.
	 *
	 * @NoAdminRequired
	 */
	public function apply(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());

			// First-run gate (D3): the wizard only runs while the house is
			// structurally empty. Once floors exist, the per-room template
			// picker is the path.
			if ($this->houseService->hasFloors($house->getId())) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'HOUSE_ALREADY_SET_UP',
						'message' => $this->l->t('The house is already set up. Use the per-room template picker instead.'),
					],
				], 409);
			}

			$payload = $this->readJsonBody();
			if (!is_array($payload)) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('A JSON request body is required'),
					],
				], 400);
			}

			$result = $this->houseSetupService->applySetup($house->getId(), $payload);

			return new JSONResponse([
				'success' => true,
				'data' => $result,
				'message' => $this->l->t('House set up successfully'),
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\InvalidArgumentException $e) {
			// Service-curated validation messages are safe to surface verbatim.
			return ControllerErrorFormatter::validation($e);
		} catch (\Exception $e) {
			$this->appLogger?->error('Setup apply failed', [
				'service' => 'SetupController',
				'event' => 'setup.apply.error',
				'exception' => get_class($e),
			]);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'SETUP_APPLY_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e),
				],
			], 500);
		}
	}

	/**
	 * Decode the JSON request body (falls back to request params).
	 *
	 * @return array|null
	 */
	private function readJsonBody(): ?array {
		$params = $this->request->getParams();
		if (is_array($params) && isset($params['floors'])) {
			return $params;
		}

		$rawBody = file_get_contents('php://input');
		if (!is_string($rawBody) || trim($rawBody) === '') {
			return null;
		}

		$decoded = json_decode($rawBody, true);

		return is_array($decoded) ? $decoded : null;
	}
}
