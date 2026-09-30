<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\BackgroundJob;

use OCA\ChoreStrike\Service\DigestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Digest delivery pass (CS-PLAN-070 WP5, parent §3.6) — the app's ONLY
 * notification path.
 *
 * Five-minute interval (the former 24-hour task-due pass is retired with
 * TaskDueReminderService; the award/takeover bells are gone): every run
 * materializes due/upcoming opt-in slots, expires missed slots
 * permanently (the 60-minute same-day window is never extended to
 * compensate cron lateness — the Nextcloud cron prerequisite is
 * documented, not engineered around) and claims one due slot at a time
 * for the bounded at-most-once dispatch in {@see DigestService}.
 *
 * The former streak-at-risk pass retired with the freeze/streak-snapshot
 * effects (CS-PLAN-070 WP3, Q-074-01 Option A — the rhythm evaluator
 * derives pending/confirmed days instead).
 */
class DailyNotificationJob extends TimedJob {
	private DigestService $digestService;

	public function __construct(
		ITimeFactory $time,
		DigestService $digestService,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);

		$this->digestService = $digestService;
	}

	/**
	 * Run the five-minute digest pass
	 */
	protected function run($argument): void {
		$this->digestService->processDueSlots($this->time->getDateTime());
	}
}
