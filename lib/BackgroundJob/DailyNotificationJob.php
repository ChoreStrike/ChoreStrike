<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\BackgroundJob;

use OCA\ChoreStrike\Service\TaskDueReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Daily notification job (06:00 server time).
 *
 * Thin delegate (CS-PLAN-023): hands the pass to
 * {@see TaskDueReminderService}, which holds the task-due reminder logic
 * (CS-PLAN-030 / FEAT-NOTIFY-001). The former streak-at-risk pass retired
 * with the freeze/streak-snapshot effects (CS-PLAN-070 WP3, Q-074-01
 * Option A — the rhythm evaluator derives pending/confirmed days instead).
 *
 * Overdue-takeover, medal-awarded and weekly-goal-reached notifications are
 * fired from their respective trigger points and are NOT handled here —
 * this job covers only the time-based reminders that no single mutation
 * can detect.
 */
class DailyNotificationJob extends TimedJob {
	private TaskDueReminderService $taskDueReminderService;

	public function __construct(
		ITimeFactory $time,
		TaskDueReminderService $taskDueReminderService,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 60 * 60);

		$this->taskDueReminderService = $taskDueReminderService;
	}

	/**
	 * Run the daily task-due reminder pass
	 */
	protected function run($argument): void {
		$this->taskDueReminderService->process($this->time->getDateTime());
	}
}
