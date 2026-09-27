<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Command;

use OCA\ChoreStrike\Command\TriggerJobs;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration smoke for the chorestrike:trigger-jobs OCC command
 * (CS-PLAN-023; WP3 cutover): runs the real command, resolved from the
 * Nextcloud DI container, end-to-end against a seeded house in the Docker
 * test DB. The retired streak-freeze/at-risk dispatches must stay gone
 * (Q-074-01 Option A — the rhythm evaluator derives streaks from valid
 * history instead of a snapshot pass).
 */
class TriggerJobsIntegrationTest extends IntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();

		// The CLI test bootstrap (bootstrap-integration.php) only runs the
		// app registration phase; the boot phase — where the notifications
		// app registers the persisting handler with OCP\Notification\IManager
		// — normally runs in handleRequest()/occ. Boot it here so notify()
		// actually persists rows like it does under `php occ`.
		\OC::$server->get(\OC\AppFramework\Bootstrap\Coordinator::class)->bootApp('notifications');
	}

	/**
	 * The daily-notification pass fires the task-due reminders with the
	 * --as-of clock substitution; the retired freeze dispatches are
	 * rejected with the unknown-type envelope.
	 */
	public function testTriggerJobsRunsDailyNotificationAndRejectsRetiredTypes(): void {
		$houseId = $this->createHouse('tt-lead');
		$this->addHouseMember($houseId, 'tt-member', 'Time Travel Member');

		// 1) the retained daily-notification pass (task-due reminders).
		$notificationTester = new CommandTester(\OC::$server->get(TriggerJobs::class));
		$tomorrow = (new \DateTime('+1 day'))->format('Y-m-d');
		$this->assertSame(
			0,
			$notificationTester->execute(['type' => 'daily-notification', '--as-of' => $tomorrow]),
			$notificationTester->getDisplay()
		);

		// 2) the retired freeze dispatch is rejected, not silently run.
		$retiredTester = new CommandTester(\OC::$server->get(TriggerJobs::class));
		$this->assertSame(
			1,
			$retiredTester->execute(['type' => 'streak-freeze', '--as-of' => $tomorrow]),
			'streak-freeze retired with the WP3 cutover'
		);
		$this->assertStringContainsString('Invalid job type', $retiredTester->getDisplay());
	}

	/**
	 * Outside test mode the command must refuse to run.
	 */
	public function testTriggerJobsRefusesOutsideTestMode(): void {
		$originalGetenv = getenv('NEXTCLOUD_TEST_MODE');
		$originalEnv = $_ENV['NEXTCLOUD_TEST_MODE'] ?? null;

		putenv('NEXTCLOUD_TEST_MODE');
		unset($_ENV['NEXTCLOUD_TEST_MODE']);

		try {
			$command = \OC::$server->get(TriggerJobs::class);
			$tester = new CommandTester($command);
			$exitCode = $tester->execute(['type' => 'daily-notification']);

			$this->assertSame(1, $exitCode);
			$this->assertStringContainsString('test mode', $tester->getDisplay());
		} finally {
			if ($originalGetenv === false) {
				putenv('NEXTCLOUD_TEST_MODE');
			} else {
				putenv('NEXTCLOUD_TEST_MODE=' . $originalGetenv);
			}
			if ($originalEnv !== null) {
				$_ENV['NEXTCLOUD_TEST_MODE'] = $originalEnv;
			}
		}
	}
}
