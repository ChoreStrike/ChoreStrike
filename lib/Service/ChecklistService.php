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
use OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskChecklistItem;
use OCA\ChoreStrike\Db\TaskChecklistItemMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
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
	private RoomTaskChecklistProgressMapper $progressMapper;
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private HouseMapper $houseMapper;
	private TransactionExecutor $transactions;
	private RoomTaskService $roomTaskService;
	private IL10N $l10n;
	private AppLogger $appLogger;
	private AuthorizationService $authorization;
	private TaskAccessPolicy $taskAccessPolicy;

	public function __construct(
		TaskChecklistItemMapper $checklistItemMapper,
		RoomTaskChecklistProgressMapper $progressMapper,
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		HouseMapper $houseMapper,
		TransactionExecutor $transactions,
		RoomTaskService $roomTaskService,
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
	 * guard) and return it.
	 *
	 * @throws NotFoundException if the room task or its task is not accessible
	 */
	private function assertRoomTaskAccessible(int $roomTaskId, int $houseId, HouseUser $viewer): RoomTask {
		$this->roomTaskService->validateRoomTaskOwnership($roomTaskId, $houseId);
		$roomTask = $this->roomTaskService->getRoomTask($roomTaskId);
		$this->assertTaskAccessible($roomTask->getTaskId(), $houseId, $viewer);

		return $roomTask;
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

		return $this->transactions->run(function () use ($taskId, $label, $sortOrder, $houseId): TaskChecklistItem {
			// FIRST lock of the documented order: the house row.
			$this->houseMapper->findForUpdate($houseId);

			$sortOrder ??= count($this->checklistItemMapper->findByTaskId($taskId));
			$item = $this->checklistItemMapper->create($taskId, $label, $sortOrder);

			$this->appLogger->info('Checklist item added', [
				'service' => 'ChecklistService',
				'action' => 'addTaskItem',
				'taskId' => $taskId,
				'houseId' => $houseId,
				'itemId' => $item->getId(),
			]);

			return $item;
		});
	}

	/**
	 * Relabel a checklist item; a foreign item id is rejected with 404.
	 * Owner-only (decision D4). Runs under the house row lock (CS-PLAN-071).
	 */
	public function updateTaskItem(int $itemId, string $label, int $houseId, HouseUser $viewer): TaskChecklistItem {
		$item = $this->findItemWithGuard($itemId, $houseId, $viewer);
		$this->assertDefinitionManager($viewer);

		return $this->transactions->run(function () use ($itemId, $label, $houseId): TaskChecklistItem {
			$this->houseMapper->findForUpdate($houseId);
			return $this->checklistItemMapper->updateLabel($itemId, $label);
		});
	}

	/**
	 * Delete a checklist item; a foreign item id is rejected with 404.
	 * Owner-only (decision D4). Runs under the house row lock (CS-PLAN-071).
	 */
	public function deleteTaskItem(int $itemId, int $houseId, HouseUser $viewer): void {
		$item = $this->findItemWithGuard($itemId, $houseId, $viewer);
		$this->assertDefinitionManager($viewer);

		$this->transactions->run(function () use ($itemId, $houseId, $item): void {
			$this->houseMapper->findForUpdate($houseId);
			$this->checklistItemMapper->delete($item);
		});

		$this->appLogger->info('Checklist item deleted', [
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

		$this->transactions->run(function () use ($sanitized, $houseId): void {
			// FIRST lock of the documented order: the house row.
			$this->houseMapper->findForUpdate($houseId);
			$this->checklistItemMapper->updateSortOrders($sanitized);
		});
	}

	/**
	 * Get checklist progress entries and stats for a room task.
	 *
	 * @return array{items: array, stats: array}
	 */
	public function getRoomTaskProgress(int $roomTaskId, int $houseId, HouseUser $viewer): array {
		$this->assertRoomTaskAccessible($roomTaskId, $houseId, $viewer);

		return [
			'items' => $this->progressMapper->findByRoomTaskId($roomTaskId),
			'stats' => $this->progressMapper->getProgressStats($roomTaskId),
		];
	}

	/**
	 * Toggle a checklist item for a room task; the item must belong to
	 * that room task's task checklist.
	 *
	 * CS-PLAN-071 WP0: the toggle runs in one house-first transaction —
	 * the house row lock, then the room-task row lock, then the locked
	 * progress row read-modify-write (the former mapper-owned transaction
	 * acquired only the progress-row lock). Two concurrent toggles still
	 * serialize; a concurrent completion or member removal now serializes
	 * with the toggle instead of interleaving.
	 *
	 * @return array{progress: \OCA\ChoreStrike\Db\RoomTaskChecklistProgress, stats: array}
	 */
	public function toggleItem(int $roomTaskId, int $itemId, int $houseId, HouseUser $viewer): array {
		$roomTask = $this->assertRoomTaskAccessible($roomTaskId, $houseId, $viewer);
		$this->assertItemBelongsToTask($itemId, $roomTask->getTaskId());

		$progress = $this->transactions->run(function () use ($roomTaskId, $itemId, $houseId, $viewer) {
			// FIRST locks of the documented order: house → room_task.
			$this->houseMapper->findForUpdate($houseId);
			$this->roomTaskMapper->lockForUpdate($roomTaskId);

			return $this->progressMapper->toggleLocked($roomTaskId, $itemId, $viewer->getId());
		});

		return [
			'progress' => $progress,
			'stats' => $this->progressMapper->getProgressStats($roomTaskId),
		];
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
