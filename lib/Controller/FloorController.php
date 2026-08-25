<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Exception\ForbiddenException;
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
	) {
		parent::__construct('chorestrike', $request);
		$this->floorService = $floorService;
		$this->houseService = $houseService;
		$this->authService = $authService;
		$this->l = $l;
	}

	/**
	 * Get all floors for the current house
	 *
	 * @NoAdminRequired
	 */
	public function index(): JSONResponse {
		// Workaround for Apache/Nextcloud POST-to-GET rewrite issue:
		// Check for create payload in JSON body, $_POST, or query params
		$rawBody = file_get_contents('php://input');
		$jsonPayload = is_string($rawBody) && trim($rawBody) !== '' ? json_decode($rawBody, true) : null;

		// Try JSON body first, then form-urlencoded, then query params
		$name = null;
		$sortOrder = null;

		if (is_array($jsonPayload) && isset($jsonPayload['name'])) {
			$name = $jsonPayload['name'];
			$sortOrder = $jsonPayload['sortOrder'] ?? null;
		} elseif (isset($_POST['name']) && is_string($_POST['name']) && trim($_POST['name']) !== '') {
			$name = trim($_POST['name']);
			$sortOrder = isset($_POST['sortOrder']) && $_POST['sortOrder'] !== '' ? (int)$_POST['sortOrder'] : null;
		} elseif (isset($_GET['name']) && is_string($_GET['name']) && trim($_GET['name']) !== '') {
			$name = trim($_GET['name']);
			$sortOrder = isset($_GET['sortOrder']) && $_GET['sortOrder'] !== '' ? (int)$_GET['sortOrder'] : null;
		}

		if ($name !== null) {
			return $this->create($name, $sortOrder);
		}

		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);

			// Require house membership
			$this->authService->ensureHouseMember($house->getId());

			$floors = $this->floorService->getFloors($house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn ($floor) => $floor->jsonSerialize(), $floors)
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'FLOORS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get floor with rooms
	 *
	 * @NoAdminRequired
	 */
	public function show(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());
			$this->floorService->validateFloorOwnership($id, $house->getId());

			$floorWithRooms = $this->floorService->getFloorWithRooms($id);
			$houseId = $floorWithRooms['floor']->getHouseId();
			$this->authService->ensureHouseMember($houseId);

			return new JSONResponse([
				'success' => true,
				'data' => [
					'floor' => $floorWithRooms['floor']->jsonSerialize(),
					'rooms' => array_map(fn ($room) => $room->jsonSerialize(), $floorWithRooms['rooms']),
					'stats' => $floorWithRooms['stats']
				]
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'FLOOR_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
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
	 * Create a new floor via a dedicated create route.
	 *
	 * @NoAdminRequired
	 */
	public function createExplicit(?string $name = null, ?int $sortOrder = null): JSONResponse {
		return $this->createFloorResponse($name, $sortOrder);
	}

	/**
	 * Create a new floor via a collision-free alias route.
	 *
	 * @NoAdminRequired
	 */
	public function createAlias(?string $name = null, ?int $sortOrder = null): JSONResponse {
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
			$this->authService->ensureHouseMember($house->getId());

			$floor = $this->floorService->createFloor($house->getId(), $resolvedName, $resolvedSortOrder);

			return new JSONResponse([
				'success' => true,
				'data' => $floor->jsonSerialize()
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'FLOOR_CREATE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	private function getRequestMethod(): string {
		try {
			return strtoupper($this->request->getMethod());
		} catch (\Throwable $exception) {
			return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
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
			$this->authService->ensureHouseMember($house->getId());
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
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
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
			$this->authService->ensureHouseMember($house->getId());

			$updatedFloors = $this->floorService->reorderFloors($house->getId(), $floorOrder);

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn ($floor) => $floor->jsonSerialize(), $updatedFloors)
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
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
			$this->authService->ensureHouseMember($house->getId());
			$this->floorService->validateFloorOwnership($id, $house->getId());

			$this->floorService->deleteFloor($id);

			return new JSONResponse([
				'success' => true,
				'message' => $this->l->t('Floor deleted successfully')
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
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
			$this->authService->ensureHouseMember($house->getId());

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
