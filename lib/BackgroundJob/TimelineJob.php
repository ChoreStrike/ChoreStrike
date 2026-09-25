<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\BackgroundJob;

use OCA\ChoreStrike\Service\OccurrenceService;
use OCA\ChoreStrike\Service\PauseService;
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
 * receipt. No GET or timeline pass mints awards, credits or
 * notifications; WP4 adds the time-driven rhythm consumer.
 */
class TimelineJob extends TimedJob {
	private OccurrenceService $occurrenceService;
	private PauseService $pauseService;

	public function __construct(
		ITimeFactory $time,
		OccurrenceService $occurrenceService,
		PauseService $pauseService,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);

		$this->occurrenceService = $occurrenceService;
		$this->pauseService = $pauseService;
	}

	/**
	 * Run the timeline reconciliation pass
	 */
	protected function run($argument): void {
		$now = clone $this->time->getDateTime();
		$this->occurrenceService->reconcileHouseForJobs($now);
		// WP2: converge the persisted neutral-suspension facts with the
		// write-free projection (a stopped cron plus read/complete
		// converges here without receipts for fictional actors).
		$this->pauseService->reconcilePauseEffectsForJobs($now);
	}
}
