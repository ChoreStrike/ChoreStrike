<?php

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
	 * @param string $scopeType The task scope type
	 * @param string|null $scopeLabel Optional custom scope label
	 * @param string $recurrenceMode The recurrence mode
	 * @param \DateTime|null $activeFrom Optional activation start
	 * @param \DateTime|null $activeUntil Optional activation end
	 * @param bool $isVoluntary Whether the task is voluntary
	 * @param bool $isTemplate Whether this is a template
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
		bool $isTemplate = false
	): Task {
		$task = $this->taskMapper->create(
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
			$isTemplate
		);

		return $task;
	}

	/**
	 * Update task
	 *
	 * @param int $taskId The task ID
	 * @param array $updates The updates to apply
	 */
	public function updateTask(int $taskId, array $updates): Task {
		$task = $this->taskMapper->find($taskId);

		foreach ($updates as $field => $value) {
			if (in_array($field, ['activeFrom', 'activeUntil'], true)) {
				if ($value === null || $value === '') {
					$value = null;
				} else {
					$value = new \DateTime((string)$value);
				}
			}

			$method = 'set' . ucfirst($field);
			if (method_exists($task, $method)) {
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
	 * Create a task template
	 *
	 * @param string $name Template name
	 * @param string $description Template description
	 * @param int $energyCost Energy cost (1-5)
	 * @param int $priority Priority (1-3)
	 * @param string $frequencyType Frequency type (daily, weekly, monthly, custom)
	 * @param int $frequencyValue Frequency value
	 * @param int $estimatedMinutes Estimated time in minutes
	 * @param string $category Task category
	 * @param string $scopeType Scope type (room, garden, home, outside, work)
	 */
	public function createTaskTemplate(
		string $name,
		string $description,
		int $energyCost,
		int $priority,
		string $frequencyType,
		int $frequencyValue,
		int $estimatedMinutes,
		string $category,
		string $scopeType = 'room'
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
			$scopeType,
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
			if (!is_numeric($taskData['energyCost']) || $taskData['energyCost'] < 1 || $taskData['energyCost'] > 5) {
				$errors[] = 'Energy cost must be between 1 and 5';
			}
		}

		// Priority validation
		if (isset($taskData['priority'])) {
			if (!is_numeric($taskData['priority']) || $taskData['priority'] < 1 || $taskData['priority'] > 3) {
				$errors[] = 'Priority must be between 1 and 3';
			}
		}

		// Estimated time validation
		if (isset($taskData['estimatedMinutes'])) {
			if (!is_numeric($taskData['estimatedMinutes']) || $taskData['estimatedMinutes'] < 1 || $taskData['estimatedMinutes'] > 480) {
				$errors[] = 'Estimated time must be between 1 and 480 minutes';
			}
		}

		// Frequency type validation
		if (isset($taskData['frequencyType'])) {
			$validTypes = ['daily', 'weekly', 'monthly', 'custom'];
			if (!in_array($taskData['frequencyType'], $validTypes, true)) {
				$errors[] = 'Invalid frequency type';
			}
		}

		if (isset($taskData['scopeType'])) {
			$validScopeTypes = ['room', 'garden', 'home', 'outside', 'work'];
			if (!in_array($taskData['scopeType'], $validScopeTypes, true)) {
				$errors[] = 'Invalid scope type';
			}
		}

		if (isset($taskData['recurrenceMode'])) {
			$validRecurrenceModes = ['one_time', 'recurring'];
			if (!in_array($taskData['recurrenceMode'], $validRecurrenceModes, true)) {
				$errors[] = 'Invalid recurrence mode';
			}
		}

		if (isset($taskData['isVoluntary']) && !is_bool($taskData['isVoluntary'])) {
			$errors[] = 'Voluntary flag must be boolean';
		}

		// Frequency value validation
		if (isset($taskData['frequencyValue'])) {
			if (!is_numeric($taskData['frequencyValue']) || $taskData['frequencyValue'] < 1) {
				$errors[] = 'Frequency value must be at least 1';
			}
		}

		if (isset($taskData['activeFrom']) && $taskData['activeFrom'] !== null) {
			try {
				new \DateTime((string)$taskData['activeFrom']);
			} catch (\Exception $exception) {
				$errors[] = 'Invalid active from date';
			}
		}

		if (isset($taskData['activeUntil']) && $taskData['activeUntil'] !== null) {
			try {
				new \DateTime((string)$taskData['activeUntil']);
			} catch (\Exception $exception) {
				$errors[] = 'Invalid active until date';
			}
		}

		if (
			isset($taskData['activeFrom'], $taskData['activeUntil'])
			&& $taskData['activeFrom'] !== null
			&& $taskData['activeUntil'] !== null
		) {
			try {
				$activeFrom = new \DateTime((string)$taskData['activeFrom']);
				$activeUntil = new \DateTime((string)$taskData['activeUntil']);
				if ($activeUntil < $activeFrom) {
					$errors[] = 'Active until must be equal or after active from';
				}
			} catch (\Exception $exception) {
				// Individual parsing errors are already collected above.
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
			['nameKey' => 'template.vacuum_floor', 'descKey' => 'template.vacuum_floor.desc', 'energyCost' => 2, 'priority' => 2, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 20, 'category' => 'cleaning', 'scopeType' => 'room'],
			['nameKey' => 'template.dust_surfaces', 'descKey' => 'template.dust_surfaces.desc', 'energyCost' => 1, 'priority' => 2, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 15, 'category' => 'cleaning', 'scopeType' => 'room'],
			['nameKey' => 'template.clean_windows', 'descKey' => 'template.clean_windows.desc', 'energyCost' => 3, 'priority' => 1, 'frequencyType' => 'monthly', 'frequencyValue' => 1, 'estimatedMinutes' => 30, 'category' => 'cleaning', 'scopeType' => 'room'],
			['nameKey' => 'template.mop_floor', 'descKey' => 'template.mop_floor.desc', 'energyCost' => 2, 'priority' => 2, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 25, 'category' => 'cleaning', 'scopeType' => 'room'],
			['nameKey' => 'template.clean_fridge', 'descKey' => 'template.clean_fridge.desc', 'energyCost' => 3, 'priority' => 1, 'frequencyType' => 'monthly', 'frequencyValue' => 1, 'estimatedMinutes' => 30, 'category' => 'cleaning', 'scopeType' => 'home'],
			['nameKey' => 'template.take_out_trash', 'descKey' => 'template.take_out_trash.desc', 'energyCost' => 1, 'priority' => 3, 'frequencyType' => 'weekly', 'frequencyValue' => 2, 'estimatedMinutes' => 10, 'category' => 'cleaning', 'scopeType' => 'home'],

			// Kitchen tasks
			['nameKey' => 'template.clean_countertops', 'descKey' => 'template.clean_countertops.desc', 'energyCost' => 1, 'priority' => 3, 'frequencyType' => 'daily', 'frequencyValue' => 1, 'estimatedMinutes' => 10, 'category' => 'kitchen', 'scopeType' => 'room'],
			['nameKey' => 'template.clean_microwave', 'descKey' => 'template.clean_microwave.desc', 'energyCost' => 2, 'priority' => 2, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 15, 'category' => 'kitchen', 'scopeType' => 'room'],
			['nameKey' => 'template.clean_oven', 'descKey' => 'template.clean_oven.desc', 'energyCost' => 4, 'priority' => 1, 'frequencyType' => 'monthly', 'frequencyValue' => 1, 'estimatedMinutes' => 60, 'category' => 'kitchen', 'scopeType' => 'room'],
			['nameKey' => 'template.load_dishwasher', 'descKey' => 'template.load_dishwasher.desc', 'energyCost' => 1, 'priority' => 3, 'frequencyType' => 'daily', 'frequencyValue' => 1, 'estimatedMinutes' => 15, 'category' => 'kitchen', 'scopeType' => 'room'],
			['nameKey' => 'template.unload_dishwasher', 'descKey' => 'template.unload_dishwasher.desc', 'energyCost' => 1, 'priority' => 2, 'frequencyType' => 'daily', 'frequencyValue' => 1, 'estimatedMinutes' => 10, 'category' => 'kitchen', 'scopeType' => 'room'],

			// Bathroom tasks
			['nameKey' => 'template.clean_toilet', 'descKey' => 'template.clean_toilet.desc', 'energyCost' => 2, 'priority' => 3, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 15, 'category' => 'bathroom', 'scopeType' => 'room'],
			['nameKey' => 'template.clean_shower', 'descKey' => 'template.clean_shower.desc', 'energyCost' => 3, 'priority' => 2, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 30, 'category' => 'bathroom', 'scopeType' => 'room'],
			['nameKey' => 'template.clean_mirror', 'descKey' => 'template.clean_mirror.desc', 'energyCost' => 1, 'priority' => 2, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 10, 'category' => 'bathroom', 'scopeType' => 'room'],
			['nameKey' => 'template.wash_towels', 'descKey' => 'template.wash_towels.desc', 'energyCost' => 2, 'priority' => 2, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 5, 'category' => 'bathroom', 'scopeType' => 'room'],
			['nameKey' => 'template.clean_sink', 'descKey' => 'template.clean_sink.desc', 'energyCost' => 1, 'priority' => 2, 'frequencyType' => 'weekly', 'frequencyValue' => 2, 'estimatedMinutes' => 10, 'category' => 'bathroom', 'scopeType' => 'room'],

			// Organizing tasks
			['nameKey' => 'template.organize_closet', 'descKey' => 'template.organize_closet.desc', 'energyCost' => 3, 'priority' => 1, 'frequencyType' => 'monthly', 'frequencyValue' => 1, 'estimatedMinutes' => 45, 'category' => 'organizing', 'scopeType' => 'room'],
			['nameKey' => 'template.sort_mail', 'descKey' => 'template.sort_mail.desc', 'energyCost' => 1, 'priority' => 2, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 15, 'category' => 'organizing', 'scopeType' => 'home'],
			['nameKey' => 'template.organize_pantry', 'descKey' => 'template.organize_pantry.desc', 'energyCost' => 2, 'priority' => 1, 'frequencyType' => 'monthly', 'frequencyValue' => 1, 'estimatedMinutes' => 30, 'category' => 'organizing', 'scopeType' => 'room'],

			// Maintenance tasks
			['nameKey' => 'template.replace_air_filter', 'descKey' => 'template.replace_air_filter.desc', 'energyCost' => 1, 'priority' => 2, 'frequencyType' => 'monthly', 'frequencyValue' => 3, 'estimatedMinutes' => 10, 'category' => 'maintenance', 'scopeType' => 'home'],
			['nameKey' => 'template.test_smoke_detectors', 'descKey' => 'template.test_smoke_detectors.desc', 'energyCost' => 1, 'priority' => 3, 'frequencyType' => 'monthly', 'frequencyValue' => 1, 'estimatedMinutes' => 15, 'category' => 'maintenance', 'scopeType' => 'home'],
			['nameKey' => 'template.check_carbon_monoxide', 'descKey' => 'template.check_carbon_monoxide.desc', 'energyCost' => 1, 'priority' => 3, 'frequencyType' => 'monthly', 'frequencyValue' => 1, 'estimatedMinutes' => 5, 'category' => 'maintenance', 'scopeType' => 'home'],

			// Laundry tasks
			['nameKey' => 'template.do_laundry', 'descKey' => 'template.do_laundry.desc', 'energyCost' => 2, 'priority' => 2, 'frequencyType' => 'weekly', 'frequencyValue' => 2, 'estimatedMinutes' => 45, 'category' => 'laundry', 'scopeType' => 'home'],
			['nameKey' => 'template.fold_laundry', 'descKey' => 'template.fold_laundry.desc', 'energyCost' => 1, 'priority' => 2, 'frequencyType' => 'weekly', 'frequencyValue' => 2, 'estimatedMinutes' => 30, 'category' => 'laundry', 'scopeType' => 'home'],
			['nameKey' => 'template.iron_clothes', 'descKey' => 'template.iron_clothes.desc', 'energyCost' => 2, 'priority' => 1, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 40, 'category' => 'laundry', 'scopeType' => 'home'],
		];

		$createdTemplates = [];
		foreach ($templates as $templateData) {
			$createdTemplates[] = $this->createTaskTemplate(
				$this->l10n->t($templateData['nameKey']),
				$this->l10n->t($templateData['descKey']),
				$templateData['energyCost'],
				$templateData['priority'],
				$templateData['frequencyType'],
				$templateData['frequencyValue'],
				$templateData['estimatedMinutes'],
				$templateData['category'],
				$templateData['scopeType']
			);
		}

		return $createdTemplates;
	}
}
