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
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;

/**
 * Daily task-due reminder pass (FEAT-NOTIFY-001 / CS-PLAN-030).
 *
 * The pass receives its "today" as a parameter — the time seam that makes
 * it testable and triggerable via `occ chorestrike:trigger-jobs --as-of` —
 * and dispatches best-effort notifications through {@see NotificationService}
 * (the retired streak passes' seam shape, kept for this retained pass).
 *
 * Recipients are assignees only (D1): users assigned via the
 * task_assignees join table. Unassigned tasks send nothing (they are
 * visible in the daily dashboard list; broadcasting house-wide would be
 * spammy). Users marked unavailable on the due weekday are skipped (D2,
 * consistent with the streak passes). Completed tasks need no explicit
 * guard (D3): completion advances next_due (recurring) or deactivates the
 * row (once), so the due-date range query naturally excludes them.
 *
 * CS-PLAN-057: the pass resolves the (single) house and derives the
 * today/tomorrow due windows in the house timezone.
 */
class TaskDueReminderService {
	public function __construct(
		private RoomTaskMapper $roomTaskMapper,
		private TaskAssigneeMapper $taskAssigneeMapper,
		private HouseUserMapper $houseUserMapper,
		private TaskMapper $taskMapper,
		private NotificationService $notificationService,
		private AppLogger $appLogger,
		private HouseMapper $houseMapper,
		private CalendarService $calendarService,
	) {
	}

	/**
	 * Run the task-due reminder pass for the given day.
	 *
	 * Sends "due today" reminders for tasks whose next_due is on $today and
	 * "due tomorrow" reminders for tasks due on $today + 1. Duplicate passes
	 * on the same day are collapsed by Nextcloud's notification dedup (the
	 * objectIds are day-qualified — see NotificationService::notifyTaskDue).
	 *
	 * @param \DateTimeInterface $today The pass run date
	 * @return array{processed: int, notifiedToday: int, notifiedTomorrow: int, skippedUnavailable: int}
	 */
	public function process(\DateTimeInterface $today): array {
		$stats = [
			'processed' => 0,
			'notifiedToday' => 0,
			'notifiedTomorrow' => 0,
			'skippedUnavailable' => 0,
		];

		try {
			// CS-PLAN-057: resolve the house timezone for this pass. No house
			// yet (bootstrap race) → nothing to process.
			$house = $this->houseMapper->findOneForJobs();
			if ($house === null) {
				return $stats;
			}
			$tz = $this->calendarService->getTimeZone($house);

			// House-local today/tomorrow calendar windows, as UTC-normalized
			// [start, end) bounds.
			[$todayStart, $todayEnd] = $this->calendarService->dayBounds($today, $tz);
			$localTomorrow = \DateTime::createFromInterface($today)->setTimezone($tz)->modify('+1 day');
			[$tomorrowStart, $tomorrowEnd] = $this->calendarService->dayBounds($localTomorrow, $tz);

			$dueToday = $this->roomTaskMapper->findDueOnDate($todayStart, $todayEnd);
			$dueTomorrow = $this->roomTaskMapper->findDueOnDate($tomorrowStart, $tomorrowEnd);
			$stats['processed'] = count($dueToday) + count($dueTomorrow);

			$assigneesByTask = $this->resolveAssignees(array_merge($dueToday, $dueTomorrow));
			$usersByNextcloudId = $this->mapUsersByNextcloudId();

			// Pass house-local representative instants so the notification
			// dedup keys ("{roomTaskId}:{Y-m-d}", CS-PLAN-057) are house-local.
			$todayLocal = \DateTime::createFromInterface($today)->setTimezone($tz);
			$this->dispatchReminders($dueToday, $assigneesByTask, $usersByNextcloudId, $todayLocal, $tz, false, $stats);
			$this->dispatchReminders($dueTomorrow, $assigneesByTask, $usersByNextcloudId, $localTomorrow, $tz, true, $stats);
		} catch (\Throwable $e) {
			$this->appLogger->logException('TaskDueReminderService failed', $e, [
				'event' => 'task_due_reminder_pass_failed',
				'service' => 'TaskDueReminderService',
			]);
			return $stats;
		}

		$this->appLogger->info('TaskDueReminderService completed', $stats + [
			'event' => 'task_due_reminder_pass_completed',
			'service' => 'TaskDueReminderService',
		]);

		return $stats;
	}

	/**
	 * Batch-resolve the assignee user IDs for the given room tasks from the
	 * canonical join table (one query, CS-PLAN-060 WP1 D2).
	 *
	 * @param RoomTask[] $roomTasks Room tasks from both due sets
	 * @return array<int, string[]> Map of room task ID => unique Nextcloud user IDs
	 */
	private function resolveAssignees(array $roomTasks): array {
		$roomTaskIds = array_map(fn ($roomTask) => $roomTask->getId(), $roomTasks);
		return $this->taskAssigneeMapper->findUserIdsByRoomTasks($roomTaskIds);
	}

	/**
	 * Index all house users by Nextcloud user ID (single pass, job-wide set
	 * — mirrors findAllForJobs usage in the streak passes) so availability
	 * lookups during dispatch stay query-free.
	 *
	 * @return array<string, HouseUser>
	 */
	private function mapUsersByNextcloudId(): array {
		$map = [];
		foreach ($this->houseUserMapper->findAllForJobs() as $user) {
			$nextcloudUserId = $user->getNextcloudUserId();
			if ($nextcloudUserId !== null && $nextcloudUserId !== '') {
				$map[$nextcloudUserId] = $user;
			}
		}
		return $map;
	}

	/**
	 * Dispatch reminders for one due set (today or tomorrow).
	 *
	 * Task names are enriched through a per-request cache (pattern:
	 * RoomTaskReadService::getDailyTasksWithDetails). Assignees not found in the
	 * house-user map (e.g. membership removed after assignment) are skipped —
	 * reminders are a member benefit, not a retention tool.
	 *
	 * @param RoomTask[] $roomTasks The due set
	 * @param array<int, string[]> $assigneesByTask Effective assignees per room task ID
	 * @param array<string, HouseUser> $usersByNextcloudId House users indexed by Nextcloud user ID
	 * @param \DateTimeInterface $dueDate A representative instant of the calendar day the tasks are due on
	 * @param \DateTimeZone $tz The house timezone (day-name resolution)
	 * @param bool $dueTomorrow True for the tomorrow set, false for the today set
	 * @param array{processed: int, notifiedToday: int, notifiedTomorrow: int, skippedUnavailable: int} $stats Pass stats (mutated)
	 */
	private function dispatchReminders(
		array $roomTasks,
		array $assigneesByTask,
		array $usersByNextcloudId,
		\DateTimeInterface $dueDate,
		\DateTimeZone $tz,
		bool $dueTomorrow,
		array &$stats,
	): void {
		$dueDayName = strtolower(\DateTime::createFromInterface($dueDate)->setTimezone($tz)->format('l'));
		$taskCache = [];

		foreach ($roomTasks as $roomTask) {
			$userIds = $assigneesByTask[$roomTask->getId()] ?? [];
			if ($userIds === []) {
				// Unassigned tasks send nothing (D1).
				continue;
			}

			$taskId = $roomTask->getTaskId();
			if (!isset($taskCache[$taskId])) {
				try {
					$taskCache[$taskId] = $this->taskMapper->find($taskId);
				} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
					// Orphaned room task (definition deleted mid-pass): skip
					// THIS task and let the pass continue — a single orphaned
					// row must not kill the whole reminder run via the
					// pass-level Throwable handler (CS-PLAN-061 §3.7).
					$this->appLogger->warning('Due reminder skipped orphaned room task', [
						'event' => 'task_due_reminder_orphaned_task',
						'service' => 'TaskDueReminderService',
						'taskId' => $taskId,
						'roomTaskId' => $roomTask->getId(),
					]);
					continue;
				}
			}
			$taskName = $taskCache[$taskId]->getName();

			foreach ($userIds as $nextcloudUserId) {
				$user = $usersByNextcloudId[$nextcloudUserId] ?? null;
				if ($user === null) {
					continue;
				}

				$preferences = $user->getSchedulingPreferences();
				$weekdayAvailability = $preferences['weekdayAvailability'] ?? [];
				if (isset($weekdayAvailability[$dueDayName]) && $weekdayAvailability[$dueDayName] === false) {
					$stats['skippedUnavailable']++;
					continue;
				}

				$this->notificationService->notifyTaskDue(
					$nextcloudUserId,
					$taskName,
					$roomTask->getId(),
					$roomTask->getNextDue(),
					$dueTomorrow
				);
				if ($dueTomorrow) {
					$stats['notifiedTomorrow']++;
				} else {
					$stats['notifiedToday']++;
				}
			}
		}
	}
}
