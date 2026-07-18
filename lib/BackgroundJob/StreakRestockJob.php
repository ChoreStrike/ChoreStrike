<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\BackgroundJob;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Weekly streak freeze restock job (Monday 00:05).
 *
 * Sets each house user's streak_freezes to the configured weekly value,
 * capped at the configured maximum.
 */
class StreakRestockJob extends TimedJob
{
    private HouseUserMapper $houseUserMapper;
    private RewardConfigService $configService;
    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        HouseUserMapper $houseUserMapper,
        RewardConfigService $configService,
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->setInterval(7 * 24 * 60 * 60);

        $this->houseUserMapper = $houseUserMapper;
        $this->configService = $configService;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Run the weekly restock
     */
    protected function run($argument): void
    {
        $freezesPerWeek = $this->configService->getStreakFreezesPerWeek();
        $cap = $this->configService->getStreakFreezeCap();
        $processed = 0;

        try {
            $allUsers = $this->findAllUsers();

            foreach ($allUsers as $user) {
                $processed++;
                $user->restockFreezes($freezesPerWeek, $cap);
                $this->houseUserMapper->update($user);
            }
        } catch (\Throwable $e) {
            $this->logger->error('StreakRestockJob failed', [
                'exception' => $e->getMessage(),
            ]);
            return;
        }

        $this->logger->info('StreakRestockJob completed', [
            'processed' => $processed,
            'freezesPerWeek' => $freezesPerWeek,
            'cap' => $cap,
        ]);
    }

    /**
     * Find all house users across all houses.
     *
     * Delegates to HouseUserMapper::findAllForJobs so all per-user cron
     * passes share one definition of the "all house users" set.
     *
     * @return \OCA\ChoreStrike\Db\HouseUser[]
     */
    private function findAllUsers(): array
    {
        return $this->houseUserMapper->findAllForJobs();
    }
}
