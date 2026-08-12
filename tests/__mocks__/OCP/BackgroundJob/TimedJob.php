<?php

namespace OCP\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Mock TimedJob base class for testing.
 *
 * Mirrors the minimal surface ChoreStrike's TimedJob subclasses
 * (StreakFreezeJob, StreakRestockJob, DailyNotificationJob) actually
 * touch from the parent: the constructor stores the time factory and
 * setInterval is a setter. The real Nextcloud class implements
 * scheduling + dispatch; tests bypass that and invoke run() directly
 * via reflection.
 *
 * The class is declared abstract with a public constructor + protected
 * run so subclasses can extend it without being forced to implement run.
 */
abstract class TimedJob {
	protected ITimeFactory $time;

	public function __construct(ITimeFactory $time) {
		$this->time = $time;
	}

	/**
	 * Set the desired run interval in seconds.
	 *
	 * @param int $interval Interval in seconds
	 */
	public function setInterval(int $interval): void {
		// No-op for tests: the real impl stores this for the scheduler.
	}

	/**
	 * Subclasses implement their run logic here.
	 *
	 * @param mixed $argument Job argument
	 */
	abstract protected function run($argument);
}
