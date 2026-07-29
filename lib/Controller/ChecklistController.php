<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Db\TaskChecklistItemMapper;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCP\AppFramework\Http\JSONResponse;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Controller;
use OCP\IRequest;

/**
 * API controller for checklist-related endpoints
 */
class ChecklistController extends Controller
{
    private TaskChecklistItemMapper $checklistItemMapper;
    private RoomTaskChecklistProgressMapper $progressMapper;
    private RoomTaskService $roomTaskService;
    private HouseService $houseService;
    private AuthorizationService $authService;

    public function __construct(
        IRequest $request,
        TaskChecklistItemMapper $checklistItemMapper,
        RoomTaskChecklistProgressMapper $progressMapper,
        RoomTaskService $roomTaskService,
        HouseService $houseService,
        AuthorizationService $authService
    ) {
        parent::__construct('chorestrike', $request);
        $this->checklistItemMapper = $checklistItemMapper;
        $this->progressMapper = $progressMapper;
        $this->roomTaskService = $roomTaskService;
        $this->houseService = $houseService;
        $this->authService = $authService;
    }

    /**
     * Get checklist items for a task
     *
     * @NoAdminRequired
     */
    public function getTaskItems(int $taskId): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->ensureHouseMember($house->getId());

            $items = $this->checklistItemMapper->findByTaskId($taskId);

            return new JSONResponse([
                'success' => true,
                'data' => array_map(fn ($item) => $item->jsonSerialize(), $items)
            ]);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'CHECKLIST_ITEMS_ERROR',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 500);
        }
    }

    /**
     * Add checklist item to task
     *
     * @NoAdminRequired
     */
    public function addTaskItem(int $taskId, string $label, ?int $sortOrder = null): JSONResponse
    {
        try {
            if (empty(trim($label))) {
                return new JSONResponse([
                    'success' => false,
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'Checklist item label is required'
                    ]
                ], 400);
            }

            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->ensureHouseMember($house->getId());

            // If sortOrder not provided, append to end
            if ($sortOrder === null) {
                $existingItems = $this->checklistItemMapper->findByTaskId($taskId);
                $sortOrder = count($existingItems);
            }

            $item = $this->checklistItemMapper->create($taskId, $label, $sortOrder);

            return new JSONResponse([
                'success' => true,
                'data' => $item->jsonSerialize(),
                'message' => 'Checklist item added'
            ]);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'ADD_CHECKLIST_ITEM_ERROR',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 500);
        }
    }

    /**
     * Update checklist item
     *
     * @NoAdminRequired
     */
    public function updateTaskItem(int $itemId, string $label): JSONResponse
    {
        try {
            if (empty(trim($label))) {
                return new JSONResponse([
                    'success' => false,
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'Checklist item label is required'
                    ]
                ], 400);
            }

            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->ensureHouseMember($house->getId());

            $item = $this->checklistItemMapper->updateLabel($itemId, $label);

            return new JSONResponse([
                'success' => true,
                'data' => $item->jsonSerialize(),
                'message' => 'Checklist item updated'
            ]);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'UPDATE_CHECKLIST_ITEM_ERROR',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 500);
        }
    }

    /**
     * Delete checklist item
     *
     * @NoAdminRequired
     */
    public function deleteTaskItem(int $itemId): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->ensureHouseMember($house->getId());

            $item = $this->checklistItemMapper->find($itemId);
            $this->checklistItemMapper->delete($item);

            return new JSONResponse([
                'success' => true,
                'message' => 'Checklist item deleted'
            ]);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'DELETE_CHECKLIST_ITEM_ERROR',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 500);
        }
    }

    /**
     * Reorder checklist items
     *
     * @NoAdminRequired
     */
    public function reorderTaskItems(): JSONResponse
    {
        try {
            $itemOrders = $this->request->getParam('itemOrders', []);

            if (empty($itemOrders) || !is_array($itemOrders)) {
                return new JSONResponse([
                    'success' => false,
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'Item orders array is required'
                    ]
                ], 400);
            }

            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->ensureHouseMember($house->getId());

            $this->checklistItemMapper->updateSortOrders($itemOrders);

            return new JSONResponse([
                'success' => true,
                'message' => 'Items reordered'
            ]);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'REORDER_CHECKLIST_ITEMS_ERROR',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 500);
        }
    }

    /**
     * Get checklist progress for a room task
     *
     * @NoAdminRequired
     */
    public function getRoomTaskProgress(int $roomTaskId): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->ensureHouseMember($house->getId());
            $this->roomTaskService->validateRoomTaskOwnership($roomTaskId, $house->getId());

            $progress = $this->progressMapper->findByRoomTaskId($roomTaskId);
            $stats = $this->progressMapper->getProgressStats($roomTaskId);

            return new JSONResponse([
                'success' => true,
                'data' => [
                    'items' => array_map(fn ($p) => $p->jsonSerialize(), $progress),
                    'stats' => $stats
                ]
            ]);
        } catch (NotFoundException $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'RESOURCE_NOT_FOUND',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 404);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'CHECKLIST_PROGRESS_ERROR',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 500);
        }
    }

    /**
     * Toggle checklist item status for a room task
     *
     * @NoAdminRequired
     */
    public function toggleItem(int $roomTaskId, int $itemId): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $houseUser = $this->authService->ensureHouseMember($house->getId());
            $this->roomTaskService->validateRoomTaskOwnership($roomTaskId, $house->getId());

            $progress = $this->progressMapper->toggle($roomTaskId, $itemId, $houseUser->getId());
            $stats = $this->progressMapper->getProgressStats($roomTaskId);

            return new JSONResponse([
                'success' => true,
                'data' => [
                    'progress' => $progress->jsonSerialize(),
                    'stats' => $stats
                ]
            ]);
        } catch (NotFoundException $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'RESOURCE_NOT_FOUND',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 404);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'TOGGLE_CHECKLIST_ITEM_ERROR',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 500);
        }
    }

    /**
     * Initialize checklist progress for a room task
     *
     * @NoAdminRequired
     */
    public function initializeProgress(int $roomTaskId, int $taskId): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->ensureHouseMember($house->getId());
            $this->roomTaskService->validateRoomTaskOwnership($roomTaskId, $house->getId());
            $roomTask = $this->roomTaskService->getRoomTask($roomTaskId);

            // Get all checklist items for this task
            $items = $this->checklistItemMapper->findByTaskId($roomTask->getTaskId());
            $itemIds = array_map(fn ($item) => $item->getId(), $items);

            // Initialize progress entries
            $this->progressMapper->initializeProgress($roomTaskId, $itemIds);

            return new JSONResponse([
                'success' => true,
                'message' => 'Progress initialized'
            ]);
        } catch (NotFoundException $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'RESOURCE_NOT_FOUND',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 404);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'INITIALIZE_PROGRESS_ERROR',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 500);
        }
    }

    /**
     * Get complete checklist with progress for a room task
     *
     * @NoAdminRequired
     */
    public function getCompleteChecklist(int $roomTaskId, int $taskId): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->ensureHouseMember($house->getId());
            $this->roomTaskService->validateRoomTaskOwnership($roomTaskId, $house->getId());
            $roomTask = $this->roomTaskService->getRoomTask($roomTaskId);

            // Get checklist items
            $items = $this->checklistItemMapper->findByTaskId($roomTask->getTaskId());

            // Get progress
            $progress = $this->progressMapper->findByRoomTaskId($roomTaskId);

            // Create progress lookup
            $progressByItemId = [];
            foreach ($progress as $p) {
                $progressByItemId[$p->getChecklistItemId()] = $p;
            }

            // Combine items with their progress
            $checklistWithProgress = array_map(function ($item) use ($progressByItemId) {
                $itemData = $item->jsonSerialize();
                $itemProgress = $progressByItemId[$item->getId()] ?? null;
                $itemData['progress'] = $itemProgress ? $itemProgress->jsonSerialize() : null;
                $itemData['isChecked'] = $itemProgress ? $itemProgress->getIsChecked() : false;
                return $itemData;
            }, $items);

            $stats = $this->progressMapper->getProgressStats($roomTaskId);

            return new JSONResponse([
                'success' => true,
                'data' => [
                    'items' => $checklistWithProgress,
                    'stats' => $stats
                ]
            ]);
        } catch (NotFoundException $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'RESOURCE_NOT_FOUND',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 404);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'GET_CHECKLIST_ERROR',
                    'message' => ControllerErrorFormatter::safeMessage($e)
                ]
            ], 500);
        }
    }
}
