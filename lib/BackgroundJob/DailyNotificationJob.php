<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\BackgroundJob;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Daily notification job (06:00 server time).
 *
 * Sends "streak at risk" reminders to members who:
 *   - have a positive streak (something to lose), AND
 *   - did NOT complete any task yesterday (so the StreakFreezeJob would
 *     consume a freeze or reset the streak), AND
 *   - have zero streak freezes left (so today's inactivity would actually
 *     break the streak rather than silently consume a freeze).
 *
 * Notifications are dispatched best-effort via {@see NotificationService}.
 * Overdue-takeover, medal-awarded and weekly-goal-reached notifications are
 * fired from their respective trigger points (RoomService,
 * GameLogicService) and are NOT handled here — this job covers only the
 * time-based reminder that no single mutation can detect.
 */
class DailyNotificationJob extends TimedJob
{
    private HouseUserMapper $houseUserMapper;
    private CompletedTaskMapper $completedTaskMapper;
    private NotificationService $notificationService;
    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        HouseUserMapper $houseUserMapper,
        CompletedTaskMapper $completedTaskMapper,
        NotificationService $notificationService,
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->setInterval(24 * 60 * 60);

        $this->houseUserMapper = $houseUserMapper;
        $this->completedTaskMapper = $completedTaskMapper;
        $this->notificationService = $notificationService;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Run the daily streak-at-risk notification pass
     */
    protected function run($argument): void
    {
        $yesterday = new \DateTime('yesterday');
        $processed = 0;
        $notified = 0;

        try {
            $allUsers = $this->findAllUsers();

            // Single GROUP BY query for everyone's yesterday completions;
            // users with zero completions are absent from the map (treated as 0).
            $yesterdayCounts = $this->completedTaskMapper->countByUserOnDateGrouped($yesterday);

            foreach ($allUsers as $user) {
                $processed++;

                if ($user->getCurrentStreak() <= 0) {
                    continue;
                }

                if ($user->getStreakFreezes() > 0) {
                    // The StreakFreezeJob would consume a freeze for yesterday's
                    // inactivity — the streak is silently protected, no nudge.
                    continue;
                }

                $yesterdayCompletions = $yesterdayCounts[$user->getId()] ?? 0;

                if ($yesterdayCompletions > 0) {
                    // They completed something yesterday; no risk reminder due.
                    continue;
                }

                $nextcloudUserId = $user->getNextcloudUserId();
                if ($nextcloudUserId === null || $nextcloudUserId === '') {
                    continue;
                }

                $this->notificationService->notifyStreakAtRisk(
                    $nextcloudUserId,
                    $user->getCurrentStreak()
                );
                $notified++;
            }
        } catch (\Throwable $e) {
            $this->logger->error('DailyNotificationJob failed', [
                'exception' => $e->getMessage(),
            ]);
            return;
        }

        $this->logger->info('DailyNotificationJob completed', [
            'processed' => $processed,
            'notified' => $notified,
        ]);
    }

    /**
     * Find all house users across all houses.
     *
     * Delegates to HouseUserMapper::findAllForJobs so the "all house users"
     * set stays consistent across StreakFreezeJob, StreakRestockJob, and
     * this job (single point of change for any future filtering).
     *
     * @return \OCA\ChoreStrike\Db\HouseUser[]
     */
    private function findAllUsers(): array
    {
        return $this->houseUserMapper->findAllForJobs();
    }
}
