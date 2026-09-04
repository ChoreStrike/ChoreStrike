<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Service for task-related business logic
 */
class TaskService {
	/**
	 * Fields a client is allowed to change through updateTask.
	 *
	 * Defense against mass-assignment: only these keys are honoured; any
	 * other key throws {@see \InvalidArgumentException} (→ 400). Derived from
	 * the controller-level whitelist + the TaskEditForm field set. Note that
	 * `ownerUserId` is whitelisted for transfer, but *who* may change it is
	 * still governed by the controller authorization (CS-PLAN-005) — the
	 * whitelist is defense-in-depth, not an authorization bypass.
	 *
	 * `taskTier` is NOT whitelisted: it is owner-only and value-checked
	 * explicitly in {@see updateTask()} (CR-053-04) because it drives the
	 * coin multiplier.
	 */
	private const MUTABLE_FIELDS = [
		'name', 'description', 'energyCost', 'priority',
		'frequencyType', 'frequencyValue', 'estimatedMinutes',
		'category', 'scopeType', 'scopeLabel', 'recurrenceMode',
		'activeFrom', 'activeUntil', 'isVoluntary',
		'schedulingMode', 'fixedScheduleTime', 'fixedScheduleDays',
		'instructions', 'tips', 'estimatedMinutesMin', 'estimatedMinutesMax',
		'visibilityType', 'ownerUserId', 'flexDays',
	];

	/** Allowed taskTier values (CR-053-04 closed set). */
	public const TASK_TIERS = ['bronze', 'silver', 'gold'];

	/** Fields whose value is a date and must be parsed from string input. */
	private const DATE_FIELDS = ['activeFrom', 'activeUntil', 'fixedScheduleTime'];

	private TaskMapper $taskMapper;
	private IL10N $l10n;
	private AuthorizationService $authorization;
	private TaskAccessPolicy $taskAccessPolicy;

	public function __construct(
		TaskMapper $taskMapper,
		IL10N $l10n,
		AuthorizationService $authorization,
		TaskAccessPolicy $taskAccessPolicy,
	) {
		$this->taskMapper = $taskMapper;
		$this->l10n = $l10n;
		$this->authorization = $authorization;
		$this->taskAccessPolicy = $taskAccessPolicy;
	}

	/**
	 * Get tasks visible to a specific house user.
	 *
	 * @param int $houseId The house ID
	 * @param \OCA\ChoreStrike\Db\HouseUser $viewer The viewing house user
	 * @return Task[]
	 */
	public function getVisibleTasks(int $houseId, \OCA\ChoreStrike\Db\HouseUser $viewer): array {
		return $this->taskMapper->findVisibleByHouse($houseId, $viewer);
	}

	/**
	 * Get task by ID
	 *
	 * @param int $taskId The task ID
	 * @throws NotFoundException if not found
	 */
	public function getTask(int $taskId): Task {
		try {
			return $this->taskMapper->find($taskId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Task not found'));
		}
	}

	/**
	 * Assert the task is visible to the actor and return it (CS-PLAN-061
	 * §3.2) — the shared gate for controller preambles (defense in depth).
	 *
	 * @throws NotFoundException if the task does not exist or is invisible to the actor
	 */
	public function getVisibleTask(int $taskId, HouseUser $actor): Task {
		$task = $this->getTask($taskId);
		if (!$this->taskAccessPolicy->canViewTask($task, $actor)) {
			throw new NotFoundException($this->l10n->t('Task not found'));
		}
		return $task;
	}

	/**
	 * Create a new task (CS-PLAN-061 §3.4: children may not create task
	 * definitions; the acting HouseUser is mandatory so the actor policy can
	 * never be bypassed by forgetting the preamble).
	 *
	 * @param HouseUser $actor The acting user's house membership
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
	 * @param string $schedulingMode Scheduling mode (dynamic, fixed)
	 * @param \DateTime|null $fixedScheduleTime Fixed schedule time
	 * @param string|null $fixedScheduleDays Fixed schedule days
	 * @throws \OCA\ChoreStrike\Exception\ForbiddenException if the actor is a child
	 */
	public function createTask(
		HouseUser $actor,
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
		string $schedulingMode = 'dynamic',
		?\DateTime $fixedScheduleTime = null,
		?string $fixedScheduleDays = null,
		?string $instructions = null,
		?string $tips = null,
		?int $estimatedMinutesMin = null,
		?int $estimatedMinutesMax = null,
		string $visibilityType = 'public',
		?string $ownerUserId = null,
	): Task {
		// Actor policy (D5): children execute tasks, they do not create them.
		$this->authorization->assertCanManageTasks($actor);

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
			$schedulingMode,
			$fixedScheduleTime,
			$fixedScheduleDays,
			$instructions,
			$tips,
			$estimatedMinutesMin,
			$estimatedMinutesMax,
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
	 * to prevent mass-assignment of internal columns. `taskTier` is handled
	 * separately (owner-only + closed set, CR-053-04).
	 *
	 * Enforces the ONE visibility matrix via {@see TaskAccessPolicy}
	 * (CS-PLAN-061 §3.2, D2): a caller that cannot even see the task is
	 * denied with the app NotFoundException (404 anti-enumeration, decision
	 * D3). A changed `ownerUserId` target is validated as a house member via
	 * {@see AuthorizationService::assertUserInHouse} (closes the unvalidated
	 * transfer, §3.4).
	 *
	 * Callers MUST supply the acting house user; the parameter is
	 * non-nullable so the policy can never be silently bypassed. The
	 * production path (TaskController::update) always supplies it.
	 *
	 * @param int $taskId The task ID
	 * @param array $updates The updates to apply (only whitelisted keys)
	 * @param HouseUser $actor The acting user's house membership
	 * @return Task The updated task
	 * @throws \InvalidArgumentException if an update key is not whitelisted
	 * @throws NotFoundException if the task does not exist or is invisible to the actor
	 * @throws \OCA\ChoreStrike\Exception\ForbiddenException if the actor is a child or the ownerUserId target is not a member
	 */
	public function updateTask(int $taskId, array $updates, HouseUser $actor): Task {
		$task = $this->getTask($taskId);

		// One visibility matrix everywhere (D2/D3): non-viewers get the 404
		// anti-enumeration denial, same as task#get and the definition list.
		if (!$this->taskAccessPolicy->canViewTask($task, $actor)) {
			throw new NotFoundException($this->l10n->t('Task not found'));
		}

		// Actor policy (D5): children execute tasks, they do not edit them.
		$this->authorization->assertCanManageTasks($actor);

		$isOwner = $actor->isOwner();

		// CR-053-04: taskTier is owner-only and value-checked outside the
		// generic whitelist loop — a member must not be able to self-serve
		// the gold coin multiplier. Role denial is a ForbiddenException
		// (403); a bad value is an InvalidArgumentException (400).
		if (array_key_exists('taskTier', $updates)) {
			if (!$isOwner) {
				throw new ForbiddenException($this->l10n->t('Only house owners may change the task tier'));
			}
			if (!in_array($updates['taskTier'], self::TASK_TIERS, true)) {
				throw new \InvalidArgumentException($this->l10n->t('Invalid task tier'));
			}
			$task->setTaskTier($updates['taskTier']);
			unset($updates['taskTier']);
		}

		// Validate an ownership transfer target: the new owner must be a
		// member of the actor's house (CS-PLAN-061 §3.4).
		if (isset($updates['ownerUserId'])
			&& $updates['ownerUserId'] !== $task->getOwnerUserId()
			&& is_string($updates['ownerUserId'])
			&& $updates['ownerUserId'] !== ''
			&& $updates['ownerUserId'] !== null
		) {
			$this->authorization->assertUserInHouse($actor->getHouseId(), $updates['ownerUserId']);
		}

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
	 * Validate task data
	 *
	 * Returns localized, client-safe error messages (surfaced in the
	 * TaskController 400 envelope `details[]`), hence every literal goes
	 * through t() (CR-SVC-37).
	 *
	 * @param array $taskData The task data to validate
	 */
	public function validateTaskData(array $taskData): array {
		$errors = [];

		// Required fields
		if (empty($taskData['name'])) {
			$errors[] = $this->l10n->t('Task name is required');
		}

		// Energy cost validation
		if (isset($taskData['energyCost'])) {
			if ($taskData['energyCost'] < 1 || $taskData['energyCost'] > 5) {
				$errors[] = $this->l10n->t('Energy cost must be between 1 and 5');
			}
		}

		// Priority validation
		if (isset($taskData['priority'])) {
			if ($taskData['priority'] < 1 || $taskData['priority'] > 3) {
				$errors[] = $this->l10n->t('Priority must be between 1 and 3');
			}
		}

		// Estimated time validation
		if (isset($taskData['estimatedMinutes'])) {
			if ($taskData['estimatedMinutes'] < 1 || $taskData['estimatedMinutes'] > 480) {
				$errors[] = $this->l10n->t('Estimated time must be between 1 and 480 minutes');
			}
		}

		// Estimated-time range cross-check (CS-PLAN-060 §3.6): an inverted
		// min/max pair would render "30-10 min" and never match a real span.
		if (isset($taskData['estimatedMinutesMin'], $taskData['estimatedMinutesMax'])
			&& $taskData['estimatedMinutesMin'] > $taskData['estimatedMinutesMax']) {
			$errors[] = $this->l10n->t('Estimated time minimum must not be greater than the maximum');
		}

		// Frequency type validation
		if (isset($taskData['frequencyType'])) {
			$validTypes = ['daily', 'weekly', 'monthly', 'custom'];
			if (!in_array($taskData['frequencyType'], $validTypes)) {
				$errors[] = $this->l10n->t('Invalid frequency type');
			}
		}

		// Frequency value validation
		if (isset($taskData['frequencyValue'])) {
			if ($taskData['frequencyValue'] < 1) {
				$errors[] = $this->l10n->t('Frequency value must be at least 1');
			}
		}

		// Scope type validation
		if (isset($taskData['scopeType'])) {
			$validScopeTypes = ['room', 'floor', 'house'];
			if (!in_array($taskData['scopeType'], $validScopeTypes)) {
				$errors[] = $this->l10n->t('Invalid scope type');
			}
		}

		// Recurrence mode validation (canonical Task::RECURRENCE_* vocabulary)
		if (isset($taskData['recurrenceMode'])) {
			if (!in_array($taskData['recurrenceMode'], Task::RECURRENCE_MODES, true)) {
				$errors[] = $this->l10n->t('Invalid recurrence mode');
			}
		}

		// Date range validation
		if (isset($taskData['activeFrom']) && isset($taskData['activeUntil'])) {
			$from = new \DateTime($taskData['activeFrom']);
			$until = new \DateTime($taskData['activeUntil']);
			if ($until < $from) {
				$errors[] = $this->l10n->t('Active until must be equal or after active from');
			}
		}

		// Visibility type validation
		if (isset($taskData['visibilityType'])) {
			if (!in_array($taskData['visibilityType'], \OCA\ChoreStrike\Db\Task::VISIBILITY_TYPES, true)) {
				$errors[] = $this->l10n->t('Invalid visibility type');
			}
		}

		return $errors;
	}

	/**
	 * Get private tasks owned by a specific user.
	 *
	 * @param string $ownerUserId The Nextcloud user ID
	 * @return Task[]
	 */
	public function getMyPrivateTasks(string $ownerUserId): array {
		return $this->taskMapper->findPrivateByOwner($ownerUserId);
	}

	// CR-SVC-33: the reward-less Task-level completeTask/uncompleteTask pair
	// was deleted — completions must go through the canonical
	// RoomTaskService::completeTask (coins/streak/cleanliness side effects).

}
