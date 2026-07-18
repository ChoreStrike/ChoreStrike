<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\BackgroundJob;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Daily streak freeze job (06:00).
 *
 * For each house user: if no completions yesterday and streak > 0,
 * consume one freeze to preserve the streak. If no freezes remain,
 * reset the streak to 0.
 */
class StreakFreezeJob extends TimedJob
{
    private HouseUserMapper $houseUserMapper;
    private CompletedTaskMapper $completedTaskMapper;
    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        HouseUserMapper $houseUserMapper,
        CompletedTaskMapper $completedTaskMapper,
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->setInterval(24 * 60 * 60);

        $this->houseUserMapper = $houseUserMapper;
        $this->completedTaskMapper = $completedTaskMapper;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Run the daily streak freeze check
     */
    protected function run($argument): void
    {
        $yesterday = new \DateTime('yesterday');
        $processed = 0;
        $frozen = 0;
        $reset = 0;

        try {
            $allUsers = $this->findAllUsers();

            foreach ($allUsers as $user) {
                $processed++;
                $currentStreak = $user->getCurrentStreak();

                if ($currentStreak <= 0) {
                    continue;
                }

                $yesterdayCompletions = $this->completedTaskMapper->countByUserOnDate(
                    $user->getId(),
                    $yesterday
                );

                if ($yesterdayCompletions > 0) {
                    continue;
                }

                if ($user->consumeFreeze()) {
                    $frozen++;
                } else {
                    $user->resetStreak();
                    $reset++;
                }

                $this->houseUserMapper->update($user);
            }
        } catch (\Throwable $e) {
            $this->logger->error('StreakFreezeJob failed', [
                'exception' => $e->getMessage(),
            ]);
            return;
        }

        $this->logger->info('StreakFreezeJob completed', [
            'processed' => $processed,
            'frozen' => $frozen,
            'reset' => $reset,
        ]);
    }

    /**
     * Find all house users across all houses
     *
     * @return \OCA\ChoreStrike\Db\HouseUser[]
     */
    private function findAllUsers(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('chorestrike_house_users');

        return $this->houseUserMapper->findEntities($qb);
    }
}
