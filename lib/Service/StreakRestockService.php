<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;
use Psr\Log\LoggerInterface;

/**
 * Weekly streak freeze restock pass (CS-PLAN-023).
 *
 * Extracted from StreakRestockJob so all three job bodies are uniformly
 * triggerable via occ chorestrike:trigger-jobs. The restock is
 * time-independent (it sets freezes to the configured weekly value), so
 * triggering it once is sufficient — no date parameter needed.
 */
class StreakRestockService
{
    public function __construct(
        private HouseUserMapper $houseUserMapper,
        private RewardConfigService $configService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Run the restock pass.
     *
     * Sets each house user's streak_freezes to the configured weekly value,
     * capped at the configured maximum.
     *
     * @return array{processed: int, freezesPerWeek: int, cap: int}
     */
    public function process(): array
    {
        $freezesPerWeek = $this->configService->getStreakFreezesPerWeek();
        $cap = $this->configService->getStreakFreezeCap();
        $stats = [
            'processed' => 0,
            'freezesPerWeek' => $freezesPerWeek,
            'cap' => $cap,
        ];

        try {
            foreach ($this->houseUserMapper->findAllForJobs() as $user) {
                $stats['processed']++;
                $user->restockFreezes($freezesPerWeek, $cap);
                $this->houseUserMapper->update($user);
            }
        } catch (\Throwable $e) {
            $this->logger->error('StreakRestockService failed', [
                'exception' => $e->getMessage(),
            ]);
            return $stats;
        }

        $this->logger->info('StreakRestockService completed', $stats);

        return $stats;
    }
}
