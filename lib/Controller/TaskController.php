<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
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
	private HouseService $houseService;
	private AuthorizationService $authService;

	public function __construct(
		IRequest $request,
		TaskService $taskService,
		HouseService $houseService,
		AuthorizationService $authService,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->taskService = $taskService;
		$this->houseService = $houseService;
		$this->authService = $authService;
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

	// `update` (PUT /tasks/{id}) was pruned 2026-11-18 (CS-PLAN-091 WP-B6):
	// the one form saves the definition with its room task through the edit
	// aggregate PUT /room-tasks/{id} (RoomTaskEditController).

	// `uncomplete` was pruned 2026-09-06 (CS-PLAN-064, decision D5): the
	// one idempotent undo operation is POST /room-tasks/{id}/undo.
}
