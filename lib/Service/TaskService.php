<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Exception\ValidationException;
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

	/** Hard length cap for free-text instruction/tip fields (CS-PLAN-063 §3.1). */
	public const MAX_TEXT_LENGTH = 2000;

	/** Valid flex-window bounds (CS-PLAN-063 D3, ratified [0, 7]). */
	public const FLEX_DAYS_MIN = 0;
	public const FLEX_DAYS_MAX = 7;

	/** Canonical weekday names accepted in fixedScheduleDays. */
	public const WEEKDAYS = [
		'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
	];

	private TaskMapper $taskMapper;
	private HouseMapper $houseMapper;
	private RoomTaskMapper $roomTaskMapper;
	private IL10N $l10n;
	private AuthorizationService $authorization;
	private TaskAccessPolicy $taskAccessPolicy;
	private TransactionExecutor $transactions;
	private OccurrenceService $occurrenceService;
	private CalendarService $calendarService;

	public function __construct(
		TaskMapper $taskMapper,
		HouseMapper $houseMapper,
		RoomTaskMapper $roomTaskMapper,
		IL10N $l10n,
		AuthorizationService $authorization,
		TaskAccessPolicy $taskAccessPolicy,
		TransactionExecutor $transactions,
		OccurrenceService $occurrenceService,
		CalendarService $calendarService,
	) {
		$this->taskMapper = $taskMapper;
		$this->houseMapper = $houseMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->l10n = $l10n;
		$this->authorization = $authorization;
		$this->taskAccessPolicy = $taskAccessPolicy;
		$this->transactions = $transactions;
		$this->occurrenceService = $occurrenceService;
		$this->calendarService = $calendarService;
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
	 * CS-PLAN-071 WP0: the write runs in one house-first transaction — the
	 * actor's house row lock first, then the task-definition row lock —
	 * and applies the validated updates to the FRESH locked entity
	 * (CR-SVC-53 pattern), so a concurrent definition edit can no longer
	 * be clobbered.
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
		// (403); a bad value is an InvalidArgumentException (400). The
		// value is validated here and applied under the locks below.
		if (array_key_exists('taskTier', $updates)) {
			if (!$isOwner) {
				throw new ForbiddenException($this->l10n->t('Only house owners may change the task tier'));
			}
			if (!in_array($updates['taskTier'], self::TASK_TIERS, true)) {
				throw new \InvalidArgumentException($this->l10n->t('Invalid task tier'));
			}
		}

		// Admin-only visibility is owner-only (ADR-010 matrix, RF-058-03):
		// setting AND unsetting is a role denial (403) for non-owners. The
		// controller gate is defense in depth; this service-side mirror
		// (creation already gates in TaskCreationService) closes the
		// direct-caller bypass, taskTier-style.
		if (array_key_exists('visibilityType', $updates)) {
			$newVisibility = $updates['visibilityType'];
			$isAdminOnly = static fn ($v): bool => $v === Task::VISIBILITY_ADMIN_ONLY;
			if ($isAdminOnly($newVisibility) && !$isOwner) {
				throw new ForbiddenException($this->l10n->t('Only house owners can set admin-only visibility'));
			}
			if ($isAdminOnly($task->getVisibilityType()) && !$isAdminOnly($newVisibility) && !$isOwner) {
				throw new ForbiddenException($this->l10n->t('Only house owners can change admin-only task visibility'));
			}
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

		// Whitelist first (mass-assignment defense, unchanged semantics):
		// an unknown or immutable key is rejected before any value check.
		// `taskTier` is not on the generic whitelist — it was owner- and
		// value-validated above and is applied explicitly under the locks.
		foreach ($updates as $field => $value) {
			if ($field === 'taskTier') {
				continue;
			}
			if (!in_array($field, self::MUTABLE_FIELDS, true)) {
				throw new \InvalidArgumentException($this->l10n->t('Unknown or immutable field: %1$s', [$field]));
			}
		}

		// Validate the whitelisted values through the one closed constraint
		// set (CS-PLAN-063 §3.1): validation is service-owned, the controller
		// no longer pre-checks. Updates are partial, so the name is only
		// validated when present. A malformed value → ValidationException (400).
		$whitelisted = array_intersect_key($updates, array_flip(self::MUTABLE_FIELDS));
		$errors = $this->validateTaskData($whitelisted, requireName: false);
		if ($errors !== []) {
			throw new ValidationException($errors[0], $errors);
		}

		// CS-PLAN-071 WP0: apply the validated updates under the locks —
		// house row first, then the task-definition row — on the FRESH
		// entity. taskTier is not on the generic whitelist but was
		// owner-validated above; it is applied explicitly.
		//
		// CS-PLAN-072 WP1: when a rule-relevant definition input changed,
		// every mounted room task's schedule is revised in the SAME
		// house-first transaction (reconcile elapsed old agreements, close
		// the old revision, append the new one) — definition edits never
		// become a second authority beside the occurrence rows, and agreed
		// history keeps its identity, zone and deadlines.
		return $this->transactions->run(function () use ($taskId, $updates, $actor): Task {
			$this->houseMapper->findForUpdate($actor->getHouseId());
			$task = $this->taskMapper->lockForUpdate($taskId);
			$before = clone $task;
			if (array_key_exists('taskTier', $updates)) {
				$task->setTaskTier($updates['taskTier']);
			}
			$this->applyUpdates($task, array_intersect_key($updates, array_flip(self::MUTABLE_FIELDS)));
			$updated = $this->taskMapper->update($task);

			if ($this->ruleRelevantInputsChanged($before, $updated)) {
				$mounted = $this->roomTaskMapper->findByTaskIdScopedToHouse($taskId, (int)$actor->getHouseId());
				if ($mounted !== []) {
					$house = $this->houseMapper->find((int)$actor->getHouseId());
					$timezoneId = $this->calendarService->getTimeZone($house)->getName();
					foreach ($mounted as $roomTask) {
						$lockedRoomTask = $this->roomTaskMapper->lockForUpdate($roomTask->getId());
						if ($lockedRoomTask->getDeletedAt() !== null) {
							continue;
						}
						$this->occurrenceService->reviseUnderLock(
							(int)$actor->getHouseId(),
							$lockedRoomTask,
							$updated,
							$timezoneId,
							null,
							new \DateTime(),
						);
					}
				}
			}

			return $updated;
		});
	}

	/**
	 * Whether any rule-relevant generation input of the definition changed
	 * (CS-PLAN-072 WP1 §3.2 rule 4): energy, cadence, lifetime, active
	 * bounds, window days, voluntary/public classification. Purely
	 * descriptive edits (name, description, priority, tips) never force a
	 * revision.
	 */
	private function ruleRelevantInputsChanged(Task $before, Task $after): bool {
		return $before->getEnergyCost() !== $after->getEnergyCost()
			|| $before->getFrequencyType() !== $after->getFrequencyType()
			|| $before->getFrequencyValue() !== $after->getFrequencyValue()
			|| $before->getRecurrenceMode() !== $after->getRecurrenceMode()
			|| $before->getSchedulingMode() !== $after->getSchedulingMode()
			|| $before->getFixedScheduleTime()?->format('H:i') !== $after->getFixedScheduleTime()?->format('H:i')
			|| $before->getFixedScheduleDays() !== $after->getFixedScheduleDays()
			|| $before->getActiveFrom()?->format('Y-m-d') !== $after->getActiveFrom()?->format('Y-m-d')
			|| $before->getActiveUntil()?->format('Y-m-d') !== $after->getActiveUntil()?->format('Y-m-d')
			|| $before->getFlexDays() !== $after->getFlexDays()
			|| $before->getIsVoluntary() !== $after->getIsVoluntary()
			|| $before->getVisibilityType() !== $after->getVisibilityType();
	}

	/**
	 * Apply the whitelisted update values to the given task entity (the
	 * setter loop extracted so validation and the locked write can use the
	 * same semantics).
	 *
	 * @param array<string, mixed> $updates
	 */
	private function applyUpdates(Task $task, array $updates): void {
		foreach ($updates as $field => $value) {
			// Task extends Nextcloud's Entity, whose accessors are magic
			// (@method/__call), so method_exists() returns false for them and
			// cannot be used as a gate. The MUTABLE_FIELDS whitelist above is
			// the authoritative guard; the Entity setter applies the value.
			$method = 'set' . ucfirst($field);

			if (in_array($field, self::DATE_FIELDS, true)) {
				// An empty string clears the field (client blanks); a filled
				// string parses (validation already guaranteed parseability).
				$value = ($value === '') ? null : (is_string($value) ? new \DateTime($value) : $value);
			}

			// fixedScheduleDays accepts the PHP weekday-list form from
			// clients and persists its canonical JSON encoding.
			if ($field === 'fixedScheduleDays' && is_array($value)) {
				$value = json_encode(array_values($value));
			}

			$task->$method($value);
		}
	}

	/**
	 * Validate task data — the single creation/update constraint authority
	 * (CS-PLAN-063 §3.1). Invoked by services (TaskService::updateTask,
	 * TaskCreationService), never by controllers.
	 *
	 * The constraint set is closed: every accepted field is range/type
	 * checked, malformed or non-scalar values are reported as errors (→ 400)
	 * and never coerced. `$requireName` is true on the creation path (name
	 * mandatory) and false on the partial-update path (absent name is fine,
	 * a present blank/non-string name is still rejected). Returns localized,
	 * client-safe error messages (surfaced in the 400 envelope `details[]`),
	 * hence every literal goes through t() (CR-SVC-37).
	 *
	 * @param array $taskData The task data to validate
	 * @param bool $requireName Whether the name is mandatory (create path)
	 */
	public function validateTaskData(array $taskData, bool $requireName = true): array {
		$errors = [];

		// Name: strictly a string, non-blank when required (no coercion). A
		// present-but-null name is invalid on every path (key presence, not
		// value truthiness — `?? null` cannot distinguish the two).
		$hasName = array_key_exists('name', $taskData);
		$name = $taskData['name'] ?? null;
		if ($name !== null && !is_string($name)) {
			$errors[] = $this->typeError('name');
		} elseif ($requireName || $hasName) {
			if (!is_string($name) || trim($name) === '') {
				$errors[] = $this->l10n->t('Task name is required');
			}
		}

		// Integer-ranged fields (strict int, no coercion).
		foreach (['energyCost', 'priority', 'estimatedMinutes', 'frequencyValue'] as $field) {
			if (isset($taskData[$field]) && !is_int($taskData[$field])) {
				$errors[] = $this->typeError($field);
			}
		}

		if (isset($taskData['energyCost']) && is_int($taskData['energyCost'])
			&& ($taskData['energyCost'] < 1 || $taskData['energyCost'] > 5)) {
			$errors[] = $this->l10n->t('Energy cost must be between 1 and 5');
		}

		if (isset($taskData['priority']) && is_int($taskData['priority'])
			&& ($taskData['priority'] < 1 || $taskData['priority'] > 3)) {
			$errors[] = $this->l10n->t('Priority must be between 1 and 3');
		}

		if (isset($taskData['estimatedMinutes']) && is_int($taskData['estimatedMinutes'])
			&& ($taskData['estimatedMinutes'] < 1 || $taskData['estimatedMinutes'] > 480)) {
			$errors[] = $this->l10n->t('Estimated time must be between 1 and 480 minutes');
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
			if (!in_array($taskData['frequencyType'], $validTypes, true)) {
				$errors[] = $this->l10n->t('Invalid frequency type');
			}
		}

		// Frequency value validation
		if (isset($taskData['frequencyValue']) && is_int($taskData['frequencyValue']) && $taskData['frequencyValue'] < 1) {
			$errors[] = $this->l10n->t('Frequency value must be at least 1');
		}

		// Scope type validation
		if (isset($taskData['scopeType'])) {
			$validScopeTypes = ['room', 'floor', 'house'];
			if (!in_array($taskData['scopeType'], $validScopeTypes, true)) {
				$errors[] = $this->l10n->t('Invalid scope type');
			}
		}

		// Recurrence mode validation (canonical Task::RECURRENCE_* vocabulary)
		if (isset($taskData['recurrenceMode'])) {
			if (!in_array($taskData['recurrenceMode'], Task::RECURRENCE_MODES, true)) {
				$errors[] = $this->l10n->t('Invalid recurrence mode');
			}
		}

		// Scheduling mode validation (canonical Task::SCHEDULING_MODES set,
		// CS-PLAN-063 §3.1 — previously unchecked on every create path).
		if (isset($taskData['schedulingMode'])) {
			if (!in_array($taskData['schedulingMode'], Task::SCHEDULING_MODES, true)) {
				$errors[] = $this->l10n->t('Invalid scheduling mode');
			}
		}

		$this->validateDateFields($taskData, $errors);
		$this->validateFixedScheduleFields($taskData, $errors);
		$this->validateFlexAndTextFields($taskData, $errors);

		// Visibility type validation
		if (isset($taskData['visibilityType'])) {
			if (!in_array($taskData['visibilityType'], Task::VISIBILITY_TYPES, true)) {
				$errors[] = $this->l10n->t('Invalid visibility type');
			}
		}

		return $errors;
	}

	/**
	 * Date-field validation: activeFrom/activeUntil must be parseable date
	 * strings (never coerced from non-strings), and the range must not be
	 * inverted.
	 *
	 * @param array $taskData The task data to validate
	 * @param string[] $errors Error accumulator
	 */
	private function validateDateFields(array $taskData, array &$errors): void {
		$parsed = [];
		foreach (['activeFrom' => 'Invalid active from date', 'activeUntil' => 'Invalid active until date'] as $field => $message) {
			$value = $taskData[$field] ?? null;
			// Empty strings mean "not set" (the client form sends blanks).
			if ($value === null || $value === '') {
				continue;
			}
			if (!is_string($value)) {
				$errors[] = $this->typeError($field);
				continue;
			}
			try {
				$parsed[$field] = new \DateTime($value);
			} catch (\Exception $e) {
				$errors[] = $this->l10n->t($message);
			}
		}

		if (isset($parsed['activeFrom'], $parsed['activeUntil']) && $parsed['activeUntil'] < $parsed['activeFrom']) {
			$errors[] = $this->l10n->t('Active until must be equal or after active from');
		}
	}

	/**
	 * Fixed-schedule validation: fixedScheduleTime must be a parseable time
	 * string; fixedScheduleDays must be a weekday list (PHP array or its
	 * canonical JSON string form) over the closed {@see WEEKDAYS} set.
	 *
	 * @param array $taskData The task data to validate
	 * @param string[] $errors Error accumulator
	 */
	private function validateFixedScheduleFields(array $taskData, array &$errors): void {
		$fixedTime = $taskData['fixedScheduleTime'] ?? null;
		if ($fixedTime !== null && $fixedTime !== '') {
			if (!is_string($fixedTime)) {
				$errors[] = $this->typeError('fixedScheduleTime');
			} else {
				try {
					new \DateTime($fixedTime);
				} catch (\Exception $e) {
					$errors[] = $this->l10n->t('Invalid fixed schedule time');
				}
			}
		}

		$fixedDays = $taskData['fixedScheduleDays'] ?? null;
		if ($fixedDays === null || $fixedDays === '') {
			return;
		}

		$decoded = $fixedDays;
		if (is_string($fixedDays)) {
			$decoded = json_decode($fixedDays, true);
			if (!is_array($decoded)) {
				$errors[] = $this->l10n->t('Invalid fixed schedule days');
				return;
			}
		}

		if (!is_array($decoded)) {
			$errors[] = $this->typeError('fixedScheduleDays');
			return;
		}

		foreach ($decoded as $day) {
			if (!is_string($day) || !in_array(strtolower($day), self::WEEKDAYS, true)) {
				$errors[] = $this->l10n->t('Invalid fixed schedule days');
				return;
			}
		}
	}

	/**
	 * Flex-window and free-text validation: flexDays is an integer within
	 * the ratified [0, 7] bound (CS-PLAN-063 D3); instructions/tips are
	 * length-capped strings.
	 *
	 * @param array $taskData The task data to validate
	 * @param string[] $errors Error accumulator
	 */
	private function validateFlexAndTextFields(array $taskData, array &$errors): void {
		$flexDays = $taskData['flexDays'] ?? null;
		if ($flexDays !== null) {
			if (!is_int($flexDays)) {
				$errors[] = $this->typeError('flexDays');
			} elseif ($flexDays < self::FLEX_DAYS_MIN || $flexDays > self::FLEX_DAYS_MAX) {
				$errors[] = $this->l10n->t('Flex days must be between %1$d and %2$d', [self::FLEX_DAYS_MIN, self::FLEX_DAYS_MAX]);
			}
		}

		foreach (['instructions' => 'Instructions', 'tips' => 'Tips'] as $field => $label) {
			$value = $taskData[$field] ?? null;
			if ($value === null) {
				continue;
			}
			if (!is_string($value)) {
				$errors[] = $this->typeError($field);
			} elseif (mb_strlen($value) > self::MAX_TEXT_LENGTH) {
				$errors[] = $this->l10n->t('%1$s must not exceed %2$d characters', [$label, self::MAX_TEXT_LENGTH]);
			}
		}
	}

	/**
	 * Localized "wrong value type" message for one field (strict 400s, no
	 * coercion — CS-PLAN-063 §3.1).
	 */
	private function typeError(string $field): string {
		return $this->l10n->t('Invalid value type for field: %1$s', [$field]);
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
