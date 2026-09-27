<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\BackgroundJob;

use OCA\ChoreStrike\Service\MedalService;
use OCA\ChoreStrike\Service\OccurrenceService;
use OCA\ChoreStrike\Service\PauseService;
use OCA\ChoreStrike\Service\RhythmService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Occurrence timeline job (CS-PLAN-072 WP1; parent CS-PLAN-070 §3.6).
 *
 * Thin delegate: derives "now" from the injected clock and hands the pass
 * to {@see OccurrenceService::reconcileHouseForJobs()}, which advances the
 * agreement frontier of every active schedule through the same generator
 * the creation and mutation paths use, then converges the WP2 pause
 * suspension facts ({@see PauseService::reconcilePauseEffectsForJobs()}).
 * Runs at five-minute intervals; a late or missed run reconciles history
 * from the immutable rule inputs — it never reconstructs history from
 * today's mutable task definition.
 *
 * Job idempotency comes from natural revision/ordinal uniqueness and
 * monotonic frontiers — no fabricated member actor, no CommandService
 * receipt. After materialization, pause convergence and rhythm refresh,
 * closed planned weeks reconcile recognition and claim their weekly bell
 * once, including when nobody completes work at the deadline.
 */
class TimelineJob extends TimedJob {
	private OccurrenceService $occurrenceService;
	private PauseService $pauseService;
	private RhythmService $rhythmService;
	private MedalService $medalService;

	public function __construct(
		ITimeFactory $time,
		OccurrenceService $occurrenceService,
		PauseService $pauseService,
		RhythmService $rhythmService,
		MedalService $medalService,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);

		$this->occurrenceService = $occurrenceService;
		$this->pauseService = $pauseService;
		$this->rhythmService = $rhythmService;
		$this->medalService = $medalService;
	}

	/**
	 * Run the timeline reconciliation pass
	 */
	protected function run($argument): void {
		$now = clone $this->time->getDateTime();
		$this->reconcileAt($now);
	}

	/** Shared cron/CLI seam; --as-of passes the simulated instant here. */
	public function reconcileAt(\DateTime $now): void {
		$this->occurrenceService->reconcileHouseForJobs($now);
		// WP2: converge the persisted neutral-suspension facts with the
		// write-free projection (a stopped cron plus read/complete
		// converges here without receipts for fictional actors).
		$this->pauseService->reconcilePauseEffectsForJobs($now);
		$this->rhythmService->refreshForJobs($now);
		$this->medalService->reconcileClosedWeeksForJobs($now);
	}
}
