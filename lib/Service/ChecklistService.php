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
 * The sub-tasks themselves are written by the one form's two aggregates
 * ({@see SubTaskWriter}, CS-PLAN-091 WP-B6); this service reads them and
 * owns the ticks of the current cycle (execution surface).
 */
class ChecklistService {
	private TaskChecklistItemMapper $checklistItemMapper;
	private CycleProgressMapper $progressMapper;
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private HouseMapper $houseMapper;
	private TransactionExecutor $transactions;
	private RoomTaskService $roomTaskService;
	private CycleProjection $cycleProjection;
	private CalendarService $calendarService;
	private IL10N $l10n;
	private AppLogger $appLogger;
	private TaskAccessPolicy $taskAccessPolicy;

	public function __construct(
		TaskChecklistItemMapper $checklistItemMapper,
		CycleProgressMapper $progressMapper,
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		HouseMapper $houseMapper,
		TransactionExecutor $transactions,
		RoomTaskService $roomTaskService,
		CycleProjection $cycleProjection,
		CalendarService $calendarService,
		IL10N $l10n,
		AppLogger $appLogger,
		TaskAccessPolicy $taskAccessPolicy,
	) {
		$this->checklistItemMapper = $checklistItemMapper;
		$this->progressMapper = $progressMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->taskMapper = $taskMapper;
		$this->houseMapper = $houseMapper;
		$this->transactions = $transactions;
		$this->roomTaskService = $roomTaskService;
		$this->cycleProjection = $cycleProjection;
		$this->calendarService = $calendarService;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
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
		$itemIds = $this->itemIdsOf((int)$roomTask->getTaskId());

		$cycle = $this->currentCycle($roomTask, $task, $this->houseMapper->find($houseId));
		if ($cycle === null || ($cycleKey !== null && $cycleKey !== '' && $cycleKey !== $cycle->key)) {
			return ['items' => [], 'stats' => $this->emptyStats(count($itemIds)), 'cycle' => null];
		}

		// Ticks of a sub-task the form removed stay as facts but count no more.
		$rows = array_values(array_filter(
			$this->progressMapper->findByCycle($roomTaskId, $cycle->key),
			static fn ($row): bool => in_array($row->getChecklistItemId(), $itemIds, true),
		));
		return [
			'items' => $rows,
			'stats' => CycleProgressMapper::statsOf($rows, $itemIds),
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
		$itemIds = $this->itemIdsOf((int)$roomTask->getTaskId());

		return $this->transactions->run(function () use ($roomTask, $cycleKey, $itemId, $expectedRevision, $houseId, $viewer, $itemIds) {
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
				'stats' => CycleProgressMapper::statsOf([...$others, $progress], $itemIds),
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
		$itemIds = $this->itemIdsOf($taskId);
		if ($itemIds !== []) {
			$this->progressMapper->checkAll($roomTaskId, $cycleKey, $itemIds, $houseUserId);
		}
	}

	/** @return int[] The ids of a definition's sub-tasks */
	private function itemIdsOf(int $taskId): array {
		return array_map(
			static fn (TaskChecklistItem $item): int => (int)$item->getId(),
			$this->checklistItemMapper->findByTaskId($taskId)
		);
	}

	/** The cycle ticks belong to: the current one of the room task (write-free). */
	private function currentCycle(RoomTask $roomTask, Task $task, House $house): ?CurrentCycle {
		return $this->cycleProjection->currentFor($roomTask, $task, $this->calendarService->getTimeZone($house), new \DateTime());
	}

	/**
	 * @return array<string, int|float>
	 */
	private function emptyStats(int $itemCount): array {
		return ['total' => $itemCount, 'checked' => 0, 'unchecked' => $itemCount, 'percentage' => 0];
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
