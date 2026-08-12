<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\BackgroundJob;

use OCA\ChoreStrike\Service\StreakAtRiskService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Daily notification job (06:00 server time).
 *
 * Thin delegate (CS-PLAN-023): derives "yesterday" from the injected
 * ITimeFactory and hands the pass to {@see StreakAtRiskService}, which
 * holds the at-risk logic and is independently triggerable via
 * occ chorestrike:trigger-jobs.
 *
 * Overdue-takeover, medal-awarded and weekly-goal-reached notifications are
 * fired from their respective trigger points (RoomService,
 * GameLogicService) and are NOT handled here — this job covers only the
 * time-based reminder that no single mutation can detect.
 */
class DailyNotificationJob extends TimedJob {
	private StreakAtRiskService $atRiskService;

	public function __construct(
		ITimeFactory $time,
		StreakAtRiskService $atRiskService,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 60 * 60);

		$this->atRiskService = $atRiskService;
	}

	/**
	 * Run the daily streak-at-risk notification pass
	 */
	protected function run($argument): void {
		$this->atRiskService->process($this->deriveYesterday());
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
