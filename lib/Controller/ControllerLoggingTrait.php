<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\AppLogger;

/**
 * Shared controller logging helpers.
 *
 * The logger is required (CS-PLAN-052): controllers using this trait must
 * pass an AppLogger and call {@see initializeControllerLogger()} from their
 * constructor, so a wiring mistake can no longer degrade to permanently
 * silent logging.
 */
trait ControllerLoggingTrait {
	private AppLogger $appLogger;

	protected function initializeControllerLogger(AppLogger $appLogger): void {
		$this->appLogger = $appLogger;
	}

	/**
	 * @param array<string, mixed> $context
	 */
	protected function logControllerException(string $action, \Throwable $exception, array $context = []): void {
		$this->appLogger->logException(
			'Controller action failed',
			$exception,
			array_merge($context, [
				'controller' => static::class,
				'action' => $action,
			]),
			$this->request
		);
	}

	/**
	 * @param array<string, mixed> $context
	 */
	protected function logControllerWarning(string $message, array $context = []): void {
		$this->appLogger->warning(
			$message,
			array_merge($context, ['controller' => static::class]),
			$this->request
		);
	}

	/**
	 * @param array<string, mixed> $context
	 */
	protected function logControllerInfo(string $message, array $context = []): void {
		$this->appLogger->info(
			$message,
			array_merge($context, ['controller' => static::class]),
			$this->request
		);
	}

	/**
	 * @param array<string, mixed> $context
	 */
	protected function logDebug(string $message, array $context = []): void {
		$this->appLogger->debug(
			$message,
			array_merge($context, ['controller' => static::class]),
			$this->request
		);
	}
}
