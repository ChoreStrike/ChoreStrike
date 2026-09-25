<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\PauseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Pause API (CS-PLAN-073 WP2 §3.5): the viewer-safe list, personal
 * request, household pause, owner decisions (approve/reject), explicit
 * retrospective-illness recognition and the next-day shortening.
 *
 * All routes are relative to `/apps/chorestrike/api/v1` and use
 * `@NoAdminRequired` with normal CSRF protection. Stable pause group
 * identity with an expected revision for updates; command UUIDs for
 * idempotent retries. Errors: malformed 400, visible role denial 403,
 * invisible or foreign target 404, stale revision/business conflict 409.
 */
class PauseController extends Controller {
	use ControllerLoggingTrait;

	private PauseService $pauseService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IL10N $l;

	public function __construct(
		IRequest $request,
		PauseService $pauseService,
		HouseService $houseService,
		AuthorizationService $authService,
		IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->pauseService = $pauseService;
		$this->houseService = $houseService;
		$this->authService = $authService;
		$this->l = $l;
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * The viewer-safe pause list (bounded; pending personal requests are
	 * self/owner data).
	 *
	 * @NoAdminRequired
	 */
	public function index(): JSONResponse {
		try {
			$house = $this->house();
			$userId = $this->authService->requireCurrentUserId();
			return new JSONResponse(['success' => true, 'data' => $this->pauseService->listForViewer((int)$house->getId(), $userId)]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return $this->error('index', $e);
		}
	}

	/**
	 * Create a pause: a personal pause for the caller (child → pending
	 * request, adult → acknowledged) or a household pause when
	 * `scope: 'house'` (owner only).
	 *
	 * Body: {commandId, startDate, endDate, reasonCode, scope?}.
	 *
	 * @NoAdminRequired
	 */
	public function create(): JSONResponse {
		try {
			$house = $this->house();
			$userId = $this->authService->requireCurrentUserId();
			$payload = $this->params();
			$scope = $payload['scope'] ?? 'personal';
			if (!is_string($scope)) {
				throw new \InvalidArgumentException($this->l->t('scope must be personal or house'));
			}
			// The routing-level scope selector is consumed here; the
			// service validates its own closed payload shape.
			unset($payload['scope']);
			if ($scope === 'house') {
				$result = $this->pauseService->createHousePause((int)$house->getId(), $userId, $payload);
			} elseif ($scope === 'personal') {
				$result = $this->pauseService->requestForSelf((int)$house->getId(), $userId, $payload);
			} else {
				throw new \InvalidArgumentException($this->l->t('scope must be personal or house'));
			}
			return new JSONResponse(['success' => true, 'data' => $result]);
		} catch (\InvalidArgumentException $e) {
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (\Exception $e) {
			return $this->error('create', $e);
		}
	}

	/**
	 * Approve a pending pause request (owner only).
	 *
	 * Body: {commandId, expectedRevision}.
	 *
	 * @param int $id Pause group id
	 * @NoAdminRequired
	 */
	public function approve(int $id): JSONResponse {
		try {
			$house = $this->house();
			$userId = $this->authService->requireCurrentUserId();
			return new JSONResponse(['success' => true, 'data' => $this->pauseService->approve((int)$house->getId(), $userId, $id, $this->params())]);
		} catch (\InvalidArgumentException $e) {
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (\Exception $e) {
			return $this->error('approve', $e);
		}
	}

	/**
	 * Reject a pending pause request (owner only).
	 *
	 * Body: {commandId, expectedRevision}.
	 *
	 * @param int $id Pause group id
	 * @NoAdminRequired
	 */
	public function reject(int $id): JSONResponse {
		try {
			$house = $this->house();
			$userId = $this->authService->requireCurrentUserId();
			return new JSONResponse(['success' => true, 'data' => $this->pauseService->reject((int)$house->getId(), $userId, $id, $this->params())]);
		} catch (\InvalidArgumentException $e) {
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (\Exception $e) {
			return $this->error('reject', $e);
		}
	}

	/**
	 * Explicit retrospective-illness recognition (owner only).
	 *
	 * Body: {commandId, subjectUserId, startDate, endDate}.
	 *
	 * @NoAdminRequired
	 */
	public function recognizeIllness(): JSONResponse {
		try {
			$house = $this->house();
			$userId = $this->authService->requireCurrentUserId();
			return new JSONResponse(['success' => true, 'data' => $this->pauseService->recognizeIllness((int)$house->getId(), $userId, $this->params())]);
		} catch (\InvalidArgumentException $e) {
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (\Exception $e) {
			return $this->error('recognizeIllness', $e);
		}
	}

	/**
	 * Shorten / revoke an acknowledged pause (next-day boundary).
	 *
	 * Body: {commandId, endDate, expectedRevision}.
	 *
	 * @param int $id Pause group id
	 * @NoAdminRequired
	 */
	public function update(int $id): JSONResponse {
		try {
			$house = $this->house();
			$userId = $this->authService->requireCurrentUserId();
			return new JSONResponse(['success' => true, 'data' => $this->pauseService->shorten((int)$house->getId(), $userId, $id, $this->params())]);
		} catch (\InvalidArgumentException $e) {
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (\Exception $e) {
			return $this->error('update', $e);
		}
	}

	private function house(): \OCA\ChoreStrike\Db\House {
		$instanceId = $this->houseService->getInstanceId();
		$house = $this->houseService->getOrCreateHouse($instanceId);
		$this->authService->assertHouseMember($house->getId());
		return $house;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function params(): array {
		$params = $this->request->getParams();
		unset($params['id'], $params['_route']);
		return $params;
	}

	private function error(string $action, \Exception $e): JSONResponse {
		$this->logControllerException($action, $e);
		return new JSONResponse([
			'success' => false,
			'error' => [
				'code' => 'PAUSE_ERROR',
				'message' => ControllerErrorFormatter::safeMessage($e)
			]
		], 500);
	}
}
