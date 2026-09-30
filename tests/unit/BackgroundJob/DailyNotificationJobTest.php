<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\BackgroundJob;

use OCA\ChoreStrike\BackgroundJob\DailyNotificationJob;
use OCA\ChoreStrike\Service\DigestService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DailyNotificationJob (CS-PLAN-070 WP5 digest pass).
 *
 * The job is the app's ONLY notification driver: a five-minute TimedJob
 * delegating the pass to {@see DigestService} with the injected clock's
 * instant. The former 24-hour task-due reminder pass retired with the
 * digest cutover; notification behaviour itself is covered by the
 * service tests.
 */
class DailyNotificationJobTest extends TestCase {
	private ITimeFactory&MockObject $timeFactory;
	private DigestService&MockObject $digestService;
	private DailyNotificationJob $job;

	protected function setUp(): void {
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->digestService = $this->createMock(DigestService::class);

		$this->job = new DailyNotificationJob(
			$this->timeFactory,
			$this->digestService,
		);
	}

	/**
	 * Run_DelegatesDigestPassWithCurrentInstant
	 *
	 * The time seam (CS-PLAN-023): the digest pass receives the job's
	 * "now" derived purely from the injected clock — no hardcoded
	 * `new \DateTime()`.
	 */
	public function testRun_DelegatesDigestPassWithCurrentInstant(): void {
		$now = new \DateTime('2026-09-29 18:05:00');
		$this->timeFactory->method('getDateTime')->willReturn($now);

		$this->digestService->expects($this->once())
			->method('processDueSlots')
			->with($this->callback(function (\DateTimeInterface $now) {
				return $now->format('Y-m-d H:i') === '2026-09-29 18:05';
			}))
			->willReturn([
				'materialized' => 0,
				'reResolved' => 0,
				'expiredSlots' => 0,
				'dispatched' => 0,
				'skipped' => 0,
				'quiet' => 0,
				'contended' => 0,
				'uncertain' => 0,
			]);

		$this->invokeRun($this->job, null);
	}

	/**
	 * Run_DoesNotMutateTheFactoryDateTime
	 *
	 * The factory's instance must survive the run untouched (guards
	 * against shared-instance clock drift).
	 */
	public function testRun_DoesNotMutateTheFactoryDateTime(): void {
		$now = new \DateTime('2026-09-29 18:05:00');
		$this->timeFactory->method('getDateTime')->willReturn($now);
		$this->digestService->method('processDueSlots')->willReturn([
			'materialized' => 0,
			'reResolved' => 0,
			'expiredSlots' => 0,
			'dispatched' => 0,
			'skipped' => 0,
			'quiet' => 0,
			'contended' => 0,
			'uncertain' => 0,
		]);

		$this->invokeRun($this->job, null);

		$this->assertSame('2026-09-29 18:05:00', $now->format('Y-m-d H:i:s'));
	}

	/**
	 * Job_IsAFiveMinuteTimedJob
	 *
	 * Parent §3.6: the digest pass runs every five minutes (the former
	 * 24-hour daily interval is retired with the task-due pass).
	 */
	public function testJob_IsAFiveMinuteTimedJob(): void {
		$this->assertInstanceOf(\OCP\BackgroundJob\TimedJob::class, $this->job);
		$this->assertSame(5 * 60, $this->job->getInterval(), 'the digest pass must run at a five-minute interval');
	}

	private function invokeRun(object $job, $argument): void {
		$ref = new \ReflectionMethod($job, 'run');
		$ref->setAccessible(true);
		$ref->invoke($job, $argument);
	}
}
