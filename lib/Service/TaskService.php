<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Service for task-related business logic
 */
class TaskService
{
    /**
     * Fields a client is allowed to change through updateTask.
     *
     * Defense against mass-assignment: only these keys are honoured; any
     * other key throws {@see \InvalidArgumentException} (→ 400). Derived from
     * the controller-level whitelist + the TaskEditForm field set. Note that
     * `ownerUserId` is whitelisted for transfer, but *who* may change it is
     * still governed by the controller authorization (CS-PLAN-005) — the
     * whitelist is defense-in-depth, not an authorization bypass.
     */
    private const MUTABLE_FIELDS = [
        'name', 'description', 'energyCost', 'priority',
        'frequencyType', 'frequencyValue', 'estimatedMinutes',
        'category', 'scopeType', 'scopeLabel', 'recurrenceMode',
        'activeFrom', 'activeUntil', 'isVoluntary',
        'schedulingMode', 'fixedScheduleTime', 'fixedScheduleDays',
        'instructions', 'tips', 'estimatedMinutesMin', 'estimatedMinutesMax',
        'visibilityType', 'ownerUserId', 'taskTier', 'flexDays',
    ];

    /** Fields whose value is a date and must be parsed from string input. */
    private const DATE_FIELDS = ['activeFrom', 'activeUntil', 'fixedScheduleTime'];

    private TaskMapper $taskMapper;

    public function __construct(TaskMapper $taskMapper)
    {
        $this->taskMapper = $taskMapper;
    }

    /**
     * Get all tasks
     *
     * @return Task[]
     */
    public function getAllTasks(): array
    {
        return $this->taskMapper->findAll();
    }

    /**
     * Get tasks visible to a specific house user.
     *
     * @param int $houseId The house ID
     * @param \OCA\ChoreStrike\Db\HouseUser $viewer The viewing house user
     * @return Task[]
     */
    public function getVisibleTasks(int $houseId, \OCA\ChoreStrike\Db\HouseUser $viewer): array
    {
        return $this->taskMapper->findVisibleByHouse($houseId, $viewer);
    }

    /**
     * Get task by ID
     *
     * @param int $taskId The task ID
     * @throws DoesNotExistException if not found
     */
    public function getTask(int $taskId): Task
    {
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
        ?int $estimatedMinutesMax = null,
        bool $isPrivate = false,
        string $visibilityType = 'public',
        ?string $ownerUserId = null
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
            $estimatedMinutesMax,
            $isPrivate,
            $visibilityType,
            $ownerUserId
        );
    }

    /**
     * Update task.
     *
     * Applies only the whitelisted {@see MUTABLE_FIELDS} from `$updates`;
     * any key not on the whitelist is rejected with
     * {@see \InvalidArgumentException} (translated to 400 by the controller)
     * to prevent mass-assignment of internal columns.
     *
     * @param int $taskId The task ID
     * @param array $updates The updates to apply (only whitelisted keys)
     * @throws \InvalidArgumentException if an update key is not whitelisted
     */
    public function updateTask(int $taskId, array $updates): Task
    {
        $task = $this->taskMapper->find($taskId);

        foreach ($updates as $field => $value) {
            if (!in_array($field, self::MUTABLE_FIELDS, true)) {
                throw new \InvalidArgumentException('Unknown or immutable field: ' . $field);
            }

            // Task extends Nextcloud's Entity, whose accessors are magic
            // (@method/__call), so method_exists() returns false for them and
            // cannot be used as a gate. The MUTABLE_FIELDS whitelist above is
            // the authoritative guard; the Entity setter applies the value.
            $method = 'set' . ucfirst($field);

            if (in_array($field, self::DATE_FIELDS, true) && is_string($value)) {
                $value = new \DateTime($value);
            }

            $task->$method($value);
        }

        return $this->taskMapper->update($task);
    }

    /**
     * Delete task.
     *
     * Enforces the task-visibility matrix as defense-in-depth so the rule
     * holds even if a caller bypasses the controller gate:
     *   - private:    only the task owner may delete
     *   - admin-only: only a house owner may delete
     *   - public:     any caller
     *
     * Callers MUST supply authorization context ($callerUserId, $isOwner);
     * the parameter is non-nullable so a caller can never silently bypass
     * the private-task ownership check. The production path
     * (TaskController::delete) always supplies it.
     *
     * @param int $taskId The task ID
     * @param string $callerUserId The Nextcloud user ID requesting the delete
     * @param bool $isOwner Whether the caller is a house owner
     * @throws ForbiddenException if the caller lacks permission for this task
     */
    public function deleteTask(int $taskId, string $callerUserId, bool $isOwner = false): void
    {
        $task = $this->taskMapper->find($taskId);

        $visibility = $task->getVisibilityType();
        if ($visibility === Task::VISIBILITY_PRIVATE
            && $task->getOwnerUserId() !== $callerUserId
        ) {
            throw new ForbiddenException('Only the task owner may delete a private task');
        }
        if ($visibility === Task::VISIBILITY_ADMIN_ONLY && !$isOwner) {
            throw new ForbiddenException('Only house owners may delete an admin-only task');
        }

        $this->taskMapper->delete($task);
    }

    /**
     * Get task templates
     *
     * @return Task[]
     */
    public function getTaskTemplates(): array
    {
        return $this->taskMapper->findTemplates();
    }

    /**
     * Create task from template
     *
     * @param int $templateId The template ID
     */
    public function createFromTemplate(int $templateId): Task
    {
        return $this->taskMapper->createFromTemplate($templateId);
    }

    /**
     * Get tasks by category
     *
     * @param string $category The task category
     * @return Task[]
     */
    public function getTasksByCategory(string $category): array
    {
        return $this->taskMapper->findByCategory($category);
    }

    /**
     * Search tasks by name
     *
     * @param string $query The search query
     * @return Task[]
     */
    public function searchTasks(string $query): array
    {
        return $this->taskMapper->searchByName($query);
    }

    /**
     * Get all task categories
     *
     * @return string[]
     */
    public function getTaskCategories(): array
    {
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
    public function validateTaskData(array $taskData): array
    {
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

        // Visibility type validation
        if (isset($taskData['visibilityType'])) {
            if (!in_array($taskData['visibilityType'], \OCA\ChoreStrike\Db\Task::VISIBILITY_TYPES, true)) {
                $errors[] = 'Invalid visibility type';
            }
        }

        return $errors;
    }

    /**
     * Get default task templates
     *
     * Creates common household task templates if none exist
     */
    public function createDefaultTemplates(): array
    {
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

    /**
     * Get private tasks owned by a specific user.
     *
     * @param string $ownerUserId The Nextcloud user ID
     * @return Task[]
     */
    public function getMyPrivateTasks(string $ownerUserId): array
    {
        return $this->taskMapper->findPrivateByOwner($ownerUserId);
    }

    /**
     * Complete a task
     *
     * @param int $taskId The task ID
     * @return Task The updated task
     */
    public function completeTask(int $taskId): Task
    {
        $task = $this->taskMapper->find($taskId);
        $task->setStatus('completed');
        $task->setCompletedAt(new \DateTime());
        return $this->taskMapper->update($task);
    }

    /**
     * Uncomplete a task
     *
     * @param int $taskId The task ID
     * @return Task The updated task
     */
    public function uncompleteTask(int $taskId): Task
    {
        $task = $this->taskMapper->find($taskId);
        $task->setStatus('pending');
        $task->setCompletedAt(null);
        return $this->taskMapper->update($task);
    }

    /**
     * Skip a task
     *
     * @param int $taskId The task ID
     * @return Task The updated task
     */
    public function skipTask(int $taskId): Task
    {
        $task = $this->taskMapper->find($taskId);
        $task->setStatus('skipped');
        return $this->taskMapper->update($task);
    }

    /**
     * Reschedule a task
     *
     * @param int $taskId The task ID
     * @param DateTime $newDate The new scheduled date
     * @return Task The updated task
     */
    public function rescheduleTask(int $taskId, \DateTime $newDate): Task
    {
        $task = $this->taskMapper->find($taskId);
        $task->setScheduledDate($newDate);
        $task->setStatus('pending');
        return $this->taskMapper->update($task);
    }
}
