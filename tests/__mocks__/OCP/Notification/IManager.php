<?php

namespace OCP\Notification;

/**
 * Mock interface for the Nextcloud notification manager.
 *
 * Defines just the surface used by ChoreStrike's NotificationService
 * (createNotification + notify). PHPUnit auto-generates the mock bodies.
 */
interface IManager {
	/**
	 * Create a fresh notification instance ready for population.
	 *
	 * @return INotification
	 */
	public function createNotification();

	/**
	 * Dispatch a notification for delivery to its recipient.
	 *
	 * @param INotification $notification Notification to send
	 */
	public function notify(INotification $notification);

	/**
	 * Register a notifier. Not used in unit tests; declared for completeness.
	 *
	 * @param callable $service Service callable returning INotifier
	 * @param callable $info Info callable returning array
	 */
	public function registerNotifier($service, $info);
}
