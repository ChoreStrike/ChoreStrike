<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\AppInfo;

use OCA\ChoreStrike\Command\TestDataSeed;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Main application class for ChoreStrike
 */
class Application extends App implements IBootstrap
{
	public const APP_ID = 'chorestrike';

	public function __construct()
	{
		parent::__construct(self::APP_ID);
	}

	/**
	 * Register services and commands
	 */
	public function register(IRegistrationContext $context): void
	{
		// Register OCC commands
		$context->registerCommand(TestDataSeed::class);
	}

	/**
	 * Boot the application
	 */
	public function boot(IBootContext $context): void
	{
		// Boot logic if needed
	}
}
