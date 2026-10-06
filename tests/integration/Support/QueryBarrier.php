<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Support;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Logging\SQLLogger;
use OCP\IDBConnection;

/** Release a held worker transaction only when the contender reaches its SQL. */
final class QueryBarrier implements SQLLogger {
	private bool $reached = false;
	private ?SQLLogger $previous;

	private function __construct(
		private Configuration $configuration,
		private string $signalFile,
		private string $pattern,
		private bool $hold,
	) {
		$this->previous = $configuration->getSQLLogger();
		$configuration->setSQLLogger($this);
	}

	public static function attach(IDBConnection $db, string $signalFile, string $pattern, bool $hold = false): self {
		// Same live DBAL seam as QueryCounter; never replace the real connection.
		$connection = $db instanceof \OC\DB\ConnectionAdapter
			? (new \ReflectionProperty(\OC\DB\ConnectionAdapter::class, 'inner'))->getValue($db)
			: $db;
		return new self($connection->getConfiguration(), $signalFile, $pattern, $hold);
	}

	public function startQuery($sql, ?array $params = null, ?array $types = null): void {
		$this->previous?->startQuery($sql, $params, $types);
		if (!$this->reached && preg_match($this->pattern, $sql) === 1) {
			$this->reached = true;
			if ($this->hold) {
				self::hold($this->signalFile);
			} else {
				file_put_contents($this->signalFile . '.attempt', 'attempting');
			}
		}
	}

	public function stopQuery(): void {
		$this->previous?->stopQuery();
	}

	public function detach(): void {
		$this->configuration->setSQLLogger($this->previous);
		if (file_exists($this->signalFile . '.attempt')) {
			unlink($this->signalFile . '.attempt');
		}
	}

	public function wasReached(): bool {
		return $this->reached;
	}

	/** Require a normal exit, not merely a marker written before commit. */
	public static function finish($process, array $pipes, float $timeout = 30.0): string {
		$deadline = microtime(true) + $timeout;
		do {
			$status = proc_get_status($process);
			if ($status !== false && !$status['running']) {
				$error = stream_get_contents($pipes[2]);
				if ($status['exitcode'] !== 0 || $error !== '') {
					throw new \RuntimeException('The holder failed: ' . $error);
				}
				return trim((string)stream_get_contents($pipes[1]));
			}
			usleep(10000);
		} while (microtime(true) < $deadline);
		throw new \RuntimeException('The holder did not exit within the barrier deadline.');
	}

	/** The holder has already acquired a real lock/insert before publishing ready. */
	public static function hold(string $signalFile, float $timeout = 30.0): void {
		file_put_contents($signalFile, 'locked');
		$deadline = microtime(true) + $timeout;
		while (!file_exists($signalFile . '.attempt')) {
			if (microtime(true) >= $deadline) {
				throw new \RuntimeException('The contender did not reach the required SQL barrier.');
			}
			usleep(10000);
		}
	}
}
