<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Service for task-related business logic
 */
class TaskService {

	private TaskMapper $taskMapper;

	public function __construct(TaskMapper $taskMapper) {
		$this->taskMapper = $taskMapper;
	}

	/**
	 * Get all tasks
	 *
	 * @return Task[]
	 */
	public function getAllTasks(): array {
		return $this->taskMapper->findAll();
	}

	/**
	 * Get task by ID
	 *
	 * @param int $taskId The task ID
	 * @throws DoesNotExistException if not found
	 */
	public function getTask(int $taskId): Task {
		return $this->taskMapper->find($taskId);
	}

	/**
	 * Create a new task
	 *
	 * @param string $name The task name
	 * @param string|null $description The task description
	 * @param int $energyCost The energy cost (1-5)
	 * @param int $priority The priority (1-3)
	 * @param string $frequencyType The frequency type
	 * @param int $frequencyValue The frequency value
	 * @param int $estimatedMinutes Estimated time in minutes
	 * @param string $category The task category
	 * @param string $scopeType The scope type (room, floor, house)
	 * @param string|null $scopeLabel Optional scope label
	 * @param string $recurrenceMode Recurrence mode (recurring, once, bounded)
	 * @param \DateTime|null $activeFrom Optional start date
	 * @param \DateTime|null $activeUntil Optional end date
	 * @param bool $isVoluntary Whether task is voluntary
	 * @param bool $isTemplate Whether this is a template
	 * @param string $schedulingMode Scheduling mode (dynamic, fixed)
	 * @param \DateTime|null $fixedScheduleTime Fixed schedule time
	 * @param string|null $fixedScheduleDays Fixed schedule days
	 */
	public function createTask(
		string $name,
		?string $description,
		int $energyCost,
		int $priority,
		string $frequencyType,
		int $frequencyValue,
		int $estimatedMinutes,
		string $category,
		string $scopeType = 'room',
		?string $scopeLabel = null,
		string $recurrenceMode = 'recurring',
		?\DateTime $activeFrom = null,
		?\DateTime $activeUntil = null,
		bool $isVoluntary = false,
		bool $isTemplate = false,
		string $schedulingMode = 'dynamic',
		?\DateTime $fixedScheduleTime = null,
		?string $fixedScheduleDays = null,
		?string $instructions = null,
		?string $tips = null,
		?int $estimatedMinutesMin = null,
		?int $estimatedMinutesMax = null
	): Task {
		return $this->taskMapper->create(
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
			$activeFrom,
			$activeUntil,
			$isVoluntary,
			$isTemplate,
			$schedulingMode,
			$fixedScheduleTime,
			$fixedScheduleDays,
			$instructions,
			$tips,
			$estimatedMinutesMin,
			$estimatedMinutesMax
		);
	}

	/**
	 * Update task
	 *
	 * @param int $taskId The task ID
	 * @param array $updates The updates to apply
	 */
	public function updateTask(int $taskId, array $updates): Task {
		$task = $this->taskMapper->find($taskId);

		$dateFields = ['activeFrom', 'activeUntil'];

		foreach ($updates as $field => $value) {
			$method = 'set' . ucfirst($field);
			if (method_exists($task, $method)) {
				if (in_array($field, $dateFields) && is_string($value)) {
					$value = new \DateTime($value);
				}
				$task->$method($value);
			}
		}

		return $this->taskMapper->update($task);
	}

	/**
	 * Delete task
	 *
	 * @param int $taskId The task ID
	 */
	public function deleteTask(int $taskId): void {
		$task = $this->taskMapper->find($taskId);
		$this->taskMapper->delete($task);
	}

	/**
	 * Get task templates
	 *
	 * @return Task[]
	 */
	public function getTaskTemplates(): array {
		return $this->taskMapper->findTemplates();
	}

	/**
	 * Create task from template
	 *
	 * @param int $templateId The template ID
	 */
	public function createFromTemplate(int $templateId): Task {
		return $this->taskMapper->createFromTemplate($templateId);
	}

	/**
	 * Get tasks by category
	 *
	 * @param string $category The task category
	 * @return Task[]
	 */
	public function getTasksByCategory(string $category): array {
		return $this->taskMapper->findByCategory($category);
	}

	/**
	 * Search tasks by name
	 *
	 * @param string $query The search query
	 * @return Task[]
	 */
	public function searchTasks(string $query): array {
		return $this->taskMapper->searchByName($query);
	}

	/**
	 * Get all task categories
	 *
	 * @return string[]
	 */
	public function getTaskCategories(): array {
		return $this->taskMapper->getCategories();
	}

	/**
	 * Create task template
	 *
	 * @param string $name The template name
	 * @param string|null $description The template description
	 * @param int $energyCost The energy cost (1-5)
	 * @param int $priority The priority (1-3)
	 * @param string $frequencyType The frequency type
	 * @param int $frequencyValue The frequency value
	 * @param int $estimatedMinutes Estimated time in minutes
	 * @param string $category The task category
	 */
	public function createTaskTemplate(
		string $name,
		?string $description,
		int $energyCost,
		int $priority,
		string $frequencyType,
		int $frequencyValue,
		int $estimatedMinutes,
		string $category
	): Task {
		return $this->createTask(
			$name,
			$description,
			$energyCost,
			$priority,
			$frequencyType,
			$frequencyValue,
			$estimatedMinutes,
			$category,
			'room',
			null,
			'recurring',
			null,
			null,
			false,
			true // isTemplate = true
		);
	}

	/**
	 * Validate task data
	 *
	 * @param array $taskData The task data to validate
	 */
	public function validateTaskData(array $taskData): array {
		$errors = [];

		// Required fields
		if (empty($taskData['name'])) {
			$errors[] = 'Task name is required';
		}

		// Energy cost validation
		if (isset($taskData['energyCost'])) {
			if ($taskData['energyCost'] < 1 || $taskData['energyCost'] > 5) {
				$errors[] = 'Energy cost must be between 1 and 5';
			}
		}

		// Priority validation
		if (isset($taskData['priority'])) {
			if ($taskData['priority'] < 1 || $taskData['priority'] > 3) {
				$errors[] = 'Priority must be between 1 and 3';
			}
		}

		// Estimated time validation
		if (isset($taskData['estimatedMinutes'])) {
			if ($taskData['estimatedMinutes'] < 1 || $taskData['estimatedMinutes'] > 480) {
				$errors[] = 'Estimated time must be between 1 and 480 minutes';
			}
		}

		// Frequency type validation
		if (isset($taskData['frequencyType'])) {
			$validTypes = ['daily', 'weekly', 'monthly', 'custom'];
			if (!in_array($taskData['frequencyType'], $validTypes)) {
				$errors[] = 'Invalid frequency type';
			}
		}

		// Frequency value validation
		if (isset($taskData['frequencyValue'])) {
			if ($taskData['frequencyValue'] < 1) {
				$errors[] = 'Frequency value must be at least 1';
			}
		}

		// Scope type validation
		if (isset($taskData['scopeType'])) {
			$validScopeTypes = ['room', 'floor', 'house'];
			if (!in_array($taskData['scopeType'], $validScopeTypes)) {
				$errors[] = 'Invalid scope type';
			}
		}

		// Recurrence mode validation
		if (isset($taskData['recurrenceMode'])) {
			$validModes = ['recurring', 'once', 'bounded'];
			if (!in_array($taskData['recurrenceMode'], $validModes)) {
				$errors[] = 'Invalid recurrence mode';
			}
		}

		// Date range validation
		if (isset($taskData['activeFrom']) && isset($taskData['activeUntil'])) {
			$from = new \DateTime($taskData['activeFrom']);
			$until = new \DateTime($taskData['activeUntil']);
			if ($until < $from) {
				$errors[] = 'Active until must be equal or after active from';
			}
		}

		return $errors;
	}

	/**
	 * Get default task templates
	 *
	 * Creates common household task templates if none exist
	 */
	public function createDefaultTemplates(): array {
		$templates = [
			// Cleaning tasks
			['name' => 'Vacuum floor', 'description' => 'Vacuum all floors in the room', 'energyCost' => 2, 'priority' => 2, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 20, 'category' => 'cleaning'],
			['name' => 'Dust surfaces', 'description' => 'Dust all surfaces including shelves and furniture', 'energyCost' => 1, 'priority' => 2, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 15, 'category' => 'cleaning'],
			['name' => 'Clean windows', 'description' => 'Clean inside and outside of windows', 'energyCost' => 3, 'priority' => 1, 'frequencyType' => 'monthly', 'frequencyValue' => 1, 'estimatedMinutes' => 30, 'category' => 'cleaning'],
			['name' => 'Mop floor', 'description' => 'Mop hard floors', 'energyCost' => 2, 'priority' => 2, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 25, 'category' => 'cleaning'],
			
			// Kitchen tasks
			['name' => 'Clean countertops', 'description' => 'Wipe down and disinfect kitchen countertops', 'energyCost' => 1, 'priority' => 3, 'frequencyType' => 'daily', 'frequencyValue' => 1, 'estimatedMinutes' => 10, 'category' => 'kitchen'],
			['name' => 'Clean microwave', 'description' => 'Clean inside and outside of microwave', 'energyCost' => 2, 'priority' => 2, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 15, 'category' => 'kitchen'],
			['name' => 'Clean oven', 'description' => 'Deep clean oven and stovetop', 'energyCost' => 4, 'priority' => 1, 'frequencyType' => 'monthly', 'frequencyValue' => 1, 'estimatedMinutes' => 60, 'category' => 'kitchen'],
			
			// Bathroom tasks
			['name' => 'Clean toilet', 'description' => 'Clean and disinfect toilet', 'energyCost' => 2, 'priority' => 3, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 15, 'category' => 'bathroom'],
			['name' => 'Clean shower/bath', 'description' => 'Clean shower walls, tub, and fixtures', 'energyCost' => 3, 'priority' => 2, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 30, 'category' => 'bathroom'],
			['name' => 'Clean mirror', 'description' => 'Clean bathroom mirror and fixtures', 'energyCost' => 1, 'priority' => 2, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 10, 'category' => 'bathroom'],
			
			// Organizing tasks
			['name' => 'Organize closet', 'description' => 'Organize clothes and accessories', 'energyCost' => 3, 'priority' => 1, 'frequencyType' => 'monthly', 'frequencyValue' => 1, 'estimatedMinutes' => 45, 'category' => 'organizing'],
			['name' => 'Sort mail', 'description' => 'Sort and organize mail and papers', 'energyCost' => 1, 'priority' => 2, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 15, 'category' => 'organizing'],
			
			// Maintenance tasks
			['name' => 'Replace air filter', 'description' => 'Replace HVAC air filter', 'energyCost' => 1, 'priority' => 2, 'frequencyType' => 'monthly', 'frequencyValue' => 3, 'estimatedMinutes' => 10, 'category' => 'maintenance'],
			['name' => 'Test smoke detectors', 'description' => 'Test and replace batteries in smoke detectors', 'energyCost' => 1, 'priority' => 3, 'frequencyType' => 'monthly', 'frequencyValue' => 1, 'estimatedMinutes' => 15, 'category' => 'maintenance'],
			['name' => 'Take out trash', 'description' => 'Empty trash bins and take out to collection point', 'energyCost' => 1, 'priority' => 3, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 10, 'category' => 'maintenance'],
		];

		$createdTemplates = [];
		foreach ($templates as $templateData) {
			$createdTemplates[] = $this->createTaskTemplate(
				$templateData['name'],
				$templateData['description'],
				$templateData['energyCost'],
				$templateData['priority'],
				$templateData['frequencyType'],
				$templateData['frequencyValue'],
				$templateData['estimatedMinutes'],
				$templateData['category']
			);
		}

		return $createdTemplates;
	}
}
