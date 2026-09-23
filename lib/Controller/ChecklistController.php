<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\ChecklistService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * API controller for checklist-related endpoints
 *
 * Thin controller (CR-053-01): house preamble + input validation +
 * delegation to {@see ChecklistService}, which owns the authorization
 * guard (house linkage + task-visibility matrix, denials map to 404).
 */
class ChecklistController extends Controller {
	use ControllerLoggingTrait;

	private ChecklistService $checklistService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IL10N $l;
	private int $houseId = 0;

	public function __construct(
		IRequest $request,
		ChecklistService $checklistService,
		HouseService $houseService,
		AuthorizationService $authService,
		IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->checklistService = $checklistService;
		$this->houseService = $houseService;
		$this->authService = $authService;
		$this->l = $l;
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * Get checklist items for a task
	 *
	 * @NoAdminRequired
	 */
	public function getTaskItems(int $taskId): JSONResponse {
		try {
			$houseUser = $this->requireViewer();

			$items = $this->checklistService->getTaskItems($taskId, $this->houseId, $houseUser);

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn ($item) => $item->jsonSerialize(), $items)
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('checklist', $e);
			return ControllerErrorFormatter::serverErrorResponse($e, 'CHECKLIST_ITEMS_ERROR');
		}
	}

	/**
	 * Add checklist item to task
	 *
	 * @NoAdminRequired
	 */
	public function addTaskItem(int $taskId, string $label, ?int $sortOrder = null): JSONResponse {
		try {
			if (empty(trim($label))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('Checklist item label is required')
					]
				], 400);
			}

			$houseUser = $this->requireViewer();

			$item = $this->checklistService->addTaskItem($taskId, $label, $sortOrder, $this->houseId, $houseUser);

			return new JSONResponse([
				'success' => true,
				'data' => $item->jsonSerialize(),
				'message' => $this->l->t('Checklist item added')
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('checklist', $e);
			return ControllerErrorFormatter::serverErrorResponse($e, 'ADD_CHECKLIST_ITEM_ERROR');
		}
	}

	/**
	 * Update checklist item
	 *
	 * @NoAdminRequired
	 */
	public function updateTaskItem(int $itemId, string $label): JSONResponse {
		try {
			if (empty(trim($label))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('Checklist item label is required')
					]
				], 400);
			}

			$houseUser = $this->requireViewer();

			$item = $this->checklistService->updateTaskItem($itemId, $label, $this->houseId, $houseUser);

			return new JSONResponse([
				'success' => true,
				'data' => $item->jsonSerialize(),
				'message' => $this->l->t('Checklist item updated')
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('checklist', $e);
			return ControllerErrorFormatter::serverErrorResponse($e, 'UPDATE_CHECKLIST_ITEM_ERROR');
		}
	}

	/**
	 * Delete checklist item
	 *
	 * @NoAdminRequired
	 */
	public function deleteTaskItem(int $itemId): JSONResponse {
		try {
			$houseUser = $this->requireViewer();

			$this->checklistService->deleteTaskItem($itemId, $this->houseId, $houseUser);

			return new JSONResponse([
				'success' => true,
				'message' => $this->l->t('Checklist item deleted')
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('checklist', $e);
			return ControllerErrorFormatter::serverErrorResponse($e, 'DELETE_CHECKLIST_ITEM_ERROR');
		}
	}

	/**
	 * Reorder checklist items
	 *
	 * @NoAdminRequired
	 */
	public function reorderTaskItems(): JSONResponse {
		try {
			$itemOrders = $this->request->getParam('itemOrders', []);

			if (empty($itemOrders) || !is_array($itemOrders)) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('Item orders array is required')
					]
				], 400);
			}

			$houseUser = $this->requireViewer();

			$this->checklistService->reorderTaskItems($itemOrders, $this->houseId, $houseUser);

			return new JSONResponse([
				'success' => true,
				'message' => $this->l->t('Items reordered')
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (\InvalidArgumentException $e) {
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('checklist', $e);
			return ControllerErrorFormatter::serverErrorResponse($e, 'REORDER_CHECKLIST_ITEMS_ERROR');
		}
	}

	/**
	 * Get checklist progress for one occurrence of a room task
	 * (CS-PLAN-072 WP1). `occurrenceId` is the public
	 * `<scheduleRevisionId>:<ordinal>` key; without it the canonical
	 * selection target is used. The response carries each row's
	 * occurrence identity and progress revision plus the resolved target.
	 *
	 * @NoAdminRequired
	 */
	public function getRoomTaskProgress(int $roomTaskId): JSONResponse {
		try {
			$houseUser = $this->requireViewer();
			$occurrenceKey = $this->request->getParam('occurrenceId');
			$validationError = $this->validateOccurrenceKey($occurrenceKey);
			if ($validationError !== null) {
				return $validationError;
			}

			$result = $this->checklistService->getRoomTaskProgress(
				$roomTaskId,
				is_string($occurrenceKey) && $occurrenceKey !== '' ? $occurrenceKey : null,
				$this->houseId,
				$houseUser
			);

			return new JSONResponse([
				'success' => true,
				'data' => [
					'items' => array_map(fn ($p) => $p->jsonSerialize(), $result['items']),
					'stats' => $result['stats'],
					'occurrence' => $result['occurrence']
				]
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('checklist', $e);
			return ControllerErrorFormatter::serverErrorResponse($e, 'CHECKLIST_PROGRESS_ERROR');
		}
	}

	/**
	 * Toggle a checklist item of one occurrence of a room task
	 * (CS-PLAN-072 WP1): `occurrenceId` targets the occurrence (default:
	 * canonical selection), `expectedRevision` is the stale-mutation
	 * guard — a moved-on progress state answers 409 instead of leaking
	 * into the occurrence's checklist.
	 *
	 * @NoAdminRequired
	 */
	public function toggleItem(int $roomTaskId, int $itemId): JSONResponse {
		try {
			$houseUser = $this->requireViewer();
			$occurrenceKey = $this->request->getParam('occurrenceId');
			$validationError = $this->validateOccurrenceKey($occurrenceKey);
			if ($validationError !== null) {
				return $validationError;
			}
			$expectedRevision = $this->request->getParam('expectedRevision');
			if ($expectedRevision !== null && (!is_numeric($expectedRevision) || (int)$expectedRevision < 0)) {
				return ControllerErrorFormatter::validation(new \InvalidArgumentException(
					$this->l->t('expectedRevision must be a non-negative integer')
				));
			}

			$result = $this->checklistService->toggleItem(
				$roomTaskId,
				is_string($occurrenceKey) && $occurrenceKey !== '' ? $occurrenceKey : null,
				$itemId,
				$expectedRevision !== null ? (int)$expectedRevision : null,
				$this->houseId,
				$houseUser
			);

			return new JSONResponse([
				'success' => true,
				'data' => [
					'progress' => $result['progress']->jsonSerialize(),
					'stats' => $result['stats'],
					'occurrence' => $result['occurrence']
				]
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('checklist', $e);
			return ControllerErrorFormatter::serverErrorResponse($e, 'TOGGLE_CHECKLIST_ITEM_ERROR');
		}
	}

	/**
	 * Validate a supplied occurrence key at the controller boundary
	 * (RF-072-WP1-13): a malformed public key is a clean 400, never an
	 * exception escaping into the generic 500 handler — the completion
	 * route applies the same contract.
	 */
	private function validateOccurrenceKey(mixed $occurrenceKey): ?JSONResponse {
		if (!is_string($occurrenceKey) || $occurrenceKey === '') {
			return null;
		}
		try {
			Occurrence::parsePublicKey($occurrenceKey);
		} catch (\InvalidArgumentException $e) {
			return ControllerErrorFormatter::validation($e);
		}
		return null;
	}

	/**
	 * Resolve the caller's house and membership (the shared preamble).
	 */
	private function requireViewer(): \OCA\ChoreStrike\Db\HouseUser {
		$instanceId = $this->houseService->getInstanceId();
		$house = $this->houseService->getOrCreateHouse($instanceId);
		$this->houseId = $house->getId();
		return $this->authService->assertHouseMember($this->houseId);
	}
}
