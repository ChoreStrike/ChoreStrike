<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\FloorService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * API controller for floor-related endpoints
 */
class FloorController extends Controller {
	use ControllerLoggingTrait;

	private FloorService $floorService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IL10N $l;

	public function __construct(
		IRequest $request,
		FloorService $floorService,
		HouseService $houseService,
		AuthorizationService $authService,
		IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->floorService = $floorService;
		$this->houseService = $houseService;
		$this->authService = $authService;
		$this->l = $l;
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * Create a new floor
	 *
	 * @NoAdminRequired
	 */
	public function create(?string $name = null, ?int $sortOrder = null): JSONResponse {
		return $this->createFloorResponse($name, $sortOrder);
	}

	/**
	 * Build a floor create response from explicit arguments or request params.
	 */
	private function createFloorResponse(?string $name = null, ?int $sortOrder = null): JSONResponse {
		try {
			$resolvedName = $name ?? $this->resolveNullableString('name');
			$resolvedSortOrder = $sortOrder ?? $this->resolveNullableInt('sortOrder');

			if ($resolvedName === null || empty(trim($resolvedName))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('Floor name is required')
					]
				], 400);
			}

			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);

			// Require house membership for creation
			$this->authService->assertHouseMember($house->getId());

			$floor = $this->floorService->createFloor($house->getId(), $resolvedName, $resolvedSortOrder);

			return new JSONResponse([
				'success' => true,
				'data' => $floor->jsonSerialize()
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('floor', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'FLOOR_CREATE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	private function resolveNullableString(string $key): ?string {
		$value = $this->resolveRequestValue($key);
		if (!is_string($value)) {
			return null;
		}

		$trimmedValue = trim($value);
		return $trimmedValue === '' ? null : $trimmedValue;
	}

	private function resolveNullableInt(string $key): ?int {
		$value = $this->resolveRequestValue($key);
		if ($value === null || $value === '') {
			return null;
		}

		return (int)$value;
	}

	private function resolveRequestValue(string $key) {
		$requestValue = $this->request->getParam($key);
		if ($requestValue !== null && $requestValue !== '') {
			return $requestValue;
		}

		$requestParams = $this->getRequestParams();
		if (array_key_exists($key, $requestParams) && $requestParams[$key] !== null && $requestParams[$key] !== '') {
			return $requestParams[$key];
		}

		if (array_key_exists($key, $_POST) && $_POST[$key] !== null && $_POST[$key] !== '') {
			return $_POST[$key];
		}

		if (array_key_exists($key, $_GET) && $_GET[$key] !== null && $_GET[$key] !== '') {
			return $_GET[$key];
		}

		return null;
	}

	private function getRequestParams(): array {
		$requestParams = [];

		try {
			$requestParams = $this->request->getParams();
		} catch (\Throwable $exception) {
			$requestParams = [];
		}

		if (is_array($requestParams) && $requestParams !== []) {
			return $requestParams;
		}

		$rawBody = file_get_contents('php://input');
		if (!is_string($rawBody) || trim($rawBody) === '') {
			return [];
		}

		$decodedBody = json_decode($rawBody, true);
		return is_array($decodedBody) ? $decodedBody : [];
	}

	/**
	 * Update floor name
	 *
	 * @NoAdminRequired
	 */
	public function update(int $id, string $name): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->assertHouseMember($house->getId());
			$this->floorService->validateFloorOwnership($id, $house->getId());

			if (empty(trim($name))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('Floor name is required')
					]
				], 400);
			}

			$floor = $this->floorService->updateFloorName($id, $name);

			return new JSONResponse([
				'success' => true,
				'data' => $floor->jsonSerialize()
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('floor', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'FLOOR_UPDATE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Reorder floors
	 *
	 * @NoAdminRequired
	 */
	public function reorder(array $floorOrder): JSONResponse {
		try {
			if (empty($floorOrder)) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('Floor order is required')
					]
				], 400);
			}

			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);

			// Require house membership
			$this->authService->assertHouseMember($house->getId());

			$updatedFloors = $this->floorService->reorderFloors($house->getId(), $floorOrder);

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn ($floor) => $floor->jsonSerialize(), $updatedFloors)
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('floor', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'FLOOR_REORDER_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Delete floor
	 *
	 * @NoAdminRequired
	 */
	public function delete(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->assertHouseMember($house->getId());
			$this->authService->requireHouseOwner($house->getId());
			$this->floorService->validateFloorOwnership($id, $house->getId());

			$this->floorService->deleteFloor($id);

			return new JSONResponse([
				'success' => true,
				'message' => $this->l->t('Floor deleted successfully')
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('floor', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'FLOOR_DELETE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get all floors with their rooms for the current house
	 *
	 * @NoAdminRequired
	 */
	public function withRooms(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);

			// Require house membership
			$this->authService->assertHouseMember($house->getId());

			$floorsWithRooms = $this->floorService->getFloorsWithRooms($house->getId());

			$result = [];
			foreach ($floorsWithRooms as $floorWithRooms) {
				$result[] = [
					'floor' => $floorWithRooms['floor']->jsonSerialize(),
					'rooms' => array_map(fn ($room) => $room->jsonSerialize(), $floorWithRooms['rooms']),
					'stats' => $floorWithRooms['stats']
				];
			}

			return new JSONResponse([
				'success' => true,
				'data' => $result
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('floor', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'FLOORS_WITH_ROOMS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}
}
