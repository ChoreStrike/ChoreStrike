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
 * Daily streak-at-risk notification pass (CS-PLAN-023).
 *
 * Extracted from DailyNotificationJob so the logic is callable with an
 * injected "yesterday" date — the time seam that makes the flow testable
 * without waiting real days (occ chorestrike:trigger-jobs).
 *
 * Sends "streak at risk" reminders to members who:
 *   - have a positive streak (something to lose), AND
 *   - did NOT complete any task on the given day (so the freeze pass would
 *     consume a freeze or reset the streak), AND
 *   - have zero streak freezes left (so today's inactivity would actually
 *     break the streak rather than silently consume a freeze).
 *
 * Notifications are dispatched best-effort via {@see NotificationService}.
 */
class StreakAtRiskService {
	public function __construct(
		private HouseUserMapper $houseUserMapper,
		private CompletedTaskMapper $completedTaskMapper,
		private NotificationService $notificationService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Run the streak-at-risk notification pass for the given day.
	 *
	 * @param \DateTimeInterface $yesterday The day to check for completions
	 * @return array{processed: int, notified: int, skippedUnavailable: int}
	 */
	public function process(\DateTimeInterface $yesterday): array {
		$yesterdayDayName = strtolower($yesterday->format('l'));
		$stats = [
			'processed' => 0,
			'notified' => 0,
			'skippedUnavailable' => 0,
		];

		try {
			$allUsers = $this->houseUserMapper->findAllForJobs();

			// Single GROUP BY query for everyone's completions on the day;
			// users with zero completions are absent from the map (treated as 0).
			$yesterdayCounts = $this->completedTaskMapper->countByUserOnDateGrouped($yesterday);

			foreach ($allUsers as $user) {
				$stats['processed']++;

				$preferences = $user->getSchedulingPreferences();
				$weekdayAvailability = $preferences['weekdayAvailability'] ?? [];

				if (isset($weekdayAvailability[$yesterdayDayName]) && $weekdayAvailability[$yesterdayDayName] === false) {
					$stats['skippedUnavailable']++;
					continue;
				}

				if ($user->getCurrentStreak() <= 0) {
					continue;
				}

				if ($user->getStreakFreezes() > 0) {
					// The freeze pass would consume a freeze for the day's
					// inactivity — the streak is silently protected, no nudge.
					continue;
				}

				$yesterdayCompletions = $yesterdayCounts[$user->getId()] ?? 0;

				if ($yesterdayCompletions > 0) {
					// They completed something that day; no risk reminder due.
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
				$stats['notified']++;
			}
		} catch (\Throwable $e) {
			$this->logger->error('StreakAtRiskService failed', [
				'exception' => $e->getMessage(),
			]);
			return $stats;
		}

		$this->logger->info('StreakAtRiskService completed', $stats);

		return $stats;
	}
}
