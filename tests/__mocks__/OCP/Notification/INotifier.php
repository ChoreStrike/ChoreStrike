<?php

namespace OCP\Notification;

/**
 * Mock interface for a notifier (renderer) implementation.
 */
interface INotifier {
	public function getID(): string;
	public function getName(): string;

	/**
	 * @return INotification
	 */
	public function prepare(INotification $notification, string $languageCode);
}
