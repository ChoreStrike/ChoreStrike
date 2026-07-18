<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Notification;

use OCA\ChoreStrike\Service\NotificationService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

/**
 * Prepares ChoreStrike notifications for display in the Nextcloud bell and
 * the mobile-app notification feed.
 *
 * Registered via appinfo/info.xml's <notifications> section (the modern
 * Nextcloud pattern; no manual registerNotifier() call in Application.php).
 *
 * The subject identifiers here MUST stay in sync with the SUBJECT_*
 * constants on {@see NotificationService} — both sides reference the
 * constants, so a new subject without a matching case is a single-point
 * failure at render time (InvalidArgumentException).
 *
 * Rendering uses setRichSubject() with rich placeholder parameters for any
 * notification that embeds user-controlled strings (task names, display
 * names). Nextcloud renders rich params with per-surface escaping (web,
 * email, push, third-party mobile clients), so we never pre-interpolate
 * user content into parsedSubject/parsedMessage via sprintf.
 */
class ChoreStrikeNotifier implements INotifier
{
    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $url
    ) {
    }

    /**
     * Unique notifier ID. Matches the Nextcloud app ID.
     */
    public function getID(): string
    {
        return NotificationService::APP_ID;
    }

    /**
     * Human-readable notifier name shown in personal notification settings.
     */
    public function getName(): string
    {
        return 'ChoreStrike';
    }

    /**
     * Prepare a notification for display: translate the subject + body, set
     * the app icon, and embed any user-controlled content as rich
     * placeholder parameters.
     *
     * @param INotification $notification Notification to prepare
     * @param string $languageCode Language code for the recipient's locale
     * @throws \InvalidArgumentException When the notification is not for this app or has an unknown subject
     */
    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if ($notification->getApp() !== NotificationService::APP_ID) {
            throw new \InvalidArgumentException('Notification not for ChoreStrike');
        }

        $l = $this->l10nFactory->get(NotificationService::APP_ID, $languageCode);

        $notification->setIcon($this->url->getAbsoluteURL('apps/chorestrike/img/app.svg'));

        switch ($notification->getSubject()) {
            case NotificationService::SUBJECT_OVERDUE_TAKEN_OVER:
                $params = $this->params($notification);
                $task = (string)($params['task'] ?? '');
                $claimer = (string)($params['claimer'] ?? '');
                // Rich subject: parameters are escaped per-surface by Nextcloud.
                $notification->setRichSubject(
                    $l->t('Your task "{task}" was taken over by {claimer}'),
                    [
                        'task' => ['type' => 'highlight', 'id' => (string)($params['taskId'] ?? '0'), 'name' => $task],
                        'claimer' => ['type' => 'user', 'id' => $claimer, 'name' => $claimer],
                    ]
                );
                $notification->setParsedSubject(
                    $l->t('Your task "%1$s" was taken over by %2$s', [$task, $claimer])
                );
                $notification->setParsedMessage(
                    $l->t('%1$s completed your overdue task so your streak is safe. Be a hero and pick up one of theirs next time!', [$claimer])
                );
                break;

            case NotificationService::SUBJECT_MEDAL_AWARDED:
                $params = $this->params($notification);
                $medalType = (string)($params['medalType'] ?? '');
                $value = (int)($params['value'] ?? 0);
                $medalLabel = $this->medalLabel($l, $medalType);
                $notification->setParsedSubject(
                    $l->t('You earned a new medal: %1$s', [$medalLabel])
                );
                $notification->setParsedMessage(
                    $l->t('You earned the "%1$s" medal with a value of %2$d. Keep up the great work!', [$medalLabel, $value])
                );
                break;

            case NotificationService::SUBJECT_WEEKLY_GOAL_REACHED:
                $params = $this->params($notification);
                $percent = (int)($params['percent'] ?? 0);
                $target = (int)($params['targetPercent'] ?? 0);
                $notification->setParsedSubject(
                    $l->t('Weekly household goal reached!')
                );
                $notification->setParsedMessage(
                    $l->t('Your household hit %1$d%% of this week\'s tasks (target: %2$d%%). Great teamwork!', [$percent, $target])
                );
                break;

            case NotificationService::SUBJECT_STREAK_AT_RISK:
                $params = $this->params($notification);
                $currentStreak = (int)($params['currentStreak'] ?? 0);
                $notification->setParsedSubject(
                    $l->t('Your %1$d-day streak is at risk — complete a task today!', [$currentStreak])
                );
                $notification->setParsedMessage(
                    $l->t('You did not complete any tasks yesterday and have no streak freeze left. Complete a task today to keep your streak alive.')
                );
                break;

            default:
                throw new \InvalidArgumentException('Unknown ChoreStrike notification subject');
        }

        return $notification;
    }

    /**
     * Get the notification's subject parameters as an associative array.
     *
     * @return array<string, mixed>
     */
    private function params(INotification $notification): array
    {
        $params = $notification->getSubjectParameters();
        if (!is_array($params)) {
            return [];
        }
        return $params;
    }

    /**
     * Localise a medal type identifier into a human-readable label.
     *
     * Medal types come from GameLogicService::awardMedalIfNew (currently
     * 'team_anchor' and 'streak_keeper'). Falls back to a prettified version
     * of the identifier for any unknown type so we never show raw snake_case
     * to the user.
     *
     * @param IL10N $l L10n instance
     * @param string $medalType Medal type identifier
     */
    private function medalLabel(IL10N $l, string $medalType): string
    {
        $labels = [
            'team_anchor' => $l->t('Team Anchor'),
            'streak_keeper' => $l->t('Streak Keeper'),
        ];

        return $labels[$medalType] ?? ucfirst(str_replace('_', ' ', $medalType));
    }
}
