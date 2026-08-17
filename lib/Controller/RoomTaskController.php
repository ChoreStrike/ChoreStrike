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
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TemplateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * API controller for room task-related endpoints
 */
class RoomTaskController extends Controller {
	private RoomTaskService $roomTaskService;
	private HouseService $houseService;
	private AuthorizationService $authService;

	public function __construct(
		IRequest $request,
		RoomTaskService $roomTaskService,
		HouseService $houseService,
		AuthorizationService $authService,
	) {
		parent::__construct('chorestrike', $request);
		$this->roomTaskService = $roomTaskService;
		$this->houseService = $houseService;
		$this->authService = $authService;
	}

	/**
	 * Create a room task from a catalog template (per-room picker).
	 *
	 * @NoAdminRequired
	 */
	public function fromTemplate(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());

			$roomId = (int)$this->request->getParam('roomId');
			$templateId = (string)($this->request->getParam('templateId') ?? '');
			$level = (string)($this->request->getParam('level') ?? TemplateService::LEVEL_PUTZTEUFEL);
			$overrides = $this->request->getParam('overrides');
			$assigneeUserId = $this->request->getParam('assigneeUserId');

			if ($roomId <= 0) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'roomId is required'],
				], 400);
			}
			if (trim($templateId) === '') {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'templateId is required'],
				], 400);
			}

			// Resolve the room server-side and validate it belongs to the caller's house.
			$this->roomTaskService->validateRoomOwnership($roomId, $house->getId());

			$roomTask = $this->roomTaskService->createFromTemplate(
				$house->getId(),
				$roomId,
				$templateId,
				$level,
				is_array($overrides) ? $overrides : [],
				is_string($assigneeUserId) && trim($assigneeUserId) !== '' ? $assigneeUserId : null
			);

			return new JSONResponse([
				'success' => true,
				'data' => $roomTask->jsonSerialize(),
			]);
		} catch (NotFoundException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => ControllerErrorFormatter::safeMessage($e)],
			], 404);
		} catch (\InvalidArgumentException $e) {
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => ['code' => 'FROM_TEMPLATE_ERROR', 'message' => ControllerErrorFormatter::safeMessage($e)],
			], 500);
		}
	}

	/**
	 * Get room tasks for a room
	 *
	 * @NoAdminRequired
	 */
	public function index(?int $roomId = null, ?int $scopeId = null, bool $activeOnly = true): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());

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

			$assigneesByTask = $this->roomTaskService->getAssigneeUserIdsByRoomTasks($roomTasks);

			return new JSONResponse([
				'success' => true,
				'data' => array_map(function ($roomTask) use ($assigneesByTask) {
					$data = $roomTask->jsonSerialize();
					$data['assignees'] = $assigneesByTask[$roomTask->getId()] ?? [];
					return $data;
				}, $roomTasks)
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ROOM_TASKS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get room task by ID
	 *
	 * @NoAdminRequired
	 */
	public function show(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());
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
					'assignees' => $this->roomTaskService->getAssigneeUserIds($id),
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
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ROOM_TASK_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
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
		?string $assignedToUserId = null,
	): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());

			$nextDueDate = null;
			if ($nextDue !== null) {
				try {
					$nextDueDate = new \DateTime($nextDue);
				} catch (\Exception $e) {
					throw new \InvalidArgumentException('Invalid nextDue date format');
				}
			}

			$roomTask = $this->roomTaskService->createRoomTask($house->getId(), $roomId, $taskId, $nextDueDate, $scopeId, $assignedToUserId);

			return new JSONResponse([
				'success' => true,
				'data' => $roomTask->jsonSerialize()
			]);
		} catch (NotFoundException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'RESOURCE_NOT_FOUND',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 404);
		} catch (\InvalidArgumentException $e) {
			// Service-curated messages (non-member assignee, invalid nextDue,
			// duplicate task) — safe to surface verbatim; safeMessage() would
			// replace them with the generic fallback.
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ROOM_TASK_CREATE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Assign room task to user
	 *
	 * @NoAdminRequired
	 */
	public function assign(int $id, string $userId): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());
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

			// The target user must belong to the caller's house; otherwise a
			// member could assign room tasks to arbitrary Nextcloud users
			// (cross-house BOLA). Mirrors userTasks()/userStats(). The
			// service re-validates internally (CR-SVC-09) — this preamble
			// stays as defense in depth.
			$this->authService->assertUserInHouse($house->getId(), $userId);

			$roomTask = $this->roomTaskService->assignTask($id, $userId, $house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => $roomTask->jsonSerialize()
			]);
		} catch (\InvalidArgumentException $e) {
			// Service-curated membership message (CR-SVC-09) — safe to
			// surface verbatim.
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ASSIGN_TASK_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Unassign room task from user
	 *
	 * @NoAdminRequired
	 */
	public function unassign(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());
			$this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

			$roomTask = $this->roomTaskService->unassignTask($id);

			return new JSONResponse([
				'success' => true,
				'data' => $roomTask->jsonSerialize()
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'UNASSIGN_TASK_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get the assignees (Nextcloud user IDs) for a room task
	 *
	 * @NoAdminRequired
	 */
	public function assignees(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());
			$this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => $this->roomTaskService->getAssigneeUserIds($id)
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ASSIGNEES_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Replace the full set of assignees for a room task (multi-assign)
	 *
	 * @NoAdminRequired
	 * @param int $id The room task ID
	 * @param string[] $userIds Nextcloud user IDs to assign
	 */
	public function setAssignees(int $id, array $userIds): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());
			$this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

			$assignedBy = $this->authService->requireCurrentUserId();
			$assignees = $this->roomTaskService->setAssignees($id, $userIds, $house->getId(), $assignedBy);

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn ($a) => $a->jsonSerialize(), $assignees)
			]);
		} catch (\InvalidArgumentException $e) {
			// Service-curated membership message — safe to surface verbatim.
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'SET_ASSIGNEES_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Add a single assignee to a room task
	 *
	 * @NoAdminRequired
	 * @param int $id The room task ID
	 * @param string $userId The Nextcloud user ID to assign
	 */
	public function addAssignee(int $id, string $userId): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());
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

			$assignedBy = $this->authService->requireCurrentUserId();
			$assignee = $this->roomTaskService->addAssignee($id, $userId, $house->getId(), $assignedBy);

			return new JSONResponse([
				'success' => true,
				'data' => $assignee->jsonSerialize()
			]);
		} catch (\InvalidArgumentException $e) {
			// Service-curated messages (non-member, duplicate assignee) —
			// safe to surface verbatim.
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ADD_ASSIGNEE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Remove an assignee from a room task
	 *
	 * @NoAdminRequired
	 * @param int $id The room task ID
	 * @param string $userId The Nextcloud user ID to remove
	 */
	public function removeAssignee(int $id, string $userId): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());
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

			$this->roomTaskService->removeAssignee($id, $userId, $house->getId());

			return new JSONResponse([
				'success' => true,
				'message' => 'Assignee removed successfully'
			]);
		} catch (\InvalidArgumentException $e) {
			// Service-curated message (non-member) — safe to surface verbatim.
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'REMOVE_ASSIGNEE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Deactivate room task
	 *
	 * @NoAdminRequired
	 */
	public function deactivate(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());
			$this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

			$roomTask = $this->roomTaskService->deactivateTask($id);

			return new JSONResponse([
				'success' => true,
				'data' => $roomTask->jsonSerialize()
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'DEACTIVATE_TASK_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Reactivate room task
	 *
	 * @NoAdminRequired
	 */
	public function reactivate(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());
			$this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

			$roomTask = $this->roomTaskService->reactivateTask($id);

			return new JSONResponse([
				'success' => true,
				'data' => $roomTask->jsonSerialize()
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'REACTIVATE_TASK_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Update room task due date
	 *
	 * @NoAdminRequired
	 */
	public function updateDueDate(int $id, string $nextDue): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());
			$this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

			try {
				$nextDueDate = new \DateTime($nextDue);
			} catch (\Exception $e) {
				throw new \InvalidArgumentException('Invalid nextDue date format');
			}

			$roomTask = $this->roomTaskService->updateDueDate($id, $nextDueDate);

			return new JSONResponse([
				'success' => true,
				'data' => $roomTask->jsonSerialize()
			]);
		} catch (\InvalidArgumentException $e) {
			// Curated date-format message — safe to surface verbatim.
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'UPDATE_DUE_DATE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Complete room task
	 *
	 * @NoAdminRequired
	 */
	public function complete(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());
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
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'COMPLETE_TASK_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Delete room task
	 *
	 * @NoAdminRequired
	 */
	public function delete(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());
			$this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

			$this->roomTaskService->deleteRoomTask($id);

			return new JSONResponse([
				'success' => true,
				'message' => 'Room task deleted successfully'
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ROOM_TASK_DELETE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get overdue tasks
	 *
	 * @NoAdminRequired
	 */
	public function overdue(?int $roomId = null): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());

			$overdueTasks = $this->roomTaskService->getOverdueTasks($house->getId(), $roomId);

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn ($task) => $task->jsonSerialize(), $overdueTasks)
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'OVERDUE_TASKS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get tasks due today
	 *
	 * @NoAdminRequired
	 */
	public function dueToday(?int $roomId = null): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());

			$dueTodayTasks = $this->roomTaskService->getDueTodayTasks($house->getId(), $roomId);

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn ($task) => $task->jsonSerialize(), $dueTodayTasks)
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'DUE_TODAY_TASKS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get tasks due within days
	 *
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

			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());

			$dueTasks = $this->roomTaskService->getDueWithinDaysTasks($house->getId(), $days, $roomId);

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn ($task) => $task->jsonSerialize(), $dueTasks)
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'DUE_WITHIN_DAYS_TASKS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get scheduled and completed tasks within a date range for the calendar view.
	 *
	 * @NoAdminRequired
	 *
	 * @param string $from Start date (Y-m-d)
	 * @param string $to End date (Y-m-d)
	 */
	public function range(string $from, string $to): JSONResponse {
		try {
			$fromDate = \DateTime::createFromFormat('Y-m-d', $from);
			$toDate = \DateTime::createFromFormat('Y-m-d', $to);

			if ($fromDate === false || $toDate === false) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'from and to must be valid Y-m-d dates'
					]
				], 400);
			}

			$fromDate->setTime(0, 0, 0);
			$toDate->setTime(23, 59, 59);

			if ($fromDate > $toDate) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'from must not be after to'
					]
				], 400);
			}

			$spanDays = $fromDate->diff($toDate)->days;
			if ($spanDays > 60) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'Range must not exceed 60 days'
					]
				], 400);
			}

			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());

			$data = $this->roomTaskService->getRangeTasks($house->getId(), $fromDate, $toDate);

			return new JSONResponse([
				'success' => true,
				'data' => $data
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'RANGE_TASKS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get user tasks
	 *
	 * @NoAdminRequired
	 */
	public function userTasks(string $userId, bool $activeOnly = true): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());

			if (empty(trim($userId))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'User ID is required'
					]
				], 400);
			}

			// Prevent cross-house access: the target user must be a member of
			// the caller's house before returning their tasks.
			$this->authService->assertUserInHouse($house->getId(), $userId);

			$userTasks = $this->roomTaskService->getUserTasks($userId, $activeOnly);

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn ($task) => $task->jsonSerialize(), $userTasks)
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'USER_TASKS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get room task statistics
	 *
	 * @NoAdminRequired
	 */
	public function roomStats(int $roomId): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());

			$stats = $this->roomTaskService->getRoomTaskStats($roomId);

			return new JSONResponse([
				'success' => true,
				'data' => $stats
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ROOM_STATS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get user task statistics
	 *
	 * @NoAdminRequired
	 */
	public function userStats(string $userId): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());

			if (empty(trim($userId))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'User ID is required'
					]
				], 400);
			}

			// Prevent cross-house access: the target user must be a member of
			// the caller's house before returning their stats.
			$this->authService->assertUserInHouse($house->getId(), $userId);

			$stats = $this->roomTaskService->getUserTaskStats($userId);

			return new JSONResponse([
				'success' => true,
				'data' => $stats
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'USER_STATS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get overdue tasks that can be claimed
	 *
	 * @NoAdminRequired
	 */
	public function overdueClaims(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());

			$tasks = $this->roomTaskService->getClaimableOverdueTasks($house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn ($task) => $task->jsonSerialize(), $tasks)
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'OVERDUE_CLAIMS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get filtered daily tasks for the current user based on role and preferences.
	 *
	 * Child users receive a filtered view based on:
	 * - weekdayAvailability: Only tasks on available days
	 * - maxDailyTasks: Limited number of tasks
	 *
	 * @NoAdminRequired
	 */
	public function filteredDaily(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());

			$currentUserId = $this->authService->requireCurrentUserId();
			$houseUserMapper = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class);

			$filteredTasks = $this->roomTaskService->getFilteredDailyTasksForUser(
				$house->getId(),
				$currentUserId,
				new \DateTime(),
				$houseUserMapper
			);

			return new JSONResponse([
				'success' => true,
				'data' => array_map(function ($item) {
					return [
						'roomTask' => $item['roomTask']->jsonSerialize(),
						'task' => $item['task']->jsonSerialize(),
						'room' => $item['room']->jsonSerialize(),
					];
				}, $filteredTasks)
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'FILTERED_DAILY_TASKS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}
}
