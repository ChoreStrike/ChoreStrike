<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\BackgroundJob;

use OCA\ChoreStrike\BackgroundJob\TimelineJob;
use OCA\ChoreStrike\Service\MedalService;
use OCA\ChoreStrike\Service\OccurrenceService;
use OCA\ChoreStrike\Service\PauseService;
use OCA\ChoreStrike\Service\RhythmService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * CS-PLAN-072 WP1: the occurrence timeline job is a thin delegate — it
 * derives "now" from the injected clock (never mutating the factory
 * instance) and hands the pass to the occurrence service's house
 * reconciliation, then pause, rhythm and closed-week recognition.
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
		$rhythmService = $this->createMock(RhythmService::class);
		$rhythmService->expects($this->once())
			->method('refreshForJobs')
			->with($this->callback(static fn (\DateTime $passed): bool => $passed->format('c') === '2026-09-20T14:12:00+00:00'));
		$medals = $this->createMock(MedalService::class);
		$medals->expects($this->once())->method('reconcileClosedWeeksForJobs')
			->with($this->callback(static fn (\DateTime $passed): bool => $passed->format('c') === '2026-09-20T14:12:00+00:00'));

		$job = new TimelineJob($timeFactory, $occurrenceService, $pauseService, $rhythmService, $medals);
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
		$occurrences = $this->createMock(OccurrenceService::class);
		$occurrences->method('reconcileHouseForJobs')->willReturnCallback(static function () use (&$order): array {
			$order[] = 'occurrences';
			return [];
		});
		$pauses = $this->createMock(PauseService::class);
		$pauses->method('reconcilePauseEffectsForJobs')->willReturnCallback(static function () use (&$order): array {
			$order[] = 'pauses';
			return [];
		});
		$rhythm = $this->createMock(RhythmService::class);
		$rhythm->method('refreshForJobs')->willReturnCallback(static function () use (&$order): void {
			$order[] = 'rhythm';
		});
		$medals = $this->createMock(MedalService::class);
		$medals->method('reconcileClosedWeeksForJobs')->willReturnCallback(static function () use (&$order): void {
			$order[] = 'closedWeeks';
		});

		(new TimelineJob($time, $occurrences, $pauses, $rhythm, $medals))->reconcileAt($now);
		self::assertSame(['occurrences', 'pauses', 'rhythm', 'closedWeeks'], $order);
	}
}
