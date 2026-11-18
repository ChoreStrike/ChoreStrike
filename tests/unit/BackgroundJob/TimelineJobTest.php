<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\BackgroundJob;

use OCA\ChoreStrike\BackgroundJob\TimelineJob;
use OCA\ChoreStrike\Service\CycleAdvanceService;
use OCA\ChoreStrike\Service\MedalService;
use OCA\ChoreStrike\Service\RhythmService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * CS-PLAN-091 WP-B1: the timeline job is a thin delegate — it derives
 * "now" from the injected clock (never mutating the factory instance) and
 * hands the pass to the cycle advance service (missed cycles, projection), then
 * to the rhythm and the closed-week recognition. Nothing is materialized
 * and no pause effect is written any more.
 */
class TimelineJobTest extends TestCase {
	public function testRunDelegatesToTheCycleServiceWithClockNow(): void {
		$now = new \DateTime('2026-09-20T14:12:00+00:00');
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn($now);
		$atNow = $this->callback(static fn (\DateTime $passed): bool => $passed->format('c') === '2026-09-20T14:12:00+00:00');

		$cycles = $this->createMock(CycleAdvanceService::class);
		$cycles->expects($this->once())->method('advanceForJobs')->with($atNow);
		$rhythmService = $this->createMock(RhythmService::class);
		$rhythmService->expects($this->once())->method('refreshForJobs')->with($atNow);
		$medals = $this->createMock(MedalService::class);
		$medals->expects($this->once())->method('reconcileClosedWeeksForJobs')->with($atNow);

		$job = new TimelineJob($timeFactory, $cycles, $rhythmService, $medals);
		$ref = new \ReflectionMethod($job, 'run');
		$ref->setAccessible(true);
		$ref->invoke($job, null);

		// The factory's instance was cloned, never mutated.
		self::assertSame('2026-09-20T14:12:00+00:00', $now->format('c'));
	}

	public function testReconcileAt_CliAsOf_UsesSameOrderedPassAsCron(): void {
		$now = new \DateTime('2026-10-05T00:01:00+00:00');
		$time = $this->createMock(ITimeFactory::class);
		$time->expects($this->never())->method('getDateTime');
		$order = [];
		$cycles = $this->createMock(CycleAdvanceService::class);
		$cycles->method('advanceForJobs')->willReturnCallback(static function () use (&$order): array {
			$order[] = 'cycles';
			return ['houses' => 1, 'tasks' => 0, 'missed' => 0];
		});
		$rhythm = $this->createMock(RhythmService::class);
		$rhythm->method('refreshForJobs')->willReturnCallback(static function () use (&$order): void {
			$order[] = 'rhythm';
		});
		$medals = $this->createMock(MedalService::class);
		$medals->method('reconcileClosedWeeksForJobs')->willReturnCallback(static function () use (&$order): void {
			$order[] = 'closedWeeks';
		});

		(new TimelineJob($time, $cycles, $rhythm, $medals))->reconcileAt($now);
		self::assertSame(['cycles', 'rhythm', 'closedWeeks'], $order);
	}
}
