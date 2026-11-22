<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\CycleReceipt;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TaskCreationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * API controller for room task-related endpoints
 */
class RoomTaskController extends Controller {
	use ControllerLoggingTrait;

	private RoomTaskService $roomTaskService;
	private RoomTaskReadService $roomTaskReadService;
	private CompletionService $completionService;
	private TaskCreationService $taskCreationService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IL10N $l;
	private \OCA\ChoreStrike\Service\CalendarService $calendarService;

	public function __construct(
		IRequest $request,
		RoomTaskService $roomTaskService,
		RoomTaskReadService $roomTaskReadService,
		CompletionService $completionService,
		TaskCreationService $taskCreationService,
		HouseService $houseService,
		AuthorizationService $authService,
		IL10N $l,
		\OCA\ChoreStrike\Service\CalendarService $calendarService,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->roomTaskService = $roomTaskService;
		$this->roomTaskReadService = $roomTaskReadService;
		$this->completionService = $completionService;
		$this->taskCreationService = $taskCreationService;
		$this->houseService = $houseService;
		$this->authService = $authService;
		$this->l = $l;
		$this->calendarService = $calendarService;
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * Resolve the house timezone for day-boundary serialization (CS-PLAN-057).
	 */
	private function timeZoneOf(\OCA\ChoreStrike\Db\House $house): \DateTimeZone {
		return $this->calendarService->getTimeZone($house);
	}

	/**
	 * Get room tasks
	 *
	 * Selector modes (CS-PLAN-067 D1), evaluated in this precedence — a
	 * supplied selector never falls back to the broader house mode:
	 * - `scopeId` present: the scope's rows intersected with the current
	 *   house and the viewer's visibility (cross-house entries are silently
	 *   absent → 200 with the local subset, possibly empty).
	 * - `roomId` present: one room's visible tasks; a foreign roomId is a
	 *   404.
	 * - neither: the whole CURRENT house (server-side resolved — never a
	 *   browser-supplied house ID), flat rows plus batched assignee arrays.
	 *
	 * @NoAdminRequired
	 */
	public function index(?int $roomId = null, ?int $scopeId = null, bool $activeOnly = true): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			// The list carries the definitions its visibility decision read;
			// the cycle derivation takes them instead of a second read.
			if ($scopeId !== null) {
				$list = $this->roomTaskReadService->getScopeTasksScopedToHouse($scopeId, $house->getId(), $houseUser, $activeOnly);
			} elseif ($roomId !== null) {
				// CR-053-02: the roomId is caller-supplied, so it must be
				// validated against the caller's house — membership
				// alone allowed cross-house reads.
				$this->roomTaskService->validateRoomOwnership($roomId, $house->getId());
				$list = $this->roomTaskReadService->getRoomTasks($roomId, $houseUser, $activeOnly);
			} else {
				$list = $this->roomTaskReadService->getHouseRoomTasks($house->getId(), $houseUser, $activeOnly);
			}

			return new JSONResponse([
				'success' => true,
				'data' => $this->serializeFlatRoomTasks($list->roomTasks, $this->timeZoneOf($house), (int)$house->getId(), $list->tasksById),
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('index', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ROOM_TASKS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Serialize room tasks into the flat list shape (CS-PLAN-067 D1): the
	 * existing `RoomTask::jsonSerialize(timezone)` fields plus a canonical
	 * `assignees` user-ID array — one batched assignee read for the whole
	 * set, never a per-row lookup. Each row also carries its `cycle` block
	 * (ADR-021) from ONE batched derivation.
	 *
	 * @param \OCA\ChoreStrike\Db\RoomTask[] $roomTasks
	 * @param \DateTimeZone $tz House timezone for day-boundary serialization
	 * @param int $houseId House scope for the batched cycle derivation
	 * @param array<int, \OCA\ChoreStrike\Db\Task>|null $tasksById Definitions the list read already loaded
	 * @return array<int, array<string, mixed>>
	 */
	private function serializeFlatRoomTasks(array $roomTasks, \DateTimeZone $tz, int $houseId, ?array $tasksById = null): array {
		$assigneesByTask = $this->roomTaskReadService->assigneesByRoomTasks($roomTasks);
		$facts = $this->roomTaskReadService->cycleFacts($houseId, $roomTasks, $tasksById, null, $tz);

		return array_map(function ($roomTask) use ($assigneesByTask, $tz, $facts) {
			$block = $facts[$roomTask->getId()]['block'] ?? null;
			$data = $this->roomTaskReadService->roomTaskPayload($roomTask, $tz, $block);
			$data['assignees'] = $assigneesByTask[$roomTask->getId()] ?? [];
			$data['cycle'] = $block;
			return $data;
		}, $roomTasks);
	}

	/**
	 * Create a room task — the one form's create (CS-PLAN-063 §3.3,
	 * CS-PLAN-091 WP-B6). Closed body: `{ roomId, task: {definition…},
	 * assigneeUserIds?, checklistItems?, firstDate?, sleeping?, wakeOn? }`.
	 *
	 * The whole aggregate (definition with its sub-tasks, room task,
	 * assignees, first window, optional sleep) commits or rolls back
	 * together; the response carries the aggregate shape
	 * `{task, roomTask, assignees}` (D1).
	 *
	 * @NoAdminRequired
	 */
	public function create(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$payload = $this->request->getParams();
			unset($payload['_route']);
			$aggregate = $this->taskCreationService->create($house->getId(), $houseUser, $payload);

			return new JSONResponse([
				'success' => true,
				'data' => $this->serializeAggregate($aggregate, $house),
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (\OCA\ChoreStrike\Exception\ValidationException $e) {
			return new JSONResponse(['success' => false, 'error' => [
				'code' => 'VALIDATION_ERROR', 'message' => $e->getMessage(), 'details' => $e->getErrors(),
			]], 400);
		} catch (\InvalidArgumentException $e) {
			// Service-curated messages (the form's shape, a non-member
			// assignee, a date) — safe to surface verbatim; safeMessage()
			// would replace them with the generic fallback.
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('create', $e);
			return ControllerErrorFormatter::serverErrorResponse($e, 'ROOM_TASK_CREATE_ERROR');
		}
	}

	/**
	 * Serialize the creation aggregate into its response shape (D1), the one
	 * the form's save answers too. Canonical `assignees` are unique,
	 * deterministically ordered Nextcloud user-ID arrays (CS-PLAN-066 D1) —
	 * the assigned_at row order of the fresh assignee set.
	 */
	private function serializeAggregate(array $aggregate, \OCA\ChoreStrike\Db\House $house): array {
		return [
			'task' => $aggregate['task']->jsonSerialize(),
			'roomTask' => $this->roomTaskReadService->committedRoomTaskPayload((int)$house->getId(), $aggregate['roomTask'], $this->timeZoneOf($house), 'create'),
			'assignees' => array_map(
				static fn ($assignee) => $assignee->getUserId(),
				$aggregate['assignees']
			),
		];
	}

	/**
	 * Complete the current cycle of a room task (ADR-021 decision 4;
	 * CS-PLAN-070 WP3 actual work).
	 *
	 * The body carries the shown target: `commandId` (client idempotency
	 * UUID), `cycleKey` and `expectedVersion` from the row's `cycle` block
	 * (`cycleKey` is absent for a bonus task) and the optional ACTUAL work
	 * split: `participants: [{userId, sharePct}]` plus `allocationReviewed`.
	 * Input validation is a CLOSED SET. The server owns work time and
	 * target effort; replays of the same command return the original
	 * completion identity without repeating effects.
	 *
	 * @NoAdminRequired
	 */
	public function complete(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->assertHouseMember($house->getId());
			$this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

			// The receipt fields come from the request body; framework
			// keys (route param `id`, `_route`) are filtered before the
			// closed-set validation.
			$payload = $this->request->getParams();
			unset($payload['id'], $payload['_route']);
			$receipt = $this->validateCompletionReceipt($payload);
			if ($receipt instanceof JSONResponse) {
				return $receipt;
			}

			$currentUserId = $this->authService->requireCurrentUserId();
			$result = $this->completionService->complete(
				$house->getId(),
				$currentUserId,
				$id,
				$receipt['commandId'],
				$receipt['cycleKey'],
				$receipt['expectedVersion'],
				$receipt['participants'],
				$receipt['allocationReviewed'],
			);

			$data = [
				// First run and replay both carry the fresh cycle block.
				'roomTask' => $this->roomTaskReadService->roomTaskPayload($result['roomTask'], $this->timeZoneOf($house), $result['cycle'] ?? null),
				'room' => $result['room']?->jsonSerialize(),
				'task' => $result['task']->jsonSerialize(),
				'rewards' => $result['rewards'],
				'completionId' => $result['completionId'],
				'cycle' => $result['cycle'] ?? null,
				'replay' => $result['replay'],
			];
			return new JSONResponse(['success' => true, 'data' => $data]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => ['code' => 'VALIDATION_ERROR', 'message' => ControllerErrorFormatter::safeMessage($e)]
			], 400);
		} catch (\Exception $e) {
			$this->logControllerException('complete', $e, ['roomTaskId' => $id]);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'COMPLETE_TASK_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Validate the completion receipt payload (closed set, parent §3.4).
	 * Returns the validated fields or a ready-to-return 400 response.
	 *
	 * @return array{commandId: string, cycleKey: ?string, expectedVersion: int, participants: list<array{userId: string, sharePct: int}>, allocationReviewed: bool}|JSONResponse
	 */
	private function validateCompletionReceipt(array $payload): array|JSONResponse {
		$badRequest = static fn (string $message): JSONResponse => new JSONResponse([
			'success' => false,
			'error' => ['code' => 'VALIDATION_ERROR', 'message' => $message],
		], 400);

		// The cycle key is absent for a bonus or sleeping task, which has no
		// cycle to name (ADR-021 decisions 7 and 8).
		try {
			$receipt = CycleReceipt::fromPayload($payload, $this->l, CycleReceipt::KEY_OPTIONAL, ['participants', 'allocationReviewed']);
		} catch (\InvalidArgumentException $e) {
			return $badRequest($e->getMessage());
		}

		// Actual work split (WP3 §3.6): optional explicit participants with
		// whole-percent shares + the deviation review confirmation.
		$participants = [];
		if (isset($payload['participants'])) {
			if (!is_array($payload['participants'])) {
				return $badRequest($this->l->t('participants must be an array of {userId, sharePct}'));
			}
			foreach ($payload['participants'] as $participant) {
				if (!is_array($participant) || !isset($participant['userId']) || !isset($participant['sharePct'])) {
					return $badRequest($this->l->t('Every participant needs a user id and a whole percent share'));
				}
				$participants[] = [
					'userId' => (string)$participant['userId'],
					'sharePct' => $participant['sharePct'],
				];
			}
		}
		$reviewed = $payload['allocationReviewed'] ?? false;
		if (!is_bool($reviewed)) {
			return $badRequest($this->l->t('allocationReviewed must be a boolean'));
		}

		return [
			'commandId' => $receipt->commandId,
			'cycleKey' => $receipt->cycleKey,
			'expectedVersion' => $receipt->expectedVersion,
			'participants' => $participants,
			'allocationReviewed' => $reviewed,
		];
	}

	/**
	 * Delete room task
	 *
	 * @NoAdminRequired
	 */
	public function delete(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());
			$this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

			$this->roomTaskService->deleteRoomTask($id, $houseUser);

			return new JSONResponse([
				'success' => true,
				'message' => $this->l->t('Room task deleted successfully')
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('delete', $e, ['roomTaskId' => $id]);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ROOM_TASK_DELETE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get overdue tasks
	 *
	 * Enriched execution rows (CS-PLAN-066 §3.1 D1): the canonical
	 * `{roomTask, task, room, assignees}` shape the filtered daily endpoint
	 * returns — same visibility/eligibility/ordering, enrichment without
	 * broadened access.
	 *
	 * @NoAdminRequired
	 */
	public function overdue(?int $roomId = null): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$rows = $this->roomTaskReadService->getOverdueTaskRows($house->getId(), $houseUser, $roomId);
			$tz = $this->timeZoneOf($house);

			return new JSONResponse([
				'success' => true,
				'data' => $this->serializeExecutionRows($rows, $tz),
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('overdue', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'OVERDUE_TASKS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get tasks due today
	 *
	 * Enriched execution rows (CS-PLAN-066 §3.1 D1) — see overdue().
	 *
	 * @NoAdminRequired
	 */
	public function dueToday(?int $roomId = null): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$rows = $this->roomTaskReadService->getDueTodayTaskRows($house->getId(), $houseUser, $roomId);
			$tz = $this->timeZoneOf($house);

			return new JSONResponse([
				'success' => true,
				'data' => $this->serializeExecutionRows($rows, $tz),
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('dueToday', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'DUE_TODAY_TASKS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Serialize `{roomTask, task, room}` service rows into the canonical
	 * enriched execution-row shape `{roomTask, task, room, assignees,
	 * cycle}` (CS-PLAN-066 D1; ADR-021 adds the `cycle` block). One
	 * batched assignee read for the whole set — the
	 * arrays are unique, deterministically ordered Nextcloud user IDs.
	 *
	 * @param array<int, array{roomTask: \OCA\ChoreStrike\Db\RoomTask, task: \OCA\ChoreStrike\Db\Task, room: \OCA\ChoreStrike\Db\Room}> $rows
	 * @param \DateTimeZone $tz House timezone for day-boundary serialization
	 * @return array<int, array<string, mixed>>
	 */
	private function serializeExecutionRows(array $rows, \DateTimeZone $tz): array {
		$roomTasks = array_map(static fn (array $row) => $row['roomTask'], $rows);
		$assigneesByTask = $this->roomTaskReadService->assigneesByRoomTasks($roomTasks);

		return array_map(function (array $row) use ($tz, $assigneesByTask) {
			return [
				'roomTask' => $this->roomTaskReadService->roomTaskPayload($row['roomTask'], $tz, $row['cycle'] ?? null),
				'task' => $row['task']->jsonSerialize(),
				'room' => $row['room']->jsonSerialize(),
				'assignees' => $assigneesByTask[$row['roomTask']->getId()] ?? [],
				'cycle' => $row['cycle'] ?? null,
				// BUG-UX-003: My Day offers the checklist toggle only above 0.
				'checklistItemCount' => $row['checklistItemCount'] ?? 0,
			];
		}, $rows);
	}

	/**
	 * Get scheduled and completed tasks within a date range for the calendar view.
	 *
	 * @NoAdminRequired
	 *
	 * @param string $from Start date (Y-m-d)
	 * @param string $to End date (Y-m-d)
	 */
	public function range(string $from, string $to): JSONResponse {
		try {
			$fromDate = \DateTime::createFromFormat('Y-m-d', $from);
			$toDate = \DateTime::createFromFormat('Y-m-d', $to);

			if ($fromDate === false || $toDate === false) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('from and to must be valid Y-m-d dates')
					]
				], 400);
			}

			$fromDate->setTime(0, 0, 0);
			$toDate->setTime(23, 59, 59);

			if ($fromDate > $toDate) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('from must not be after to')
					]
				], 400);
			}

			$spanDays = $fromDate->diff($toDate)->days;
			if ($spanDays > 60) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('Range must not exceed 60 days')
					]
				], 400);
			}

			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$data = $this->roomTaskService->getRangeTasks($house->getId(), $fromDate, $toDate, $houseUser);

			return new JSONResponse([
				'success' => true,
				'data' => $data
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('range', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'RANGE_TASKS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get filtered daily tasks for the current user based on role and preferences.
	 *
	 * Child users receive a filtered view based on:
	 * - weekdayAvailability: Only tasks on available days
	 * - maxDailyTasks: Limited number of tasks
	 *
	 * @NoAdminRequired
	 */
	public function filteredDaily(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$filteredTasks = $this->roomTaskReadService->getFilteredDailyTasksForUser(
				$house->getId(),
				$houseUser,
				new \DateTime()
			);

			// CS-PLAN-065 (decision D4): the rows carry their canonical
			// assignee sets (service-side batched read, RF-058-11) so My Day
			// never re-interprets an unknown set as unassigned after the
			// child limit truncated the list.
			return new JSONResponse(['success' => true, 'data' => $this->serializeDailyRows($filteredTasks, $this->timeZoneOf($house))]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('filteredDaily', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'FILTERED_DAILY_TASKS_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * The bonus tasks of *Today* (ADR-021 decision 8): every visible bonus
	 * task that is not asleep, in the daily row shape plus `creditedToday`
	 * — the members it already paid on this house-local day. Never due,
	 * so neither a child's daily limit nor the availability gate applies.
	 *
	 * @NoAdminRequired
	 */
	public function bonus(): JSONResponse {
		try {
			$house = $this->houseService->getOrCreateHouse($this->houseService->getInstanceId());
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$rows = $this->roomTaskReadService->getBonusTaskRows((int)$house->getId(), $houseUser);
			return new JSONResponse(['success' => true, 'data' => $this->serializeDailyRows($rows, $this->timeZoneOf($house))]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('bonus', $e);
			return ControllerErrorFormatter::serverErrorResponse($e, 'BONUS_TASKS_ERROR');
		}
	}

	/**
	 * The row shape of *Today*: the daily rows and the bonus rows.
	 *
	 * @param list<array<string, mixed>> $rows Service rows with `roomTask`, `task`, `room`, `assignees` and `cycle`
	 * @return list<array<string, mixed>>
	 */
	private function serializeDailyRows(array $rows, \DateTimeZone $tz): array {
		return array_map(fn (array $item): array => [
			'roomTask' => $this->roomTaskReadService->roomTaskPayload($item['roomTask'], $tz, $item['cycle'] ?? null),
			'task' => $item['task']->jsonSerialize(),
			'room' => $item['room']->jsonSerialize(),
			'assignees' => $item['assignees'],
			'cycle' => $item['cycle'] ?? null,
			// BUG-UX-005: an open window whose task is not suggested for today.
			'openWindow' => $item['openWindow'] ?? false,
			// BUG-UX-003: My Day offers the checklist toggle only above 0.
			'checklistItemCount' => $item['checklistItemCount'] ?? 0,
		] + (array_key_exists('creditedToday', $item) ? ['creditedToday' => $item['creditedToday']] : [])
			// ADR-022: who today's suggestion placed the row on, and whether it fits no day.
			+ (array_key_exists('suggestedFor', $item) ? ['suggestedFor' => $item['suggestedFor'], 'overload' => $item['overload'] ?? false] : []), $rows);
	}
}
