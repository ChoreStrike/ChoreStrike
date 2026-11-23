<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssignee;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Exception\ValidationException;
use OCP\IL10N;

/**
 * Owns the atomic task-creation aggregate (CS-PLAN-063 §3.2, CR-WF-03), the
 * one form's create (CS-PLAN-091 WP-B6): one service-owned transaction
 * writes the task definition with its sub-tasks, the room task (its first
 * date anchors the derived window, ADR-021), the initial assignees and an
 * optional sleep — or nothing at all. Every room task gets a definition of
 * its own; a catalog template only fills the form (FEAT-UX-043).
 *
 * Transaction doctrine (ADR-007, WP3 precedent): the transaction takes the
 * house row lock first (the documented first lock — house → room_task →
 * dependents), revalidates every distinct assignee membership under the
 * lock, then performs fresh inserts only. A membership removed after the
 * pre-transaction checks rolls the whole aggregate back — no orphan task
 * definitions, ever.
 *
 * Authorization preamble (never bypassable): children cannot create
 * (CS-PLAN-061 D5), the room must belong to the caller's house (404
 * anti-enumeration), admin-only creation is owner-gated (403), and private
 * creation stamps the creator as `ownerUserId`.
 */
class TaskCreationService {
	public function __construct(
		private TransactionExecutor $transactions,
		private TaskMapper $taskMapper,
		private RoomTaskMapper $roomTaskMapper,
		private TaskAssigneeMapper $taskAssigneeMapper,
		private HouseMapper $houseMapper,
		private RoomService $roomService,
		private TaskService $taskService,
		private SubTaskWriter $subTasks,
		private SleepService $sleep,
		private AuthorizationService $authorization,
		private CalendarService $calendarService,
		private CycleService $cycleService,
		private IL10N $l10n,
		private AppLogger $appLogger,
	) {
	}

	/**
	 * Create one visible, valid room task — or nothing at all.
	 *
	 * @param int $houseId The caller's house ID (scopes the target room)
	 * @param HouseUser $actor The acting user's house membership
	 * @param array<string, mixed> $payload The form: `{roomId, task, assigneeUserIds?, checklistItems?, firstDate?, sleeping?, wakeOn?}`
	 * @return array{task: Task, roomTask: RoomTask, assignees: TaskAssignee[]}
	 *
	 * @throws ValidationException if the definition fails the closed constraint set (400)
	 * @throws NotFoundException if the room is not the caller's (404)
	 * @throws \OCA\ChoreStrike\Exception\ForbiddenException if the actor is a child or member + admin-only (403)
	 * @throws \InvalidArgumentException on a bad payload, a non-member assignee or an invalid date (400)
	 */
	public function create(int $houseId, HouseUser $actor, array $payload): array {
		$form = $this->validateEnvelope($payload);

		// Actor policy (CS-PLAN-061 D5): children execute tasks, they do not
		// create them (frontend entry-point gating is CS-PLAN-063 D6).
		$this->authorization->assertCanManageTasks($actor);

		// CR-SVC-01: the room is resolved through the floor chain and must
		// belong to the caller's house — a miss is the 404 anti-enumeration
		// denial (never a hint that the room exists elsewhere). The denial
		// is logged as a security event with stable context keys.
		try {
			$this->roomService->validateRoomOwnership((int)$form->roomId, $houseId);
		} catch (NotFoundException $e) {
			$this->appLogger->securityWarning('Cross-house room task creation denied', [
				'service' => 'TaskCreationService',
				'roomId' => $form->roomId,
				'houseId' => $houseId,
			]);
			throw $e;
		}

		$definition = $this->materializeDefinition((array)$form->definition);
		[$visibilityType, $ownerUserId] = $this->resolveCreationVisibility($definition, $actor);

		return $this->runAggregate($houseId, $actor, $form, $definition, $visibilityType, $ownerUserId);
	}

	/**
	 * The closed envelope of the form's create: a room and a definition are
	 * required; every other field is the form's ({@see TaskFormPayload}).
	 *
	 * @throws \InvalidArgumentException
	 */
	private function validateEnvelope(array $payload): TaskFormPayload {
		CommandService::assertClosedBody($payload, TaskFormPayload::FIELDS, $this->l10n);
		$form = TaskFormPayload::fromPayload($payload, $this->calendarService, $this->l10n);
		if ($form->roomId === null) {
			throw new \InvalidArgumentException($this->l10n->t('roomId is required'));
		}
		if ($form->definition === null) {
			throw new \InvalidArgumentException($this->l10n->t('task must be an object'));
		}
		return $form;
	}

	/**
	 * Normalize and validate the definition against the one closed
	 * constraint set (CS-PLAN-063 §3.1, {@see TaskService::FORM_FIELDS}):
	 * unknown fields are rejected, absent optionals fall back to the
	 * canonical defaults, and every value must pass
	 * {@see TaskService::validateTaskData}.
	 */
	private function materializeDefinition(array $raw): array {
		foreach (array_keys($raw) as $field) {
			if (!in_array($field, TaskService::FORM_FIELDS, true)) {
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
			'category' => TaskCategoryService::OTHER,
			'recurrenceMode' => Task::RECURRENCE_RECURRING,
			'schedulingMode' => Task::SCHEDULING_DYNAMIC,
			'flexDays' => 0,
			'visibilityType' => Task::VISIBILITY_PUBLIC,
			'isVoluntary' => false,
		], $raw);

		$errors = $this->taskService->validateTaskData($definition);
		if ($errors !== []) {
			throw new ValidationException($errors[0], $errors);
		}

		return $definition;
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
	 * the under-lock membership revalidation and the writes — definition and
	 * sub-tasks, room task, initial assignees, the first window and an
	 * optional sleep. Any failure rolls back every write.
	 *
	 * @return array{task: Task, roomTask: RoomTask, assignees: TaskAssignee[]}
	 */
	private function runAggregate(int $houseId, HouseUser $actor, TaskFormPayload $form, array $definition, string $visibilityType, ?string $ownerUserId): array {
		[$task, $roomTask] = $this->transactions->run(function () use ($houseId, $actor, $form, $definition, $visibilityType, $ownerUserId): array {
			// First lock of the documented order (house → room_task →
			// dependents): serialize concurrent writes on the house row.
			$house = $this->houseMapper->findForUpdate($houseId);
			$timeZone = $this->calendarService->getTimeZone($house);
			$now = new \DateTime();
			// The year of a first date counts in house days (ADR-009), checked before any write.
			$form->assertFirstDateInRange($this->l10n, $this->calendarService->dayKey($now, $timeZone));
			// A membership removed after the pre-transaction checks must not
			// leave assignee rows for a former member (→ rollback).
			$assigneeUserIds = $form->assigneeUserIds ?? [];
			$this->authorization->assertMembersUnderLock($houseId, $assigneeUserIds);

			$task = $this->insertDefinition($definition, $visibilityType, $ownerUserId);
			if ($form->subTasks !== null) {
				$this->subTasks->createUnderLock((int)$task->getId(), $form->subTasks);
			}

			// The first date anchors the derived window (ADR-021); no
			// future cycle is stored. Default: tomorrow in the house zone.
			$firstDate = $form->firstDate ?? RecurrenceCalculator::dayAfter($this->calendarService->dayKey($now, $timeZone));
			$roomTask = $this->roomTaskMapper->create((int)$form->roomId, $task->getId(), $this->calendarService->dayStartInstant($firstDate, $timeZone));
			foreach ($assigneeUserIds as $userId) {
				$this->taskAssigneeMapper->addAssignee($roomTask->getId(), $userId, $actor->getNextcloudUserId());
			}
			// The room task is new: it has no completions to read.
			$this->cycleService->startUnderLock($roomTask, $task, $firstDate, $timeZone, $now, []);
			if ($form->sleeping === true) {
				$this->sleep->applyUnderLock($house, $roomTask, $task, true, $form->wakeOn, $timeZone, $now, []);
			}
			return [$task, $roomTask];
		});

		return [
			'task' => $task,
			'roomTask' => $roomTask,
			'assignees' => $this->taskAssigneeMapper->findByRoomTask($roomTask->getId()),
		];
	}

	/**
	 * Insert the task definition via named arguments (replaces the
	 * 24-positional-param seam, CS-PLAN-063 §3.2). Every value takes its
	 * stored form from the one normalizer the edit uses too
	 * ({@see TaskService::storedValue()}, D-091-100).
	 */
	private function insertDefinition(array $definition, string $visibilityType, ?string $ownerUserId): Task {
		$stored = static fn (string $field): mixed => TaskService::storedValue($field, $definition[$field] ?? null);
		return $this->taskMapper->create(
			name: (string)$definition['name'],
			description: $stored('description'),
			energyCost: (int)$definition['energyCost'],
			priority: (int)$definition['priority'],
			frequencyType: (string)$definition['frequencyType'],
			frequencyValue: (int)$definition['frequencyValue'],
			estimatedMinutes: (int)$definition['estimatedMinutes'],
			category: (string)$definition['category'],
			scopeType: (string)($definition['scopeType'] ?? 'room'),
			recurrenceMode: (string)($definition['recurrenceMode'] ?? Task::RECURRENCE_RECURRING),
			activeFrom: $stored('activeFrom'),
			activeUntil: $stored('activeUntil'),
			isVoluntary: (bool)$definition['isVoluntary'],
			schedulingMode: (string)($definition['schedulingMode'] ?? Task::SCHEDULING_DYNAMIC),
			fixedScheduleTime: $stored('fixedScheduleTime'),
			fixedScheduleDays: $stored('fixedScheduleDays'),
			instructions: $stored('instructions'),
			tips: $stored('tips'),
			visibilityType: $visibilityType,
			ownerUserId: $ownerUserId,
			flexDays: (int)($definition['flexDays'] ?? 0),
			fixedMonthDay: isset($definition['fixedMonthDay']) ? (int)$definition['fixedMonthDay'] : null,
		);
	}
}
