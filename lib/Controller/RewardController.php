<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RewardService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Reward catalog API (CS-PLAN-070 WP7, CS-PLAN-078 §3.3).
 *
 * All routes are relative to `/apps/chorestrike/api/v1` and use
 * `@NoAdminRequired` with normal CSRF protection and pure
 * `assertHouseMember()` membership gates (no bootstrap auto-join).
 * Controllers validate/delegate; services enforce current
 * membership/authority under the house lock.
 *
 * Money is exact integer hundredths (`priceMinor=1250` = 12.50 Coins);
 * floats and numeric strings are rejected. Errors: malformed 400,
 * nonexistent/foreign/invisible 404, visible role denial 403, stale
 * version/displayed-price conflict 409.
 */
class RewardController extends Controller {
	use ControllerLoggingTrait;

	private RewardService $rewardService;
	private AuthorizationService $authService;
	private HouseService $houseService;
	private IL10N $l;

	public function __construct(
		IRequest $request,
		RewardService $rewardService,
		AuthorizationService $authService,
		HouseService $houseService,
		IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->rewardService = $rewardService;
		$this->authService = $authService;
		$this->houseService = $houseService;
		$this->l = $l;
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * The viewer-safe catalog list. `scope=active` (default): active
	 * items only while the household gate is enabled, otherwise the
	 * explicit disabled state plus an empty list. `scope=admin`:
	 * owner-only, includes archived items even while off.
	 *
	 * @NoAdminRequired
	 */
	public function index(): JSONResponse {
		try {
			[$house, $viewer] = $this->context();
			$scope = $this->request->getParam('scope', 'active');
			if (!is_string($scope) || !in_array($scope, [RewardService::SCOPE_ACTIVE, RewardService::SCOPE_ADMIN], true)) {
				throw new \InvalidArgumentException($this->l->t('scope must be active or admin'));
			}
			$limit = $this->intParam('limit', RewardService::DEFAULT_LIMIT);
			$cursor = $this->optionalIdParam('cursor');
			return new JSONResponse(['success' => true, 'data' => $this->rewardService->listItems($house->getId(), $viewer, $scope, $cursor, $limit)]);
		} catch (\InvalidArgumentException $e) {
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return $this->error('index', $e);
		}
	}

	/**
	 * Owner-only item creation. Body: {title, description?, priceMinor,
	 * commandId}.
	 *
	 * @NoAdminRequired
	 */
	public function create(): JSONResponse {
		try {
			$house = $this->house();
			$payload = $this->params();
			return new JSONResponse(['success' => true, 'data' => $this->rewardService->createItem($house->getId(), $payload)]);
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
	 * Owner-only item edit/archive. Body: {title?, description?,
	 * priceMinor?, archived?, expectedVersion, commandId}.
	 *
	 * @param int $id Reward item id
	 * @NoAdminRequired
	 */
	public function update(int $id): JSONResponse {
		try {
			$house = $this->house();
			$payload = $this->params();
			return new JSONResponse(['success' => true, 'data' => $this->rewardService->updateItem($house->getId(), $id, $payload)]);
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
				'code' => 'REWARD_ERROR',
				'message' => ControllerErrorFormatter::safeMessage($e)
			]
		], 500);
	}
}
