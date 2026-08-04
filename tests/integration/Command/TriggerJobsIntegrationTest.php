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
 * (CS-PLAN-023): runs the real command, resolved from the Nextcloud DI
 * container, end-to-end against a seeded house in the Docker test DB.
 *
 * Scenario mirrors the family seed's time-travel state: a member with a
 * live streak, zero freezes and no completions. Triggering with
 * --as-of=tomorrow makes "yesterday" today, so the freeze pass resets
 * the streak immediately — no real multi-day wait.
 */
class TriggerJobsIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The CLI test bootstrap (bootstrap-integration.php) only runs the
        // app registration phase; the boot phase — where the notifications
        // app registers the persisting handler with OCP\Notification\IManager
        // — normally runs in handleRequest()/occ. Boot it here so notify()
        // actually persists rows like it does under `php occ`.
        \OC::$server->get(\OC\AppFramework\Bootstrap\Coordinator::class)->bootApp('notifications');
    }

    /**
     * The full at-risk flow: daily-notification fires the reminder while
     * the streak is still positive, then streak-freeze resets it.
     */
    public function testTriggerJobsRunsAtRiskNotificationAndFreezeReset(): void
    {
        $houseId = $this->createHouse('tt-lead');
        $this->addHouseMember($houseId, 'tt-member', 'Time Travel Member');

        // streak > 0, zero freezes, no completions (family-seed state).
        $this->setStreakState('tt-member', 5, 0);
        // Fresh notification state (rows from earlier runs must not leak in).
        $this->deleteNotifications('tt-member');

        $command = \OC::$server->get(TriggerJobs::class);
        $tomorrow = (new \DateTime('+1 day'))->format('Y-m-d');

        // 1) at-risk notification pass (must run while streak > 0)
        $notificationTester = new CommandTester($command);
        $this->assertSame(
            0,
            $notificationTester->execute(['type' => 'daily-notification', '--as-of' => $tomorrow]),
            $notificationTester->getDisplay()
        );

        // The at-risk reminder must be persisted for the member.
        $this->assertGreaterThanOrEqual(
            1,
            $this->countNotifications('tt-member'),
            'expected a persisted streak-at-risk notification for tt-member'
        );

        // 2) freeze pass: no completions "yesterday" (= today), no freezes
        $freezeTester = new CommandTester($command);
        $this->assertSame(
            0,
            $freezeTester->execute(['type' => 'streak-freeze', '--as-of' => $tomorrow]),
            $freezeTester->getDisplay()
        );

        $this->assertSame(0, $this->readStreak('tt-member'), 'streak must be reset after the freeze pass');
    }

    /**
     * Outside test mode the command must refuse to run.
     */
    public function testTriggerJobsRefusesOutsideTestMode(): void
    {
        $originalGetenv = getenv('NEXTCLOUD_TEST_MODE');
        $originalEnv = $_ENV['NEXTCLOUD_TEST_MODE'] ?? null;

        putenv('NEXTCLOUD_TEST_MODE');
        unset($_ENV['NEXTCLOUD_TEST_MODE']);

        try {
            $command = \OC::$server->get(TriggerJobs::class);
            $tester = new CommandTester($command);
            $exitCode = $tester->execute(['type' => 'streak-freeze']);

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

    /**
     * Set a member's streak/freeze counters directly.
     */
    private function setStreakState(string $nextcloudUserId, int $streak, int $freezes): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update('chorestrike_house_users')
            ->set('current_streak', $qb->createNamedParameter($streak))
            ->set('streak_freezes', $qb->createNamedParameter($freezes))
            ->where($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($nextcloudUserId)));
        $qb->executeStatement();
    }

    /**
     * Read a member's current streak from the database.
     */
    private function readStreak(string $nextcloudUserId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('current_streak')
            ->from('chorestrike_house_users')
            ->where($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($nextcloudUserId)));

        return (int)$qb->executeQuery()->fetchOne();
    }

    /**
     * Count persisted ChoreStrike streak notifications for a user.
     */
    private function countNotifications(string $nextcloudUserId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from('notifications')
            ->where($qb->expr()->eq('app', $qb->createNamedParameter('chorestrike')))
            ->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($nextcloudUserId)))
            ->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter('streak')));

        return (int)$qb->executeQuery()->fetchOne();
    }

    /**
     * Remove persisted ChoreStrike streak notifications for a user.
     */
    private function deleteNotifications(string $nextcloudUserId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('notifications')
            ->where($qb->expr()->eq('app', $qb->createNamedParameter('chorestrike')))
            ->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($nextcloudUserId)))
            ->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter('streak')));
        $qb->executeStatement();
    }
}
