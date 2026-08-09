<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use Psr\Log\LoggerInterface;

/**
 * Daily streak freeze pass (CS-PLAN-023).
 *
 * Extracted from StreakFreezeJob so the logic is callable with an injected
 * "yesterday" date — the time seam that makes the flow testable without
 * waiting real days (occ chorestrike:trigger-jobs).
 *
 * For each house user: if no completions on the given day and streak > 0,
 * consume one freeze to preserve the streak. If no freezes remain, reset
 * the streak to 0.
 */
class StreakFreezeService
{
    public function __construct(
        private HouseUserMapper $houseUserMapper,
        private CompletedTaskMapper $completedTaskMapper,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Run the streak freeze pass for the given day.
     *
     * @param \DateTimeInterface $yesterday The day to check for completions
     * @return array{processed: int, frozen: int, reset: int, skippedUnavailable: int, errors: int}
     */
    public function process(\DateTimeInterface $yesterday): array
    {
        $yesterdayDayName = strtolower($yesterday->format('l'));
        // CR-SVC-03: collapse to a Y-m-d key for the idempotency comparison so
        // timezone/time skew in the formatting can't defeat the guard.
        $yesterdayKey = $yesterday->format('Y-m-d');
        $stats = [
            'processed' => 0,
            'frozen' => 0,
            'reset' => 0,
            'skippedUnavailable' => 0,
            'errors' => 0,
        ];

        foreach ($this->houseUserMapper->findAllForJobs() as $user) {
            try {
                $stats['processed']++;
                $currentStreak = $user->getCurrentStreak();

                if ($currentStreak <= 0) {
                    continue;
                }

                $preferences = $user->getSchedulingPreferences();
                $weekdayAvailability = $preferences['weekdayAvailability'] ?? [];

                if (isset($weekdayAvailability[$yesterdayDayName]) && $weekdayAvailability[$yesterdayDayName] === false) {
                    $stats['skippedUnavailable']++;
                    continue;
                }

                $yesterdayCompletions = $this->completedTaskMapper->countByUserOnDate(
                    $user->getId(),
                    $yesterday
                );

                if ($yesterdayCompletions > 0) {
                    continue;
                }

                // CR-SVC-03 idempotency: a re-run for the same day is a no-op.
                // The destructive branch (freeze consume / streak reset) stamps
                // last_freeze_date below, so a job retry or a manual
                // `occ trigger-jobs` cannot double-consume a freeze or reset an
                // already-preserved streak.
                $lastFreeze = $user->getLastFreezeDate();
                if ($lastFreeze !== null && $lastFreeze->format('Y-m-d') === $yesterdayKey) {
                    continue;
                }

                if ($user->consumeFreeze()) {
                    $stats['frozen']++;
                } else {
                    $user->resetStreak();
                    $stats['reset']++;
                }

                $user->setLastFreezeDate(new \DateTime($yesterdayKey));
                $this->houseUserMapper->update($user);
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->logger->error('StreakFreezeService: per-user failure', [
                    'userId' => $user->getId(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('StreakFreezeService completed', $stats);

        return $stats;
    }
}
