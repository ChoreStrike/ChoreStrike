<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Notification;

use OCA\ChoreStrike\Service\NotificationService;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

/**
 * Prepares ChoreStrike notifications for display in the Nextcloud bell and
 * the mobile-app notification feed.
 *
 * Registered in {@see \OCA\ChoreStrike\AppInfo\Application::register()} via
 * registerNotifierService() — modern Nextcloud does NOT pick notifiers up
 * from info.xml (the former class doc claiming an info.xml registration was
 * stale; the WP5 digest cutover corrected it).
 *
 * The notifier renders exactly ONE subject — the daily `digest` summary
 * (CS-PLAN-076 §3.5, C5e/D9). All former pressure subjects (task due
 * reminders, medal/weekly-goal/title/overdue-takeover bells) are retired
 * with their dispatch callers; an unknown subject is rejected.
 *
 * Visibility safety: the digest content is category/count-based only —
 * the notifier resolves NO object references (no task names, no user
 * lookups, no foreign request details), so a summary can never leak
 * hidden work.
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
	 * Prepare a notification for display: translate the subject + body and
	 * set the app icon. The digest carries only counts in its parameters —
	 * nothing here resolves back into user-controlled content.
	 *
	 * @param INotification $notification Notification to prepare
	 * @param string $languageCode Language code for the recipient's locale
	 * @throws \InvalidArgumentException When the notification is not for this app or has an unknown subject
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== NotificationService::APP_ID) {
			throw new \InvalidArgumentException('Notification not for ChoreStrike');
		}
		if ($notification->getSubject() !== NotificationService::SUBJECT_DIGEST) {
			throw new \InvalidArgumentException('Unknown ChoreStrike notification subject');
		}

		$l = $this->l10nFactory->get(NotificationService::APP_ID, $languageCode);

		$notification->setIcon($this->url->getAbsoluteURL('apps/chorestrike/img/app.svg'));

		$params = $notification->getSubjectParameters();
		$params = is_array($params) ? $params : [];
		$taskCount = (int)($params['taskCount'] ?? 0);
		$orgCount = (int)($params['orgCount'] ?? 0);

		// Count-based content with proper plural forms; the counts are the
		// whole payload — no object references are resolved.
		$parts = [];
		if ($taskCount > 0) {
			$parts[] = $l->n('%n planned task', '%n planned tasks', $taskCount);
		}
		if ($orgCount > 0) {
			$parts[] = $l->n('%n household update', '%n household updates', $orgCount);
		}
		$subjectText = $parts === []
			? $l->t('Daily summary')
			: $l->t('Daily summary: %s', [implode(' · ', $parts)]);

		$notification->setParsedSubject($subjectText);
		$notification->setRichSubject($subjectText);
		$notification->setParsedMessage(
			$l->t('Open My Day to see the details. You receive at most one summary per day.')
		);
		$notification->setLink($this->url->linkToRouteAbsolute('chorestrike.page.index'));

		return $notification;
	}
}
