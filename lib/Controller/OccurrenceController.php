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
use OCA\ChoreStrike\Service\AgreementService;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Occurrence agreement API (CS-PLAN-073 WP2 §3.5): the owner-recorded
 * single-occurrence amendment, catch-up and restart commands.
 *
 * All routes are relative to `/apps/chorestrike/api/v1` and use
 * `@NoAdminRequired` with normal CSRF protection. Errors: malformed 400,
 * visible role denial 403, invisible or foreign target 404, stale
 * version/deadline/business conflict 409.
 */
class OccurrenceController extends Controller {
	use ControllerLoggingTrait;

	private AgreementService $agreementService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IL10N $l;

	public function __construct(
		IRequest $request,
		AgreementService $agreementService,
		HouseService $houseService,
		AuthorizationService $authService,
		IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->agreementService = $agreementService;
		$this->houseService = $houseService;
		$this->authService = $authService;
		$this->l = $l;
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * Amend one occurrence (date/participants) as an owner-recorded
	 * agreement (CS-PLAN-073 §3.3).
	 *
	 * Body: {roomTaskId, commandId, occurrenceId, expectedVersion,
	 * selectionToken, consent: true, date?, participants?}.
	 *
	 * @param string $id Public occurrence key `<scheduleRevisionId>:<ordinal>`
	 * @NoAdminRequired
	 */
	public function amend(string $id): JSONResponse {
		return $this->run('amend', $id, fn (int $houseId, string $userId, int $roomTaskId, array $payload) => $this->agreementService->amend($houseId, $userId, $roomTaskId, $payload));
	}

	/**
	 * Agree a catch-up work appointment on the current unfulfilled miss.
	 *
	 * Body: {roomTaskId, commandId, occurrenceId, expectedVersion,
	 * selectionToken, consent: true, workDate, workZone?, participants?}.
	 *
	 * @param string $id Public occurrence key
	 * @NoAdminRequired
	 */
	public function catchUp(string $id): JSONResponse {
		return $this->run('catchUp', $id, fn (int $houseId, string $userId, int $roomTaskId, array $payload) => $this->agreementService->catchUp($houseId, $userId, $roomTaskId, $payload));
	}

	/**
	 * Agree one linked restart of a neutrally suspended interval
	 * predecessor.
	 *
	 * Body: {roomTaskId, commandId, occurrenceId, expectedVersion,
	 * consent: true, workDate}.
	 *
	 * @param string $id Public occurrence key
	 * @NoAdminRequired
	 */
	public function restart(string $id): JSONResponse {
		return $this->run('restart', $id, fn (int $houseId, string $userId, int $roomTaskId, array $payload) => $this->agreementService->restart($houseId, $userId, $roomTaskId, $payload));
	}

	private function run(string $action, string $occurrenceKey, callable $fn): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->assertHouseMember($house->getId());
			$userId = $this->authService->requireCurrentUserId();

			$payload = $this->request->getParams();
			unset($payload['id'], $payload['_route']);
			// The route segment is the canonical occurrence identity; the
			// body must agree with it (closed-shape validation downstream).
			$payload['occurrenceId'] = $occurrenceKey;
			// roomTaskId is the controller-level scoping selector consumed
			// by roomTaskIdOf(); the service validates its own closed
			// command payload shape.
			$roomTaskId = $this->roomTaskIdOf($payload);
			unset($payload['roomTaskId']);

			$result = $fn((int)$house->getId(), $userId, $roomTaskId, $payload);

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
			$this->logControllerException($action, $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'AGREEMENT_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function roomTaskIdOf(array $payload): int {
		$roomTaskId = $payload['roomTaskId'] ?? null;
		if (!is_numeric($roomTaskId) || (int)$roomTaskId < 1) {
			throw new \InvalidArgumentException($this->l->t('A valid roomTaskId is required'));
		}
		return (int)$roomTaskId;
	}
}
