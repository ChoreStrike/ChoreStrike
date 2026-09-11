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
use OCA\ChoreStrike\Service\HouseService;
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
	private CompletionService $completionService;
	private TaskCreationService $taskCreationService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IL10N $l;
	private \OCA\ChoreStrike\Service\CalendarService $calendarService;

	public function __construct(
		IRequest $request,
		RoomTaskService $roomTaskService,
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
	 * Get room tasks for a room
	 *
	 * @NoAdminRequired
	 */
	public function index(?int $roomId = null, ?int $scopeId = null, bool $activeOnly = true): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			if ($scopeId !== null) {
				$roomTasks = $this->roomTaskService->getScopeTasksScopedToHouse($scopeId, $house->getId(), $houseUser, $activeOnly);
			} elseif ($roomId !== null) {
				// CR-053-02: the roomId is caller-supplied, so it must be
				// validated against the caller's house (same fix as
				// roomStats) — membership alone allowed cross-house reads.
				$this->roomTaskService->validateRoomOwnership($roomId, $house->getId());
				$roomTasks = $this->roomTaskService->getRoomTasks($roomId, $houseUser, $activeOnly);
			} else {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('roomId or scopeId is required')
					]
				], 400);
			}

			$assigneesByTask = $this->roomTaskService->getAssigneeUserIdsByRoomTasks($roomTasks);
			$tz = $this->timeZoneOf($house);

			return new JSONResponse([
				'success' => true,
				'data' => array_map(function ($roomTask) use ($assigneesByTask, $tz) {
					$data = $roomTask->jsonSerialize($tz);
					$data['assignees'] = $assigneesByTask[$roomTask->getId()] ?? [];
					return $data;
				}, $roomTasks)
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
	 * both create paths (D1).
	 */
	private function serializeAggregate(array $aggregate, \OCA\ChoreStrike\Db\House $house): array {
		$tz = $this->timeZoneOf($house);
		return [
			'task' => $aggregate['task']->jsonSerialize(),
			'roomTask' => $aggregate['roomTask']->jsonSerialize($tz),
			'assignees' => array_map(static fn ($assignee) => $assignee->jsonSerialize(), $aggregate['assignees']),
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

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn ($a) => $a->jsonSerialize(), $assignees)
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

			try {
				$nextDueDate = new \DateTime($nextDue);
			} catch (\Exception $e) {
				throw new \InvalidArgumentException($this->l->t('Invalid nextDue date format'));
			}

			$roomTask = $this->roomTaskService->updateDueDate($id, $nextDueDate, $houseUser);

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
	 * Complete room task
	 *
	 * Calls the CompletionService aggregate directly (CS-PLAN-064, decision
	 * D2 — the RoomTaskService::completeTask passthrough is gone).
	 *
	 * @NoAdminRequired
	 */
	public function complete(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->assertHouseMember($house->getId());
			$this->roomTaskService->validateRoomTaskOwnership($id, $house->getId());

			$currentUserId = $this->authService->requireCurrentUserId();
			$result = $this->completionService->complete($house->getId(), $currentUserId, $id);

			return new JSONResponse([
				'success' => true,
				'data' => [
					'roomTask' => $result['roomTask']->jsonSerialize($this->timeZoneOf($house)),
					'room' => $result['room']->jsonSerialize(),
					'task' => $result['task']->jsonSerialize(),
					'rewards' => $result['rewards']
				]
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
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
	 * Undo the newest revertible completion of a room task (CS-PLAN-064 WP5).
	 *
	 * One idempotent aggregate: restores recurrence, ledger marking,
	 * rewards/streak/freezes/titles, cleanliness, and checklist progress
	 * from the recorded effect. Nothing revertible → 200 with
	 * `undone: false` (zero writes).
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

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn ($roomTask) => $roomTask->jsonSerialize($tz), $assignedTasks)
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
	 * @NoAdminRequired
	 */
	public function overdue(?int $roomId = null): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$overdueTasks = $this->roomTaskService->getOverdueTasks($house->getId(), $houseUser, $roomId);
			$tz = $this->timeZoneOf($house);

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn ($task) => $task->jsonSerialize($tz), $overdueTasks)
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
	 * @NoAdminRequired
	 */
	public function dueToday(?int $roomId = null): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$dueTodayTasks = $this->roomTaskService->getDueTodayTasks($house->getId(), $houseUser, $roomId);
			$tz = $this->timeZoneOf($house);

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn ($task) => $task->jsonSerialize($tz), $dueTodayTasks)
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

			$filteredTasks = $this->roomTaskService->getFilteredDailyTasksForUser(
				$house->getId(),
				$houseUser,
				new \DateTime()
			);
			$tz = $this->timeZoneOf($house);

			// CS-PLAN-065 (decision D4): the rows carry their canonical
			// assignee sets so My Day never re-interprets an unknown set as
			// unassigned after the child limit truncated the list.
			$roomTasks = array_map(static fn (array $item) => $item['roomTask'], $filteredTasks);
			$assigneesByTask = $this->roomTaskService->getAssigneeUserIdsByRoomTasks($roomTasks);

			return new JSONResponse([
				'success' => true,
				'data' => array_map(function ($item) use ($tz, $assigneesByTask) {
					return [
						'roomTask' => $item['roomTask']->jsonSerialize($tz),
						'task' => $item['task']->jsonSerialize(),
						'room' => $item['room']->jsonSerialize(),
						'assignees' => $assigneesByTask[$item['roomTask']->getId()] ?? [],
					];
				}, $filteredTasks)
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
