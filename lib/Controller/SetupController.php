<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Exception\ConflictException;
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
 * Authorization (ADR-010, decision D1): both endpoints are designated
 * bootstrap endpoints (ensureHouseMember); apply additionally requires the
 * owner role in HouseSetupService (CS-PLAN-061 §3.4). The first-run gate is
 * enforced INSIDE the service transaction under the house lock (CS-PLAN-062
 * §3.1) — a raced second apply gets 409 HOUSE_ALREADY_SET_UP, this endpoint
 * has no pre-check of its own (single gate owner).
 */
class SetupController extends Controller {
	use ControllerLoggingTrait;

	private TemplateService $templateService;
	private HouseSetupService $houseSetupService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IL10N $l;

	public function __construct(
		IRequest $request,
		TemplateService $templateService,
		HouseSetupService $houseSetupService,
		HouseService $houseService,
		AuthorizationService $authService,
		IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->templateService = $templateService;
		$this->houseSetupService = $houseSetupService;
		$this->houseService = $houseService;
		$this->authService = $authService;
		$this->l = $l;
		$this->initializeControllerLogger($appLogger);
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
			$this->logControllerException('catalog', $e);
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
		} catch (ConflictException $e) {
			// The first-run gate fires inside the setup transaction
			// (CS-PLAN-062 §3.1) — the envelope (code + localized message)
			// is unchanged from the former controller pre-check.
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'HOUSE_ALREADY_SET_UP',
					'message' => $e->getMessage(),
				],
			], 409);
		} catch (\InvalidArgumentException $e) {
			// Service-curated validation messages are safe to surface verbatim.
			return ControllerErrorFormatter::validation($e);
		} catch (\Exception $e) {
			$this->logControllerException('apply', $e);
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
