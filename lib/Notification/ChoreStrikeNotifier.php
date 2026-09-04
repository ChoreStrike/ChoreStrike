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
class ChoreStrikeNotifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $url,
	) {
	}

	/**
	 * Unique notifier ID. Matches the Nextcloud app ID.
	 */
	public function getID(): string {
		return NotificationService::APP_ID;
	}

	/**
	 * Human-readable notifier name shown in personal notification settings.
	 */
	public function getName(): string {
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
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== NotificationService::APP_ID) {
			throw new \InvalidArgumentException('Notification not for ChoreStrike');
		}

		$l = $this->l10nFactory->get(NotificationService::APP_ID, $languageCode);

		$notification->setIcon($this->url->getAbsoluteURL('apps/chorestrike/img/app.svg'));

		switch ($notification->getSubject()) {
			case NotificationService::SUBJECT_OVERDUE_TAKEN_OVER:
				$params = $this->params($notification);
				$task = (string)($params['task'] ?? '');
				// The rich `user` param's `id` must be a Nextcloud UID; the
				// display name rides as `name` (CS-PLAN-061 §3.7).
				$claimerId = (string)($params['claimer'] ?? '');
				$claimerName = (string)($params['claimerName'] ?? $claimerId);
				// Rich subject: parameters are escaped per-surface by Nextcloud.
				$notification->setRichSubject(
					$l->t('Your task "{task}" was taken over by {claimer}'),
					[
						'task' => ['type' => 'highlight', 'id' => (string)($params['taskId'] ?? '0'), 'name' => $task],
						'claimer' => ['type' => 'user', 'id' => $claimerId, 'name' => $claimerName],
					]
				);
				$notification->setParsedSubject(
					$l->t('Your task "%1$s" was taken over by %2$s', [$task, $claimerName])
				);
				$notification->setParsedMessage(
					$l->t('%1$s completed your overdue task so your streak is safe. Be a hero and pick up one of theirs next time!', [$claimerName])
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

			case NotificationService::SUBJECT_TITLE_UNLOCKED:
				$params = $this->params($notification);
				$titleLabelKey = (string)($params['titleLabelKey'] ?? '');
				$packLabelKey = (string)($params['packLabelKey'] ?? '');
				$titleLabel = $l->t($titleLabelKey);
				$packLabel = $l->t($packLabelKey);
				$notification->setParsedSubject(
					$l->t('You unlocked a new title: %1$s', [$titleLabel])
				);
				$notification->setParsedMessage(
					$l->t('You reached a new tier ("%1$s") in the "%2$s" pack. Set it as your title in Settings!', [$titleLabel, $packLabel])
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

			case NotificationService::SUBJECT_TASK_DUE_TODAY:
			case NotificationService::SUBJECT_TASK_DUE_TOMORROW:
				$params = $this->params($notification);
				$task = (string)($params['task'] ?? '');
				$taskId = (string)($params['taskId'] ?? '0');
				if ($notification->getSubject() === NotificationService::SUBJECT_TASK_DUE_TOMORROW) {
					$richSubject = $l->t('Task "{task}" is due tomorrow');
					$parsedSubject = $l->t('Task "%1$s" is due tomorrow', [$task]);
					$parsedMessage = $l->t('Plan ahead so the chore does not sneak up on you tomorrow.');
				} else {
					$richSubject = $l->t('Task "{task}" is due today');
					$parsedSubject = $l->t('Task "%1$s" is due today', [$task]);
					$parsedMessage = $l->t('Complete it today to collect your coins and keep your streak.');
				}
				// Rich subject: the task name is user-controlled, so it must
				// ride as an escaped placeholder parameter, never pre-interpolated.
				$notification->setRichSubject(
					$richSubject,
					[
						'task' => ['type' => 'highlight', 'id' => $taskId, 'name' => $task],
					]
				);
				$notification->setParsedSubject($parsedSubject);
				$notification->setParsedMessage($parsedMessage);
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
	private function params(INotification $notification): array {
		$params = $notification->getSubjectParameters();
		if (!is_array($params)) {
			return [];
		}
		return $params;
	}

	/**
	 * Localise a medal type identifier into a human-readable label.
	 *
	 * Medal types come from GameLogicService::awardMedalIfNew (all five:
	 * 'team_anchor', 'streak_keeper', 'flow_keeper', 'room_guardian',
	 * 'team_player'). Falls back to a prettified version of the identifier
	 * for any unknown type so we never show raw snake_case to the user.
	 *
	 * @param IL10N $l L10n instance
	 * @param string $medalType Medal type identifier
	 */
	private function medalLabel(IL10N $l, string $medalType): string {
		$labels = [
			'team_anchor' => $l->t('Team Anchor'),
			'streak_keeper' => $l->t('Streak Keeper'),
			'flow_keeper' => $l->t('Flow Keeper'),
			'room_guardian' => $l->t('Room Guardian'),
			'team_player' => $l->t('Team Player'),
		];

		return $labels[$medalType] ?? ucfirst(str_replace('_', ' ', $medalType));
	}
}
