<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CycleProgressMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskChecklistItem;
use OCA\ChoreStrike\Db\TaskChecklistItemMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Exception\StaleTargetException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Service for checklist business logic (CR-053-01).
 *
 * Owns the checklist authorization guard so the controller stays thin:
 * every endpoint resolves the affected task and runs
 * {@see assertTaskAccessible()} — house linkage via the room-chain
 * (room_tasks → rooms → floors → house, mirroring
 * RoomTaskService::validateRoomTaskOwnership) plus the task-visibility
 * matrix, delegated to {@see TaskAccessPolicy} (public → any member;
 * private → creator ∪ assignees; admin-only → house owner, CS-PLAN-061
 * §3.2 D2). Denials surface as NotFoundException (404, anti-enumeration —
 * TaskController precedent).
 *
 * Definition mutations (item add/update/delete/reorder) are owner-only in
 * the service (decision D4); progress read/toggle stays on the visibility
 * matrix (execution surface).
 */
class ChecklistService {
	private TaskChecklistItemMapper $checklistItemMapper;
	private CycleProgressMapper $progressMapper;
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private HouseMapper $houseMapper;
	private TransactionExecutor $transactions;
	private RoomTaskService $roomTaskService;
	private CycleService $cycleService;
	private CalendarService $calendarService;
	private IL10N $l10n;
	private AppLogger $appLogger;
	private AuthorizationService $authorization;
	private TaskAccessPolicy $taskAccessPolicy;

	public function __construct(
		TaskChecklistItemMapper $checklistItemMapper,
		CycleProgressMapper $progressMapper,
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		HouseMapper $houseMapper,
		TransactionExecutor $transactions,
		RoomTaskService $roomTaskService,
		CycleService $cycleService,
		CalendarService $calendarService,
		IL10N $l10n,
		AppLogger $appLogger,
		AuthorizationService $authorization,
		TaskAccessPolicy $taskAccessPolicy,
	) {
		$this->checklistItemMapper = $checklistItemMapper;
		$this->progressMapper = $progressMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->taskMapper = $taskMapper;
		$this->houseMapper = $houseMapper;
		$this->transactions = $transactions;
		$this->roomTaskService = $roomTaskService;
		$this->cycleService = $cycleService;
		$this->calendarService = $calendarService;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
		$this->authorization = $authorization;
		$this->taskAccessPolicy = $taskAccessPolicy;
	}

	/**
	 * Assert the viewer may access the task's checklist and return it.
	 *
	 * @throws NotFoundException if the task does not exist, is not linked
	 *                           to the viewer's house, or is invisible to
	 *                           the viewer per the visibility matrix
	 */
	private function assertTaskAccessible(int $taskId, int $houseId, HouseUser $viewer): Task {
		try {
			$task = $this->taskMapper->find($taskId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Task not found'));
		}

		$houseIds = $this->roomTaskMapper->findHouseIdsForTask($taskId);
		if ($houseIds !== [] && !in_array($houseId, $houseIds, true)) {
			$this->appLogger->securityWarning('Checklist access denied: task belongs to another house', [
				'service' => 'ChecklistService',
				'action' => 'assertTaskAccessible',
				'taskId' => $taskId,
				'houseId' => $houseId,
			]);
			throw new NotFoundException($this->l10n->t('Task not found'));
		}

		if (!$this->taskAccessPolicy->canViewTask($task, $viewer)) {
			$this->appLogger->securityWarning('Checklist access denied: visibility matrix', [
				'service' => 'ChecklistService',
				'action' => 'assertTaskAccessible',
				'taskId' => $taskId,
				'houseId' => $houseId,
				'visibilityType' => $task->getVisibilityType(),
			]);
			throw new NotFoundException($this->l10n->t('Task not found'));
		}

		return $task;
	}

	/**
	 * Definition mutations are owner-only in the service (CS-PLAN-061 §3.4,
	 * decision D4) — matches the frontend gating and CS-PLAN-058 WP2 text.
	 *
	 * @throws ForbiddenException if the viewer is not a house owner
	 */
	private function assertDefinitionManager(HouseUser $viewer): void {
		if (!$viewer->isOwner()) {
			$this->appLogger->warning('Checklist definition mutation denied for non-owner', [
				'service' => 'ChecklistService',
				'action' => 'assertDefinitionManager',
				'userId' => $viewer->getNextcloudUserId(),
			]);
			throw new ForbiddenException($this->l10n->t('Only house owners may edit checklists'));
		}
	}

	/**
	 * Resolve a room task in the caller's house (ownership chain + task
	 * guard) and return it with its definition.
	 *
	 * @return array{0: RoomTask, 1: Task}
	 * @throws NotFoundException if the room task or its task is not accessible
	 */
	private function assertRoomTaskAccessible(int $roomTaskId, int $houseId, HouseUser $viewer): array {
		$this->roomTaskService->validateRoomTaskOwnership($roomTaskId, $houseId);
		$roomTask = $this->roomTaskService->getRoomTask($roomTaskId);

		return [$roomTask, $this->assertTaskAccessible($roomTask->getTaskId(), $houseId, $viewer)];
	}

	/**
	 * Get all checklist items of a task.
	 *
	 * @return TaskChecklistItem[]
	 */
	public function getTaskItems(int $taskId, int $houseId, HouseUser $viewer): array {
		$this->assertTaskAccessible($taskId, $houseId, $viewer);
		return $this->checklistItemMapper->findByTaskId($taskId);
	}

	/**
	 * Add a checklist item to a task (appends when no sortOrder is given).
	 * Owner-only (decision D4).
	 */
	public function addTaskItem(int $taskId, string $label, ?int $sortOrder, int $houseId, HouseUser $viewer): TaskChecklistItem {
		$this->assertTaskAccessible($taskId, $houseId, $viewer);
		$this->assertDefinitionManager($viewer);

		$actorId = 0;
		$item = $this->transactions->run(function () use ($taskId, $label, $sortOrder, $houseId, &$actorId): TaskChecklistItem {
			// FIRST lock of the documented order: the house row.
			$this->houseMapper->findForUpdate($houseId);
			$actor = $this->authorization->requireHouseOwner($houseId);
			$actorId = (int)$actor->getId();
			$this->assertTaskAccessible($taskId, $houseId, $actor);

			$sortOrder ??= count($this->checklistItemMapper->findByTaskId($taskId));
			return $this->checklistItemMapper->create($taskId, $label, $sortOrder);
		});
		$this->appLogger->audit('Checklist item added', [
			'event' => 'house.checklist.item_added', 'service' => 'ChecklistService',
			'houseId' => $houseId, 'actorId' => $actorId, 'taskId' => $taskId,
			'itemId' => (int)$item->getId(), 'outcome' => 'created',
		]);
		return $item;
	}

	/**
	 * Relabel a checklist item; a foreign item id is rejected with 404.
	 * Owner-only (decision D4). Runs under the house row lock (CS-PLAN-071).
	 */
	public function updateTaskItem(int $itemId, string $label, int $houseId, HouseUser $viewer): TaskChecklistItem {
		$item = $this->findItemWithGuard($itemId, $houseId, $viewer);
		$this->assertDefinitionManager($viewer);

		$actorId = 0;
		$updated = $this->transactions->run(function () use ($itemId, $label, $houseId, &$actorId): TaskChecklistItem {
			$this->houseMapper->findForUpdate($houseId);
			$actor = $this->authorization->requireHouseOwner($houseId);
			$actorId = (int)$actor->getId();
			$fresh = $this->checklistItemMapper->find($itemId);
			$this->assertTaskAccessible($fresh->getTaskId(), $houseId, $actor);
			return $this->checklistItemMapper->updateLabel($itemId, $label);
		});
		$this->appLogger->audit('Checklist item update completed', [
			'event' => 'house.checklist.item_updated', 'service' => 'ChecklistService',
			'houseId' => $houseId, 'actorId' => $actorId,
			'itemId' => $itemId, 'outcome' => 'completed',
		]);
		return $updated;
	}

	/**
	 * Delete a checklist item; a foreign item id is rejected with 404.
	 * Owner-only (decision D4). Runs under the house row lock (CS-PLAN-071).
	 */
	public function deleteTaskItem(int $itemId, int $houseId, HouseUser $viewer): void {
		$item = $this->findItemWithGuard($itemId, $houseId, $viewer);
		$this->assertDefinitionManager($viewer);

		$actorId = 0;
		$this->transactions->run(function () use ($itemId, $houseId, &$actorId): void {
			$this->houseMapper->findForUpdate($houseId);
			$actor = $this->authorization->requireHouseOwner($houseId);
			$actorId = (int)$actor->getId();
			$fresh = $this->checklistItemMapper->find($itemId);
			$this->assertTaskAccessible($fresh->getTaskId(), $houseId, $actor);
			$this->checklistItemMapper->delete($fresh);
		});

		$this->appLogger->audit('Checklist item deleted', [
			'event' => 'house.checklist.item_deleted',
			'actorId' => $actorId,
			'outcome' => 'deleted',
			'service' => 'ChecklistService',
			'action' => 'deleteTaskItem',
			'taskId' => $item->getTaskId(),
			'houseId' => $houseId,
			'itemId' => $itemId,
		]);
	}

	/**
	 * Reorder checklist items. Owner-only (decision D4).
	 *
	 * The raw client map (item id => order) is validated before it reaches
	 * the mapper: ids must resolve to items of exactly one task accessible
	 * to the viewer, and orders must be integers. Foreign ids are rejected
	 * with 404 instead of silently rewriting another task's items.
	 *
	 * @param array $itemOrders Map of itemId => sortOrder
	 * @throws NotFoundException if an item does not exist or is foreign
	 * @throws ForbiddenException if the viewer is not a house owner
	 * @throws \InvalidArgumentException if the payload shape is invalid
	 */
	public function reorderTaskItems(array $itemOrders, int $houseId, HouseUser $viewer): void {
		$sanitized = [];
		foreach ($itemOrders as $itemId => $sortOrder) {
			if (!is_numeric($itemId) || !is_int($sortOrder)) {
				throw new \InvalidArgumentException($this->l10n->t('Item orders must map integer item ids to integer orders'));
			}
			$sanitized[(int)$itemId] = $sortOrder;
		}

		$items = $this->checklistItemMapper->findByIds(array_keys($sanitized));
		if (count($items) !== count($sanitized)) {
			throw new NotFoundException($this->l10n->t('Checklist item not found'));
		}

		$taskIds = array_unique(array_map(static fn (TaskChecklistItem $item): int => $item->getTaskId(), $items));
		if (count($taskIds) !== 1) {
			throw new \InvalidArgumentException($this->l10n->t('Checklist items must belong to a single task'));
		}

		$this->assertTaskAccessible($taskIds[0], $houseId, $viewer);
		$this->assertDefinitionManager($viewer);

		$actorId = 0;
		$this->transactions->run(function () use ($sanitized, $houseId, &$actorId): void {
			// FIRST lock of the documented order: the house row.
			$this->houseMapper->findForUpdate($houseId);
			$actor = $this->authorization->requireHouseOwner($houseId);
			$actorId = (int)$actor->getId();
			$freshItems = $this->checklistItemMapper->findByIds(array_keys($sanitized));
			if (count($freshItems) !== count($sanitized)) {
				throw new NotFoundException($this->l10n->t('Task not found'));
			}
			$freshTaskIds = array_unique(array_map(static fn (TaskChecklistItem $item): int => $item->getTaskId(), $freshItems));
			foreach ($freshTaskIds as $taskId) {
				$this->assertTaskAccessible($taskId, $houseId, $actor);
			}
			$this->checklistItemMapper->updateSortOrders($sanitized);
		});
		$this->appLogger->audit('Checklist reorder completed', [
			'event' => 'house.checklist.reordered', 'service' => 'ChecklistService',
			'houseId' => $houseId, 'actorId' => $actorId,
			'taskId' => (int)$taskIds[0], 'outcome' => 'completed',
		]);
	}

	/**
	 * Get the sub-task ticks and stats of the CURRENT cycle of a room task
	 * (ADR-021 decision 3). A GET causes no DB writes. Each row carries its
	 * cycle key and progress revision. A key that is no longer current, or
	 * a task without a cycle, answers empty.
	 *
	 * @param string|null $cycleKey The cycle the client shows (null = the current one)
	 * @return array{items: array, stats: array, cycle: ?array}
	 */
	public function getRoomTaskProgress(int $roomTaskId, ?string $cycleKey, int $houseId, HouseUser $viewer): array {
		[$roomTask, $task] = $this->assertRoomTaskAccessible($roomTaskId, $houseId, $viewer);
		$itemCount = count($this->checklistItemMapper->findByTaskId($roomTask->getTaskId()));

		$cycle = $this->currentCycle($roomTask, $task, $this->houseMapper->find($houseId));
		if ($cycle === null || ($cycleKey !== null && $cycleKey !== '' && $cycleKey !== $cycle->key)) {
			return ['items' => [], 'stats' => $this->emptyStats($itemCount), 'cycle' => null];
		}

		$rows = $this->progressMapper->findByCycle($roomTaskId, $cycle->key);
		return [
			'items' => $rows,
			'stats' => CycleProgressMapper::statsOf($rows, $itemCount),
			'cycle' => ['cycleKey' => $cycle->key, 'windowStartOn' => $cycle->startOn, 'windowEndOn' => $cycle->endOn],
		];
	}

	/**
	 * Toggle a sub-task of the CURRENT cycle; the item must belong to that
	 * room task's task checklist. Ticking only some sub-tasks leaves the
	 * task open and the ticks saved (D-089-02). A stale cycle key or
	 * progress revision conflicts with 409 instead of leaking into a later
	 * cycle.
	 *
	 * CS-PLAN-071 WP0: the toggle runs in one house-first transaction —
	 * the house row lock, then the room-task row lock, then the locked
	 * progress row read-modify-write. Two concurrent toggles still
	 * serialize; a concurrent completion or member removal now serializes
	 * with the toggle instead of interleaving.
	 *
	 * @param string|null $cycleKey The cycle the client shows (null = the current one)
	 * @param int|null $expectedRevision Stale-mutation guard (409 when the row moved on)
	 * @return array{progress: \OCA\ChoreStrike\Db\CycleProgress, stats: array, cycle: array}
	 */
	public function toggleItem(int $roomTaskId, ?string $cycleKey, int $itemId, ?int $expectedRevision, int $houseId, HouseUser $viewer): array {
		[$roomTask] = $this->assertRoomTaskAccessible($roomTaskId, $houseId, $viewer);
		$this->assertItemBelongsToTask($itemId, $roomTask->getTaskId());
		$itemCount = count($this->checklistItemMapper->findByTaskId($roomTask->getTaskId()));

		return $this->transactions->run(function () use ($roomTask, $cycleKey, $itemId, $expectedRevision, $houseId, $viewer, $itemCount) {
			// FIRST locks of the documented order: house → room_task. The
			// definition is read under them: its rule decides the cycle.
			$house = $this->houseMapper->findForUpdate($houseId);
			$locked = $this->roomTaskMapper->lockForUpdate($roomTask->getId());

			$cycle = $this->currentCycle($locked, $this->taskMapper->find($locked->getTaskId()), $house);
			if ($cycle === null) {
				throw new NotFoundException($this->l10n->t('Room task not found'));
			}
			$rows = $this->progressMapper->findByCycle((int)$locked->getId(), $cycle->key);
			$stale = $cycleKey !== null && $cycleKey !== '' && $cycleKey !== $cycle->key;
			if (!$stale && $expectedRevision !== null) {
				$stale = max([0, ...array_map(static fn ($row): int => $row->getRevision(), $rows)]) !== $expectedRevision;
			}
			if ($stale) {
				throw new StaleTargetException(
					$this->l10n->t('The checklist changed since it was displayed. Reload and try again.')
				);
			}

			$progress = $this->progressMapper->toggleLocked((int)$locked->getId(), $cycle->key, $itemId, $viewer->getId());
			$others = array_filter($rows, static fn ($row): bool => $row->getChecklistItemId() !== $itemId);
			return [
				'progress' => $progress,
				'stats' => CycleProgressMapper::statsOf([...$others, $progress], $itemCount),
				'cycle' => ['cycleKey' => $cycle->key, 'windowStartOn' => $cycle->startOn, 'windowEndOn' => $cycle->endOn],
			];
		});
	}

	/**
	 * A completion closes its cycle UNDER LOCK (ADR-021 decision 4): every
	 * sub-task of the cycle is done with it, in the completion's own
	 * transaction. Ticks that were already set keep who set them.
	 */
	public function markCycleDoneUnderLock(int $roomTaskId, int $taskId, string $cycleKey, ?int $houseUserId): void {
		$itemIds = array_map(
			static fn (TaskChecklistItem $item): int => (int)$item->getId(),
			$this->checklistItemMapper->findByTaskId($taskId)
		);
		if ($itemIds !== []) {
			$this->progressMapper->checkAll($roomTaskId, $cycleKey, $itemIds, $houseUserId);
		}
	}

	/** The cycle ticks belong to: the current one of the room task (write-free). */
	private function currentCycle(RoomTask $roomTask, Task $task, House $house): ?CurrentCycle {
		return $this->cycleService->currentFor($roomTask, $task, $this->calendarService->getTimeZone($house), new \DateTime());
	}

	/**
	 * @return array<string, int|float>
	 */
	private function emptyStats(int $itemCount): array {
		return ['total' => $itemCount, 'checked' => 0, 'unchecked' => $itemCount, 'percentage' => 0];
	}

	/**
	 * Resolve an item and guard its task; foreign ids end as 404.
	 */
	private function findItemWithGuard(int $itemId, int $houseId, HouseUser $viewer): TaskChecklistItem {
		try {
			$item = $this->checklistItemMapper->find($itemId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Checklist item not found'));
		}

		$this->assertTaskAccessible($item->getTaskId(), $houseId, $viewer);

		return $item;
	}

	/**
	 * Assert the item belongs to the task's checklist (foreign item ids
	 * must not toggle progress rows of an unrelated task).
	 *
	 * @throws NotFoundException if the item does not exist or belongs to another task
	 */
	private function assertItemBelongsToTask(int $itemId, int $taskId): void {
		try {
			$item = $this->checklistItemMapper->find($itemId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Checklist item not found'));
		}

		if ($item->getTaskId() !== $taskId) {
			$this->appLogger->securityWarning('Checklist item does not belong to the room task\'s task', [
				'service' => 'ChecklistService',
				'action' => 'assertItemBelongsToTask',
				'taskId' => $taskId,
				'itemId' => $itemId,
			]);
			throw new NotFoundException($this->l10n->t('Checklist item not found'));
		}
	}
}
