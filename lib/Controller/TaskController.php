<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\TaskService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * API controller for task-related endpoints
 */
class TaskController extends OCSController {

	private TaskService $taskService;
	private RoomTaskService $roomTaskService;
	private HouseService $houseService;
	private AuthorizationService $authService;

	public function __construct(
		IRequest $request,
		TaskService $taskService,
		RoomTaskService $roomTaskService,
		HouseService $houseService,
		AuthorizationService $authService
	) {
		parent::__construct('chorestrike', $request);
		$this->taskService = $taskService;
		$this->roomTaskService = $roomTaskService;
		$this->houseService = $houseService;
		$this->authService = $authService;
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
			$this->authService->requireHouseMember($house->getId());
			
			$tasks = $this->taskService->getAllTasks();
			
			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn($task) => $task->jsonSerialize(), $tasks)
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'TASKS_ERROR',
					'message' => $e->getMessage()
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
			$this->authService->requireHouseMember($house->getId());
			
			$task = $this->taskService->getTask($id);
			
			return new JSONResponse([
				'success' => true,
				'data' => $task->jsonSerialize()
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'TASK_ERROR',
					'message' => $e->getMessage()
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
		bool $isTemplate = false,
		string $schedulingMode = 'dynamic',
		?string $fixedScheduleTime = null,
		?string $fixedScheduleDays = null
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
			$this->authService->requireHouseMember($house->getId());
			
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
				$isTemplate,
				$schedulingMode,
				$fixedScheduleTimeDate,
				$fixedScheduleDays
			);
			
			return new JSONResponse([
				'success' => true,
				'data' => $task->jsonSerialize()
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'TASK_CREATE_ERROR',
					'message' => $e->getMessage()
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
			$this->authService->requireHouseMember($house->getId());
			
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
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'TASK_UPDATE_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Delete task
	 *
	 * @NoAdminRequired
	 */
	public function delete(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());
			
			$this->taskService->deleteTask($id);
			
			return new JSONResponse([
				'success' => true,
				'message' => 'Task deleted successfully'
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'TASK_DELETE_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Get task templates
	 *
	 * @NoAdminRequired
	 */
	public function templates(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());
			
			$templates = $this->taskService->getTaskTemplates();
			
			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn($template) => $template->jsonSerialize(), $templates)
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'TEMPLATES_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Create task from template
	 *
	 * @NoAdminRequired
	 */
	public function createFromTemplate(int $templateId): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());
			
			$task = $this->taskService->createFromTemplate($templateId);
			
			return new JSONResponse([
				'success' => true,
				'data' => $task->jsonSerialize()
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'CREATE_FROM_TEMPLATE_ERROR',
					'message' => $e->getMessage()
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
			$this->authService->requireHouseMember($house->getId());
			
			$tasks = $this->taskService->getTasksByCategory($category);
			
			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn($task) => $task->jsonSerialize(), $tasks)
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'CATEGORY_TASKS_ERROR',
					'message' => $e->getMessage()
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
			$this->authService->requireHouseMember($house->getId());

			$tasks = $this->taskService->searchTasks($query);
			
			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn($task) => $task->jsonSerialize(), $tasks)
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'SEARCH_ERROR',
					'message' => $e->getMessage()
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
			$this->authService->requireHouseMember($house->getId());
			
			$categories = $this->taskService->getTaskCategories();
			
			return new JSONResponse([
				'success' => true,
				'data' => $categories
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'CATEGORIES_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Create default task templates
	 *
	 * @NoAdminRequired
	 */
	public function createDefaultTemplates(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());
			
			$templates = $this->taskService->createDefaultTemplates();
			
			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn($template) => $template->jsonSerialize(), $templates),
				'message' => 'Default task templates created successfully'
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'CREATE_DEFAULT_TEMPLATES_ERROR',
					'message' => $e->getMessage()
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
			$this->authService->requireHouseMember($house->getId());
			
			$today = new \DateTime();
			$dailyTasks = $this->roomTaskService->getDailyTasks($house->getId(), $today);
			
			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn($task) => $task->jsonSerialize(), $dailyTasks)
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'DAILY_TASKS_ERROR',
					'message' => $e->getMessage()
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
			$this->authService->requireHouseMember($house->getId());
			
			$task = $this->roomTaskService->completeTaskSimple($id);
			
			return new JSONResponse([
				'success' => true,
				'data' => $task->jsonSerialize()
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
	 * Uncomplete a task
	 *
	 * @NoAdminRequired
	 */
	public function uncomplete(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());
			
			$task = $this->roomTaskService->uncompleteTask($id);
			
			return new JSONResponse([
				'success' => true,
				'data' => $task->jsonSerialize()
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'UNCOMPLETE_TASK_ERROR',
					'message' => $e->getMessage()
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
			$this->authService->requireHouseMember($house->getId());
			
			$task = $this->roomTaskService->skipTask($id);
			
			return new JSONResponse([
				'success' => true,
				'data' => $task->jsonSerialize()
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'SKIP_TASK_ERROR',
					'message' => $e->getMessage()
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
			$this->authService->requireHouseMember($house->getId());
			
			$newDateTime = new \DateTime($newDate);
			$task = $this->roomTaskService->rescheduleTask($id, $newDateTime);
			
			return new JSONResponse([
				'success' => true,
				'data' => $task->jsonSerialize()
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'RESCHEDULE_TASK_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}
}
