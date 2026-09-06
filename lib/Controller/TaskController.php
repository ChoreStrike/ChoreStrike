<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Exception\ValidationException;
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
			$houseUser = $this->authService->assertHouseMember($house->getId());

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
	 * Update task
	 *
	 * Envelope shaping and owner-gate mapping only — value validation is
	 * service-owned (TaskService::updateTask → validateTaskData, CS-PLAN-063
	 * §3.1). The definition-only create endpoint was pruned by the WP4
	 * aggregate (POST /room-tasks).
	 *
	 * @NoAdminRequired
	 */
	public function update(int $id, array $updates): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			// Load existing task through the visibility policy (defense in
			// depth, CS-PLAN-061 §3.2): an invisible task is a 404 before any
			// update logic runs.
			$existingTask = $this->taskService->getVisibleTask($id, $houseUser);

			// The canonical `visibilityType` is the sole visibility source of
			// truth; any legacy `isPrivate` key still sent by a stale client
			// is dropped (CS-PLAN-060 WP1 D1).
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

			try {
				$task = $this->taskService->updateTask($id, $updates, $houseUser);
			} catch (ValidationException $e) {
				// Service-owned value validation (CS-PLAN-063 §3.1) keeps
				// the 400 envelope with the localized details[] list.
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $e->getMessage(),
						'details' => $e->getErrors(),
					]
				], 400);
			} catch (NotFoundException $e) {
				// Defense-in-depth visibility denial from the service
				// (CS-PLAN-061 §3.2) must preserve the 404 anti-enumeration
				// contract — never a 403 that confirms the task exists.
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
			$houseUser = $this->authService->assertHouseMember($house->getId());
			$this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

			$task = $this->roomTaskService->uncompleteTask($id, $houseUser);

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
