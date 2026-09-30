<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCP\Notification\IManager;

/**
 * The ONE notification choke point of ChoreStrike — digest-only
 * (CS-PLAN-076 §3.4/§3.5, C5e, D9, RF-070-10).
 *
 * Every app-originated Nextcloud notification is the at-most-one daily
 * opt-in summary. Awards, titles and in-app state stay in-app: the
 * former pressure surfaces (task due reminders, medal/weekly-goal/title
 * bells, overdue-takeover bell) are retired with their callers —
 * nothing external is sent by default and there is no per-recipient
 * activity pressure.
 *
 * Outcome contract (parent §3.6): {@see notifyDigest()} either ACCEPTS
 * (returns normally) or the attempt is UNCERTAIN (a throwable
 * propagates after bounded, payload-free logging) — the caller marks
 * the delivery slot `sent` only on acceptance and `uncertain`
 * otherwise. An uncertain result is consumed and NEVER resent; the
 * old catch-and-log-only dispatch (which made acceptance
 * indistinguishable from an uncertain failure) is gone.
 *
 * The (app, user, objectType, objectId) dedup key reinforces the daily
 * budget (objectId `{houseId}:{houseUserId}:{Y-m-d}`) but is never the
 * guarantee — the slot identity is.
 */
class NotificationService {
	public const APP_ID = 'chorestrike';

	/** The single retained subject (rendered by {@see \OCA\ChoreStrike\Notification\ChoreStrikeNotifier}). */
	public const SUBJECT_DIGEST = 'digest';

	public function __construct(
		private IManager $notificationManager,
		private AppLogger $appLogger,
	) {
	}

	/**
	 * Dispatch the member's combined daily summary (both categories in
	 * ONE notification).
	 *
	 * Content is category/count-based only — no task names, no object
	 * references the notifier would resolve, no foreign request details.
	 *
	 * @param string $recipientNextcloudUserId Recipient's Nextcloud user ID
	 * @param int $houseId The house ID (dedup key part)
	 * @param int $houseUserId The recipient's membership ID (dedup key part)
	 * @param string $localDay House-local `Y-m-d` slot day (dedup key part)
	 * @param int $taskCount Relevant planned/agreed-catch-up work items
	 * @param int $orgCount Queued organizational updates
	 * @throws \Throwable when the notification outcome is UNCERTAIN
	 *                    (acceptance could not be confirmed) — the caller
	 *                    consumes the slot without resending
	 */
	public function notifyDigest(
		string $recipientNextcloudUserId,
		int $houseId,
		int $houseUserId,
		string $localDay,
		int $taskCount,
		int $orgCount,
	): void {
		$this->dispatch(
			$recipientNextcloudUserId,
			self::SUBJECT_DIGEST,
			'digest',
			$houseId . ':' . $houseUserId . ':' . $localDay,
			[
				'taskCount' => $taskCount,
				'orgCount' => $orgCount,
			]
		);
	}

	/**
	 * Build and send a notification. An uncertain outcome is logged once
	 * (bounded, payload-free context — identifiers only) and RETHROWN so
	 * the caller can distinguish an accepted send from an uncertain one
	 * (at-most-once doctrine: uncertain is consumed, never retried).
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
		array $parameters,
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
			$this->appLogger->logException('Failed to dispatch ChoreStrike notification', $e, [
				'event' => 'notification_dispatch_failed',
				'service' => 'NotificationService',
				'subject' => $subject,
				'objectType' => $objectType,
				'objectId' => $objectId,
			]);
			throw $e;
		}
	}
}
