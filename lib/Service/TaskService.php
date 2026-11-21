<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Exception\ValidationException;
use OCP\IL10N;

/**
 * Service for task-related business logic
 */
class TaskService {
	/**
	 * The definition fields of the one form (CS-PLAN-091 WP-B6), the closed
	 * set of its two writes: the creation aggregate and the edit aggregate.
	 * Any other key is refused (mass-assignment defense, → 400). The owner
	 * of a private task is never sent: {@see prepareUpdate()} claims it.
	 */
	public const FORM_FIELDS = [
		'name', 'description', 'energyCost', 'priority',
		'frequencyType', 'frequencyValue', 'estimatedMinutes', 'category',
		'scopeType', 'recurrenceMode', 'activeFrom', 'activeUntil', 'isVoluntary',
		'schedulingMode', 'fixedScheduleTime', 'fixedScheduleDays', 'fixedMonthDay',
		'flexDays', 'instructions', 'tips', 'visibilityType',
	];

	/** Fields whose value is a date and must be parsed from string input. */
	private const DATE_FIELDS = ['activeFrom', 'activeUntil', 'fixedScheduleTime'];

	/** Optional household text: an empty value is no text, stored as null. */
	private const OPTIONAL_TEXT_FIELDS = ['description', 'instructions', 'tips'];

	/** Hard length cap for the free-text description, instruction and tip fields (CS-PLAN-063 §3.1, D-091-111). */
	public const MAX_TEXT_LENGTH = 2000;

	/** The name fits its column (D-091-111). */
	public const MAX_NAME_LENGTH = 255;

	/** Lower flex-window bound; the upper bound is the rule's cap ({@see FlexDaysPolicy}, FEAT-UX-039). */
	public const FLEX_DAYS_MIN = 0;

	/** Canonical weekday names accepted in fixedScheduleDays. */
	public const WEEKDAYS = [
		'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
	];

	public function __construct(
		private TaskMapper $taskMapper,
		private RoomTaskMapper $roomTaskMapper,
		private IL10N $l10n,
		private AuthorizationService $authorization,
		private TaskAccessPolicy $taskAccessPolicy,
		private CycleService $cycleService,
		private TaskCategoryService $categories,
	) {
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
	 * The gate of a definition edit (CS-PLAN-091 WP-B6): visibility, role,
	 * the closed field set and its values — everything decided before a
	 * lock. The one form's save ({@see RoomTaskEditService}) runs it and
	 * then writes under its own house-first lock.
	 *
	 * A caller that cannot see the task gets the 404 anti-enumeration
	 * denial (ADR-010); a child gets 403 (CS-PLAN-061 D5); admin-only
	 * visibility is set and unset by the owner only (RF-058-03). A task that
	 * becomes private, or a private task without an owner, is claimed by
	 * the actor so it never ends up invisible to everybody; an edit of a
	 * private task by one of its assignees leaves its owner as it is.
	 *
	 * @param array<string, mixed> $updates Definition fields of the form ({@see FORM_FIELDS})
	 * @return array<string, mixed> The accepted updates, an ownership claim included
	 * @throws NotFoundException if the task is invisible to the actor
	 * @throws ForbiddenException if the actor is a child, or a member touches admin-only visibility
	 * @throws ValidationException on an unknown field or a value outside the constraint set (400)
	 */
	public function prepareUpdate(Task $task, array $updates, HouseUser $actor): array {
		if (!$this->taskAccessPolicy->canViewTask($task, $actor)) {
			throw new NotFoundException($this->l10n->t('Task not found'));
		}
		$this->authorization->assertCanManageTasks($actor);

		foreach (array_keys($updates) as $field) {
			if (!in_array($field, self::FORM_FIELDS, true)) {
				throw new ValidationException($this->l10n->t('Unknown or immutable field: %1$s', [$field]));
			}
		}
		$this->assertVisibilityRight($task, $updates, $actor);

		$errors = $this->validateTaskData($updates, requireName: false);
		if ($errors !== []) {
			throw new ValidationException($errors[0], $errors);
		}

		$visibility = $updates['visibilityType'] ?? $task->getVisibilityType();
		$becomesPrivate = $visibility === Task::VISIBILITY_PRIVATE
			&& ($task->getVisibilityType() !== Task::VISIBILITY_PRIVATE || $task->getOwnerUserId() === null);
		if ($becomesPrivate) {
			$updates['ownerUserId'] = $actor->getNextcloudUserId();
		}
		return $updates;
	}

	/**
	 * Who may change the visibility. A private task's is its owner's alone
	 * (D-091-109): an assignee can neither open it to the house nor claim
	 * it by a round trip through public; a private task without an owner
	 * keeps the claim rule of {@see prepareUpdate()}. Admin-only visibility
	 * is owner-only (ADR-010 matrix, RF-058-03): setting AND unsetting it is
	 * a role denial (403) for anyone else. The unchanged value the form
	 * sends is no change.
	 *
	 * @throws ForbiddenException
	 */
	private function assertVisibilityRight(Task $task, array $updates, HouseUser $actor): void {
		if (!array_key_exists('visibilityType', $updates) || $updates['visibilityType'] === $task->getVisibilityType()) {
			return;
		}
		if ($task->getVisibilityType() === Task::VISIBILITY_PRIVATE && $task->getOwnerUserId() !== null
			&& $task->getOwnerUserId() !== $actor->getNextcloudUserId()) {
			throw new ForbiddenException($this->l10n->t('Only the owner of a private task can change its visibility'));
		}
		if ($actor->isOwner()) {
			return;
		}
		if ($updates['visibilityType'] === Task::VISIBILITY_ADMIN_ONLY) {
			throw new ForbiddenException($this->l10n->t('Only house owners can set admin-only visibility'));
		}
		if ($task->getVisibilityType() === Task::VISIBILITY_ADMIN_ONLY) {
			throw new ForbiddenException($this->l10n->t('Only house owners can change admin-only task visibility'));
		}
	}

	/**
	 * Apply prepared updates UNDER LOCK to a definition row the caller
	 * locked (house first, then the definition): the flex cap of the merged
	 * rule (FEAT-UX-039) and the write. `$before` is the definition the
	 * values replace — the row itself, or the shared definition a split
	 * copied (CS-PLAN-091 WP-B6).
	 *
	 * @param array<string, mixed> $updates From {@see prepareUpdate()}
	 * @throws ValidationException when a changed rule input breaks the flex cap
	 */
	public function applyUnderLock(Task $target, Task $before, array $updates): Task {
		$this->applyUpdates($target, $updates);
		$this->assertFlexWithinCap($before, $target);
		return $this->taskMapper->update($target);
	}

	/**
	 * The prepared updates that would change the definition: the form
	 * sends every field, and a save that changes none of them leaves a
	 * definition several rooms share as it is. An empty optional text and
	 * no text are the same (a stored empty string included).
	 *
	 * @param array<string, mixed> $updates From {@see prepareUpdate()}
	 * @return array<string, mixed>
	 */
	public function changedFields(Task $task, array $updates): array {
		$probe = clone $task;
		$this->applyUpdates($probe, $updates);
		$value = static function (Task $entity, string $field): mixed {
			$stored = $entity->{'get' . ucfirst($field)}();
			if (in_array($field, self::OPTIONAL_TEXT_FIELDS, true) && $stored === '') {
				return null;
			}
			return $stored instanceof \DateTimeInterface ? $stored->format('Y-m-d H:i:s') : $stored;
		};
		return array_filter(
			$updates,
			static fn (string $field): bool => $value($task, $field) !== $value($probe, $field),
			ARRAY_FILTER_USE_KEY,
		);
	}

	/**
	 * Move every mounted room task's current cycle after a rule-relevant
	 * edit UNDER LOCK (ADR-021 decision 1): the changed rule, flex or energy
	 * shows at once, the cycle version bumps and `next_due` follows. The
	 * anchor policy of a rule change (D-091-28) belongs to
	 * {@see CycleService::ruleChangedUnderLock()}.
	 *
	 * @param int[] $exceptRoomTaskIds Room tasks the caller moves itself
	 * @param \OCA\ChoreStrike\Db\RoomTask[]|null $mounted The definition's room tasks when the caller read them under the same lock
	 */
	public function reviseMountedUnderLock(Task $before, Task $updated, int $houseId, \DateTimeZone $timeZone, \DateTime $now, array $exceptRoomTaskIds = [], ?array $mounted = null): void {
		if (!self::ruleRelevantInputsChanged($before, $updated)) {
			return;
		}
		$mounted = array_values(array_filter(
			$mounted ?? $this->roomTaskMapper->findByTaskIdScopedToHouse((int)$updated->getId(), $houseId),
			static fn ($roomTask): bool => !in_array((int)$roomTask->getId(), $exceptRoomTaskIds, true),
		));
		if ($mounted === []) {
			return;
		}
		// One lock statement and one read of the completion inputs for every
		// mounted room task; the house lock the edit holds keeps completions
		// out meanwhile.
		$ids = array_map(static fn ($roomTask): int => (int)$roomTask->getId(), $mounted);
		$histories = $this->cycleService->historiesFor($ids);
		foreach ($this->roomTaskMapper->lockForUpdateByIds($ids) as $lockedRoomTask) {
			if ($lockedRoomTask->getDeletedAt() !== null) {
				continue;
			}
			$this->cycleService->ruleChangedUnderLock($lockedRoomTask, $before, $updated, $timeZone, $now, $histories);
		}
	}

	/**
	 * Whether any rule-relevant generation input of the definition changed
	 * (CS-PLAN-072 WP1 §3.2 rule 4): energy, cadence, lifetime, active
	 * bounds, window days, voluntary/public classification. Purely
	 * descriptive edits (name, description, priority, tips) never force a
	 * revision.
	 */
	public static function ruleRelevantInputsChanged(Task $before, Task $after): bool {
		return self::flexCapInputsChanged($before, $after)
			|| $before->getEnergyCost() !== $after->getEnergyCost()
			|| $before->getFixedScheduleTime()?->format('H:i') !== $after->getFixedScheduleTime()?->format('H:i')
			|| $before->getFixedScheduleDays() !== $after->getFixedScheduleDays()
			|| $before->getFixedMonthDay() !== $after->getFixedMonthDay()
			|| $before->getActiveFrom()?->format('Y-m-d') !== $after->getActiveFrom()?->format('Y-m-d')
			|| $before->getActiveUntil()?->format('Y-m-d') !== $after->getActiveUntil()?->format('Y-m-d')
			|| $before->getIsVoluntary() !== $after->getIsVoluntary()
			|| $before->getVisibilityType() !== $after->getVisibilityType();
	}

	/**
	 * The inputs of the flex cap (FEAT-UX-039, {@see FlexDaysPolicy}): the
	 * flex value, cadence, recurrence and scheduling mode, and the fixed
	 * weekdays — one list for the cap check and the revision trigger.
	 */
	private static function flexCapInputsChanged(Task $before, Task $after): bool {
		$weekdays = static fn (Task $task): array => FlexDaysPolicy::fixedWeekdays(Task::SCHEDULING_STATIC, $task->getFixedScheduleDays());
		return $before->getFlexDays() !== $after->getFlexDays()
			|| $before->getFrequencyType() !== $after->getFrequencyType()
			|| $before->getFrequencyValue() !== $after->getFrequencyValue()
			|| $before->getRecurrenceMode() !== $after->getRecurrenceMode()
			|| $before->getSchedulingMode() !== $after->getSchedulingMode()
			|| $weekdays($before) !== $weekdays($after);
	}

	/**
	 * FEAT-UX-039: a changed flex value or rule input must fit the merged
	 * rule's cap. A stored value above the cap stays valid while neither
	 * changes, so unrelated edits of a legacy definition still save.
	 *
	 * @throws ValidationException
	 */
	private function assertFlexWithinCap(Task $before, Task $after): void {
		$max = FlexDaysPolicy::maxForTask($after);
		if (self::flexCapInputsChanged($before, $after) && (int)$after->getFlexDays() > $max) {
			$message = $this->flexRangeError($max);
			throw new ValidationException($message, [$message]);
		}
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
			// cannot be used as a gate. The FORM_FIELDS whitelist above is
			// the authoritative guard; the Entity setter applies the value.
			$method = 'set' . ucfirst($field);
			$task->$method(self::storedValue($field, $value));
		}
	}

	/**
	 * A definition value as it is stored, for creation and edit alike
	 * (D-091-100): a date field parsed, where an empty string clears it
	 * (validation guaranteed parseability); an empty optional text as no
	 * text; the weekday list of a fixed rule in its canonical JSON.
	 */
	public static function storedValue(string $field, mixed $value): mixed {
		if (in_array($field, self::DATE_FIELDS, true)) {
			return $value === '' ? null : (is_string($value) ? new \DateTime($value) : $value);
		}
		if (in_array($field, self::OPTIONAL_TEXT_FIELDS, true)) {
			return $value === '' ? null : $value;
		}
		if ($field === 'fixedScheduleDays') {
			return $value === '' ? null : (is_array($value) ? json_encode(array_values($value)) : $value);
		}
		return $value;
	}

	/**
	 * Validate task data — the single creation/update constraint authority
	 * (CS-PLAN-063 §3.1). Invoked by services ({@see prepareUpdate()},
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
			} elseif (mb_strlen($name) > self::MAX_NAME_LENGTH) {
				$errors[] = $this->l10n->t('Task name must be at most %1$d characters', [self::MAX_NAME_LENGTH]);
			}
		}

		// Integer-ranged fields (strict int, no coercion).
		foreach (['energyCost', 'priority', 'estimatedMinutes', 'frequencyValue'] as $field) {
			if (isset($taskData[$field]) && !is_int($taskData[$field])) {
				$errors[] = $this->typeError($field);
			}
		}

		if (isset($taskData['energyCost']) && is_int($taskData['energyCost'])
			&& ($taskData['energyCost'] < EffortAllocationService::ENERGY_MIN || $taskData['energyCost'] > EffortAllocationService::ENERGY_MAX)) {
			$errors[] = $this->l10n->t('Energy cost must be between %1$s and %2$s', [EffortAllocationService::ENERGY_MIN, EffortAllocationService::ENERGY_MAX]);
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

		// Only a key of the household's current list (D-090-65); a retained
		// row with a deleted key is never rewritten, but cannot be written.
		if (array_key_exists('category', $taskData)
			&& (!is_string($taskData['category']) || !$this->categories->isKnown($taskData['category']))) {
			$errors[] = $this->l10n->t('Unknown task category');
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
		// The creation path validates a complete definition (defaults merged),
		// so its rule cap applies here; partial updates only get the sanity
		// bound and check the merged entity in assertFlexWithinCap().
		$maxFlexDays = $requireName ? $this->flexCapOfDefinition($taskData) : FlexDaysPolicy::SANITY_MAX;
		$this->validateFlexAndTextFields($taskData, $errors, $maxFlexDays);

		// Visibility type validation
		if (isset($taskData['visibilityType'])) {
			if (!in_array($taskData['visibilityType'], Task::VISIBILITY_TYPES, true)) {
				$errors[] = $this->l10n->t('Invalid visibility type');
			}
		}

		// The bonus flag (ADR-021 decision 8) is a boolean, never coerced.
		if (array_key_exists('isVoluntary', $taskData) && !is_bool($taskData['isVoluntary'])) {
			$errors[] = $this->typeError('isVoluntary');
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
	 * string; fixedMonthDay a day 1–31; fixedScheduleDays must be a weekday list (PHP array or its
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

		// ADR-021 decision 1: the day of a fixed monthly rule; a shorter
		// month uses its last day.
		$monthDay = $taskData['fixedMonthDay'] ?? null;
		if ($monthDay !== null) {
			if (!is_int($monthDay)) {
				$errors[] = $this->typeError('fixedMonthDay');
			} elseif ($monthDay < 1 || $monthDay > 31) {
				$errors[] = $this->l10n->t('The day of the month must be between 1 and 31');
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
	 * [0, $maxFlexDays] (FEAT-UX-039: the rule's cap, formerly a fixed 7);
	 * instructions/tips are length-capped strings.
	 *
	 * @param array $taskData The task data to validate
	 * @param string[] $errors Error accumulator
	 * @param int $maxFlexDays Upper flex bound for this payload
	 */
	private function validateFlexAndTextFields(array $taskData, array &$errors, int $maxFlexDays): void {
		$flexDays = $taskData['flexDays'] ?? null;
		if ($flexDays !== null) {
			if (!is_int($flexDays)) {
				$errors[] = $this->typeError('flexDays');
			} elseif ($flexDays < self::FLEX_DAYS_MIN || $flexDays > $maxFlexDays) {
				$errors[] = $this->flexRangeError($maxFlexDays);
			}
		}

		foreach (['description' => 'Description', 'instructions' => 'Instructions', 'tips' => 'Tips'] as $field => $label) {
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

	private function flexRangeError(int $maxFlexDays): string {
		return $this->l10n->t('Flex days must be between %1$d and %2$d', [self::FLEX_DAYS_MIN, $maxFlexDays]);
	}

	/**
	 * The flex cap of a complete creation definition; malformed rule
	 * values (already reported) fall back to the creation defaults.
	 */
	private function flexCapOfDefinition(array $taskData): int {
		$frequencyType = $taskData['frequencyType'] ?? 'weekly';
		$frequencyValue = $taskData['frequencyValue'] ?? 1;
		$recurrenceMode = $taskData['recurrenceMode'] ?? Task::RECURRENCE_RECURRING;
		$schedulingMode = $taskData['schedulingMode'] ?? Task::SCHEDULING_DYNAMIC;
		return FlexDaysPolicy::maxFlexDays(
			is_string($frequencyType) ? $frequencyType : 'weekly',
			is_int($frequencyValue) ? $frequencyValue : 1,
			is_string($recurrenceMode) ? $recurrenceMode : Task::RECURRENCE_RECURRING,
			FlexDaysPolicy::fixedWeekdays(is_string($schedulingMode) ? $schedulingMode : '', $taskData['fixedScheduleDays'] ?? null),
		);
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
