<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\AppInfo;

use OCA\ChoreStrike\Listener\UserDeletedListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserDeletedEvent;

/**
 * Main application class for ChoreStrike
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'chorestrike';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	/**
	 * Register services and commands
	 */
	public function register(IRegistrationContext $context): void {
		// Commands are registered via appinfo/info.xml <commands> (supported
		// by all Nextcloud versions this app targets).

		// Notifiers are NOT picked up from info.xml on modern Nextcloud —
		// they must be registered here or persisted notifications are never
		// rendered in the bell (discovered while wiring CS-PLAN-023 E2E #2).
		$context->registerNotifierService(\OCA\ChoreStrike\Notification\ChoreStrikeNotifier::class);

		// CS-PLAN-071 WP0 (Q-070-02): platform account deletion erases every
		// ChoreStrike reference of the deleted account.
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
	}

	/**
	 * Boot the application
	 */
	public function boot(IBootContext $context): void {
		// Boot logic if needed
	}
}
