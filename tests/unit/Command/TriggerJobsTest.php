<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Command;

use OCA\ChoreStrike\Command\TriggerJobs;
use OCA\ChoreStrike\Service\StreakAtRiskService;
use OCA\ChoreStrike\Service\StreakFreezeService;
use OCA\ChoreStrike\Service\StreakRestockService;
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
 * guard, unknown-type rejection and the --as-of time seam.
 */
class TriggerJobsTest extends TestCase {
	private TestDataService&MockObject $testDataService;
	private StreakAtRiskService&MockObject $atRiskService;
	private TaskDueReminderService&MockObject $taskDueReminderService;
	private StreakFreezeService&MockObject $freezeService;
	private StreakRestockService&MockObject $restockService;
	private \OCA\ChoreStrike\Service\OccurrenceService&MockObject $occurrenceService;
	private ITimeFactory&MockObject $timeFactory;
	private TriggerJobs $command;
	private OutputInterface&MockObject $output;
	/** @var string[] */
	private array $outputLines;

	protected function setUp(): void {
		$this->testDataService = $this->createMock(TestDataService::class);
		$this->atRiskService = $this->createMock(StreakAtRiskService::class);
		$this->taskDueReminderService = $this->createMock(TaskDueReminderService::class);
		$this->freezeService = $this->createMock(StreakFreezeService::class);
		$this->restockService = $this->createMock(StreakRestockService::class);
		$this->occurrenceService = $this->createMock(\OCA\ChoreStrike\Service\OccurrenceService::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);

		$this->command = new TriggerJobs(
			$this->testDataService,
			$this->atRiskService,
			$this->taskDueReminderService,
			$this->freezeService,
			$this->restockService,
			$this->occurrenceService,
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
			$this->atRiskService,
			$this->taskDueReminderService,
			$this->freezeService,
			$this->restockService,
			$this->occurrenceService,
			$this->timeFactory,
		);

		$this->atRiskService->expects($this->never())->method('process');
		$this->taskDueReminderService->expects($this->never())->method('process');
		$this->freezeService->expects($this->never())->method('process');
		$this->restockService->expects($this->never())->method('process');

		$exitCode = $command->run(
			$this->makeInput('streak-freeze'),
			$this->output
		);

		$this->assertSame(TriggerJobs::FAILURE, $exitCode);
		$this->assertStringContainsString('test mode', implode("\n", $this->outputLines));
	}

	/**
	 * Execute_DispatchesTimelineOnly
	 *
	 * CS-PLAN-072 WP1: the deterministic timeline trigger delegates to the
	 * SAME generator pass the five-minute TimelineJob runs, with the
	 * --as-of clock substitution (here: the default clock's today).
	 */
	public function testExecute_DispatchesTimelineOnly(): void {
		$this->atRiskService->expects($this->never())->method('process');
		$this->freezeService->expects($this->never())->method('process');
		$this->restockService->expects($this->never())->method('process');
		$this->occurrenceService->expects($this->once())->method('reconcileHouseForJobs')
			->with($this->callback(static fn (\DateTimeInterface $now): bool => $now->format('Y-m-d') === '2026-08-04'))
			->willReturn(['houses' => 1, 'tasks' => 3, 'materialized' => 2]);

		$exitCode = $this->command->run(
			$this->makeInput('timeline'),
			$this->output
		);

		$this->assertSame(TriggerJobs::SUCCESS, $exitCode);
		$this->assertStringContainsString('houses=1', implode("\n", $this->outputLines));
	}

	/**
	 * Execute_RejectsUnknownType
	 */
	public function testExecute_RejectsUnknownType(): void {
		$this->atRiskService->expects($this->never())->method('process');
		$this->taskDueReminderService->expects($this->never())->method('process');
		$this->freezeService->expects($this->never())->method('process');
		$this->restockService->expects($this->never())->method('process');

		$exitCode = $this->command->run(
			$this->makeInput('bogus-type'),
			$this->output
		);

		$this->assertSame(TriggerJobs::FAILURE, $exitCode);
		$this->assertStringContainsString('Invalid job type', implode("\n", $this->outputLines));
	}

	/**
	 * Execute_DispatchesDailyNotificationOnly
	 *
	 * daily-notification covers both daily passes: the streak-at-risk pass
	 * (yesterday) and the task-due reminder pass (today) — CS-PLAN-030.
	 */
	public function testExecute_DispatchesDailyNotificationOnly(): void {
		$this->atRiskService->expects($this->once())->method('process')
			->willReturn(['processed' => 0, 'notified' => 0, 'skippedUnavailable' => 0]);
		$this->taskDueReminderService->expects($this->once())->method('process')
			->willReturn(['processed' => 0, 'notifiedToday' => 0, 'notifiedTomorrow' => 0, 'skippedUnavailable' => 0]);
		$this->freezeService->expects($this->never())->method('process');
		$this->restockService->expects($this->never())->method('process');

		$exitCode = $this->command->run(
			$this->makeInput('daily-notification'),
			$this->output
		);

		$this->assertSame(TriggerJobs::SUCCESS, $exitCode);
	}

	/**
	 * Execute_DispatchesStreakFreezeOnly
	 */
	public function testExecute_DispatchesStreakFreezeOnly(): void {
		$this->atRiskService->expects($this->never())->method('process');
		$this->freezeService->expects($this->once())->method('process')
			->willReturn(['processed' => 0, 'frozen' => 0, 'reset' => 0, 'skippedUnavailable' => 0, 'errors' => 0]);
		$this->occurrenceService->method('reconcileHouseForJobs')
			->willReturn(['houses' => 1, 'tasks' => 0, 'materialized' => 0]);
		$this->restockService->expects($this->never())->method('process');

		$exitCode = $this->command->run(
			$this->makeInput('streak-freeze'),
			$this->output
		);

		$this->assertSame(TriggerJobs::SUCCESS, $exitCode);
	}

	/**
	 * Execute_DispatchesStreakRestockOnly
	 *
	 * The restock pass is time-independent — it is delegated without a date.
	 */
	public function testExecute_DispatchesStreakRestockOnly(): void {
		$this->atRiskService->expects($this->never())->method('process');
		$this->freezeService->expects($this->never())->method('process');
		$this->restockService->expects($this->once())->method('process')
			->willReturn(['processed' => 0, 'freezesPerWeek' => 1, 'cap' => 2]);

		$exitCode = $this->command->run(
			$this->makeInput('streak-restock'),
			$this->output
		);

		$this->assertSame(TriggerJobs::SUCCESS, $exitCode);
	}

	/**
	 * Execute_AllRunsEachServiceExactlyOnce
	 *
	 * `all` runs daily-notification BEFORE streak-freeze so the at-risk
	 * reminder fires while the streak is still positive (deterministic
	 * resolution of the 06:00 ordering race noted in appinfo/info.xml).
	 */
	public function testExecute_AllRunsEachServiceExactlyOnce(): void {
		$order = [];
		$this->atRiskService->expects($this->once())->method('process')
			->willReturnCallback(function () use (&$order) {
				$order[] = 'daily-notification';
				return ['processed' => 0, 'notified' => 0, 'skippedUnavailable' => 0];
			});
		$this->taskDueReminderService->expects($this->once())->method('process')
			->willReturnCallback(function () use (&$order) {
				$order[] = 'task-due-reminders';
				return ['processed' => 0, 'notifiedToday' => 0, 'notifiedTomorrow' => 0, 'skippedUnavailable' => 0];
			});
		$this->freezeService->expects($this->once())->method('process')
			->willReturnCallback(function () use (&$order) {
				$order[] = 'streak-freeze';
				return ['processed' => 0, 'frozen' => 0, 'reset' => 0, 'skippedUnavailable' => 0, 'errors' => 0];
			});
		$this->restockService->expects($this->once())->method('process')
			->willReturnCallback(function () use (&$order) {
				$order[] = 'streak-restock';
				return ['processed' => 0, 'freezesPerWeek' => 1, 'cap' => 2, 'failed' => 0];
			});

		$exitCode = $this->command->run(
			$this->makeInput('all'),
			$this->output
		);

		$this->assertSame(TriggerJobs::SUCCESS, $exitCode);
		$this->assertSame(['daily-notification', 'task-due-reminders', 'streak-freeze', 'streak-restock'], $order);
	}

	/**
	 * Execute_DefaultAsOfDerivesTodayAndYesterdayFromInjectedFactory
	 *
	 * Without --as-of the injected clock defines "today" (task-due pass) and
	 * "yesterday" (streak passes).
	 */
	public function testExecute_DefaultAsOfDerivesTodayAndYesterdayFromInjectedFactory(): void {
		$this->taskDueReminderService->expects($this->once())->method('process')
			->with($this->callback(function (\DateTimeInterface $today) {
				return $today->format('Y-m-d') === '2026-08-04';
			}))
			->willReturn(['processed' => 0, 'notifiedToday' => 0, 'notifiedTomorrow' => 0, 'skippedUnavailable' => 0]);
		$this->freezeService->expects($this->once())->method('process')
			->with($this->callback(function (\DateTimeInterface $yesterday) {
				return $yesterday->format('Y-m-d') === '2026-08-03';
			}))
			->willReturn(['processed' => 0, 'frozen' => 0, 'reset' => 0, 'skippedUnavailable' => 0, 'errors' => 0]);
		$this->occurrenceService->method('reconcileHouseForJobs')
			->willReturn(['houses' => 1, 'tasks' => 0, 'materialized' => 0]);

		$this->command->run(
			$this->makeInput('all'),
			$this->output
		);
	}

	/**
	 * Execute_AsOfControlsTodayAndYesterday
	 *
	 * --as-of=2026-08-10 makes "yesterday" 2026-08-09 for the streak passes
	 * and "today" 2026-08-10 for the task-due pass — the E2E seam for
	 * simulating "N days passed" with zero clock trickery.
	 */
	public function testExecute_AsOfControlsTodayAndYesterday(): void {
		$this->atRiskService->expects($this->once())->method('process')
			->with($this->callback(function (\DateTimeInterface $yesterday) {
				return $yesterday->format('Y-m-d') === '2026-08-09';
			}))
			->willReturn(['processed' => 0, 'notified' => 0, 'skippedUnavailable' => 0]);
		$this->taskDueReminderService->expects($this->once())->method('process')
			->with($this->callback(function (\DateTimeInterface $today) {
				return $today->format('Y-m-d') === '2026-08-10';
			}))
			->willReturn(['processed' => 0, 'notifiedToday' => 0, 'notifiedTomorrow' => 0, 'skippedUnavailable' => 0]);
		$this->freezeService->expects($this->once())->method('process')
			->with($this->callback(function (\DateTimeInterface $yesterday) {
				return $yesterday->format('Y-m-d') === '2026-08-09';
			}))
			->willReturn(['processed' => 0, 'frozen' => 0, 'reset' => 0, 'skippedUnavailable' => 0, 'errors' => 0]);
		$this->occurrenceService->method('reconcileHouseForJobs')
			->willReturn(['houses' => 1, 'tasks' => 0, 'materialized' => 0]);

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
		$this->freezeService->expects($this->never())->method('process');

		$exitCode = $this->command->run(
			$this->makeInput('streak-freeze', ['as-of' => 'not-a-date']),
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
		$this->freezeService->expects($this->once())->method('process')
			->willReturn(['processed' => 0, 'frozen' => 0, 'reset' => 0, 'skippedUnavailable' => 0, 'errors' => 0]);
		$this->occurrenceService->method('reconcileHouseForJobs')
			->willReturn(['houses' => 1, 'tasks' => 0, 'materialized' => 0]);

		$exitCode = $this->command->run(
			$this->makeInput('streak-freeze', ['user' => 'mom']),
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
