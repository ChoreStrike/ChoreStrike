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
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Targeted completion correction API (CS-PLAN-070 WP3 §3.6).
 *
 * `GET /completions/{id}` returns the versioned original work, the
 * selected allocation and the authorized correction history with
 * `canCorrect` — no foreign wallet totals, no raw audit snapshots; GET is
 * write-free. `POST /completions/{id}/undo` and
 * `PUT /completions/{id}/attribution` are CommandService receipts
 * targeting the EXACT completion id (older completions of a recurring task
 * included); an already-undone target is a stable no-op, a stale live
 * target conflicts (409). All routes are authenticated, CSRF-protected
 * `@NoAdminRequired` with the shared envelope/error formatter.
 */
class CompletionController extends Controller {
	use ControllerLoggingTrait;

	private HouseService $houseService;
	private AuthorizationService $authService;
	private CompletionService $completionService;
	private IL10N $l;

	public function __construct(
		IRequest $request,
		HouseService $houseService,
		AuthorizationService $authService,
		CompletionService $completionService,
		IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->houseService = $houseService;
		$this->authService = $authService;
		$this->completionService = $completionService;
		$this->l = $l;
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * Show one completion with its versioned identity and viewer-safe
	 * correction surface (WP3 §3.6).
	 *
	 * @NoAdminRequired
	 */
	public function show(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$data = $this->completionService->showCompletion($house->getId(), $houseUser, $id);
			return new JSONResponse(['success' => true, 'data' => $data]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('show', $e, ['completionId' => $id]);
			return new JSONResponse([
				'success' => false,
				'error' => ['code' => 'COMPLETION_SHOW_ERROR', 'message' => ControllerErrorFormatter::safeMessage($e)],
			], 500);
		}
	}

	/**
	 * Undo one exact completion (completer or current authorized owner).
	 *
	 * @NoAdminRequired
	 */
	public function undo(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$payload = $this->validateCorrectionPayload(['commandId', 'expectedVersion', 'reasonCode']);
			if ($payload instanceof JSONResponse) {
				return $payload;
			}

			$result = $this->completionService->undo(
				$house->getId(),
				$houseUser,
				$id,
				$payload['commandId'],
				$payload['expectedVersion'],
				$payload['reasonCode'],
			);

			return new JSONResponse(['success' => true, 'data' => $result]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => ['code' => 'VALIDATION_ERROR', 'message' => ControllerErrorFormatter::safeMessage($e)],
			], 400);
		} catch (\Exception $e) {
			$this->logControllerException('undo', $e, ['completionId' => $id]);
			return new JSONResponse([
				'success' => false,
				'error' => ['code' => 'COMPLETION_UNDO_ERROR', 'message' => ControllerErrorFormatter::safeMessage($e)],
			], 500);
		}
	}

	/**
	 * Correct the actual participants/shares of one completion. Never
	 * accepts time/energy/occurrence edits — the closed payload carries
	 * only the receipt identity, participants, review flag and reason.
	 *
	 * @NoAdminRequired
	 */
	public function attribute(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$payload = $this->validateCorrectionPayload(
				['commandId', 'expectedVersion', 'participants', 'allocationReviewed', 'reasonCode'],
				true
			);
			if ($payload instanceof JSONResponse) {
				return $payload;
			}

			$result = $this->completionService->attribute(
				$house->getId(),
				$houseUser,
				$id,
				$payload['commandId'],
				$payload['expectedVersion'],
				$payload['participants'],
				$payload['allocationReviewed'],
				$payload['reasonCode'],
			);

			return new JSONResponse(['success' => true, 'data' => $result]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => ['code' => 'VALIDATION_ERROR', 'message' => ControllerErrorFormatter::safeMessage($e)],
			], 400);
		} catch (\Exception $e) {
			$this->logControllerException('attribute', $e, ['completionId' => $id]);
			return new JSONResponse([
				'success' => false,
				'error' => ['code' => 'COMPLETION_ATTRIBUTE_ERROR', 'message' => ControllerErrorFormatter::safeMessage($e)],
			], 500);
		}
	}

	/**
	 * Validate a correction receipt payload (closed set): commandId UUID,
	 * expectedVersion, bounded optional reasonCode; with `$withParticipants`
	 * additionally the non-empty participants array and the boolean
	 * allocationReviewed confirmation (the attribution payload).
	 *
	 * @return array{commandId: string, expectedVersion: int, reasonCode: ?string, participants: array<int, array{userId: string, sharePct: int}>, allocationReviewed: bool}|JSONResponse
	 */
	private function validateCorrectionPayload(array $allowedFields, bool $withParticipants = false): array|JSONResponse {
		$payload = $this->request->getParams();
		unset($payload['id'], $payload['_route']);

		foreach (array_keys($payload) as $key) {
			if (!in_array($key, $allowedFields, true)) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('Unknown field: %1$s', [$key])],
				], 400);
			}
		}

		$commandId = $payload['commandId'] ?? null;
		if (!is_string($commandId) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $commandId)) {
			return new JSONResponse([
				'success' => false,
				'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('A valid commandId (UUID) is required')],
			], 400);
		}

		$expectedVersion = $payload['expectedVersion'] ?? null;
		if (!is_numeric($expectedVersion) || (int)$expectedVersion < 0 || (string)(int)$expectedVersion !== (string)$expectedVersion) {
			return new JSONResponse([
				'success' => false,
				'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('A valid expectedVersion is required')],
			], 400);
		}

		$reasonCode = $payload['reasonCode'] ?? null;
		if ($reasonCode !== null && (!is_string($reasonCode) || mb_strlen($reasonCode) > 64)) {
			return new JSONResponse([
				'success' => false,
				'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('reasonCode must be a string of at most 64 characters')],
			], 400);
		}

		$participants = [];
		$allocationReviewed = false;
		if ($withParticipants) {
			$participants = $payload['participants'] ?? null;
			if (!is_array($participants) || $participants === []) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('participants must be a non-empty array of {userId, sharePct}')],
				], 400);
			}

			$allocationReviewed = $payload['allocationReviewed'] ?? false;
			if (!is_bool($allocationReviewed)) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('allocationReviewed must be a boolean')],
				], 400);
			}
		}

		return [
			'commandId' => strtolower($commandId),
			'expectedVersion' => (int)$expectedVersion,
			'reasonCode' => $reasonCode,
			'participants' => $participants,
			'allocationReviewed' => $allocationReviewed,
		];
	}
}
