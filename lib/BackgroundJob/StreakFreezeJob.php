<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\BackgroundJob;

use OCA\ChoreStrike\Service\StreakFreezeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Daily streak freeze job (06:00).
 *
 * Thin delegate (CS-PLAN-023): derives "yesterday" from the injected
 * ITimeFactory and hands the pass to {@see StreakFreezeService}, which
 * holds the freeze/reset logic and is independently triggerable via
 * occ chorestrike:trigger-jobs.
 */
class StreakFreezeJob extends TimedJob {
	private StreakFreezeService $freezeService;

	public function __construct(
		ITimeFactory $time,
		StreakFreezeService $freezeService,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 60 * 60);

		$this->freezeService = $freezeService;
	}

	/**
	 * Run the daily streak freeze check
	 */
	protected function run($argument): void {
		$this->freezeService->process($this->deriveYesterday());
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
