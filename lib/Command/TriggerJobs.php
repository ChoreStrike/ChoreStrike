<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Command;

use OCA\ChoreStrike\BackgroundJob\TimelineJob;
use OCA\ChoreStrike\Service\TaskDueReminderService;
use OCA\ChoreStrike\Service\TestDataService;
use OCP\AppFramework\Utility\ITimeFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command to trigger ChoreStrike background jobs on demand (test-only).
 *
 * The job bodies live in services (CS-PLAN-023 refactor), so this command
 * calls them directly — no cron, no multi-day waits. `--as-of` sets the
 * reference "now" day: the task-due reminder pass (CS-PLAN-030) uses the
 * as-of day itself, so an E2E can simulate "N days passed" with zero clock
 * trickery. The freeze/restock streak passes retired with the WP3
 * legacy-effect cutover (CS-PLAN-070, Q-074-01 Option A).
 *
 * Usage:
 *   php occ chorestrike:trigger-jobs daily-notification --as-of=2026-08-05
 *   php occ chorestrike:trigger-jobs timeline --as-of=2026-09-21
 *   php occ chorestrike:trigger-jobs all
 *
 * Guarded by TestDataService::isTestMode() like TestDataSeed — refuses to
 * run unless NEXTCLOUD_TEST_MODE=true.
 */
class TriggerJobs extends Command {
	/**
	 * Valid job types (the freeze/restock passes retired with the WP3
	 * legacy-effect cutover).
	 */
	public const TYPES = ['daily-notification', 'timeline', 'all'];

	public function __construct(
		private TestDataService $testDataService,
		private TaskDueReminderService $taskDueReminderService,
		private TimelineJob $timelineJob,
		private ITimeFactory $time,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('chorestrike:trigger-jobs');
		$this->setDescription('Trigger ChoreStrike background jobs on demand (test mode only)');
		$this->addArgument(
			'type',
			InputArgument::REQUIRED,
			'Job type: ' . implode(' | ', self::TYPES)
		);
		$this->addOption(
			'as-of',
			null,
			InputOption::VALUE_OPTIONAL,
			'Reference date (YYYY-MM-DD); the task-due pass uses the day itself. Defaults to now.'
		);
		$this->addOption(
			'user',
			null,
			InputOption::VALUE_OPTIONAL,
			'Scope to one Nextcloud user ID where the service supports it.'
		);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->testDataService->isTestMode()) {
			$output->writeln('<error>ERROR: This command can only run in test mode.</error>');
			$output->writeln('<comment>Set NEXTCLOUD_TEST_MODE=true or run in CI environment.</comment>');
			return Command::FAILURE;
		}

		$type = (string)$input->getArgument('type');
		if (!in_array($type, self::TYPES, true)) {
			$output->writeln("<error>Invalid job type: {$type}</error>");
			$output->writeln('<comment>Valid types: ' . implode(', ', self::TYPES) . '</comment>');
			return Command::FAILURE;
		}

		try {
			$today = $this->resolveToday($input->getOption('as-of'));
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		if ($input->getOption('user') !== null && $input->getOption('user') !== '') {
			$output->writeln(
				'<comment>Notice: per-user scoping is not supported by the job services yet; running the full pass.</comment>'
			);
		}

		$results = [];
		if ($type === 'daily-notification' || $type === 'all') {
			$results['task-due-reminders'] = $this->taskDueReminderService->process($today);
		}
		if ($type === 'timeline' || $type === 'all') {
			// Use the full cron pass so a simulated closed week also reconciles
			// pause, rhythm, recognition, and its one-time household bell.
			$this->timelineJob->reconcileAt($today);
			$results['timeline'] = ['triggered' => 1];
		}

		foreach ($results as $name => $stats) {
			$pairs = [];
			foreach ($stats as $key => $value) {
				$pairs[] = "{$key}={$value}";
			}
			$output->writeln("<info>{$name}: " . implode(', ', $pairs) . '</info>');
		}

		return Command::SUCCESS;
	}

	/**
	 * Derive the "today" reference date passed to (or derived by) the services.
	 *
	 * Without --as-of the injected clock provides "now"; with --as-of the given
	 * day is the reference. The task-due pass uses "today" itself.
	 *
	 * @param string|null $asOf Optional reference date (YYYY-MM-DD)
	 * @throws \InvalidArgumentException When --as-of is not a valid Y-m-d date
	 */
	private function resolveToday(?string $asOf): \DateTime {
		if ($asOf === null || $asOf === '') {
			return clone $this->time->getDateTime();
		}

		$parsed = \DateTime::createFromFormat('Y-m-d', $asOf);
		if ($parsed === false || $parsed->format('Y-m-d') !== $asOf) {
			throw new \InvalidArgumentException("Invalid --as-of date: {$asOf} (expected YYYY-MM-DD)");
		}

		$parsed->setTime(0, 0, 0);
		return $parsed;
	}
}
