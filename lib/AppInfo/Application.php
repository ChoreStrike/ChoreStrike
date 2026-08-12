<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

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
		// Commands are registered via appinfo/info.xml in Nextcloud 31+
		// (no programmatic registration needed for them).

		// Notifiers are NOT picked up from info.xml on modern Nextcloud —
		// they must be registered here or persisted notifications are never
		// rendered in the bell (discovered while wiring CS-PLAN-023 E2E #2).
		$context->registerNotifierService(\OCA\ChoreStrike\Notification\ChoreStrikeNotifier::class);
	}

	/**
	 * Boot the application
	 */
	public function boot(IBootContext $context): void {
		// Boot logic if needed
	}
}
