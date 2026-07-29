<?php

declare(strict_types=1);

namespace OCP\Log;

use Psr\Log\LoggerInterface;

/**
 * Mock Nextcloud ILogFactory for unit testing AppLogger, which obtains its
 * PSR logger via `getCustomPsrLogger()`.
 */
interface ILogFactory
{
	/**
	 * @return LoggerInterface
	 */
	public function getCustomPsrLogger(string $file = '');
}
