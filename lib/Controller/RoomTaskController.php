<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * API controller for room task-related endpoints
 */
class RoomTaskController extends OCSController
{
    private RoomTaskService $roomTaskService;
    private HouseService $houseService;
    private AuthorizationService $authService;

    public function __construct(
        IRequest $request,
        RoomTaskService $roomTaskService,
        HouseService $houseService,
        AuthorizationService $authService
    ) {
        parent::__construct('chorestrike', $request);
        $this->roomTaskService = $roomTaskService;
        $this->houseService = $houseService;
        $this->authService = $authService;
    }

    /**
     * Get room tasks for a room
     *
     * @NoAdminRequired
     */
    public function index(?int $roomId = null, ?int $scopeId = null, bool $activeOnly = true): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            if ($scopeId !== null) {
                $roomTasks = $this->roomTaskService->getScopeTasks($scopeId, $activeOnly);
            } elseif ($roomId !== null) {
                $roomTasks = $this->roomTaskService->getRoomTasks($roomId, $activeOnly);
            } else {
                return new JSONResponse([
                    'success' => false,
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'roomId or scopeId is required'
                    ]
                ], 400);
            }

            return new JSONResponse([
                'success' => true,
                'data' => array_map(fn ($roomTask) => $roomTask->jsonSerialize(), $roomTasks)
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'ROOM_TASKS_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get room task by ID
     *
     * @NoAdminRequired
     */
    public function show(int $id): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());
            $this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

            $roomTaskWithDetails = $this->roomTaskService->getRoomTaskWithDetails($id);
            $roomTask = $roomTaskWithDetails['roomTask'];
            $task = $roomTaskWithDetails['task'];

            // Calculate flex window bounds
            $flexDays = $task->getFlexDays();
            $flexWindow = $roomTask->getFlexWindowBounds($flexDays);

            return new JSONResponse([
                'success' => true,
                'data' => [
                    'roomTask' => $roomTask->jsonSerialize(),
                    'task' => $task->jsonSerialize(),
                    'room' => $roomTaskWithDetails['room']->jsonSerialize(),
                    'status' => $roomTaskWithDetails['status'],
                    'isOverdue' => $roomTaskWithDetails['isOverdue'],
                    'isDueToday' => $roomTaskWithDetails['isDueToday'],
                    'daysUntilDue' => $roomTaskWithDetails['daysUntilDue'],
                    'flexWindow' => [
                        'earliest' => $flexWindow['earliest']?->format('c'),
                        'ideal' => $flexWindow['ideal']?->format('c'),
                        'latest' => $flexWindow['latest']?->format('c'),
                        'flexDays' => $flexDays,
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'ROOM_TASK_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Create a new room task
     *
     * @NoAdminRequired
     */
    public function create(
        int $roomId,
        int $taskId,
        ?string $nextDue = null,
        ?int $scopeId = null,
        ?string $assignedToUserId = null
    ): JSONResponse {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $nextDueDate = null;
            if ($nextDue !== null) {
                try {
                    $nextDueDate = new \DateTime($nextDue);
                } catch (\Exception $e) {
                    throw new \InvalidArgumentException('Invalid nextDue date format: ' . $e->getMessage());
                }
            }

            $roomTask = $this->roomTaskService->createRoomTask($roomId, $taskId, $nextDueDate, $scopeId, $assignedToUserId);

            return new JSONResponse([
                'success' => true,
                'data' => $roomTask->jsonSerialize()
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'ROOM_TASK_CREATE_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Assign room task to user
     *
     * @NoAdminRequired
     */
    public function assign(int $id, string $userId): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());
            $this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

            if (empty(trim($userId))) {
                return new JSONResponse([
                    'success' => false,
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'User ID is required'
                    ]
                ], 400);
            }

            $roomTask = $this->roomTaskService->assignTask($id, $userId);

            return new JSONResponse([
                'success' => true,
                'data' => $roomTask->jsonSerialize()
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'ASSIGN_TASK_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Unassign room task from user
     *
     * @NoAdminRequired
     */
    public function unassign(int $id): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());
            $this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

            $roomTask = $this->roomTaskService->unassignTask($id);

            return new JSONResponse([
                'success' => true,
                'data' => $roomTask->jsonSerialize()
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'UNASSIGN_TASK_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Deactivate room task
     *
     * @NoAdminRequired
     */
    public function deactivate(int $id): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());
            $this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

            $roomTask = $this->roomTaskService->deactivateTask($id);

            return new JSONResponse([
                'success' => true,
                'data' => $roomTask->jsonSerialize()
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'DEACTIVATE_TASK_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Reactivate room task
     *
     * @NoAdminRequired
     */
    public function reactivate(int $id): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());
            $this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

            $roomTask = $this->roomTaskService->reactivateTask($id);

            return new JSONResponse([
                'success' => true,
                'data' => $roomTask->jsonSerialize()
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'REACTIVATE_TASK_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Update room task due date
     *
     * @NoAdminRequired
     */
    public function updateDueDate(int $id, string $nextDue): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());
            $this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

            try {
                $nextDueDate = new \DateTime($nextDue);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('Invalid nextDue date format: ' . $e->getMessage());
            }

            $roomTask = $this->roomTaskService->updateDueDate($id, $nextDueDate);

            return new JSONResponse([
                'success' => true,
                'data' => $roomTask->jsonSerialize()
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'UPDATE_DUE_DATE_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Complete room task
     *
     * @NoAdminRequired
     */
    public function complete(int $id): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());
            $this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

            $currentUserId = $this->authService->requireCurrentUserId();
            $result = $this->roomTaskService->completeTask($id, $currentUserId);

            return new JSONResponse([
                'success' => true,
                'data' => [
                    'roomTask' => $result['roomTask']->jsonSerialize(),
                    'room' => $result['room']->jsonSerialize(),
                    'task' => $result['task']->jsonSerialize(),
                    'rewards' => $result['rewards']
                ]
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'COMPLETE_TASK_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Delete room task
     *
     * @NoAdminRequired
     */
    public function delete(int $id): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());
            $this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

            $this->roomTaskService->deleteRoomTask($id);

            return new JSONResponse([
                'success' => true,
                'message' => 'Room task deleted successfully'
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'ROOM_TASK_DELETE_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get overdue tasks
     *
     * @NoAdminRequired
     */
    public function overdue(?int $roomId = null): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $overdueTasks = $this->roomTaskService->getOverdueTasks($roomId);

            return new JSONResponse([
                'success' => true,
                'data' => array_map(fn ($task) => $task->jsonSerialize(), $overdueTasks)
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'OVERDUE_TASKS_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get tasks due today
     *
     * @NoAdminRequired
     */
    public function dueToday(?int $roomId = null): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $dueTodayTasks = $this->roomTaskService->getDueTodayTasks($roomId);

            return new JSONResponse([
                'success' => true,
                'data' => array_map(fn ($task) => $task->jsonSerialize(), $dueTodayTasks)
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'DUE_TODAY_TASKS_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get tasks due within days
     *
     * @NoAdminRequired
     */
    public function dueWithinDays(int $days, ?int $roomId = null): JSONResponse
    {
        try {
            if ($days < 1 || $days > 365) {
                return new JSONResponse([
                    'success' => false,
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'Days must be between 1 and 365'
                    ]
                ], 400);
            }

            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $dueTasks = $this->roomTaskService->getDueWithinDaysTasks($days, $roomId);

            return new JSONResponse([
                'success' => true,
                'data' => array_map(fn ($task) => $task->jsonSerialize(), $dueTasks)
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'DUE_WITHIN_DAYS_TASKS_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get user tasks
     *
     * @NoAdminRequired
     */
    public function userTasks(string $userId, bool $activeOnly = true): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            if (empty(trim($userId))) {
                return new JSONResponse([
                    'success' => false,
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'User ID is required'
                    ]
                ], 400);
            }

            $userTasks = $this->roomTaskService->getUserTasks($userId, $activeOnly);

            return new JSONResponse([
                'success' => true,
                'data' => array_map(fn ($task) => $task->jsonSerialize(), $userTasks)
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'USER_TASKS_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get room task statistics
     *
     * @NoAdminRequired
     */
    public function roomStats(int $roomId): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $stats = $this->roomTaskService->getRoomTaskStats($roomId);

            return new JSONResponse([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'ROOM_STATS_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get user task statistics
     *
     * @NoAdminRequired
     */
    public function userStats(string $userId): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            if (empty(trim($userId))) {
                return new JSONResponse([
                    'success' => false,
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'User ID is required'
                    ]
                ], 400);
            }

            $stats = $this->roomTaskService->getUserTaskStats($userId);

            return new JSONResponse([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'USER_STATS_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Assign room task to group
     *
     * @NoAdminRequired
     */
    public function assignToGroup(int $id, string $groupId): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());
            $this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

            $roomTask = $this->roomTaskService->assignToGroup($id, $groupId);

            return new JSONResponse([
                'success' => true,
                'data' => $roomTask->jsonSerialize()
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'ASSIGN_TO_GROUP_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Claim a group-assigned task for the current user
     *
     * @NoAdminRequired
     */
    public function claimForUser(int $id): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $currentUserId = $this->authService->requireCurrentUserId();
            $roomTask = $this->roomTaskService->claimForUser($id, $currentUserId);

            return new JSONResponse([
                'success' => true,
                'data' => $roomTask->jsonSerialize()
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'CLAIM_TASK_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Unclaim a group-assigned task
     *
     * @NoAdminRequired
     */
    public function unclaim(int $id): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $roomTask = $this->roomTaskService->unclaim($id);

            return new JSONResponse([
                'success' => true,
                'data' => $roomTask->jsonSerialize()
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'UNCLAIM_TASK_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get tasks assigned to a group
     *
     * @NoAdminRequired
     */
    public function groupTasks(string $groupId, bool $activeOnly = true): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $tasks = $this->roomTaskService->getGroupTasks($groupId, $activeOnly);

            return new JSONResponse([
                'success' => true,
                'data' => array_map(fn ($task) => $task->jsonSerialize(), $tasks)
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'GROUP_TASKS_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get tasks visible to the current user (assigned directly or via group membership)
     *
     * @NoAdminRequired
     */
    public function visibleToUser(): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $currentUserId = $this->authService->requireCurrentUserId();
            $groupIds = $this->authService->getCurrentUserGroupIds();

            $tasks = $this->roomTaskService->getTasksVisibleToUser($currentUserId, $groupIds);

            return new JSONResponse([
                'success' => true,
                'data' => array_map(fn ($task) => $task->jsonSerialize(), $tasks)
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'VISIBLE_TASKS_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Join a task as a participant (teamwork)
     *
     * @NoAdminRequired
     */
    public function joinTask(int $id): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $currentUserId = $this->authService->requireCurrentUserId();
            $result = $this->roomTaskService->joinTask($id, $currentUserId);

            return new JSONResponse([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'JOIN_TASK_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Leave a task as a participant
     *
     * @NoAdminRequired
     */
    public function leaveTask(int $id): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $currentUserId = $this->authService->requireCurrentUserId();
            $result = $this->roomTaskService->leaveTask($id, $currentUserId);

            return new JSONResponse([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'LEAVE_TASK_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get participants for a task
     *
     * @NoAdminRequired
     */
    public function getParticipants(int $id): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $participants = $this->roomTaskService->getTaskParticipants($id);

            return new JSONResponse([
                'success' => true,
                'data' => array_map(fn ($p) => $p->jsonSerialize(), $participants)
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'GET_PARTICIPANTS_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Claim an overdue task from another user
     *
     * @NoAdminRequired
     */
    public function claimOverdue(int $id): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $currentUserId = $this->authService->requireCurrentUserId();
            $result = $this->roomTaskService->claimOverdueTask($id, $currentUserId);

            return new JSONResponse([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'CLAIM_OVERDUE_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get overdue tasks that can be claimed
     *
     * @NoAdminRequired
     */
    public function overdueClaims(): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $tasks = $this->roomTaskService->getClaimableOverdueTasks($house->getId());

            return new JSONResponse([
                'success' => true,
                'data' => array_map(fn ($task) => $task->jsonSerialize(), $tasks)
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'OVERDUE_CLAIMS_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get claim history for a task
     *
     * @NoAdminRequired
     */
    public function claimHistory(int $id): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $claims = $this->roomTaskService->getClaimHistory($id);

            return new JSONResponse([
                'success' => true,
                'data' => array_map(fn ($claim) => $claim->jsonSerialize(), $claims)
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'CLAIM_HISTORY_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get claim statistics for a user
     *
     * @NoAdminRequired
     */
    public function claimStats(string $userId): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->requireHouseMember($house->getId());

            $stats = $this->roomTaskService->getUserClaimStats($userId);

            return new JSONResponse([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'CLAIM_STATS_ERROR',
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }
}
