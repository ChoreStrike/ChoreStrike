<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Command;

use OCA\ChoreStrike\Service\StreakAtRiskService;
use OCA\ChoreStrike\Service\StreakFreezeService;
use OCA\ChoreStrike\Service\StreakRestockService;
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
 * reference "now" day: "yesterday" is derived as as-of minus one day for the
 * streak passes, and the task-due reminder pass (CS-PLAN-030) uses the
 * as-of day itself, so an E2E can simulate "N days passed" with zero clock
 * trickery.
 *
 * Usage:
 *   php occ chorestrike:trigger-jobs streak-freeze --as-of=2026-08-05
 *   php occ chorestrike:trigger-jobs daily-notification
 *   php occ chorestrike:trigger-jobs streak-restock
 *   php occ chorestrike:trigger-jobs all
 *
 * Guarded by TestDataService::isTestMode() like TestDataSeed — refuses to
 * run unless NEXTCLOUD_TEST_MODE=true.
 */
class TriggerJobs extends Command {
	/**
	 * Valid job types. `all` runs daily-notification BEFORE streak-freeze:
	 * the at-risk reminder must fire while the streak is still positive,
	 * which the freeze pass can reset (deterministic resolution of the
	 * 06:00 ordering race documented in appinfo/info.xml).
	 */
	public const TYPES = ['daily-notification', 'streak-freeze', 'streak-restock', 'all'];

	public function __construct(
		private TestDataService $testDataService,
		private StreakAtRiskService $atRiskService,
		private TaskDueReminderService $taskDueReminderService,
		private StreakFreezeService $freezeService,
		private StreakRestockService $restockService,
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
			'Reference date (YYYY-MM-DD); "yesterday" (streak passes) is derived as as-of minus one day, the task-due pass uses the day itself. Defaults to now.'
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
		$yesterday = (clone $today)->modify('-1 day');

		if ($input->getOption('user') !== null && $input->getOption('user') !== '') {
			$output->writeln(
				'<comment>Notice: per-user scoping is not supported by the job services yet; running the full pass.</comment>'
			);
		}

		$results = [];
		if ($type === 'daily-notification' || $type === 'all') {
			$results['daily-notification'] = $this->atRiskService->process($yesterday);
			$results['task-due-reminders'] = $this->taskDueReminderService->process($today);
		}
		if ($type === 'streak-freeze' || $type === 'all') {
			$results['streak-freeze'] = $this->freezeService->process($yesterday);
		}
		if ($type === 'streak-restock' || $type === 'all') {
			$results['streak-restock'] = $this->restockService->process();
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
	 * day is the reference. "Yesterday" (streak passes) is derived as today
	 * minus one day by the caller; the task-due pass uses "today" itself.
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
