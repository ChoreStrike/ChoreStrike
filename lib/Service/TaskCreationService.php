<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssignee;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Owns the atomic task-creation aggregate (CS-PLAN-063 §3.2, CR-WF-03):
 * one service-owned transaction writes the task definition, the room-task
 * materialization (explicit nextDue, flex-aware idealDueDate), and the
 * initial assignee join rows — or nothing at all.
 *
 * Both user-facing create paths run through {@see create()}:
 * the custom path (inline `task` definition payload) and the mount path
 * (existing `taskId`), while {@see createFromTemplate()} materializes a
 * catalog template and delegates to the same body. Every variant returns
 * the one aggregate response shape `{task, roomTask, assignees}` (D1).
 *
 * Transaction doctrine (ADR-007, WP3 precedent): the transaction takes the
 * house row lock first (the documented first lock — house → room_task →
 * dependents), revalidates every distinct assignee membership and the
 * mount-duplicate guard under the lock, then performs fresh inserts only.
 * A membership removed after the pre-transaction checks rolls the whole
 * aggregate back — no orphan task definitions, ever.
 *
 * Authorization preamble (never bypassable): children cannot create
 * (CS-PLAN-061 D5), the room must belong to the caller's house (404
 * anti-enumeration), the definition must be visible to the actor
 * (ADR-010 matrix), admin-only creation is owner-gated (403), and
 * private creation stamps the creator as `ownerUserId`.
 */
class TaskCreationService {
	/** Definition fields accepted on the custom create path (closed set). */
	private const DEFINITION_FIELDS = [
		'name', 'description', 'energyCost', 'priority',
		'frequencyType', 'frequencyValue', 'estimatedMinutes', 'category',
		'scopeType', 'recurrenceMode', 'activeFrom', 'activeUntil',
		'schedulingMode', 'fixedScheduleTime', 'fixedScheduleDays',
		'flexDays', 'instructions', 'tips', 'visibilityType',
	];

	private TransactionExecutor $transactions;
	private TaskMapper $taskMapper;
	private RoomTaskMapper $roomTaskMapper;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private HouseMapper $houseMapper;
	private HouseUserMapper $houseUserMapper;
	private RoomService $roomService;
	private TaskService $taskService;
	private TemplateService $templateService;
	private TaskAccessPolicy $taskAccessPolicy;
	private AuthorizationService $authorization;
	private IL10N $l10n;
	private AppLogger $appLogger;

	public function __construct(
		TransactionExecutor $transactions,
		TaskMapper $taskMapper,
		RoomTaskMapper $roomTaskMapper,
		TaskAssigneeMapper $taskAssigneeMapper,
		HouseMapper $houseMapper,
		HouseUserMapper $houseUserMapper,
		RoomService $roomService,
		TaskService $taskService,
		TemplateService $templateService,
		TaskAccessPolicy $taskAccessPolicy,
		AuthorizationService $authorization,
		IL10N $l10n,
		AppLogger $appLogger,
	) {
		$this->transactions = $transactions;
		$this->taskMapper = $taskMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->taskAssigneeMapper = $taskAssigneeMapper;
		$this->houseMapper = $houseMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->roomService = $roomService;
		$this->taskService = $taskService;
		$this->templateService = $templateService;
		$this->taskAccessPolicy = $taskAccessPolicy;
		$this->authorization = $authorization;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
	}

	/**
	 * Create one visible, valid room task — or nothing at all.
	 *
	 * @param int $houseId The caller's house ID (scopes the target room)
	 * @param HouseUser $actor The acting user's house membership
	 * @param array $payload Either `{task: {...definition}, roomId, assigneeUserIds?, nextDue?}`
	 *                       (custom) or `{taskId, roomId, assigneeUserIds?, nextDue?}` (mount)
	 * @return array{task: Task, roomTask: RoomTask, assignees: TaskAssignee[]}
	 *
	 * @throws ValidationException if the definition fails the closed constraint set (400)
	 * @throws NotFoundException if the room, task, or its visibility denies the actor (404)
	 * @throws \OCA\ChoreStrike\Exception\ForbiddenException if the actor is a child or member + admin-only (403)
	 * @throws \InvalidArgumentException on a bad payload variant, non-member assignee, invalid nextDue, or duplicate mount (400)
	 */
	public function create(int $houseId, HouseUser $actor, array $payload): array {
		[$roomId, $assigneeUserIds, $nextDue] = $this->validatePayloadEnvelope($payload);

		// Actor policy (CS-PLAN-061 D5): children execute tasks, they do not
		// create them (frontend entry-point gating is CS-PLAN-063 D6).
		$this->authorization->assertCanManageTasks($actor);

		// CR-SVC-01: the room is resolved through the floor chain and must
		// belong to the caller's house — a miss is the 404 anti-enumeration
		// denial (never a hint that the room exists elsewhere). The denial
		// is logged as a security event with stable context keys.
		try {
			$this->roomService->validateRoomOwnership($roomId, $houseId);
		} catch (NotFoundException $e) {
			$this->appLogger->securityWarning('Cross-house room task creation denied', [
				'service' => 'TaskCreationService',
				'roomId' => $roomId,
				'houseId' => $houseId,
			]);
			throw $e;
		}

		if (array_key_exists('task', $payload)) {
			$definition = $this->materializeDefinition($payload['task']);
		} else {
			$definition = $this->materializeExistingTask((int)$payload['taskId'], $actor);
		}

		[$visibilityType, $ownerUserId] = $this->resolveCreationVisibility($definition, $actor);

		return $this->runAggregate($houseId, $actor, $roomId, $definition, $visibilityType, $ownerUserId, $assigneeUserIds, $nextDue);
	}

	/**
	 * Create a room task from a catalog template.
	 *
	 * Materializes the template at the given cleaning level (default
	 * putzteufel), applies whitelisted overrides, and runs the same atomic
	 * {@see create()} body — the template path shares the exact validation,
	 * gates, and response shape of the custom path (no bypass, D1). The
	 * scalar assignee is normalized to a one-element set (setup parity).
	 *
	 * @param int $houseId The caller's house ID (scopes the target room)
	 * @param HouseUser $actor The acting user's house membership
	 * @param int $roomId The room ID (resolved + validated server-side)
	 * @param string $templateId The catalog template ID
	 * @param string|null $level One of TemplateService::LEVELS (default putzteufel)
	 * @param array $overrides Optional materialization overrides
	 * @param string|null $assigneeUserId Optional assignee (must be a house member)
	 * @return array{task: Task, roomTask: RoomTask, assignees: TaskAssignee[]}
	 */
	public function createFromTemplate(
		int $houseId,
		HouseUser $actor,
		int $roomId,
		string $templateId,
		?string $level,
		array $overrides,
		?string $assigneeUserId,
	): array {
		$materialized = $this->templateService->materializeTemplate(
			$templateId,
			$level ?? TemplateService::LEVEL_PUTZTEUFEL,
			$overrides,
		);

		return $this->create($houseId, $actor, [
			'roomId' => $roomId,
			'task' => $materialized,
			'assigneeUserIds' => ($assigneeUserId !== null && $assigneeUserId !== '') ? [$assigneeUserId] : [],
		]);
	}

	/**
	 * Validate the shared envelope: room id, assignee list, and nextDue.
	 *
	 * @return array{0: int, 1: string[], 2: \DateTime|null}
	 */
	private function validatePayloadEnvelope(array $payload): array {
		$roomId = $payload['roomId'] ?? null;
		if (!is_int($roomId) || $roomId < 1) {
			throw new \InvalidArgumentException($this->l10n->t('roomId is required'));
		}

		$hasTask = array_key_exists('task', $payload);
		$hasTaskId = array_key_exists('taskId', $payload);
		if ($hasTask === $hasTaskId) {
			throw new \InvalidArgumentException(
				$this->l10n->t('Exactly one of task or taskId is required')
			);
		}
		if ($hasTaskId && (!is_int($payload['taskId']) || $payload['taskId'] < 1)) {
			throw new \InvalidArgumentException($this->l10n->t('Invalid value type for field: %1$s', ['taskId']));
		}

		$rawAssignees = $payload['assigneeUserIds'] ?? [];
		if (!is_array($rawAssignees)) {
			throw new \InvalidArgumentException($this->l10n->t('Invalid value type for field: %1$s', ['assigneeUserIds']));
		}
		$assigneeUserIds = array_values(array_unique(array_filter(
			$rawAssignees,
			fn ($id) => is_string($id) && trim($id) !== ''
		)));

		return [$roomId, $assigneeUserIds, $this->parseNextDue($payload['nextDue'] ?? null)];
	}

	/**
	 * Parse the optional creation due date (house-local date, ADR-009
	 * semantics) — parseable strings only, never coerced (→ 400).
	 */
	private function parseNextDue($nextDue): ?\DateTime {
		if ($nextDue === null || $nextDue === '') {
			return null;
		}
		if (!is_string($nextDue)) {
			throw new \InvalidArgumentException($this->l10n->t('Invalid value type for field: %1$s', ['nextDue']));
		}
		try {
			return new \DateTime($nextDue);
		} catch (\Exception $e) {
			throw new \InvalidArgumentException($this->l10n->t('Invalid nextDue date format'));
		}
	}

	/**
	 * Normalize and validate the custom-path definition against the one
	 * closed constraint set (CS-PLAN-063 §3.1): unknown fields are rejected,
	 * absent optionals fall back to the canonical defaults, and every value
	 * must pass {@see TaskService::validateTaskData} (the template path runs
	 * through the same gate — no bypass).
	 */
	private function materializeDefinition($raw): array {
		if (!is_array($raw)) {
			throw new ValidationException($this->l10n->t('Invalid value type for field: %1$s', ['task']));
		}

		foreach (array_keys($raw) as $field) {
			if (!in_array($field, self::DEFINITION_FIELDS, true)) {
				throw new ValidationException($this->l10n->t('Unknown or immutable field: %1$s', [$field]));
			}
		}

		$definition = array_merge([
			'description' => null,
			'energyCost' => 1,
			'priority' => 2,
			'frequencyType' => 'weekly',
			'frequencyValue' => 1,
			'estimatedMinutes' => 15,
			'category' => 'cleaning',
			'recurrenceMode' => Task::RECURRENCE_RECURRING,
			'schedulingMode' => Task::SCHEDULING_DYNAMIC,
			'flexDays' => 0,
			'visibilityType' => Task::VISIBILITY_PUBLIC,
		], $raw);

		$errors = $this->taskService->validateTaskData($definition);
		if ($errors !== []) {
			throw new ValidationException($errors[0], $errors);
		}

		return $definition;
	}

	/**
	 * Resolve the mount variant: the existing definition is re-read and must
	 * be visible to the actor (ADR-010) — an invisible or unknown id is the
	 * 404 anti-enumeration denial.
	 */
	private function materializeExistingTask(int $taskId, HouseUser $actor): array {
		try {
			$task = $this->taskMapper->find($taskId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Task not found'));
		}

		$this->taskAccessPolicy->assertCanViewTask($task, $actor);

		return [
			'name' => $task->getName(),
			'flexDays' => $task->getFlexDays(),
			'visibilityType' => $task->getVisibilityType(),
			// Mount never re-inserts the definition — carried via taskId.
			'__existingTaskId' => $taskId,
		];
	}

	/**
	 * Creation visibility rules (CS-PLAN-061 §3.4): admin-only creation is
	 * owner-gated (403 role denial) and private creation stamps the creator
	 * as ownerUserId.
	 *
	 * @return array{0: string, 1: ?string}
	 */
	private function resolveCreationVisibility(array $definition, HouseUser $actor): array {
		$visibilityType = $definition['visibilityType'] ?? Task::VISIBILITY_PUBLIC;

		if ($visibilityType === Task::VISIBILITY_ADMIN_ONLY && !$actor->isOwner()) {
			throw new \OCA\ChoreStrike\Exception\ForbiddenException(
				$this->l10n->t('Only house owners can create admin-only tasks')
			);
		}

		$ownerUserId = null;
		if ($visibilityType === Task::VISIBILITY_PRIVATE) {
			$ownerUserId = $actor->getNextcloudUserId();
		}

		return [$visibilityType, $ownerUserId];
	}

	/**
	 * The aggregate transaction: house lock first (documented order), then
	 * under-lock membership revalidation, the mount-duplicate recheck, and
	 * the three canonical writes — definition, room task, initial assignees.
	 * Any failure rolls back every write.
	 *
	 * @param string[] $assigneeUserIds
	 * @return array{task: Task, roomTask: RoomTask, assignees: TaskAssignee[]}
	 */
	private function runAggregate(
		int $houseId,
		HouseUser $actor,
		int $roomId,
		array $definition,
		string $visibilityType,
		?string $ownerUserId,
		array $assigneeUserIds,
		?\DateTime $nextDue,
	): array {
		$existingTaskId = $definition['__existingTaskId'] ?? null;
		unset($definition['__existingTaskId']);

		[$task, $roomTask] = $this->transactions->run(
			function () use ($houseId, $actor, $roomId, $definition, $visibilityType, $ownerUserId, $assigneeUserIds, $nextDue, $existingTaskId) {
				// First lock of the documented order (house → room_task →
				// dependents): serialize concurrent writes on the house row.
				$this->houseMapper->findForUpdate($houseId);

				// Membership revalidation at the transaction boundary: a
				// membership removed after the pre-transaction checks must
				// not leave assignee rows for a former member (→ rollback).
				foreach (array_unique($assigneeUserIds) as $userId) {
					if ($this->houseUserMapper->findByHouseAndUser($houseId, $userId) === null) {
						throw new \InvalidArgumentException(
							$this->l10n->t('User is not a member of this house: %1$s', [$userId])
						);
					}
				}

				// Mount-duplicate guard, rechecked under the lock so two
				// concurrent mounts cannot both pass the pre-check.
				if ($existingTaskId !== null && $this->roomTaskMapper->exists($roomId, $existingTaskId)) {
					throw new \InvalidArgumentException(
						$this->l10n->t('Task %1$d already exists in room %2$d', [$existingTaskId, $roomId])
					);
				}

				if ($existingTaskId !== null) {
					$task = $this->taskMapper->find($existingTaskId);
				} else {
					$task = $this->insertDefinition($definition, $visibilityType, $ownerUserId);
				}

				$roomTask = $this->materializeRoomTask($roomId, $task, $nextDue);

				foreach ($assigneeUserIds as $userId) {
					$this->taskAssigneeMapper->addAssignee($roomTask->getId(), $userId, $actor->getNextcloudUserId());
				}

				return [$task, $roomTask];
			}
		);

		return [
			'task' => $task,
			'roomTask' => $roomTask,
			'assignees' => $this->taskAssigneeMapper->findByRoomTask($roomTask->getId()),
		];
	}

	/**
	 * Insert the task definition via named arguments (replaces the
	 * 24-positional-param seam, CS-PLAN-063 §3.2).
	 */
	private function insertDefinition(array $definition, string $visibilityType, ?string $ownerUserId): Task {
		return $this->taskMapper->create(
			name: (string)$definition['name'],
			description: isset($definition['description']) ? (string)$definition['description'] : null,
			energyCost: (int)$definition['energyCost'],
			priority: (int)$definition['priority'],
			frequencyType: (string)$definition['frequencyType'],
			frequencyValue: (int)$definition['frequencyValue'],
			estimatedMinutes: (int)$definition['estimatedMinutes'],
			category: (string)$definition['category'],
			scopeType: (string)($definition['scopeType'] ?? 'room'),
			recurrenceMode: (string)($definition['recurrenceMode'] ?? Task::RECURRENCE_RECURRING),
			activeFrom: $this->toDate($definition['activeFrom'] ?? null),
			activeUntil: $this->toDate($definition['activeUntil'] ?? null),
			schedulingMode: (string)($definition['schedulingMode'] ?? Task::SCHEDULING_DYNAMIC),
			fixedScheduleTime: $this->toDate($definition['fixedScheduleTime'] ?? null),
			fixedScheduleDays: $this->toWeekdayJson($definition['fixedScheduleDays'] ?? null),
			instructions: isset($definition['instructions']) ? (string)$definition['instructions'] : null,
			tips: isset($definition['tips']) ? (string)$definition['tips'] : null,
			visibilityType: $visibilityType,
			ownerUserId: $ownerUserId,
			flexDays: (int)($definition['flexDays'] ?? 0),
		);
	}

	/**
	 * Materialize the room task: explicit nextDue (default tomorrow) and a
	 * flex-aware idealDueDate when the definition carries a flex window.
	 */
	private function materializeRoomTask(int $roomId, Task $task, ?\DateTime $nextDue): RoomTask {
		if ($nextDue === null) {
			$nextDue = new \DateTime();
			$nextDue->add(new \DateInterval('P1D'));
		}

		$flexDays = $task->getFlexDays();
		$idealDueDate = null;
		if ($flexDays > 0) {
			$idealDueDate = (new RoomTask())->calculateIdealDueDate($nextDue, $flexDays);
		}

		return $this->roomTaskMapper->create($roomId, $task->getId(), $nextDue, null, $idealDueDate);
	}

	/** Parse a validated date string (validation guarantees parseability). */
	private function toDate($value): ?\DateTime {
		if ($value === null || $value === '') {
			return null;
		}
		return new \DateTime((string)$value);
	}

	/** Persist weekday lists in their canonical JSON encoding. */
	private function toWeekdayJson($value): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		if (is_array($value)) {
			return json_encode(array_values($value));
		}
		return (string)$value;
	}
}
