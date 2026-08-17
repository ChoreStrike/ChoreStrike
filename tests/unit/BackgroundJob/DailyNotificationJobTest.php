<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\BackgroundJob;

use OCA\ChoreStrike\BackgroundJob\DailyNotificationJob;
use OCA\ChoreStrike\Service\StreakAtRiskService;
use OCA\ChoreStrike\Service\TaskDueReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DailyNotificationJob.
 *
 * Since CS-PLAN-023 the job is a thin delegate: it derives "yesterday"
 * from the injected ITimeFactory and hands the pass to
 * StreakAtRiskService. Since CS-PLAN-030 it additionally runs the
 * TaskDueReminderService pass with the current day (FEAT-NOTIFY-001). The
 * notification behaviour itself is covered by the services' tests as a
 * function of the injected date.
 */
class DailyNotificationJobTest extends TestCase {
	private ITimeFactory&MockObject $timeFactory;
	private StreakAtRiskService&MockObject $atRiskService;
	private TaskDueReminderService&MockObject $taskDueReminderService;
	private DailyNotificationJob $job;

	protected function setUp(): void {
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->atRiskService = $this->createMock(StreakAtRiskService::class);
		$this->taskDueReminderService = $this->createMock(TaskDueReminderService::class);

		$this->job = new DailyNotificationJob(
			$this->timeFactory,
			$this->atRiskService,
			$this->taskDueReminderService,
		);
	}

	/**
	 * Run_DerivesYesterdayFromInjectedFactoryAndDelegates
	 *
	 * The time seam (CS-PLAN-023): "yesterday" must be a pure function of
	 * the injected clock — no hardcoded `new \DateTime('yesterday')`.
	 */
	public function testRun_DerivesYesterdayFromInjectedFactoryAndDelegates(): void {
		$now = new \DateTime('2026-08-04 06:00:00');
		$this->timeFactory->method('getDateTime')->willReturn($now);

		$this->atRiskService->expects($this->once())
			->method('process')
			->with($this->callback(function (\DateTimeInterface $yesterday) {
				return $yesterday->format('Y-m-d') === '2026-08-03';
			}))
			->willReturn([
				'processed' => 0,
				'notified' => 0,
				'skippedUnavailable' => 0,
			]);

		$this->invokeRun($this->job, null);
	}

	/**
	 * Run_DelegatesDueRemindersWithCurrentDay
	 *
	 * CS-PLAN-030: the task-due pass receives the job's "today" derived
	 * from the same injected clock.
	 */
	public function testRun_DelegatesDueRemindersWithCurrentDay(): void {
		$now = new \DateTime('2026-08-04 06:00:00');
		$this->timeFactory->method('getDateTime')->willReturn($now);
		$this->atRiskService->method('process')->willReturn([
			'processed' => 0,
			'notified' => 0,
			'skippedUnavailable' => 0,
		]);

		$this->taskDueReminderService->expects($this->once())
			->method('process')
			->with($this->callback(function (\DateTimeInterface $today) {
				return $today->format('Y-m-d') === '2026-08-04';
			}))
			->willReturn([
				'processed' => 0,
				'notifiedToday' => 0,
				'notifiedTomorrow' => 0,
				'skippedUnavailable' => 0,
			]);

		$this->invokeRun($this->job, null);
	}

	/**
	 * Run_DoesNotMutateTheFactoryDateTime
	 *
	 * The job clones before modify(); the factory's instance must survive
	 * the run untouched (guards against shared-instance clock drift).
	 */
	public function testRun_DoesNotMutateTheFactoryDateTime(): void {
		$now = new \DateTime('2026-08-04 06:00:00');
		$this->timeFactory->method('getDateTime')->willReturn($now);
		$this->atRiskService->method('process')->willReturn([
			'processed' => 0,
			'notified' => 0,
			'skippedUnavailable' => 0,
		]);
		$this->taskDueReminderService->method('process')->willReturn([
			'processed' => 0,
			'notifiedToday' => 0,
			'notifiedTomorrow' => 0,
			'skippedUnavailable' => 0,
		]);

		$this->invokeRun($this->job, null);

		$this->assertSame('2026-08-04 06:00:00', $now->format('Y-m-d H:i:s'));
	}

	private function invokeRun(object $job, $argument): void {
		$ref = new \ReflectionMethod($job, 'run');
		$ref->setAccessible(true);
		$ref->invoke($job, $argument);
	}
}
