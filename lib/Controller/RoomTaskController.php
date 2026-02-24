<?php

declare(strict_types=1);

namespace OCA\ChoreWars\Controller;

use OCA\ChoreWars\Service\RoomTaskService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * API controller for room task-related endpoints
 */
class RoomTaskController extends OCSController {

	private RoomTaskService $roomTaskService;

	public function __construct(
		IRequest $request,
		RoomTaskService $roomTaskService
	) {
		parent::__construct($request);
		$this->roomTaskService = $roomTaskService;
	}

	/**
	 * Get room tasks for a room
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function index(int $roomId, bool $activeOnly = true): JSONResponse {
		try {
			$roomTasks = $this->roomTaskService->getRoomTasks($roomId, $activeOnly);
			
			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn($roomTask) => $roomTask->jsonSerialize(), $roomTasks)
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
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function show(int $id): JSONResponse {
		try {
			$roomTaskWithDetails = $this->roomTaskService->getRoomTaskWithDetails($id);
			
			return new JSONResponse([
				'success' => true,
				'data' => [
					'roomTask' => $roomTaskWithDetails['roomTask']->jsonSerialize(),
					'task' => $roomTaskWithDetails['task']->jsonSerialize(),
					'room' => $roomTaskWithDetails['room']->jsonSerialize(),
					'status' => $roomTaskWithDetails['status'],
					'isOverdue' => $roomTaskWithDetails['isOverdue'],
					'isDueToday' => $roomTaskWithDetails['isDueToday'],
					'daysUntilDue' => $roomTaskWithDetails['daysUntilDue']
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
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function create(
		int $roomId,
		int $taskId,
		?string $nextDue = null,
		?string $assignedToUserId = null
	): JSONResponse {
		try {
			$nextDueDate = null;
			if ($nextDue !== null) {
				try {
					$nextDueDate = new \DateTime($nextDue);
				} catch (\Exception $e) {
					throw new \InvalidArgumentException('Invalid nextDue date format: ' . $e->getMessage());
				}
			}

			$roomTask = $this->roomTaskService->createRoomTask($roomId, $taskId, $nextDueDate, $assignedToUserId);
			
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
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function assign(int $id, string $userId): JSONResponse {
		try {
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
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function unassign(int $id): JSONResponse {
		try {
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
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function deactivate(int $id): JSONResponse {
		try {
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
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function reactivate(int $id): JSONResponse {
		try {
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
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function updateDueDate(int $id, string $nextDue): JSONResponse {
		try {
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
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function complete(int $id, string $userId): JSONResponse {
		try {
			if (empty(trim($userId))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'User ID is required'
					]
				], 400);
			}

			$result = $this->roomTaskService->completeTask($id, $userId);
			
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
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function delete(int $id): JSONResponse {
		try {
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
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function overdue(?int $roomId = null): JSONResponse {
		try {
			$overdueTasks = $this->roomTaskService->getOverdueTasks($roomId);
			
			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn($task) => $task->jsonSerialize(), $overdueTasks)
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
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function dueToday(?int $roomId = null): JSONResponse {
		try {
			$dueTodayTasks = $this->roomTaskService->getDueTodayTasks($roomId);
			
			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn($task) => $task->jsonSerialize(), $dueTodayTasks)
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
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function dueWithinDays(int $days, ?int $roomId = null): JSONResponse {
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

			$dueTasks = $this->roomTaskService->getDueWithinDaysTasks($days, $roomId);
			
			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn($task) => $task->jsonSerialize(), $dueTasks)
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
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function userTasks(string $userId, bool $activeOnly = true): JSONResponse {
		try {
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
				'data' => array_map(fn($task) => $task->jsonSerialize(), $userTasks)
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
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function roomStats(int $roomId): JSONResponse {
		try {
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
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function userStats(string $userId): JSONResponse {
		try {
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
}
