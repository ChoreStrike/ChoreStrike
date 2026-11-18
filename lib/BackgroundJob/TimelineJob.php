<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\BackgroundJob;

use OCA\ChoreStrike\Service\CycleService;
use OCA\ChoreStrike\Service\MedalService;
use OCA\ChoreStrike\Service\RhythmService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Timeline job (CS-PLAN-091 WP-B1, ADR-021 decision 5; formerly the
 * occurrence materialization pass of CS-PLAN-072).
 *
 * Nothing is materialized any more: windows are derived on read. The job
 * only writes what the passing of time decides — it records fixed cycles
 * that stopped being current as missed, exactly once, and repairs the
 * `next_due` projection where the current cycle moved on
 * ({@see CycleService::advanceForJobs()}). Runs at five-minute intervals;
 * a late or missed run catches up from the rule, because every missed
 * cycle follows from the rule and the valid completions alone.
 *
 * Idempotency comes from the unique cycle key — no fabricated member
 * actor, no CommandService receipt. After the cycle pass and the rhythm
 * refresh, closed weeks reconcile recognition and claim their weekly
 * receipt once, including when nobody completes work at the deadline.
 */
class TimelineJob extends TimedJob {
	private CycleService $cycleService;
	private RhythmService $rhythmService;
	private MedalService $medalService;

	public function __construct(
		ITimeFactory $time,
		CycleService $cycleService,
		RhythmService $rhythmService,
		MedalService $medalService,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);

		$this->cycleService = $cycleService;
		$this->rhythmService = $rhythmService;
		$this->medalService = $medalService;
	}

	/**
	 * Run the timeline pass
	 */
	protected function run($argument): void {
		$now = clone $this->time->getDateTime();
		$this->reconcileAt($now);
	}

	/** Shared cron/CLI seam; --as-of passes the simulated instant here. */
	public function reconcileAt(\DateTime $now): void {
		$this->cycleService->advanceForJobs($now);
		$this->rhythmService->refreshForJobs($now);
		$this->medalService->reconcileClosedWeeksForJobs($now);
	}
}
