<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
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
	) {
		parent::__construct('chorestrike', $request);
		$this->checklistService = $checklistService;
		$this->houseService = $houseService;
		$this->authService = $authService;
		$this->l = $l;
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
			return ControllerErrorFormatter::serverErrorResponse($e, 'REORDER_CHECKLIST_ITEMS_ERROR');
		}
	}

	/**
	 * Get checklist progress for a room task
	 *
	 * @NoAdminRequired
	 */
	public function getRoomTaskProgress(int $roomTaskId): JSONResponse {
		try {
			$houseUser = $this->requireViewer();

			$result = $this->checklistService->getRoomTaskProgress($roomTaskId, $this->houseId, $houseUser);

			return new JSONResponse([
				'success' => true,
				'data' => [
					'items' => array_map(fn ($p) => $p->jsonSerialize(), $result['items']),
					'stats' => $result['stats']
				]
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return ControllerErrorFormatter::serverErrorResponse($e, 'CHECKLIST_PROGRESS_ERROR');
		}
	}

	/**
	 * Toggle checklist item status for a room task
	 *
	 * @NoAdminRequired
	 */
	public function toggleItem(int $roomTaskId, int $itemId): JSONResponse {
		try {
			$houseUser = $this->requireViewer();

			$result = $this->checklistService->toggleItem($roomTaskId, $itemId, $this->houseId, $houseUser);

			return new JSONResponse([
				'success' => true,
				'data' => [
					'progress' => $result['progress']->jsonSerialize(),
					'stats' => $result['stats']
				]
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return ControllerErrorFormatter::serverErrorResponse($e, 'TOGGLE_CHECKLIST_ITEM_ERROR');
		}
	}

	/**
	 * Resolve the caller's house and membership (the shared preamble).
	 */
	private function requireViewer(): \OCA\ChoreStrike\Db\HouseUser {
		$instanceId = $this->houseService->getInstanceId();
		$house = $this->houseService->getOrCreateHouse($instanceId);
		$this->houseId = $house->getId();
		return $this->authService->ensureHouseMember($this->houseId);
	}
}
