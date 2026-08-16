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
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

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
	private HouseService $houseService;
	private HouseMapper $houseMapper;
	private NotificationService $notificationService;
	private IDBConnection $db;

	public function __construct(
		RoomMapper $roomMapper,
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		HouseUserMapper $houseUserMapper,
		FloorMapper $floorMapper,
		GameLogicService $gameLogicService,
		TaskAssigneeMapper $taskAssigneeMapper,
		CompletedTaskMapper $completedTaskMapper,
		HouseService $houseService,
		HouseMapper $houseMapper,
		NotificationService $notificationService,
		IDBConnection $db,
	) {
		$this->roomMapper = $roomMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->taskMapper = $taskMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->floorMapper = $floorMapper;
		$this->gameLogicService = $gameLogicService;
		$this->taskAssigneeMapper = $taskAssigneeMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->houseService = $houseService;
		$this->houseMapper = $houseMapper;
		$this->notificationService = $notificationService;
		$this->db = $db;
	}

	/**
	 * Validate that a room belongs to the specified house
	 *
	 * @param int $roomId The room ID
	 * @param int $houseId The house ID to validate against
	 * @throws NotFoundException if room doesn't belong to house
	 */
	public function validateRoomOwnership(int $roomId, int $houseId): void {
		$room = $this->roomMapper->find($roomId);
		$floor = $this->floorMapper->find($room->getFloorId());

		if ($floor->getHouseId() !== $houseId) {
			throw new NotFoundException('Room not found');
		}
	}

	/**
	 * Get all rooms for a floor
	 *
	 * @param int $floorId The floor ID
	 * @return Room[]
	 */
	public function getRooms(int $floorId): array {
		return $this->roomMapper->findByFloorId($floorId);
	}

	/**
	 * Get room by ID
	 *
	 * @param int $roomId The room ID
	 * @throws DoesNotExistException if not found
	 */
	public function getRoom(int $roomId): Room {
		return $this->roomMapper->find($roomId);
	}

	/**
	 * Get floor by ID.
	 *
	 * @param int $floorId The floor ID
	 * @throws DoesNotExistException if not found
	 */
	public function getFloor(int $floorId): Floor {
		return $this->floorMapper->find($floorId);
	}

	/**
	 * Create a new room
	 *
	 * @param int $floorId The floor ID
	 * @param string $name The room name
	 * @param ?string $icon The configured room icon
	 * @param string $roomType The room type (defaults to 'generic')
	 */
	public function createRoom(int $floorId, string $name, ?string $icon = null, string $roomType = 'generic'): Room {
		return $this->roomMapper->create($floorId, $name, $icon, $roomType);
	}

	/**
	 * Update room name
	 *
	 * @param int $roomId The room ID
	 * @param string $name The new name
	 */
	public function updateRoomName(int $roomId, string $name): Room {
		$room = $this->roomMapper->find($roomId);
		$room->setName($name);
		return $this->roomMapper->update($room);
	}

	/**
	 * Delete room and all associated tasks
	 *
	 * @param int $roomId The room ID
	 */
	public function deleteRoom(int $roomId): void {
		$room = $this->roomMapper->find($roomId);

		// Delete associated room tasks
		$roomTasks = $this->roomTaskMapper->findByRoomId($roomId);
		foreach ($roomTasks as $roomTask) {
			$this->roomTaskMapper->delete($roomTask);
		}

		$this->roomMapper->delete($room);
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
	 * Get room with tasks
	 *
	 * @param int $roomId The room ID
	 */
	public function getRoomWithTasks(int $roomId): array {
		$room = $this->roomMapper->find($roomId);
		$roomTasks = $this->roomTaskMapper->findByRoomId($roomId);

		// Load task details for each room task
		$tasksWithDetails = [];
		foreach ($roomTasks as $roomTask) {
			try {
				$task = $this->taskMapper->find($roomTask->getTaskId());
				$tasksWithDetails[] = [
					'roomTask' => $roomTask,
					'task' => $task
				];
			} catch (DoesNotExistException $e) {
				// Task doesn't exist, skip this room task
				continue;
			}
		}

		return [
			'room' => $room,
			'tasks' => $tasksWithDetails,
			'stats' => $this->calculateRoomStats($room, $tasksWithDetails)
		];
	}

	/**
	 * Calculate room statistics
	 *
	 * @param Room $room The room
	 * @param array $tasksWithDetails Array of tasks with details
	 */
	private function calculateRoomStats(Room $room, array $tasksWithDetails): array {
		$activeTasks = array_filter($tasksWithDetails, fn ($item) => $item['roomTask']->getIsActive());
		$overdueTasks = array_filter($activeTasks, fn ($item) => $item['roomTask']->isOverdue());
		$dueTodayTasks = array_filter($activeTasks, fn ($item) => $item['roomTask']->isDueToday());

		return [
			'cleanliness' => $room->getCleanliness(),
			'monsterCount' => $room->getMonsterCount(),
			'status' => $room->getStatus(),
			'needsCleaning' => $room->needsCleaning(),
			'activeTasks' => count($activeTasks),
			'overdueTasks' => count($overdueTasks),
			'dueTodayTasks' => count($dueTodayTasks),
			'lastCleaned' => $room->getLastCleaned()?->format('c')
		];
	}

	/**
	 * Get rooms that need cleaning
	 *
	 * @param int|null $floorId Optional floor ID to filter by
	 * @return Room[]
	 */
	public function getRoomsNeedingCleaning(?int $floorId = null): array {
		return $this->roomMapper->findNeedingCleaning($floorId);
	}

	/**
	 * Get room task by ID
	 *
	 * @param int $roomTaskId The room task ID
	 */
	public function getRoomTask(int $roomTaskId): array {
		$roomTask = $this->roomTaskMapper->find($roomTaskId);
		$task = $this->taskMapper->find($roomTask->getTaskId());
		$room = $this->roomMapper->find($roomTask->getRoomId());

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
	 * @param int|null $nextcloudTaskId Optional Nextcloud task id stamped on
	 *                                  the completion row for sync completions (CR-SVC-46), so the
	 *                                  ChoreStrike → Nextcloud sync-out picks the completion up.
	 */
	public function completeRoomTask(int $roomTaskId, string $userId, ?int $nextcloudTaskId = null): array {
		$roomTaskData = $this->getRoomTask($roomTaskId);
		$roomTask = $roomTaskData['roomTask'];
		$task = $roomTaskData['task'];
		$room = $roomTaskData['room'];

		// Get house user for scheduling preferences
		$floor = $this->floorMapper->find($room->getFloorId());
		$houseUser = $this->houseUserMapper->findByNextcloudUserId($floor->getHouseId(), $userId);

		// Captured inside the transaction if a takeover happens, dispatched
		// after commit so rollbacks never leave a stale notification.
		$takeoverNotification = null;

		// The whole completion (lock, gate, guard, mutations, reward insert) is
		// atomic: a failure at any step rolls back every prior write. The early
		// room-task row lock also serializes concurrent completions of the SAME
		// task so the duplicate-completion guard cannot be raced past (TOCTOU).
		$this->db->beginTransaction();
		try {
			// CR-SVC-53: replace the pre-lock entity with the freshly locked
			// one so every post-lock decision (overdue takeover, claim state,
			// markCompleted recurrence) sees the latest committed state — a
			// concurrent winner may have advanced next_due before our lock
			// was granted. The pre-lock fetch above stays: it provides
			// $task, $room, and the early membership resolution.
			$roomTask = $this->roomTaskMapper->lockForUpdate($roomTaskId);

			// Resolve the full set of assigned Nextcloud user IDs for this room task:
			// the multi-assign join table plus the legacy single assignee column.
			$assigneeUserIds = TaskAssigneeMapper::mergeLegacyAssignee(
				$this->taskAssigneeMapper->findUserIdsByRoomTask($roomTaskId),
				$roomTask->getAssignedToUserId()
			);

			// Private-task gate: only assigned users may complete a private task.
			// Non-private tasks remain completable by any house member.
			if ($task->isPrivate() && !in_array($userId, $assigneeUserIds, true)) {
				throw new ForbiddenException('Private tasks may only be completed by assigned users');
			}

			// Admin-only gate: only house owners may complete admin-only tasks.
			if ($task->getVisibilityType() === \OCA\ChoreStrike\Db\Task::VISIBILITY_ADMIN_ONLY && !$houseUser->isOwner()) {
				throw new ForbiddenException('Admin-only tasks may only be completed by house owners');
			}

			// Duplicate-completion guard: prevent the same user from earning coins
			// twice for the same room task on the same calendar day (defense in
			// depth against double-clicks / API re-submission).
			$todayCount = $this->completedTaskMapper->countByRoomTaskAndUserOnDate(
				$roomTaskId,
				$houseUser->getId(),
				new \DateTime()
			);
			if ($todayCount > 0) {
				throw new ForbiddenException('You already completed this task today');
			}

			// Overdue-takeover: only record a takeover when a NON-assignee picks
			// up an overdue task. A co-assignee (join table or legacy) completing
			// their own shared task is NOT a takeover. The original assignee's
			// streak is unaffected because game logic only updates the completer.
			if ($roomTask->isOverdue() && !in_array($userId, $assigneeUserIds, true)) {
				$originalAssignee = $roomTask->getAssignedToUserId();
				if ($originalAssignee !== null && $originalAssignee !== '') {
					$roomTask->markClaimed($originalAssignee, $userId);
					// Defer the actual notification dispatch until after commit
					// (below) so a rollback never leaves a stale "taken over"
					// notification in the bell. Capture the context here while
					// it's in scope.
					$takeoverNotification = [
						'recipient' => $originalAssignee,
						'taskName' => $task->getName(),
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
				$energyBudget
			);
			$this->roomTaskMapper->update($roomTask);

			// Update room cleanliness based on task energy cost
			$cleanlinessChange = $task->getEnergyCost() * 10; // 10% per energy point
			$updatedRoom = $this->updateRoomCleanliness($room->getId(), $cleanlinessChange);

			// Trigger game logic for task completion
			$gameResult = $this->gameLogicService->handleTaskCompletion(
				$roomTaskId,
				$houseUser->getId(),
				$task,
				$updatedRoom->getCleanliness(),
				$nextcloudTaskId
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

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
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
		//     Membership re-check at dispatch time guards against leaking
		//     task details to former members whose house_users row was
		//     deleted but whose legacy assigned_to_user_id column wasn't
		//     cleared (root-cause fix tracked as CR-SVC-26: null
		//     assigned_to_user_id + delete task_assignees on member removal).
		if ($takeoverNotification !== null) {
			$stillMember = $this->houseUserMapper->findByHouseAndUser(
				$floor->getHouseId(),
				$takeoverNotification['recipient']
			);
			if ($stillMember !== null) {
				$this->notificationService->notifyOverdueTakenOver(
					$takeoverNotification['recipient'],
					$takeoverNotification['taskName'],
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
