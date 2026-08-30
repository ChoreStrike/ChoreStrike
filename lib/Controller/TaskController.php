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
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TaskService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
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
	private IL10N $l;

	public function __construct(
		IRequest $request,
		TaskService $taskService,
		RoomTaskService $roomTaskService,
		HouseService $houseService,
		AuthorizationService $authService,
		IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->taskService = $taskService;
		$this->roomTaskService = $roomTaskService;
		$this->houseService = $houseService;
		$this->authService = $authService;
		$this->l = $l;
		$this->initializeControllerLogger($appLogger);
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
							'message' => $this->l->t('Invalid active from date')
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
							'message' => $this->l->t('Invalid active until date')
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
							'message' => $this->l->t('Invalid fixed schedule time')
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
						'message' => $this->l->t('Invalid visibility type')
					]
				], 400);
			}

			// Admin-only tasks require owner role
			if ($visibilityType === \OCA\ChoreStrike\Db\Task::VISIBILITY_ADMIN_ONLY && !$houseUser->isOwner()) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'FORBIDDEN',
						'message' => $this->l->t('Only house owners can create admin-only tasks')
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
						'message' => $this->l->t('Validation failed'),
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

			// Visibility access gate: a non-owner may not even touch a
			// private task, and only an owner may touch an admin-only task.
			// Denied with 404 (anti-enumeration) so the endpoint cannot be
			// used to enumerate which task IDs exist.
			$existingVisibility = $existingTask->getVisibilityType();
			if ($existingVisibility === \OCA\ChoreStrike\Db\Task::VISIBILITY_PRIVATE
				&& $existingTask->getOwnerUserId() !== $houseUser->getNextcloudUserId()
			) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => $this->l->t('Task not found')]
				], 404);
			}
			if ($existingVisibility === \OCA\ChoreStrike\Db\Task::VISIBILITY_ADMIN_ONLY && !$houseUser->isOwner()) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => $this->l->t('Task not found')]
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
							'message' => $this->l->t('Only house owners can set admin-only visibility')
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
							'message' => $this->l->t('Only house owners can change admin-only task visibility')
						]
					], 403);
				}
			}

			// Prevent non-owners from reassigning task ownership
			if (isset($updates['ownerUserId']) && !$houseUser->isOwner()) {
				unset($updates['ownerUserId']);
			}

			// CR-053-04: taskTier is owner-only (coin multiplier). Unlike
			// visibility denials (404 anti-enumeration), this is a plain
			// role denial → 403, checked here so it does not flow through
			// the inner ForbiddenException→404 catch below; the service
			// re-checks as defense-in-depth for direct callers. A value
			// outside the closed set is a plain 400.
			if (array_key_exists('taskTier', $updates)) {
				if (!$houseUser->isOwner()) {
					return new JSONResponse([
						'success' => false,
						'error' => [
							'code' => 'FORBIDDEN',
							'message' => $this->l->t('Only house owners may change the task tier')
						]
					], 403);
				}
				if (!in_array($updates['taskTier'], \OCA\ChoreStrike\Service\TaskService::TASK_TIERS, true)) {
					return new JSONResponse([
						'success' => false,
						'error' => [
							'code' => 'VALIDATION_ERROR',
							'message' => $this->l->t('Invalid task tier')
						]
					], 400);
				}
			}

			// Taking a task private claims ownership for the caller when no
			// owner is set. Otherwise a member could make a public task
			// private with a null owner, orphaning it — reads then return
			// 404 for every user (null !== any userId), so the task would be
			// invisible and un-updatable through the API.
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
						'message' => $this->l->t('Validation failed'),
						'details' => $errors
					]
				], 400);
			}

			try {
				$task = $this->taskService->updateTask(
					$id,
					$updates,
					$houseUser->getNextcloudUserId(),
					$houseUser->isOwner()
				);
			} catch (ForbiddenException $e) {
				// Defense-in-depth visibility denial from the service
				// (CR-SVC-17) must preserve the 404 anti-enumeration
				// contract (same as the gates above),
				// never a 403 that confirms the task exists. House-access
				// ForbiddenExceptions are still handled as 403 by the outer
				// catch below.
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => $this->l->t('Task not found')]
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
	 * Uncomplete a task
	 *
	 * @NoAdminRequired
	 */
	public function uncomplete(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->ensureHouseMember($house->getId());
			$this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

			$task = $this->roomTaskService->uncompleteTask($id, $houseUser, $houseUser->getNextcloudUserId());

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

}
