<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RewardRequest;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RewardRequestService;
use OCA\ChoreStrike\Service\RewardService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Reward request lifecycle API (CS-PLAN-070 parent C4; CS-PLAN-078 §3.3).
 *
 * All routes are relative to `/apps/chorestrike/api/v1` and use
 * `@NoAdminRequired` with normal CSRF protection and pure
 * `assertHouseMember()` membership gates. Commands are receipt-protected
 * (`commandId`) with `expectedVersion` optimistic concurrency; the
 * under-lock authorization guard makes replays subject to CURRENT
 * roles/visibility. Reads stay available while the catalog is hidden or
 * disabled.
 *
 * Errors: malformed 400, nonexistent/foreign/invisible 404, visible role
 * denial 403, stale version/closed transition/displayed-price
 * conflict/insufficient funds/funding hold 409.
 */
class RewardRequestController extends Controller {
	use ControllerLoggingTrait;

	private RewardRequestService $requestService;
	private AuthorizationService $authService;
	private HouseService $houseService;
	private IL10N $l;

	public function __construct(
		IRequest $request,
		RewardRequestService $requestService,
		AuthorizationService $authService,
		HouseService $houseService,
		IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->requestService = $requestService;
		$this->authService = $authService;
		$this->houseService = $houseService;
		$this->l = $l;
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * The viewer-safe request list. `scope=mine` (default): own requests.
	 * `scope=house`: owner administration. Optional bounded
	 * `state` filter and stable `cursor`/`limit` paging.
	 *
	 * @NoAdminRequired
	 */
	public function index(): JSONResponse {
		try {
			[$house, $viewer] = $this->context();
			$scope = $this->request->getParam('scope', RewardRequestService::SCOPE_MINE);
			if (!is_string($scope) || !in_array($scope, [RewardRequestService::SCOPE_MINE, RewardRequestService::SCOPE_HOUSE], true)) {
				throw new \InvalidArgumentException($this->l->t('scope must be mine or house'));
			}
			$state = $this->request->getParam('state');
			if ($state !== null) {
				$allowedStates = [
					RewardRequest::STATE_PENDING,
					RewardRequest::STATE_APPROVED,
					RewardRequest::STATE_FULFILLED,
					RewardRequest::STATE_REJECTED,
					RewardRequest::STATE_CANCELLED,
					RewardRequest::STATE_CORRECTED,
				];
				if (!is_string($state) || !in_array($state, $allowedStates, true)) {
					throw new \InvalidArgumentException($this->l->t('Unknown state filter'));
				}
			}
			$limit = $this->intParam('limit', RewardService::DEFAULT_LIMIT);
			$cursor = $this->optionalIdParam('cursor');
			return new JSONResponse(['success' => true, 'data' => $this->requestService->listForViewer($house->getId(), $viewer, $scope, $state, $cursor, $limit)]);
		} catch (\InvalidArgumentException $e) {
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return $this->error('index', $e);
		}
	}

	/**
	 * One request with its authorized audit history (requester or owner;
	 * foreign members get 404 like nonexistent ids).
	 *
	 * @param int $id Reward request id
	 * @NoAdminRequired
	 */
	public function show(int $id): JSONResponse {
		try {
			[$house, $viewer] = $this->context();
			return new JSONResponse(['success' => true, 'data' => $this->requestService->showForViewer($house->getId(), $viewer, $id)]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (\Exception $e) {
			return $this->error('show', $e);
		}
	}

	/**
	 * Create a request for self. Body: {itemId, itemVersion, priceMinor,
	 * commandId}. The displayed terms are validated against the live
	 * unarchived item under lock and frozen; drift is 409.
	 *
	 * @NoAdminRequired
	 */
	public function create(): JSONResponse {
		try {
			$house = $this->house();
			$userId = $this->authService->requireCurrentUserId();
			return new JSONResponse(['success' => true, 'data' => $this->requestService->create($house->getId(), $userId, $this->params())]);
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
	 * Owner approval of a current child's pending request / explicit
	 * adult self-approval. Body: {commandId, expectedVersion}.
	 *
	 * @param int $id Reward request id
	 * @NoAdminRequired
	 */
	public function approve(int $id): JSONResponse {
		return $this->lifecycle('approve', $id);
	}

	/**
	 * Owner rejection of a current child's pending request.
	 * Body: {commandId, expectedVersion, reasonCode?}.
	 *
	 * @param int $id Reward request id
	 * @NoAdminRequired
	 */
	public function reject(int $id): JSONResponse {
		return $this->lifecycle('reject', $id);
	}

	/**
	 * Requester (including child) or owner cancellation of an
	 * unfulfilled request. Body: {commandId, expectedVersion, reasonCode?}.
	 *
	 * @param int $id Reward request id
	 * @NoAdminRequired
	 */
	public function cancel(int $id): JSONResponse {
		return $this->lifecycle('cancel', $id);
	}

	/**
	 * Delivery confirmation. Body: {commandId, expectedVersion,
	 * delivered:true}.
	 *
	 * @param int $id Reward request id
	 * @NoAdminRequired
	 */
	public function fulfill(int $id): JSONResponse {
		return $this->lifecycle('fulfill', $id);
	}

	/**
	 * Owner-attested false-fulfillment correction. Body: {commandId,
	 * expectedVersion, notDelivered:true, reasonCode, settlementEntryId}.
	 *
	 * @param int $id Reward request id
	 * @NoAdminRequired
	 */
	public function correct(int $id): JSONResponse {
		return $this->lifecycle('correct', $id);
	}

	/**
	 * @return JSONResponse
	 */
	private function lifecycle(string $action, int $id): JSONResponse {
		try {
			$house = $this->house();
			$userId = $this->authService->requireCurrentUserId();
			$data = match ($action) {
				'approve' => $this->requestService->approve($house->getId(), $userId, $id, $this->params()),
				'reject' => $this->requestService->reject($house->getId(), $userId, $id, $this->params()),
				'cancel' => $this->requestService->cancel($house->getId(), $userId, $id, $this->params()),
				'fulfill' => $this->requestService->fulfill($house->getId(), $userId, $id, $this->params()),
				'correct' => $this->requestService->correct($house->getId(), $userId, $id, $this->params()),
				default => throw new \LogicException('Unknown lifecycle action'),
			};
			return new JSONResponse(['success' => true, 'data' => $data]);
		} catch (\InvalidArgumentException $e) {
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (\Exception $e) {
			return $this->error($action, $e);
		}
	}

	/**
	 * @return array{0: \OCA\ChoreStrike\Db\House, 1: HouseUser}
	 */
	private function context(): array {
		$house = $this->house();
		$viewer = $this->authService->assertHouseMember($house->getId());
		return [$house, $viewer];
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

	/**
	 * Strict paging integer: JSON ints or canonical digit strings only
	 * (query params always arrive as strings over real HTTP GET; floats,
	 * decimals, negatives and non-canonical numbers are rejected).
	 */
	private function intParam(string $key, int $default): int {
		$value = $this->request->getParam($key, $default);
		if (is_int($value)) {
			$intValue = $value;
		} elseif (is_string($value) && ctype_digit($value)) {
			$intValue = (int)$value;
		} else {
			throw new \InvalidArgumentException($this->l->t('A valid %1$s is required', [$key]));
		}
		if ($intValue < 1) {
			throw new \InvalidArgumentException($this->l->t('A valid %1$s is required', [$key]));
		}
		return min($intValue, RewardService::MAX_LIMIT);
	}

	private function optionalIdParam(string $key): ?int {
		$value = $this->request->getParam($key);
		if ($value === null || $value === '') {
			return null;
		}
		if (is_int($value)) {
			$intValue = $value;
		} elseif (is_string($value) && ctype_digit($value)) {
			$intValue = (int)$value;
		} else {
			throw new \InvalidArgumentException($this->l->t('A valid %1$s is required', [$key]));
		}
		return $intValue;
	}

	private function error(string $action, \Exception $e): JSONResponse {
		$this->logControllerException($action, $e);
		return new JSONResponse([
			'success' => false,
			'error' => [
				'code' => 'REWARD_REQUEST_ERROR',
				'message' => ControllerErrorFormatter::safeMessage($e)
			]
		], 500);
	}
}
