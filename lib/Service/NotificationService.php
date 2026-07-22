<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Titles\TitleCatalog;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;

/**
 * Thin wrapper around Nextcloud's OCP\Notification\IManager that builds and
 * dispatches ChoreStrike notifications.
 *
 * Centralising the dispatch here keeps the subject/object identifiers and
 * parameter shapes consistent with the {@see ChoreStrikeNotifier} that
 * renders them, and lets every caller treat notifications as fire-and-forget
 * (every method logs and swallows — notifications are best-effort and must
 * never break a task completion or medal award).
 */
class NotificationService
{
    public const APP_ID = 'chorestrike';

    /**
     * Subject identifiers for ChoreStrike notifications.
     *
     * Kept in sync with {@see \OCA\ChoreStrike\Notification\ChoreStrikeNotifier::prepare()}
     * by referencing these constants from both sides — adding a new subject
     * without a matching case in the notifier fails at render time
     * (InvalidArgumentException), so a missing constant is the single
     * canonical signal that the wiring is incomplete.
     */
    public const SUBJECT_OVERDUE_TAKEN_OVER = 'overdue_taken_over';
    public const SUBJECT_MEDAL_AWARDED = 'medal_awarded';
    public const SUBJECT_WEEKLY_GOAL_REACHED = 'weekly_goal_reached';
    public const SUBJECT_STREAK_AT_RISK = 'streak_at_risk';
    public const SUBJECT_TITLE_UNLOCKED = 'title_unlocked';

    public function __construct(
        private IManager $notificationManager,
        private HouseUserMapper $houseUserMapper,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Notify the original assignee that their overdue task was taken over by
     * another member.
     *
     * @param string $recipientNextcloudUserId Original assignee's Nextcloud user ID
     * @param string $taskName Display name of the task (for the message body)
     * @param string $claimerDisplayName Display name of the member who took it over
     * @param int $roomTaskId Room task ID (used as the notification object ID + rich param)
     */
    public function notifyOverdueTakenOver(
        string $recipientNextcloudUserId,
        string $taskName,
        string $claimerDisplayName,
        int $roomTaskId
    ): void {
        $this->dispatch(
            $recipientNextcloudUserId,
            self::SUBJECT_OVERDUE_TAKEN_OVER,
            'room_task',
            (string)$roomTaskId,
            [
                'task' => $taskName,
                'taskId' => $roomTaskId,
                'claimer' => $claimerDisplayName,
            ]
        );
    }

    /**
     * Notify a member that they earned a medal this week.
     *
     * @param string $recipientNextcloudUserId The medal winner's Nextcloud user ID
     * @param string $medalType Medal type identifier (e.g. 'team_anchor')
     * @param int $value The medal's value (task count or streak length)
     * @param string $weekStart Week start (Y-m-d) — kept for de-duplication context
     */
    public function notifyMedalAwarded(
        string $recipientNextcloudUserId,
        string $medalType,
        int $value,
        string $weekStart
    ): void {
        $this->dispatch(
            $recipientNextcloudUserId,
            self::SUBJECT_MEDAL_AWARDED,
            'medal',
            $weekStart,
            [
                'medalType' => $medalType,
                'value' => $value,
            ]
        );
    }

    /**
     * Notify a member that the weekly household goal was reached.
     *
     * @param string $recipientNextcloudUserId Recipient's Nextcloud user ID
     * @param int $percent Achieved percentage (0-100)
     * @param int $targetPercent Configured target percentage
     * @param string $weekStart Week start (ISO 8601)
     */
    public function notifyWeeklyGoalReached(
        string $recipientNextcloudUserId,
        int $percent,
        int $targetPercent,
        string $weekStart
    ): void {
        $this->dispatch(
            $recipientNextcloudUserId,
            self::SUBJECT_WEEKLY_GOAL_REACHED,
            'weekly_goal',
            $weekStart,
            [
                'percent' => $percent,
                'targetPercent' => $targetPercent,
            ]
        );
    }

    /**
     * Notify every member of a house that the weekly goal was reached.
     *
     * Iterates all house users and dispatches a per-user notification so each
     * member sees the achievement in their own Nextcloud bell. Best-effort:
     * a failure for one user does not abort the loop.
     *
     * @param int $houseId The house ID
     * @param int $percent Achieved percentage (0-100)
     * @param int $targetPercent Configured target percentage
     * @param string $weekStart Week start (ISO 8601)
     */
    public function notifyWeeklyGoalReachedForHouse(
        int $houseId,
        int $percent,
        int $targetPercent,
        string $weekStart
    ): int {
        $users = $this->houseUserMapper->findByHouseId($houseId);
        $sent = 0;
        foreach ($users as $user) {
            $nextcloudUserId = $user->getNextcloudUserId();
            if ($nextcloudUserId === null || $nextcloudUserId === '') {
                continue;
            }
            $this->notifyWeeklyGoalReached($nextcloudUserId, $percent, $targetPercent, $weekStart);
            $sent++;
        }
        return $sent;
    }

    /**
     * Notify a member that they unlocked a new role title.
     *
     * The notification object id is pack-qualified (`{pack}.{tier}`) so that
     * two different packs unlocking the same tier rank do NOT collide on the
     * Nextcloud dedup key (app, user, objectType, objectId) and drop each
     * other's notification.
     *
     * @param string $recipientNextcloudUserId The recipient's Nextcloud user ID
     * @param string $pack Pack key the unlocked tier belongs to
     * @param string $titleLabelKey i18n key for the unlocked tier label
     * @param string $packLabelKey i18n key for the pack the tier belongs to
     * @param int $tierIndex 0-based tier rank (for a stable, pack-qualified object id)
     */
    public function notifyTitleUnlocked(
        string $recipientNextcloudUserId,
        string $pack,
        string $titleLabelKey,
        string $packLabelKey,
        int $tierIndex
    ): void {
        $this->dispatch(
            $recipientNextcloudUserId,
            self::SUBJECT_TITLE_UNLOCKED,
            'title',
            TitleCatalog::tierId($pack, $tierIndex),
            [
                'titleLabelKey' => $titleLabelKey,
                'packLabelKey' => $packLabelKey,
            ]
        );
    }

    /**
     * Notify a user that their streak is at risk and they should complete a
     * task today to preserve it.
     *
     * @param string $recipientNextcloudUserId Recipient's Nextcloud user ID
     * @param int $currentStreak The current streak count
     */
    public function notifyStreakAtRisk(
        string $recipientNextcloudUserId,
        int $currentStreak
    ): void {
        $this->dispatch(
            $recipientNextcloudUserId,
            self::SUBJECT_STREAK_AT_RISK,
            'streak',
            (string)$currentStreak,
            [
                'currentStreak' => $currentStreak,
            ]
        );
    }

    /**
     * Build and send a notification. Catches all errors: a notification
     * failure must never propagate to the caller (notifications are
     * best-effort and the triggering mutation has already succeeded).
     *
     * @param string $recipientNextcloudUserId Nextcloud user ID of the recipient
     * @param string $subject Subject identifier (matched in ChoreStrikeNotifier::prepare)
     * @param string $objectType Notification object type
     * @param string $objectId Notification object ID
     * @param array<string, mixed> $parameters Subject parameters
     */
    private function dispatch(
        string $recipientNextcloudUserId,
        string $subject,
        string $objectType,
        string $objectId,
        array $parameters
    ): void {
        if ($recipientNextcloudUserId === '') {
            return;
        }

        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(self::APP_ID)
                ->setUser($recipientNextcloudUserId)
                ->setDateTime(new \DateTime())
                ->setObject($objectType, $objectId)
                ->setSubject($subject, $parameters);
            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to dispatch ChoreStrike notification', [
                'app' => self::APP_ID,
                'recipient' => $recipientNextcloudUserId,
                'subject' => $subject,
                'objectType' => $objectType,
                'objectId' => $objectId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
