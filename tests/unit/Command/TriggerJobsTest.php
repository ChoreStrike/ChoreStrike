<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Command;

use OCA\ChoreStrike\BackgroundJob\TimelineJob;
use OCA\ChoreStrike\Command\TriggerJobs;
use OCA\ChoreStrike\Service\TaskDueReminderService;
use OCA\ChoreStrike\Service\TestDataService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Unit tests for the chorestrike:trigger-jobs OCC command (CS-PLAN-023).
 *
 * Covers dispatch per job type, the `all` ordering, the isTestMode()
 * guard, unknown-type rejection and the --as-of time seam. The
 * freeze/restock/at-risk types retired with the WP3 legacy-effect
 * cutover (CS-PLAN-070 WP3, Q-074-01 Option A).
 */
class TriggerJobsTest extends TestCase {
	private TestDataService&MockObject $testDataService;
	private TaskDueReminderService&MockObject $taskDueReminderService;
	private TimelineJob&MockObject $timelineJob;
	private ITimeFactory&MockObject $timeFactory;
	private TriggerJobs $command;
	private OutputInterface&MockObject $output;
	/** @var string[] */
	private array $outputLines;

	protected function setUp(): void {
		$this->testDataService = $this->createMock(TestDataService::class);
		$this->taskDueReminderService = $this->createMock(TaskDueReminderService::class);
		$this->timelineJob = $this->createMock(TimelineJob::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);

		$this->command = new TriggerJobs(
			$this->testDataService,
			$this->taskDueReminderService,
			$this->timelineJob,
			$this->timeFactory,
		);

		$this->outputLines = [];
		$this->output = $this->createMock(OutputInterface::class);
		$this->output->method('writeln')
			->willReturnCallback(function ($line) {
				$this->outputLines[] = (string)$line;
			});

		// Default: test mode enabled, clock pinned.
		$this->testDataService->method('isTestMode')->willReturn(true);
		$this->timeFactory->method('getDateTime')
			->willReturn(new \DateTime('2026-08-04 15:30:00'));
	}

	/**
	 * Execute_RefusesOutsideTestMode
	 */
	public function testExecute_RefusesOutsideTestMode(): void {
		$testDataService = $this->createMock(TestDataService::class);
		$testDataService->method('isTestMode')->willReturn(false);
		$command = new TriggerJobs(
			$testDataService,
			$this->taskDueReminderService,
			$this->timelineJob,
			$this->timeFactory,
		);

		$this->taskDueReminderService->expects($this->never())->method('process');
		$this->timelineJob->expects($this->never())->method('reconcileAt');

		$exitCode = $command->run(
			$this->makeInput('timeline'),
			$this->output
		);

		$this->assertSame(TriggerJobs::FAILURE, $exitCode);
		$this->assertStringContainsString('test mode', implode("\n", $this->outputLines));
	}

	/**
	 * Execute_DispatchesTimelineOnly
	 *
	 * The deterministic timeline trigger delegates to the full pass the
	 * five-minute TimelineJob runs, with the
	 * --as-of clock substitution (here: the default clock's today).
	 */
	public function testExecute_DispatchesTimelineOnly(): void {
		$this->taskDueReminderService->expects($this->never())->method('process');
		$this->timelineJob->expects($this->once())->method('reconcileAt')
			->with($this->callback(static fn (\DateTimeInterface $now): bool => $now->format('Y-m-d') === '2026-08-04'));

		$exitCode = $this->command->run(
			$this->makeInput('timeline'),
			$this->output
		);

		$this->assertSame(TriggerJobs::SUCCESS, $exitCode);
		$this->assertStringContainsString('triggered=1', implode("\n", $this->outputLines));
	}

	/**
	 * Execute_RejectsUnknownType
	 *
	 * The retired freeze/restock types are no longer valid dispatches.
	 */
	public function testExecute_RejectsUnknownType(): void {
		$this->taskDueReminderService->expects($this->never())->method('process');
		$this->timelineJob->expects($this->never())->method('reconcileAt');

		$exitCode = $this->command->run(
			$this->makeInput('streak-freeze'),
			$this->output
		);

		$this->assertSame(TriggerJobs::FAILURE, $exitCode);
		$this->assertStringContainsString('Invalid job type', implode("\n", $this->outputLines));
	}

	/**
	 * Execute_DispatchesDailyNotificationOnly
	 *
	 * daily-notification is the task-due reminder pass with the injected
	 * day — the streak-at-risk pass retired with the freeze effects.
	 */
	public function testExecute_DispatchesDailyNotificationOnly(): void {
		$this->taskDueReminderService->expects($this->once())->method('process')
			->willReturn(['processed' => 0, 'notifiedToday' => 0, 'notifiedTomorrow' => 0, 'skippedUnavailable' => 0]);
		$this->timelineJob->expects($this->never())->method('reconcileAt');

		$exitCode = $this->command->run(
			$this->makeInput('daily-notification'),
			$this->output
		);

		$this->assertSame(TriggerJobs::SUCCESS, $exitCode);
	}

	/**
	 * Execute_AllRunsEachServiceExactlyOnce
	 */
	public function testExecute_AllRunsEachServiceExactlyOnce(): void {
		$order = [];
		$this->taskDueReminderService->expects($this->once())->method('process')
			->willReturnCallback(function () use (&$order) {
				$order[] = 'task-due-reminders';
				return ['processed' => 0, 'notifiedToday' => 0, 'notifiedTomorrow' => 0, 'skippedUnavailable' => 0];
			});
		$this->timelineJob->expects($this->once())->method('reconcileAt')
			->willReturnCallback(function () use (&$order) {
				$order[] = 'timeline';
			});

		$exitCode = $this->command->run(
			$this->makeInput('all'),
			$this->output
		);

		$this->assertSame(TriggerJobs::SUCCESS, $exitCode);
		$this->assertSame(['task-due-reminders', 'timeline'], $order);
	}

	/**
	 * Execute_DefaultAsOfDerivesTodayFromInjectedFactory
	 *
	 * Without --as-of the injected clock defines "today" (task-due and
	 * timeline passes).
	 */
	public function testExecute_DefaultAsOfDerivesTodayFromInjectedFactory(): void {
		$this->taskDueReminderService->expects($this->once())->method('process')
			->with($this->callback(function (\DateTimeInterface $today) {
				return $today->format('Y-m-d') === '2026-08-04';
			}))
			->willReturn(['processed' => 0, 'notifiedToday' => 0, 'notifiedTomorrow' => 0, 'skippedUnavailable' => 0]);
		$this->timelineJob->expects($this->once())->method('reconcileAt')
			->with($this->callback(static fn (\DateTimeInterface $today): bool => $today->format('Y-m-d') === '2026-08-04'));

		$this->command->run(
			$this->makeInput('all'),
			$this->output
		);
	}

	/**
	 * Execute_AsOfControlsToday
	 *
	 * --as-of=2026-08-10 makes "today" 2026-08-10 for both passes — the
	 * E2E seam for simulating "N days passed" with zero clock trickery.
	 */
	public function testExecute_AsOfControlsToday(): void {
		$this->taskDueReminderService->expects($this->once())->method('process')
			->with($this->callback(function (\DateTimeInterface $today) {
				return $today->format('Y-m-d') === '2026-08-10';
			}))
			->willReturn(['processed' => 0, 'notifiedToday' => 0, 'notifiedTomorrow' => 0, 'skippedUnavailable' => 0]);
		$this->timelineJob->expects($this->once())->method('reconcileAt')
			->with($this->callback(static fn (\DateTimeInterface $today): bool => $today->format('Y-m-d') === '2026-08-10'));

		$exitCode = $this->command->run(
			$this->makeInput('all', ['as-of' => '2026-08-10']),
			$this->output
		);

		$this->assertSame(TriggerJobs::SUCCESS, $exitCode);
	}

	/**
	 * Execute_InvalidAsOfRejected
	 */
	public function testExecute_InvalidAsOfRejected(): void {
		$this->taskDueReminderService->expects($this->never())->method('process');
		$this->timelineJob->expects($this->never())->method('reconcileAt');

		$exitCode = $this->command->run(
			$this->makeInput('timeline', ['as-of' => 'not-a-date']),
			$this->output
		);

		$this->assertSame(TriggerJobs::FAILURE, $exitCode);
		$this->assertStringContainsString('Invalid --as-of date', implode("\n", $this->outputLines));
	}

	/**
	 * Execute_UserOptionLogsNoticeAndRunsFullPass
	 *
	 * Per-user scoping is not supported by the services yet; the command
	 * must say so and still run the full pass (scope documented in the AC).
	 */
	public function testExecute_UserOptionLogsNoticeAndRunsFullPass(): void {
		$this->timelineJob->expects($this->once())->method('reconcileAt');

		$exitCode = $this->command->run(
			$this->makeInput('timeline', ['user' => 'mom']),
			$this->output
		);

		$this->assertSame(TriggerJobs::SUCCESS, $exitCode);
		$this->assertStringContainsString('per-user scoping is not supported', implode("\n", $this->outputLines));
	}

	/**
	 * Configure_RegistersNameArgumentAndOptions
	 */
	public function testConfigure_RegistersNameArgumentAndOptions(): void {
		$this->assertSame('chorestrike:trigger-jobs', $this->command->getName());
		$this->assertArrayHasKey('type', $this->command->getRecordedArguments());
		$this->assertSame(
			InputArgument::REQUIRED,
			$this->command->getRecordedArguments()['type']['mode']
		);

		$options = $this->command->getRecordedOptions();
		$this->assertArrayHasKey('as-of', $options);
		$this->assertSame(InputOption::VALUE_OPTIONAL, $options['as-of']['mode']);
		$this->assertArrayHasKey('user', $options);
		$this->assertSame(InputOption::VALUE_OPTIONAL, $options['user']['mode']);
	}

	/**
	 * Build a mock input carrying the given type argument and options.
	 *
	 * @param array<string, mixed> $options
	 */
	private function makeInput(string $type, array $options = []): InputInterface {
		$input = $this->createMock(InputInterface::class);
		$input->method('getArgument')
			->willReturnMap([['type', $type]]);
		$input->method('getOption')
			->willReturnCallback(static function (string $name) use ($options) {
				return $options[$name] ?? null;
			});
		$input->method('hasOption')->willReturn(true);

		return $input;
	}
}
