<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\TemplateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * API controller for room-related endpoints
 */
class RoomController extends Controller {
	/** Maximum persisted roomType length (rooms.room_type column, CR-027-01). */
	private const ROOM_TYPE_MAX_LENGTH = 50;

	private RoomService $roomService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private TemplateService $templateService;
	private IL10N $l;

	public function __construct(
		IRequest $request,
		RoomService $roomService,
		HouseService $houseService,
		AuthorizationService $authService,
		TemplateService $templateService,
		IL10N $l,
	) {
		parent::__construct('chorestrike', $request);
		$this->roomService = $roomService;
		$this->houseService = $houseService;
		$this->authService = $authService;
		$this->templateService = $templateService;
		$this->l = $l;
	}

	/**
	 * Create a new room
	 *
	 * @NoAdminRequired
	 */
	public function create(?int $floorId = null, ?string $name = null, ?string $icon = null, ?string $roomType = null): JSONResponse {
		return $this->createRoomResponse($floorId, $name, $icon, $roomType);
	}

	/**
	 * Build a room create response from explicit arguments or request params.
	 */
	private function createRoomResponse(?int $floorId = null, ?string $name = null, ?string $icon = null, ?string $roomType = null): JSONResponse {
		try {
			$resolvedFloorId = $floorId ?? $this->resolveNullableInt('floorId');
			$resolvedName = $name ?? $this->resolveNullableString('name');
			$resolvedIcon = $icon ?? $this->resolveNullableString('icon');
			$resolvedRoomType = $roomType ?? $this->resolveNullableString('roomType') ?? 'generic';

			if ($resolvedFloorId === null) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('Floor ID is required')
					]
				], 400);
			}

			if ($resolvedName === null || empty(trim($resolvedName))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('Room name is required')
					]
				], 400);
			}

			// CR-027-01: never trust client roomType — reject oversized and
			// non-catalog values instead of persisting them (a >50-char value
			// would 500 on insert; an unknown type degrades the template picker).
			if (strlen($resolvedRoomType) > self::ROOM_TYPE_MAX_LENGTH) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('Room type must be at most 50 characters')
					]
				], 400);
			}

			if (!$this->templateService->isValidRoomType($resolvedRoomType)) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('Invalid room type')
					]
				], 400);
			}

			// Verify house membership via floor
			$floor = $this->roomService->getFloor($resolvedFloorId);
			$this->authService->ensureHouseMember($floor->getHouseId());

			$room = $this->roomService->createRoom($resolvedFloorId, $resolvedName, $resolvedIcon, $resolvedRoomType);

			return new JSONResponse([
				'success' => true,
				'data' => $room->jsonSerialize()
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ROOM_CREATE_ERROR',
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
	 * Update room name
	 *
	 * @NoAdminRequired
	 */
	public function update(int $id, string $name): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());
			$this->roomService->validateRoomOwnership($id, $house->getId());

			if (empty(trim($name))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('Room name is required')
					]
				], 400);
			}

			$room = $this->roomService->updateRoomName($id, $name);

			return new JSONResponse([
				'success' => true,
				'data' => $room->jsonSerialize()
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ROOM_UPDATE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Delete room
	 *
	 * @NoAdminRequired
	 */
	public function delete(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());
			$this->authService->requireHouseOwner($house->getId());
			$this->roomService->validateRoomOwnership($id, $house->getId());

			$this->roomService->deleteRoom($id);

			return new JSONResponse([
				'success' => true,
				'message' => $this->l->t('Room deleted successfully')
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ROOM_DELETE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Update room cleanliness
	 *
	 * @NoAdminRequired
	 */
	public function updateCleanliness(int $id, int $cleanlinessChange): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());
			$this->roomService->validateRoomOwnership($id, $house->getId());

			if ($cleanlinessChange < -100 || $cleanlinessChange > 100) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('Cleanliness change must be between -100 and 100')
					]
				], 400);
			}

			$room = $this->roomService->updateRoomCleanliness($id, $cleanlinessChange);

			return new JSONResponse([
				'success' => true,
				'data' => $room->jsonSerialize()
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'CLEANLINESS_UPDATE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}
}
