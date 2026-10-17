<?php

/**
 * Mock UnknownNotificationException for testing (OCP since Nextcloud 30)
 */

declare(strict_types=1);

namespace OCP\Notification;

class UnknownNotificationException extends \InvalidArgumentException {
}
