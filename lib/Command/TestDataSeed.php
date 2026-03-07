<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Command;

use OCA\ChoreStrike\Service\TestDataService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command to seed test data with different profiles
 *
 * Usage:
 *   php occ chorestrike:test-data:seed --profile=empty
 *   php occ chorestrike:test-data:seed --profile=minimal
 *   php occ chorestrike:test-data:seed --profile=family
 *   php occ chorestrike:test-data:reset
 */
class TestDataSeed extends Command
{
    private TestDataService $testDataService;

    public function __construct(TestDataService $testDataService)
    {
        parent::__construct();
        $this->testDataService = $testDataService;
    }

    protected function configure(): void
    {
        $this->setName('chorestrike:test-data:seed');
        $this->setDescription('Seed test data with a specific profile');
        $this->addOption(
            'profile',
            'p',
            InputOption::VALUE_OPTIONAL,
            'Profile to seed (empty, minimal, family)',
            'minimal'
        );
        $this->addOption(
            'reset',
            'r',
            InputOption::VALUE_NONE,
            'Reset database without seeding (equivalent to --profile=empty)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Check test mode (safety)
        if (!$this->testDataService->isTestMode()) {
            $output->writeln('<error>ERROR: This command can only run in test mode.</error>');
            $output->writeln('<comment>Set NEXTCLOUD_TEST_MODE=true or run in CI environment.</comment>');
            return Command::FAILURE;
        }

        $profile = $input->getOption('profile');
        $reset = $input->getOption('reset');

        if ($reset) {
            $profile = 'empty';
        }

        $validProfiles = ['empty', 'minimal', 'family'];
        if (!in_array($profile, $validProfiles)) {
            $output->writeln("<error>Invalid profile: {$profile}</error>");
            $output->writeln('<comment>Valid profiles: ' . implode(', ', $validProfiles) . '</comment>');
            return Command::FAILURE;
        }

        $output->writeln("<info>Seeding test data with profile: {$profile}</info>");

        try {
            $result = $this->testDataService->seed($profile);

            $output->writeln('<info>✓ Test data seeded successfully</info>');
            $output->writeln('');

            // Print summary
            $data = $result['data'];
            if ($profile === 'empty') {
                $output->writeln('Database reset complete (no data seeded)');
            } elseif ($profile === 'minimal') {
                $output->writeln("House: {$data['house']['name']} (ID: {$data['house']['id']})");
                $output->writeln('Floors: ' . count($data['floors']));
                $output->writeln('Rooms: ' . count($data['rooms']));
                $output->writeln('Users: ' . count($data['users']));
            } elseif ($profile === 'family') {
                $output->writeln("House: {$data['house']['name']} (ID: {$data['house']['id']})");
                $output->writeln('Floors: ' . count($data['floors']));
                $output->writeln('Rooms: ' . count($data['rooms']));
                $output->writeln('Users: ' . count($data['users']) . ' (mom, dad, teen, kid, grandma, friend)');
                $output->writeln('Tasks: ' . count($data['tasks']));
                $output->writeln('Room Tasks: ' . count($data['roomTasks']));
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Failed to seed test data: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
