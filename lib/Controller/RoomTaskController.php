<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TaskCreationService;
use OCA\ChoreStrike\Service\TemplateService;
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
	 * Create a room task from a catalog template (per-room picker).
	 *
	 * Delegates to the atomic creation aggregate and returns the aggregate
	 * shape `{task, roomTask, assignees}` (CS-PLAN-063 §3.3, D1).
	 *
	 * @NoAdminRequired
	 */
	public function fromTemplate(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$roomId = (int)$this->request->getParam('roomId');
			$templateId = (string)($this->request->getParam('templateId') ?? '');
			$level = (string)($this->request->getParam('level') ?? TemplateService::LEVEL_PUTZTEUFEL);
			$overrides = $this->request->getParam('overrides');
			$assigneeUserId = $this->request->getParam('assigneeUserId');

			if ($roomId <= 0) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('roomId is required')],
				], 400);
			}
			if (trim($templateId) === '') {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('templateId is required')],
				], 400);
			}

			$aggregate = $this->taskCreationService->createFromTemplate(
				$house->getId(),
				$houseUser,
				$roomId,
				$templateId,
				$level,
				is_array($overrides) ? $overrides : [],
				is_string($assigneeUserId) && trim($assigneeUserId) !== '' ? $assigneeUserId : null,
			);

			return new JSONResponse([
				'success' => true,
				'data' => $this->serializeAggregate($aggregate, $house),
			]);
		} catch (NotFoundException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => ControllerErrorFormatter::safeMessage($e)],
			], 404);
		} catch (\InvalidArgumentException $e) {
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('fromTemplate', $e);
			return new JSONResponse([
				'success' => false,
				'error' => ['code' => 'FROM_TEMPLATE_ERROR', 'message' => ControllerErrorFormatter::safeMessage($e)],
			], 500);
		}
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

			if ($scopeId !== null) {
				$roomTasks = $this->roomTaskReadService->getScopeTasksScopedToHouse($scopeId, $house->getId(), $houseUser, $activeOnly);
			} elseif ($roomId !== null) {
				// CR-053-02: the roomId is caller-supplied, so it must be
				// validated against the caller's house (same fix as
				// roomStats) — membership alone allowed cross-house reads.
				$this->roomTaskService->validateRoomOwnership($roomId, $house->getId());
				$roomTasks = $this->roomTaskReadService->getRoomTasks($roomId, $houseUser, $activeOnly);
			} else {
				$roomTasks = $this->roomTaskReadService->getHouseRoomTasks($house->getId(), $houseUser, $activeOnly);
			}

			return new JSONResponse([
				'success' => true,
				'data' => $this->serializeFlatRoomTasks($roomTasks, $this->timeZoneOf($house), (int)$house->getId()),
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
	 * set, never a per-row lookup. Each row also carries its canonical
	 * occurrence target block (CS-PLAN-072 WP1) from ONE batched
	 * derivation.
	 *
	 * @param \OCA\ChoreStrike\Db\RoomTask[] $roomTasks
	 * @param \DateTimeZone $tz House timezone for day-boundary serialization
	 * @param int $houseId House scope for the batched occurrence derivation
	 * @return array<int, array<string, mixed>>
	 */
	private function serializeFlatRoomTasks(array $roomTasks, \DateTimeZone $tz, int $houseId): array {
		$assigneesByTask = $this->roomTaskReadService->assigneesByRoomTasks($roomTasks);
		$selections = $this->roomTaskReadService->occurrenceSelectionsForHouse($houseId, $roomTasks);

		return array_map(function ($roomTask) use ($assigneesByTask, $tz, $selections) {
			$data = $roomTask->jsonSerialize($tz);
			$data['assignees'] = $assigneesByTask[$roomTask->getId()] ?? [];
			$selection = $selections[$roomTask->getId()] ?? null;
			$data['occurrence'] = $selection !== null
				? $this->roomTaskReadService->occurrenceBlockFor($selection['occurrence'], $selection['revision'], $selection['classification'])
				: null;
			return $data;
		}, $roomTasks);
	}

	/**
	 * Create a new room task — the one atomic creation endpoint
	 * (CS-PLAN-063 §3.3, CR-WF-03).
	 *
	 * Payload variants (mutually exclusive):
	 *  - custom create: `{ task: {definition...}, roomId, assigneeUserIds?, nextDue? }`
	 *  - mount existing: `{ taskId, roomId, assigneeUserIds?, nextDue? }`
	 *
	 * The whole aggregate (definition, room-task materialization, initial
	 * assignees) commits or rolls back together; the response carries the
	 * aggregate shape `{task, roomTask, assignees}` (D1).
	 *
	 * @NoAdminRequired
	 */
	public function create(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$roomId = (int)$this->request->getParam('roomId');
			if ($roomId <= 0) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('roomId is required')],
				], 400);
			}

			$payload = ['roomId' => $roomId];

			$task = $this->request->getParam('task');
			$taskId = $this->request->getParam('taskId');
			if ($task !== null && $taskId !== null) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('Exactly one of task or taskId is required')],
				], 400);
			}
			if ($task === null && $taskId === null) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('Exactly one of task or taskId is required')],
				], 400);
			}
			if ($task !== null) {
				if (!is_array($task)) {
					return new JSONResponse([
						'success' => false,
						'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('task must be an object')],
					], 400);
				}
				$payload['task'] = $task;
			} else {
				if (!is_numeric($taskId) || (int)$taskId < 1) {
					return new JSONResponse([
						'success' => false,
						'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('taskId must be a positive integer')],
					], 400);
				}
				$payload['taskId'] = (int)$taskId;
			}

			$assigneeUserIds = $this->request->getParam('assigneeUserIds');
			if ($assigneeUserIds !== null) {
				if (!is_array($assigneeUserIds)) {
					return new JSONResponse([
						'success' => false,
						'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('assigneeUserIds must be an array of user IDs')],
					], 400);
				}
				$payload['assigneeUserIds'] = $assigneeUserIds;
			}

			$nextDue = $this->request->getParam('nextDue');
			if ($nextDue !== null && $nextDue !== '') {
				if (!is_string($nextDue)) {
					return new JSONResponse([
						'success' => false,
						'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('Invalid nextDue date format')],
					], 400);
				}
				$payload['nextDue'] = $nextDue;
			}

			$aggregate = $this->taskCreationService->create($house->getId(), $houseUser, $payload);

			return new JSONResponse([
				'success' => true,
				'data' => $this->serializeAggregate($aggregate, $house),
			]);
		} catch (NotFoundException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'RESOURCE_NOT_FOUND',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 404);
		} catch (\InvalidArgumentException $e) {
			// Service-curated messages (bad payload variant, non-member
			// assignee, invalid nextDue, duplicate mount) — safe to surface
			// verbatim; safeMessage() would replace them with the generic
			// fallback.
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('create', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ROOM_TASK_CREATE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Serialize the creation aggregate into the one response shape shared by
	 * both create paths (D1). Canonical `assignees` are unique,
	 * deterministically ordered Nextcloud user-ID arrays (CS-PLAN-066 D1) —
	 * the assigned_at row order of the fresh assignee set.
	 */
	private function serializeAggregate(array $aggregate, \OCA\ChoreStrike\Db\House $house): array {
		$tz = $this->timeZoneOf($house);
		return [
			'task' => $aggregate['task']->jsonSerialize(),
			'roomTask' => $aggregate['roomTask']->jsonSerialize($tz),
			'assignees' => array_map(
				static fn ($assignee) => $assignee->getUserId(),
				$aggregate['assignees']
			),
		];
	}

	/**
	 * Get the assignees (Nextcloud user IDs) for a room task
	 *
	 * @NoAdminRequired
	 */
	public function assignees(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());
			$this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => $this->roomTaskService->getAssigneeUserIds($id, $houseUser)
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('assignees', $e, ['roomTaskId' => $id]);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ASSIGNEES_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Replace the full set of assignees for a room task (multi-assign)
	 *
	 * @NoAdminRequired
	 * @param int $id The room task ID
	 * @param string[] $userIds Nextcloud user IDs to assign
	 */
	public function setAssignees(int $id, array $userIds): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());
			$this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

			$assignees = $this->roomTaskService->setAssignees($id, $userIds, $house->getId(), $houseUser);

			// Canonical user-ID array (CS-PLAN-066 D1) — the same shape the
			// read/create/aggregate responses carry.
			return new JSONResponse([
				'success' => true,
				'data' => array_map(static fn ($a) => $a->getUserId(), $assignees)
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (\InvalidArgumentException $e) {
			// Service-curated membership message — safe to surface verbatim.
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('setAssignees', $e, ['roomTaskId' => $id]);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'SET_ASSIGNEES_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Update room task due date
	 *
	 * @NoAdminRequired
	 */
	public function updateDueDate(int $id, string $nextDue): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());
			$this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

			$roomTask = $this->roomTaskService->updateDueDate($id, $nextDue, $houseUser);

			return new JSONResponse([
				'success' => true,
				'data' => $roomTask->jsonSerialize($this->timeZoneOf($house))
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (\InvalidArgumentException $e) {
			// Curated date-format message — safe to surface verbatim.
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('updateDueDate', $e, ['roomTaskId' => $id]);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'UPDATE_DUE_DATE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Complete one shown occurrence of a room task (CS-PLAN-064 D2 calls
	 * the CompletionService aggregate directly; CS-PLAN-072 WP1 adds the
	 * occurrence receipt contract).
	 *
	 * The body carries the shown target identity: `commandId` (client
	 * idempotency UUID), `occurrenceId` (public
	 * `<scheduleRevisionId>:<ordinal>` key), `expectedVersion` and
	 * `selectionToken`. Input validation is a CLOSED SET — unknown fields
	 * (e.g. share participants, WP3) are rejected with 400, never
	 * silently ignored. The server owns work time and target effort;
	 * replays of the same command return the original completion
	 * identity without repeating effects.
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
				$receipt['occurrenceId'],
				$receipt['expectedVersion'],
				$receipt['selectionToken'],
			);

			$data = [
				'roomTask' => $result['roomTask']->jsonSerialize($this->timeZoneOf($house)),
				'room' => $result['room']->jsonSerialize(),
				'task' => $result['task']->jsonSerialize(),
				'rewards' => $result['rewards'],
				'completionId' => $result['completionId'],
				'occurrence' => $result['occurrence'],
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
	 * @param array $payload
	 * @return array{commandId: string, occurrenceId: string, expectedVersion: int, selectionToken: string}|JSONResponse
	 */
	private function validateCompletionReceipt(array $payload): array|JSONResponse {
		$badRequest = static fn (string $message): JSONResponse => new JSONResponse([
			'success' => false,
			'error' => ['code' => 'VALIDATION_ERROR', 'message' => $message],
		], 400);

		$allowed = ['commandId', 'occurrenceId', 'expectedVersion', 'selectionToken'];
		foreach ($payload as $key => $value) {
			if (!in_array($key, $allowed, true)) {
				return $badRequest($this->l->t('Unknown field: %1$s', [$key]));
			}
		}

		$commandId = $payload['commandId'] ?? null;
		$occurrenceId = $payload['occurrenceId'] ?? null;
		$expectedVersion = $payload['expectedVersion'] ?? null;
		$selectionToken = $payload['selectionToken'] ?? null;

		if (!is_string($commandId) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $commandId)) {
			return $badRequest($this->l->t('A valid commandId (UUID) is required'));
		}
		if (!is_string($occurrenceId) || $occurrenceId === '') {
			return $badRequest($this->l->t('An occurrenceId is required'));
		}
		try {
			Occurrence::parsePublicKey($occurrenceId);
		} catch (\InvalidArgumentException $e) {
			return $badRequest($e->getMessage());
		}
		if (!is_numeric($expectedVersion) || (int)$expectedVersion < 0 || (string)(int)$expectedVersion !== (string)$expectedVersion) {
			return $badRequest($this->l->t('A valid expectedVersion is required'));
		}
		if (!is_string($selectionToken) || $selectionToken === '') {
			return $badRequest($this->l->t('A selectionToken is required'));
		}

		return [
			'commandId' => strtolower($commandId),
			'occurrenceId' => $occurrenceId,
			'expectedVersion' => (int)$expectedVersion,
			'selectionToken' => $selectionToken,
		];
	}

	/**
	 * Undo the newest revertible completion of a room task (CS-PLAN-064 WP5).
	 *
	 * One idempotent aggregate: restores ledger marking,
	 * rewards/streak/freezes/titles, cleanliness and claim markers
	 * from the recorded effect, and re-derives the recorded occurrence's
	 * timeline state (later valid work survives). Nothing revertible →
	 * 200 with `undone: false` (zero writes).
	 *
	 * @NoAdminRequired
	 */
	public function undo(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());
			$this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

			$result = $this->completionService->undo($house->getId(), $houseUser, $id);

			$data = [
				'roomTask' => $result['roomTask']->jsonSerialize($this->timeZoneOf($house)),
				'room' => $result['room']->jsonSerialize(),
				'task' => $result['task']->jsonSerialize(),
				'rewards' => $result['rewards'],
				'undone' => $result['undone'],
				'occurrence' => $result['occurrence'] ?? null,
			];

			return new JSONResponse([
				'success' => true,
				'data' => $data
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('undo', $e, ['roomTaskId' => $id]);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'UNDO_TASK_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
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
	 * Bulk-assign multiple room tasks to one house member (CR-SVC-61).
	 *
	 * Delegates to {@see RoomTaskService::bulkAssignTasks}, which scopes the
	 * ids to the caller's house in one query: foreign/missing ids are
	 * skipped per item (isolation contract), while a non-member target user
	 * fails the whole request up front (→ 400).
	 *
	 * @NoAdminRequired
	 */
	public function bulkAssign(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$userId = (string)($this->request->getParam('userId') ?? '');
			if (empty(trim($userId))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('User ID is required')
					]
				], 400);
			}

			$rawIds = $this->request->getParam('roomTaskIds');
			if (!is_array($rawIds)) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('roomTaskIds must be an array of room task IDs')
					]
				], 400);
			}

			$roomTaskIds = [];
			foreach ($rawIds as $rawId) {
				if (!is_numeric($rawId) || (int)$rawId < 1) {
					return new JSONResponse([
						'success' => false,
						'error' => [
							'code' => 'VALIDATION_ERROR',
							'message' => $this->l->t('roomTaskIds must be an array of positive integers')
						]
					], 400);
				}
				$roomTaskIds[] = (int)$rawId;
			}

			$assignedTasks = $this->roomTaskService->bulkAssignTasks($roomTaskIds, $userId, $house->getId(), $houseUser);
			$tz = $this->timeZoneOf($house);

			// CS-PLAN-066 (D1): the returned successes carry their canonical
			// assignee sets — the client reconciles exactly what the server
			// persisted, never the requested set.
			return new JSONResponse([
				'success' => true,
				'data' => $this->serializeFlatRoomTasks($assignedTasks, $tz, (int)$house->getId()),
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (\InvalidArgumentException $e) {
			// Service-curated membership message — safe to surface verbatim.
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('bulkAssign', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'BULK_ASSIGN_TASKS_ERROR',
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
	 * occurrence}` (CS-PLAN-066 D1; CS-PLAN-072 WP1 adds the occurrence
	 * target block). One batched assignee read for the whole set — the
	 * arrays are unique, deterministically ordered Nextcloud user IDs.
	 *
	 * @param array<int, array{roomTask: \OCA\ChoreStrike\Db\RoomTask, task: \OCA\ChoreStrike\Db\Task, room: \OCA\ChoreStrike\Db\Room}> $rows
	 * @param \DateTimeZone $tz House timezone for day-boundary serialization
	 * @return array<int, array<string, mixed>>
	 */
	private function serializeExecutionRows(array $rows, \DateTimeZone $tz): array {
		$roomTasks = array_map(static fn (array $row) => $row['roomTask'], $rows);
		$assigneesByTask = $this->roomTaskReadService->assigneesByRoomTasks($roomTasks);

		return array_map(static function (array $row) use ($tz, $assigneesByTask) {
			return [
				'roomTask' => $row['roomTask']->jsonSerialize($tz),
				'task' => $row['task']->jsonSerialize(),
				'room' => $row['room']->jsonSerialize(),
				'assignees' => $assigneesByTask[$row['roomTask']->getId()] ?? [],
				'occurrence' => $row['occurrence'] ?? null,
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
	 * Get room task statistics
	 *
	 * @NoAdminRequired
	 */
	public function roomStats(int $roomId): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			if ($roomId <= 0) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('roomId is required')
					]
				], 400);
			}

			// CS-PLAN-040 security fix: the roomId is caller-supplied, so it
			// must be validated against the caller's house like every sibling
			// endpoint — membership alone did not stop cross-house reads.
			$this->roomTaskService->validateRoomOwnership($roomId, $house->getId());

			$stats = $this->roomTaskService->getRoomTaskStats($roomId, $houseUser);

			return new JSONResponse([
				'success' => true,
				'data' => $stats
			]);
		} catch (NotFoundException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'RESOURCE_NOT_FOUND',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 404);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('roomStats', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ROOM_STATS_ERROR',
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
			$tz = $this->timeZoneOf($house);

			// CS-PLAN-065 (decision D4): the rows carry their canonical
			// assignee sets (service-side batched read, RF-058-11) so My Day
			// never re-interprets an unknown set as unassigned after the
			// child limit truncated the list.
			return new JSONResponse([
				'success' => true,
				'data' => array_map(static fn (array $item): array => [
					'roomTask' => $item['roomTask']->jsonSerialize($tz),
					'task' => $item['task']->jsonSerialize(),
					'room' => $item['room']->jsonSerialize(),
					'assignees' => $item['assignees'],
					'occurrence' => $item['occurrence'] ?? null,
				], $filteredTasks)
			]);
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
}
