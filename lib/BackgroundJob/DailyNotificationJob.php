<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\BackgroundJob;

use OCA\ChoreStrike\Service\StreakAtRiskService;
use OCA\ChoreStrike\Service\TaskDueReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Daily notification job (06:00 server time).
 *
 * Thin delegate (CS-PLAN-023): derives "yesterday" from the injected
 * ITimeFactory and hands the pass to {@see StreakAtRiskService}, which
 * holds the at-risk logic and is independently triggerable via
 * occ chorestrike:trigger-jobs. Since CS-PLAN-030 the job additionally runs
 * the task-due reminder pass ({@see TaskDueReminderService}) with the
 * current day, covering the "task X is due today / tomorrow" nudges
 * (FEAT-NOTIFY-001).
 *
 * Overdue-takeover, medal-awarded and weekly-goal-reached notifications are
 * fired from their respective trigger points (RoomService,
 * GameLogicService) and are NOT handled here — this job covers only the
 * time-based reminders that no single mutation can detect.
 */
class DailyNotificationJob extends TimedJob {
	private StreakAtRiskService $atRiskService;
	private TaskDueReminderService $taskDueReminderService;

	public function __construct(
		ITimeFactory $time,
		StreakAtRiskService $atRiskService,
		TaskDueReminderService $taskDueReminderService,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 60 * 60);

		$this->atRiskService = $atRiskService;
		$this->taskDueReminderService = $taskDueReminderService;
	}

	/**
	 * Run the daily notification passes (streak-at-risk + task-due reminders)
	 */
	protected function run($argument): void {
		$this->atRiskService->process($this->deriveYesterday());
		$this->taskDueReminderService->process($this->time->getDateTime());
	}

	/**
	 * Derive "yesterday" from the injected time factory.
	 *
	 * The time seam required by CS-PLAN-023: the date is a pure function of
	 * the injected clock, so unit tests control it and the trigger command
	 * can substitute an --as-of-derived date. Cloned before modify() so the
	 * factory's instance is never mutated.
	 */
	private function deriveYesterday(): \DateTimeInterface {
		$yesterday = clone $this->time->getDateTime();
		return $yesterday->modify('-1 day');
	}
}
