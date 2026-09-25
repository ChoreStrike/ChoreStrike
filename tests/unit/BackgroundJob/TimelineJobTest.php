<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\BackgroundJob;

use OCA\ChoreStrike\BackgroundJob\TimelineJob;
use OCA\ChoreStrike\Service\OccurrenceService;
use OCA\ChoreStrike\Service\PauseService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * CS-PLAN-072 WP1: the occurrence timeline job is a thin delegate — it
 * derives "now" from the injected clock (never mutating the factory
 * instance) and hands the pass to the occurrence service's house
 * reconciliation, then converges the WP2 pause suspension facts. No
 * award, credit or notification minting lives here.
 */
class TimelineJobTest extends TestCase {
	public function testRunDelegatesToOccurrenceServiceWithClockNow(): void {
		$now = new \DateTime('2026-09-20T14:12:00+00:00');
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn($now);

		$occurrenceService = $this->createMock(OccurrenceService::class);
		$occurrenceService->expects($this->once())
			->method('reconcileHouseForJobs')
			->with($this->callback(static fn (\DateTime $passed): bool => $passed->format('c') === '2026-09-20T14:12:00+00:00'));

		$pauseService = $this->createMock(PauseService::class);
		$pauseService->expects($this->once())
			->method('reconcilePauseEffectsForJobs')
			->with($this->callback(static fn (\DateTime $passed): bool => $passed->format('c') === '2026-09-20T14:12:00+00:00'));

		$job = new TimelineJob($timeFactory, $occurrenceService, $pauseService);
		$ref = new \ReflectionMethod($job, 'run');
		$ref->setAccessible(true);
		$ref->invoke($job, null);

		// The factory's instance was cloned, never mutated.
		self::assertSame('2026-09-20T14:12:00+00:00', $now->format('c'));
	}
}
