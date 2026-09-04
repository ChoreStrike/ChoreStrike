<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Service for room-related business logic
 */
class RoomService {
	private RoomMapper $roomMapper;
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private HouseUserMapper $houseUserMapper;
	private FloorMapper $floorMapper;
	private GameLogicService $gameLogicService;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private RoomTaskChecklistProgressMapper $checklistProgressMapper;
	private HouseService $houseService;
	private HouseMapper $houseMapper;
	private NotificationService $notificationService;
	private TransactionExecutor $transactions;
	private IL10N $l10n;
	private AppLogger $appLogger;
	private CalendarService $calendarService;
	private AuthorizationService $authorization;
	private TaskAccessPolicy $taskAccessPolicy;

	public function __construct(
		RoomMapper $roomMapper,
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		HouseUserMapper $houseUserMapper,
		FloorMapper $floorMapper,
		GameLogicService $gameLogicService,
		TaskAssigneeMapper $taskAssigneeMapper,
		CompletedTaskMapper $completedTaskMapper,
		RoomTaskChecklistProgressMapper $checklistProgressMapper,
		HouseService $houseService,
		HouseMapper $houseMapper,
		NotificationService $notificationService,
		TransactionExecutor $transactions,
		IL10N $l10n,
		AppLogger $appLogger,
		CalendarService $calendarService,
		AuthorizationService $authorization,
		TaskAccessPolicy $taskAccessPolicy,
	) {
		$this->roomMapper = $roomMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->taskMapper = $taskMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->floorMapper = $floorMapper;
		$this->gameLogicService = $gameLogicService;
		$this->taskAssigneeMapper = $taskAssigneeMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->checklistProgressMapper = $checklistProgressMapper;
		$this->houseService = $houseService;
		$this->houseMapper = $houseMapper;
		$this->notificationService = $notificationService;
		$this->transactions = $transactions;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
		$this->calendarService = $calendarService;
		$this->authorization = $authorization;
		$this->taskAccessPolicy = $taskAccessPolicy;
	}

	/**
	 * Validate that a room belongs to the specified house
	 *
	 * @param int $roomId The room ID
	 * @param int $houseId The house ID to validate against
	 * @throws NotFoundException if room doesn't exist or belong to house
	 */
	public function validateRoomOwnership(int $roomId, int $houseId): void {
		try {
			$room = $this->roomMapper->find($roomId);
			$floor = $this->floorMapper->find($room->getFloorId());
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Room not found'));
		}

		if ($floor->getHouseId() !== $houseId) {
			throw new NotFoundException($this->l10n->t('Room not found'));
		}
	}

	/**
	 * Get floor by ID.
	 *
	 * @param int $floorId The floor ID
	 * @throws NotFoundException if not found
	 */
	public function getFloor(int $floorId): Floor {
		try {
			return $this->floorMapper->find($floorId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Floor not found'));
		}
	}

	/**
	 * Create a new room (owner-only, CS-PLAN-061 §3.4 / ADR-006).
	 *
	 * The floor is resolved here (a nonexistent floor id maps to 404) and
	 * the caller must own the floor's house — controller checks stay as
	 * defense in depth only.
	 *
	 * @param int $floorId The floor ID
	 * @param string $name The room name
	 * @param ?string $icon The configured room icon
	 * @param string $roomType The room type (defaults to 'generic')
	 * @throws NotFoundException if the floor does not exist
	 * @throws \OCA\ChoreStrike\Exception\ForbiddenException if the caller is not the house owner
	 */
	public function createRoom(int $floorId, string $name, ?string $icon = null, string $roomType = 'generic'): Room {
		$floor = $this->getFloor($floorId);
		$this->authorization->requireHouseOwner($floor->getHouseId());

		return $this->roomMapper->create($floorId, $name, $icon, $roomType);
	}

	/**
	 * Update room name (owner-only, CS-PLAN-061 §3.4 / ADR-006).
	 *
	 * @param int $roomId The room ID
	 * @param string $name The new name
	 * @throws NotFoundException if the room does not exist
	 * @throws \OCA\ChoreStrike\Exception\ForbiddenException if the caller is not the house owner
	 */
	public function updateRoomName(int $roomId, string $name): Room {
		$room = $this->roomMapper->find($roomId);
		$floor = $this->floorMapper->find($room->getFloorId());
		$this->authorization->requireHouseOwner($floor->getHouseId());

		$room->setName($name);
		return $this->roomMapper->update($room);
	}

	/**
	 * Delete room and everything that depends on it (CS-PLAN-037, D1/D2).
	 *
	 * The schema has no FK constraints, so the cascade is explicit and
	 * transactional (all-or-nothing): checklist progress → assignees →
	 * completions → room tasks → room, each as one bulk statement over
	 * the room's room-task ids (3 + N_roomTasks → constant ~7 queries).
	 * Before this, dependent rows in completed_tasks, task_assignees,
	 * and room_task_checklist_progress were left orphaned.
	 *
	 * @param int $roomId The room ID
	 */
	public function deleteRoom(int $roomId): void {
		$room = $this->roomMapper->find($roomId);
		$floor = $this->floorMapper->find($room->getFloorId());
		// Structure mutation → owner-only in the service (CS-PLAN-061 §3.4).
		$this->authorization->requireHouseOwner($floor->getHouseId());

		try {
			$this->transactions->run(function () use ($roomId, $room) {
				$this->deleteRoomTaskDependents($this->roomTaskMapper->findIdsByRoomId($roomId));
				$this->roomTaskMapper->deleteByRoomId($roomId);
				$this->roomMapper->delete($room);
			});
		} catch (\Throwable $e) {
			$this->appLogger->logException('RoomService: room delete rolled back', $e, [
				'event' => 'room_delete_rollback',
				'service' => 'RoomService',
				'roomId' => $roomId,
			]);
			throw $e;
		}
	}

	/**
	 * Delete all rows that depend on the given room tasks — the explicit
	 * FK-cascade replacement shared by deleteRoom,
	 * RoomTaskService::deleteRoomTask, and FloorService::deleteFloor
	 * (CS-PLAN-037).
	 *
	 * Dependency order: room_task_checklist_progress → task_assignees →
	 * completed_tasks (D2: the completion history goes with its task;
	 * medals/streaks/coins are denormalized snapshots and stay valid).
	 * Lives here (not RoomTaskService) because RoomTaskService already
	 * depends on RoomService — the dependency arrow can only point this
	 * way. Callers wrap it in their own transaction.
	 *
	 * @param int[] $roomTaskIds The room task IDs (empty input is a no-op)
	 */
	public function deleteRoomTaskDependents(array $roomTaskIds): void {
		$this->checklistProgressMapper->deleteByRoomTaskIds($roomTaskIds);
		$this->taskAssigneeMapper->deleteByRoomTasks($roomTaskIds);
		$this->completedTaskMapper->deleteByRoomTaskIds($roomTaskIds);
	}

	/**
	 * Update room cleanliness after task completion
	 *
	 * @param int $roomId The room ID
	 * @param int $cleanlinessChange The change in cleanliness (-100 to 100)
	 */
	public function updateRoomCleanliness(int $roomId, int $cleanlinessChange): Room {
		$room = $this->roomMapper->find($roomId);
		$room->updateCleanliness($cleanlinessChange);
		return $this->roomMapper->update($room);
	}

	/**
	 * Get room task by ID
	 *
	 * @param int $roomTaskId The room task ID
	 * @throws NotFoundException if not found
	 */
	public function getRoomTask(int $roomTaskId): array {
		try {
			$roomTask = $this->roomTaskMapper->find($roomTaskId);
			$task = $this->taskMapper->find($roomTask->getTaskId());
			$room = $this->roomMapper->find($roomTask->getRoomId());
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}

		return [
			'roomTask' => $roomTask,
			'task' => $task,
			'room' => $room
		];
	}

	/**
	 * Complete room task and update room cleanliness
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string $userId The Nextcloud user ID who completed the task
	 */
	public function completeRoomTask(int $roomTaskId, string $userId): array {
		$roomTaskData = $this->getRoomTask($roomTaskId);
		$roomTask = $roomTaskData['roomTask'];
		$task = $roomTaskData['task'];
		$room = $roomTaskData['room'];

		// Get house user for scheduling preferences. CR-SVC-10: use the
		// nullable lookup and map a missing membership to a clean 403 —
		// findByNextcloudUserId() throws DoesNotExistException (uncaught →
		// 500) and must not be dereferenced.
		$floor = $this->floorMapper->find($room->getFloorId());
		$houseId = $floor->getHouseId();
		$houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
		if ($houseUser === null) {
			throw new ForbiddenException($this->l10n->t('Only house members can complete tasks'));
		}

		// Captured inside the transaction if a takeover happens, dispatched
		// after commit so rollbacks never leave a stale notification.
		$takeoverNotification = null;

		// CS-PLAN-057: resolve the house timezone once per request pass and
		// thread it explicitly into every day-boundary decision below
		// (duplicate guard, recurrence window, streak day).
		$house = $this->houseMapper->find($houseId);
		$timeZone = $this->calendarService->getTimeZone($house);
		[$dayStart, $dayEnd] = $this->calendarService->dayBounds(new \DateTime(), $timeZone);

		// The whole completion (lock, gate, guard, mutations, reward insert) is
		// atomic: a failure at any step rolls back every prior write. The early
		// room-task row lock also serializes concurrent completions of the SAME
		// task so the duplicate-completion guard cannot be raced past (TOCTOU).
		$this->transactions->begin();
		try {
			// CR-SVC-53: replace the pre-lock entity with the freshly locked
			// one so every post-lock decision (overdue takeover, claim state,
			// markCompleted recurrence) sees the latest committed state — a
			// concurrent winner may have advanced next_due before our lock
			// was granted. The pre-lock fetch above stays: it provides
			// $task, $room, and the early membership resolution.
			$roomTask = $this->roomTaskMapper->lockForUpdate($roomTaskId);

			// CR-SVC-10: re-resolve membership under the lock — a member
			// removed between the pre-transaction fetch and this point must
			// yield a clean 403, not a dereference of a stale entity. One
			// indexed (house_id, nextcloud_user_id) lookup inside the
			// critical section (CR-SVC-50 targets a different query class).
			$houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
			if ($houseUser === null) {
				throw new ForbiddenException($this->l10n->t('Only house members can complete tasks'));
			}

			// Resolve the set of assigned Nextcloud user IDs for this room
			// task from the canonical join table (CS-PLAN-060 WP1, D2).
			$assigneeUserIds = $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTaskId);

			// One visibility matrix for completion too (CS-PLAN-061 §3.2, D2):
			// private tasks → creator ∪ assignees, admin-only → owners. A
			// non-viewer gets the 404 anti-enumeration denial — completion
			// must not confirm that an invisible room-task id exists.
			$this->taskAccessPolicy->assertCanView($task, $houseUser, $assigneeUserIds);

			// Duplicate-completion guard: prevent the same user from earning coins
			// twice for the same room task on the same calendar day (defense in
			// depth against double-clicks / API re-submission). The "day" is the
			// house-local calendar day (CS-PLAN-057): a completion at 00:30
			// Berlin is the NEXT Berlin day, not the same UTC day.
			$todayCount = $this->completedTaskMapper->countByRoomTaskAndUserOnDate(
				$roomTaskId,
				$houseUser->getId(),
				$dayStart,
				$dayEnd
			);
			if ($todayCount > 0) {
				throw new ForbiddenException($this->l10n->t('You already completed this task today'));
			}

			// Overdue-takeover: only record a takeover when a NON-assignee picks
			// up an overdue task. A co-assignee completing their own shared
			// task is NOT a takeover. The original assignee's streak is
			// unaffected because game logic only updates the completer. The
			// claim snapshot takes the first canonical assignee (D2 — the
			// join table is the assignee store).
			if ($roomTask->isOverdue() && !in_array($userId, $assigneeUserIds, true)) {
				$originalAssignee = $assigneeUserIds[0] ?? null;
				if ($originalAssignee !== null && $originalAssignee !== '') {
					$roomTask->markClaimed($originalAssignee, $userId);
					// Defer the actual notification dispatch until after commit
					// (below) so a rollback never leaves a stale "taken over"
					// notification in the bell. Capture the context here while
					// it's in scope. The claimer travels as uid + display name —
					// the notifier's rich `user` param requires the uid as `id`
					// (CS-PLAN-061 §3.7).
					$takeoverNotification = [
						'recipient' => $originalAssignee,
						'taskName' => $task->getName(),
						'claimerUserId' => $userId,
						'claimerDisplayName' => $houseUser->getDisplayName(),
						'roomTaskId' => $roomTaskId,
					];
				}
			}

			// Get user's scheduling preferences for flex scheduling
			$preferences = $houseUser->getSchedulingPreferences();
			$weekdayAvailability = $preferences['weekdayAvailability'] ?? [];
			$energyBudget = $preferences['energyBudget'] ?? [];

			// Mark task as completed and calculate next due date with flex scheduling
			$roomTask->markCompleted(
				new \DateTime(),
				$task->getFrequencyType(),
				$task->getFrequencyValue(),
				$task->getRecurrenceMode(),
				$task->getSchedulingMode(),
				$task->getActiveUntil(),
				$task->getFixedScheduleTime(),
				$task->getFixedScheduleDays(),
				$task->getFlexDays(),
				$weekdayAvailability,
				$energyBudget,
				$timeZone
			);
			$this->roomTaskMapper->update($roomTask);

			// Update room cleanliness based on task energy cost
			$cleanlinessChange = $task->getEnergyCost() * 10; // 10% per energy point
			$updatedRoom = $this->updateRoomCleanliness($room->getId(), $cleanlinessChange);

			// Trigger game logic for task completion. The locked room task
			// entity is threaded in (CR-SVC-50) so game logic never
			// re-fetches it inside the house_user lock. The house timezone
			// threads the day-key decisions (CS-PLAN-057).
			$gameResult = $this->gameLogicService->handleTaskCompletion(
				$roomTask,
				$houseUser->getId(),
				$task,
				$timeZone
			);

			// Teamwork bonus: award additional coins when multiple assignees
			// complete a task together. The bonus is additive on top of the
			// base reward and capped to prevent inflation.
			// Re-fetch the house user so we build on the coins/streak that
			// handleTaskCompletion() just persisted (avoids a lost-update).
			$teamworkBonus = 0;
			if (count($assigneeUserIds) > 1) {
				$teamworkBonus = $this->gameLogicService->calculateTeamworkBonus($task, $assigneeUserIds);
				if ($teamworkBonus > 0) {
					$freshHouseUser = $this->houseUserMapper->find($houseUser->getId());
					$freshHouseUser->addCoins($teamworkBonus);
					$freshHouseUser->touch();
					$this->houseUserMapper->update($freshHouseUser);
				}
			}

			$this->transactions->commit();
		} catch (\Throwable $e) {
			$this->transactions->rollBack();
			$this->appLogger->logException('RoomService: task completion rolled back', $e, [
				'event' => 'room_task_completion_rollback',
				'service' => 'RoomService',
				'houseId' => $houseId,
				'roomId' => $room->getId(),
				'roomTaskId' => $roomTaskId,
				'userId' => $userId,
			]);
			throw $e;
		}

		// Refresh weekly goal progress AFTER commit so reads are consistent
		// across engines (the new completion row + any next_due shift from
		// markCompleted are visible). The frontend detects the
		// not-achieved → achieved transition by comparing its previous store
		// snapshot with this fresh one, so we don't need a pre-read here.
		$weeklyGoal = $this->houseService->getWeeklyGoalProgress($floor->getHouseId());

		// Fire server-side notifications AFTER commit so a rolled-back
		// completion never leaves a stale notification in the bell. Both are
		// best-effort (NotificationService logs and swallows failures).
		//
		// (1) Overdue-takeover: notify the original assignee that another
		//     member picked up their overdue task. Their streak is unaffected
		//     (game logic only updates the completer) but the notification is
		//     polite + matches the plan's "you got bailed out" message.
		//     Membership re-check at dispatch time is documented
		//     defense-in-depth: the root cause (house_users row deleted
		//     while assignee rows stayed set) is fixed transactionally by
		//     HouseService::removeHouseMember (CR-SVC-26), but a removed
		//     member must never receive task details even if a future path
		//     reintroduces a stale reference.
		if ($takeoverNotification !== null) {
			$stillMember = $this->houseUserMapper->findByHouseAndUser(
				$floor->getHouseId(),
				$takeoverNotification['recipient']
			);
			if ($stillMember !== null) {
				$this->notificationService->notifyOverdueTakenOver(
					$takeoverNotification['recipient'],
					$takeoverNotification['taskName'],
					$takeoverNotification['claimerUserId'],
					$takeoverNotification['claimerDisplayName'],
					$takeoverNotification['roomTaskId']
				);
			}
		}

		// (2) Weekly-goal-reached notification: claim dispatch atomically via
		//     a conditional UPDATE on chorestrike_houses.weekly_goal_notified_week.
		//     This deduplicates across concurrent completions that together
		//     cross the threshold — only the first caller's UPDATE affects a
		//     row, the others get 0 rows and skip dispatch. Per-house-per-week
		//     guarantee: each household gets exactly one bell notification per
		//     achievement week (column resets naturally as the ISO weekStart
		//     changes on Monday).
		if ($weeklyGoal['achieved']) {
			// weekStart is ISO 8601; collapse to Y-m-d for the dedup key so
			// timezone skew in the format doesn't defeat the comparison.
			$weekKey = substr($weeklyGoal['weekStart'], 0, 10);
			$claimed = $this->houseMapper->claimWeeklyGoalNotification($floor->getHouseId(), $weekKey);
			if ($claimed) {
				$this->notificationService->notifyWeeklyGoalReachedForHouse(
					$floor->getHouseId(),
					$weeklyGoal['percent'],
					$weeklyGoal['targetPercent'],
					$weeklyGoal['weekStart']
				);
			}
		}

		// (3) Title-unlock celebration (CR-SVC-12): dispatched after commit so
		//     a rolled-back completion never raises a stale title notification.
		//     The payload is produced inside the lock by GameLogicService and
		//     surfaced via the game result; only the dispatch is deferred.
		$newTitle = $gameResult['newTitle'] ?? null;
		if ($newTitle !== null && $userId !== '') {
			$this->notificationService->notifyTitleUnlocked(
				$userId,
				$newTitle['pack'],
				$newTitle['labelKey'],
				$newTitle['packLabelKey'],
				$newTitle['tierIndex']
			);
		}

		return [
			'roomTask' => $roomTask,
			'room' => $updatedRoom,
			'task' => $task,
			'rewards' => [
				'cleanlinessChange' => $cleanlinessChange,
				'energyCost' => $task->getEnergyCost(),
				'priority' => $task->getPriority(),
				'contributionScore' => $gameResult['contributionScore'],
				'coins' => $gameResult['coins'],
				'isCombo' => $gameResult['isCombo'],
				'currentStreak' => $gameResult['currentStreak'],
				'streakFreezeUsed' => $gameResult['streakFreezeUsed'] ?? false,
				'streakFreezesRemaining' => $gameResult['streakFreezesRemaining'] ?? 0,
				'comboBonusFreeze' => $gameResult['comboBonusFreeze'] ?? false,
				'teamworkBonus' => $teamworkBonus,
				'weeklyGoal' => $weeklyGoal,
			]
		];
	}
}
