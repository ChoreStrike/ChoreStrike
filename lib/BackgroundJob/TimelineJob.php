<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\BackgroundJob;

use OCA\ChoreStrike\Service\OccurrenceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Occurrence timeline job (CS-PLAN-072 WP1; parent CS-PLAN-070 §3.6).
 *
 * Thin delegate: derives "now" from the injected clock and hands the pass
 * to {@see OccurrenceService::reconcileHouseForJobs()}, which advances the
 * agreement frontier of every active schedule through the same generator
 * the creation and mutation paths use. Runs at five-minute intervals; a
 * late or missed run reconciles history from the immutable rule inputs —
 * it never reconstructs history from today's mutable task definition.
 *
 * Job idempotency comes from natural revision/ordinal uniqueness and
 * monotonic frontiers — no fabricated member actor, no CommandService
 * receipt. No GET or timeline pass mints awards, credits or
 * notifications; WP4 adds the time-driven rhythm consumer.
 */
class TimelineJob extends TimedJob {
	private OccurrenceService $occurrenceService;

	public function __construct(
		ITimeFactory $time,
		OccurrenceService $occurrenceService,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);

		$this->occurrenceService = $occurrenceService;
	}

	/**
	 * Run the timeline reconciliation pass
	 */
	protected function run($argument): void {
		$now = clone $this->time->getDateTime();
		$this->occurrenceService->reconcileHouseForJobs($now);
	}
}
