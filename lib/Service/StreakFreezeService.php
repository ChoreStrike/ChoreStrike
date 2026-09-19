<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;

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
 *
 * CS-PLAN-057: the pass resolves the (single) house and buckets "yesterday"
 * in the house timezone — the idempotency key, the availability day name,
 * and the completion-count window are all house-local.
 *
 * CS-PLAN-071 WP0: every destructive per-user write runs in one
 * house-first transaction (house row lock → membership row lock) and
 * re-evaluates its guards on the FRESH locked entity, so a concurrent
 * completion or membership removal serializes with the job instead of
 * racing its read-modify-write.
 */
class StreakFreezeService {
	public function __construct(
		private HouseUserMapper $houseUserMapper,
		private CompletedTaskMapper $completedTaskMapper,
		private AppLogger $appLogger,
		private HouseMapper $houseMapper,
		private CalendarService $calendarService,
		private TransactionExecutor $transactions,
	) {
	}

	/**
	 * Run the streak freeze pass for the given day.
	 *
	 * @param \DateTimeInterface $yesterday The instant to derive the checked day from
	 * @return array{processed: int, frozen: int, reset: int, skippedUnavailable: int, errors: int}
	 */
	public function process(\DateTimeInterface $yesterday): array {
		$stats = [
			'processed' => 0,
			'frozen' => 0,
			'reset' => 0,
			'skippedUnavailable' => 0,
			'errors' => 0,
		];

		// CS-PLAN-057: resolve the house timezone for this pass. No house
		// yet (bootstrap race) → nothing to process.
		$house = $this->houseMapper->findOneForJobs();
		if ($house === null) {
			return $stats;
		}
		$tz = $this->calendarService->getTimeZone($house);
		$yesterdayDayName = strtolower(\DateTime::createFromInterface($yesterday)->setTimezone($tz)->format('l'));
		// CR-SVC-03: collapse to a Y-m-d key for the idempotency comparison so
		// timezone/time skew in the formatting can't defeat the guard. The
		// key is house-local (CS-PLAN-057).
		$yesterdayKey = $this->calendarService->dayKey($yesterday, $tz);

		// Single GROUP BY query for everyone's completions on the day
		// (CR-SVC-55 / CS-PLAN-036) instead of a per-user COUNT inside the
		// loop; users with zero completions are absent from the map
		// (treated as 0) — same shape as StreakAtRiskService.
		[$dayStart, $dayEnd] = $this->calendarService->dayBounds($yesterday, $tz);
		$yesterdayCounts = $this->completedTaskMapper->countByUserOnDateGrouped($dayStart, $dayEnd);

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

				// CR-SVC-03 idempotency: a re-run for the same day is a no-op.
				// The destructive branch (freeze consume / streak reset) stamps
				// last_freeze_date below, so a job retry or a manual
				// `occ trigger-jobs` cannot double-consume a freeze or reset an
				// already-preserved streak.
				$lastFreeze = $user->getLastFreezeDate();
				if ($lastFreeze !== null && $lastFreeze->format('Y-m-d') === $yesterdayKey) {
					continue;
				}

				$yesterdayCompletions = $yesterdayCounts[$user->getId()] ?? 0;

				if ($yesterdayCompletions > 0) {
					continue;
				}

				// CS-PLAN-071 WP0: the destructive branch runs house-first
				// and re-evaluates its guards on the FRESH locked entity
				// (a completion committed behind the pre-read re-arms the
				// guard; the idempotency stamp below stays exact).
				$this->transactions->run(function () use ($house, $user, $yesterdayKey, &$stats): void {
					$this->houseMapper->findForUpdate($house->getId());
					$fresh = $this->houseUserMapper->findForUpdate($user->getId());

					if ($fresh->getCurrentStreak() <= 0) {
						return;
					}

					$lastFreeze = $fresh->getLastFreezeDate();
					if ($lastFreeze !== null && $lastFreeze->format('Y-m-d') === $yesterdayKey) {
						return;
					}

					if ($fresh->consumeFreeze()) {
						$stats['frozen']++;
					} else {
						$fresh->resetStreak();
						$stats['reset']++;
					}

					$fresh->setLastFreezeDate(new \DateTime($yesterdayKey));
					$this->houseUserMapper->update($fresh);
				});
			} catch (\Throwable $e) {
				$stats['errors']++;
				$this->appLogger->logException('StreakFreezeService: per-user failure', $e, [
					'event' => 'streak_freeze_user_failed',
					'service' => 'StreakFreezeService',
					'userId' => $user->getId(),
				]);
			}
		}

		$this->appLogger->info('StreakFreezeService completed', $stats + [
			'event' => 'streak_freeze_pass_completed',
			'service' => 'StreakFreezeService',
		]);

		return $stats;
	}
}
