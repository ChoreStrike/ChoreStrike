<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TaskService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * API controller for task-related endpoints
 */
class TaskController extends Controller {
	use ControllerLoggingTrait;

	private TaskService $taskService;
	private RoomTaskService $roomTaskService;
	private HouseService $houseService;
	private AuthorizationService $authService;

	public function __construct(
		IRequest $request,
		TaskService $taskService,
		RoomTaskService $roomTaskService,
		HouseService $houseService,
		AuthorizationService $authService,
		?AppLogger $appLogger = null,
	) {
		parent::__construct('chorestrike', $request);
		$this->taskService = $taskService;
		$this->roomTaskService = $roomTaskService;
		$this->houseService = $houseService;
		$this->authService = $authService;
		if ($appLogger !== null) {
			$this->initializeControllerLogger($appLogger);
		}
	}

	/**
	 * Get all tasks
	 *
	 * @NoAdminRequired
	 */
	public function index(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->ensureHouseMember($house->getId());

			$tasks = $this->taskService->getVisibleTasks($house->getId(), $houseUser);

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn ($task) => $task->jsonSerialize(), $tasks)
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('index', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'TASKS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get task by ID
	 *
	 * @NoAdminRequired
	 */
	public function show(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->ensureHouseMember($house->getId());

			$task = $this->taskService->getTask($id);

			$visibility = $task->getVisibilityType();
			if ($visibility === \OCA\ChoreStrike\Db\Task::VISIBILITY_PRIVATE
				&& $task->getOwnerUserId() !== $houseUser->getNextcloudUserId()) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'Task not found']
				], 404);
			}
			if ($visibility === \OCA\ChoreStrike\Db\Task::VISIBILITY_ADMIN_ONLY && !$houseUser->isOwner()) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'Task not found']
				], 404);
			}

			return new JSONResponse([
				'success' => true,
				'data' => $task->jsonSerialize()
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
			$this->logControllerException('show', $e, ['taskId' => $id]);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'TASK_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Create a new task
	 *
	 * @NoAdminRequired
	 */
	public function create(
		string $name,
		?string $description = null,
		int $energyCost = 1,
		int $priority = 2,
		string $frequencyType = 'weekly',
		int $frequencyValue = 1,
		int $estimatedMinutes = 15,
		string $category = 'cleaning',
		string $scopeType = 'room',
		?string $scopeLabel = null,
		string $recurrenceMode = 'recurring',
		?string $activeFrom = null,
		?string $activeUntil = null,
		bool $isVoluntary = false,
		string $schedulingMode = 'dynamic',
		?string $fixedScheduleTime = null,
		?string $fixedScheduleDays = null,
		bool $isPrivate = false,
		string $visibilityType = 'public',
	): JSONResponse {
		try {
			$activeFromDate = null;
			$activeUntilDate = null;

			if ($activeFrom !== null) {
				try {
					$activeFromDate = new \DateTime($activeFrom);
				} catch (\Exception $exception) {
					return new JSONResponse([
						'success' => false,
						'error' => [
							'code' => 'VALIDATION_ERROR',
							'message' => 'Invalid active from date'
						]
					], 400);
				}
			}

			if ($activeUntil !== null) {
				try {
					$activeUntilDate = new \DateTime($activeUntil);
				} catch (\Exception $exception) {
					return new JSONResponse([
						'success' => false,
						'error' => [
							'code' => 'VALIDATION_ERROR',
							'message' => 'Invalid active until date'
						]
					], 400);
				}
			}

			$fixedScheduleTimeDate = null;

			if ($fixedScheduleTime !== null) {
				try {
					$fixedScheduleTimeDate = new \DateTime($fixedScheduleTime);
				} catch (\Exception $exception) {
					return new JSONResponse([
						'success' => false,
						'error' => [
							'code' => 'VALIDATION_ERROR',
							'message' => 'Invalid fixed schedule time'
						]
					], 400);
				}
			}

			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->ensureHouseMember($house->getId());

			// Validate visibilityType
			if (!in_array($visibilityType, \OCA\ChoreStrike\Db\Task::VISIBILITY_TYPES, true)) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'Invalid visibility type'
					]
				], 400);
			}

			// Admin-only tasks require owner role
			if ($visibilityType === \OCA\ChoreStrike\Db\Task::VISIBILITY_ADMIN_ONLY && !$houseUser->isOwner()) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'FORBIDDEN',
						'message' => 'Only house owners can create admin-only tasks'
					]
				], 403);
			}

			// Private tasks track the creator as owner
			$ownerUserId = null;
			if ($visibilityType === \OCA\ChoreStrike\Db\Task::VISIBILITY_PRIVATE) {
				$ownerUserId = $houseUser->getNextcloudUserId();
			}

			// Backwards compat: isPrivate=true implies visibilityType=private
			if ($isPrivate && $visibilityType === 'public') {
				$visibilityType = \OCA\ChoreStrike\Db\Task::VISIBILITY_PRIVATE;
				$ownerUserId = $houseUser->getNextcloudUserId();
			}

			// Validate input
			$errors = $this->taskService->validateTaskData([
				'name' => $name,
				'energyCost' => $energyCost,
				'priority' => $priority,
				'frequencyType' => $frequencyType,
				'frequencyValue' => $frequencyValue,
				'estimatedMinutes' => $estimatedMinutes,
				'scopeType' => $scopeType,
				'recurrenceMode' => $recurrenceMode,
				'activeFrom' => $activeFrom,
				'activeUntil' => $activeUntil,
			]);

			if (!empty($errors)) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'Validation failed',
						'details' => $errors
					]
				], 400);
			}

			$task = $this->taskService->createTask(
				$name,
				$description,
				$energyCost,
				$priority,
				$frequencyType,
				$frequencyValue,
				$estimatedMinutes,
				$category,
				$scopeType,
				$scopeLabel,
				$recurrenceMode,
				$activeFromDate,
				$activeUntilDate,
				$isVoluntary,
				$schedulingMode,
				$fixedScheduleTimeDate,
				$fixedScheduleDays,
				null,
				null,
				null,
				null,
				$isPrivate,
				$visibilityType,
				$ownerUserId
			);

			return new JSONResponse([
				'success' => true,
				'data' => $task->jsonSerialize()
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
			$this->logControllerException('create', $e, ['taskName' => $name]);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'TASK_CREATE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Update task
	 *
	 * @NoAdminRequired
	 */
	public function update(int $id, array $updates): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->ensureHouseMember($house->getId());

			// Load existing task to guard visibility transitions
			$existingTask = $this->taskService->getTask($id);

			// Visibility access gate (mirrors show()/delete()): a non-owner
			// may not even touch a private task, and only an owner may touch
			// an admin-only task. Denied with 404 (anti-enumeration) — same
			// shape as show()/delete() so the endpoint cannot be used to
			// enumerate which task IDs exist.
			$existingVisibility = $existingTask->getVisibilityType();
			if ($existingVisibility === \OCA\ChoreStrike\Db\Task::VISIBILITY_PRIVATE
				&& $existingTask->getOwnerUserId() !== $houseUser->getNextcloudUserId()
			) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'Task not found']
				], 404);
			}
			if ($existingVisibility === \OCA\ChoreStrike\Db\Task::VISIBILITY_ADMIN_ONLY && !$houseUser->isOwner()) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'Task not found']
				], 404);
			}

			// Normalize the legacy `isPrivate` shim into the canonical
			// `visibilityType`. The frontend sends both; applying `isPrivate`
			// separately would (a) let setIsPrivate() clobber an admin-only
			// value — it only maps to public/private — and (b) bypass the
			// visibility-transition gate below, which keys on `visibilityType`.
			// `visibilityType` is therefore the sole source of truth.
			if (isset($updates['isPrivate']) && !isset($updates['visibilityType'])) {
				$updates['visibilityType'] = $updates['isPrivate']
					? \OCA\ChoreStrike\Db\Task::VISIBILITY_PRIVATE
					: \OCA\ChoreStrike\Db\Task::VISIBILITY_PUBLIC;
			}
			unset($updates['isPrivate']);

			// Admin-only visibility requires owner role (both setting and unsetting)
			if (isset($updates['visibilityType'])) {
				$newVisibility = $updates['visibilityType'];
				if ($newVisibility === \OCA\ChoreStrike\Db\Task::VISIBILITY_ADMIN_ONLY && !$houseUser->isOwner()) {
					return new JSONResponse([
						'success' => false,
						'error' => [
							'code' => 'FORBIDDEN',
							'message' => 'Only house owners can set admin-only visibility'
						]
					], 403);
				}
				if ($existingTask->getVisibilityType() === \OCA\ChoreStrike\Db\Task::VISIBILITY_ADMIN_ONLY
					&& $newVisibility !== \OCA\ChoreStrike\Db\Task::VISIBILITY_ADMIN_ONLY
					&& !$houseUser->isOwner()) {
					return new JSONResponse([
						'success' => false,
						'error' => [
							'code' => 'FORBIDDEN',
							'message' => 'Only house owners can change admin-only task visibility'
						]
					], 403);
				}
			}

			// Prevent non-owners from reassigning task ownership
			if (isset($updates['ownerUserId']) && !$houseUser->isOwner()) {
				unset($updates['ownerUserId']);
			}

			// Taking a task private claims ownership for the caller when no
			// owner is set. Otherwise a member could make a public task
			// private with a null owner, orphaning it — show()/delete() then
			// return 404 for every user (null !== any userId), so the task
			// would be invisible and undeletable through the API.
			$effectiveVisibility = $updates['visibilityType'] ?? $existingTask->getVisibilityType();
			if ($effectiveVisibility === \OCA\ChoreStrike\Db\Task::VISIBILITY_PRIVATE
				&& !isset($updates['ownerUserId'])
				&& $existingTask->getOwnerUserId() !== $houseUser->getNextcloudUserId()
			) {
				$updates['ownerUserId'] = $houseUser->getNextcloudUserId();
			}

			// Validate updates
			$errors = $this->taskService->validateTaskData($updates);
			if (!empty($errors)) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'Validation failed',
						'details' => $errors
					]
				], 400);
			}

			$task = $this->taskService->updateTask($id, $updates);

			return new JSONResponse([
				'success' => true,
				'data' => $task->jsonSerialize()
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
			$this->logControllerException('update', $e, ['taskId' => $id]);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'TASK_UPDATE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Delete task
	 *
	 * Enforces the task-visibility matrix before deletion (mirrors show()):
	 *   - private:    only the task owner may delete
	 *   - admin-only: only a house owner may delete
	 *   - public:     any house member may delete
	 * A denied delete returns 404 RESOURCE_NOT_FOUND (same as show) so the
	 * endpoint cannot be used to enumerate which tasks exist.
	 *
	 * @NoAdminRequired
	 */
	public function delete(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->ensureHouseMember($house->getId());

			$task = $this->taskService->getTask($id);

			$visibility = $task->getVisibilityType();
			if ($visibility === \OCA\ChoreStrike\Db\Task::VISIBILITY_PRIVATE
				&& $task->getOwnerUserId() !== $houseUser->getNextcloudUserId()) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'Task not found']
				], 404);
			}
			if ($visibility === \OCA\ChoreStrike\Db\Task::VISIBILITY_ADMIN_ONLY && !$houseUser->isOwner()) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'Task not found']
				], 404);
			}

			try {
				$this->taskService->deleteTask(
					$id,
					$houseUser->getNextcloudUserId(),
					$houseUser->isOwner()
				);
			} catch (ForbiddenException $e) {
				// Defense-in-depth visibility denial from the service must
				// preserve the 404 anti-enumeration contract (same as the
				// gates above and show()), never a 403 that confirms the
				// task exists. House-access ForbiddenExceptions are still
				// handled as 403 by the outer catch below.
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'Task not found']
				], 404);
			}

			return new JSONResponse([
				'success' => true,
				'message' => 'Task deleted successfully'
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
			$this->logControllerException('delete', $e, ['taskId' => $id]);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'TASK_DELETE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get tasks by category
	 *
	 * @NoAdminRequired
	 */
	public function byCategory(string $category): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());

			$tasks = $this->taskService->getTasksByCategory($category);

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn ($task) => $task->jsonSerialize(), $tasks)
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('byCategory', $e, ['category' => $category]);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'CATEGORY_TASKS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Search tasks by name
	 *
	 * @NoAdminRequired
	 */
	public function search(string $query): JSONResponse {
		try {
			if (empty(trim($query))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'Search query is required'
					]
				], 400);
			}

			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());

			$tasks = $this->taskService->searchTasks($query);

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn ($task) => $task->jsonSerialize(), $tasks)
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('search', $e, ['query' => $query]);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'SEARCH_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get task categories
	 *
	 * @NoAdminRequired
	 */
	public function categories(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());

			$categories = $this->taskService->getTaskCategories();

			return new JSONResponse([
				'success' => true,
				'data' => $categories
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('categories', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'CATEGORIES_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get today's scheduled tasks
	 *
	 * @NoAdminRequired
	 */
	public function daily(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());

			$today = new \DateTime();
			$dailyTasks = $this->roomTaskService->getDailyTasksWithDetails($house->getId(), $today);
			$taskPayload = [];

			foreach ($dailyTasks as $index => $dailyTask) {
				$taskPayload[] = $this->mapDailyTaskForApi(
					$dailyTask['roomTask'],
					$dailyTask['task'],
					$dailyTask['room'],
					$today,
					$index
				);
			}

			return new JSONResponse([
				'success' => true,
				'data' => $taskPayload
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('daily', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'DAILY_TASKS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get the current user's private tasks
	 *
	 * @NoAdminRequired
	 */
	public function myPrivate(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->ensureHouseMember($house->getId());

			$tasks = $this->taskService->getMyPrivateTasks($houseUser->getNextcloudUserId());

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn ($task) => $task->jsonSerialize(), $tasks)
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('myPrivate', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'MY_PRIVATE_TASKS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Complete a task
	 *
	 * @NoAdminRequired
	 */
	public function complete(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->ensureHouseMember($house->getId());
			$this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

			$details = $this->roomTaskService->getRoomTaskWithDetails($id);
			$task = $details['task'];

			if ($task->getVisibilityType() === \OCA\ChoreStrike\Db\Task::VISIBILITY_ADMIN_ONLY && !$houseUser->isOwner()) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'FORBIDDEN',
						'message' => 'Admin-only tasks may only be completed by house owners'
					]
				], 403);
			}

			if ($task->isPrivate()) {
				$assigneeUserIds = $this->roomTaskService->getAssigneeUserIds($id);
				$legacyAssignee = $details['roomTask']->getAssignedToUserId();
				if ($legacyAssignee && !in_array($legacyAssignee, $assigneeUserIds, true)) {
					$assigneeUserIds[] = $legacyAssignee;
				}
				if (!in_array($houseUser->getNextcloudUserId(), $assigneeUserIds, true)) {
					return new JSONResponse([
						'success' => false,
						'error' => [
							'code' => 'FORBIDDEN',
							'message' => 'Private tasks may only be completed by assigned users'
						]
					], 403);
				}
			}

			$roomTask = $this->roomTaskService->completeTaskSimple($id);

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
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('complete', $e, ['roomTaskId' => $id]);
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
	 * Uncomplete a task
	 *
	 * @NoAdminRequired
	 */
	public function uncomplete(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());
			$this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

			$task = $this->roomTaskService->uncompleteTask($id);

			return new JSONResponse([
				'success' => true,
				'data' => $task->jsonSerialize()
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
			$this->logControllerException('uncomplete', $e, ['roomTaskId' => $id]);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'UNCOMPLETE_TASK_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Skip a task
	 *
	 * @NoAdminRequired
	 */
	public function skip(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());
			$this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

			$task = $this->roomTaskService->skipTask($id);

			return new JSONResponse([
				'success' => true,
				'data' => $task->jsonSerialize()
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
			$this->logControllerException('skip', $e, ['roomTaskId' => $id]);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'SKIP_TASK_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Reschedule a task
	 *
	 * @NoAdminRequired
	 */
	public function reschedule(int $id, string $newDate): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->ensureHouseMember($house->getId());
			$this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

			try {
				$newDateTime = new \DateTime($newDate);
			} catch (\Exception $exception) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'Invalid reschedule date'
					]
				], 400);
			}

			$task = $this->roomTaskService->rescheduleTask($id, $newDateTime);

			return new JSONResponse([
				'success' => true,
				'data' => $task->jsonSerialize()
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
			$this->logControllerException('reschedule', $e, ['roomTaskId' => $id]);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'RESCHEDULE_TASK_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Map a daily room task to frontend API payload.
	 */
	private function mapDailyTaskForApi(RoomTask $roomTask, Task $task, Room $room, \DateTime $today, int $order): array {
		$lastCompleted = $roomTask->getLastCompleted();
		$isCompletedToday = $lastCompleted !== null && $lastCompleted->format('Y-m-d') === $today->format('Y-m-d');

		return [
			'id' => $roomTask->getId(),
			'name' => $task->getName(),
			'roomName' => $room->getName(),
			'energyCost' => $task->getEnergyCost(),
			'priority' => $task->getPriority(),
			'completed' => $isCompletedToday,
			'isOverdue' => $roomTask->isOverdue(),
			'allowTeamwork' => $task->getAllowTeamwork(),
			'order' => $order,
			'nextDue' => $roomTask->getNextDue()?->format('Y-m-d'),
			'assignedToUserId' => $roomTask->getAssignedToUserId(),
			'visibilityType' => $task->getVisibilityType(),
			'isPrivate' => $task->isPrivate(),
		];
	}
}
