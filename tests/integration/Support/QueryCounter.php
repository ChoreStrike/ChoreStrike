<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Support;

use Doctrine\DBAL\Logging\SQLLogger;

/**
 * Executed-query counter for the WP8 workflow query budgets (CS-PLAN-067 §4.1).
 *
 * Counting seam (verified in slice 1 against the canonical Docker stack —
 * bundled DBAL 3.10.4, provider mariadb/mysql): the Doctrine configuration's
 * SQL logger is the only hook that can be attached to the ALREADY-CONSTRUCTED
 * Nextcloud connection the integration fixture uses. `Configuration::setSQLLogger()`
 * is marked deprecated in this DBAL version in favor of connection-time
 * middleware, but middleware cannot be retrofitted onto a live connection and
 * the logger IS still consulted at call time by
 * `Connection::executeQuery()/executeStatement()` and `Statement::execute()`
 * (DBAL 3.10.4 source lines 1089/1197/1384/1496/1546/1625 and Statement:183) —
 * which covers both counting paths this plan requires:
 *
 * - query-builder execution (`$qb->executeQuery()/executeStatement()`)
 * - raw prepared lock statements (`$db->executeQuery('SELECT … FOR UPDATE')`)
 *
 * The deprecation is NOT suppressed: doctrine/deprecations in this stack is
 * inert by default (no E_USER_DEPRECATED unless explicitly enabled), so
 * attaching the logger triggers no warning and none is silenced.
 *
 * Usage pattern (dedicated budget tests only — the counter MUST be detached
 * in tearDown so it can never observe or affect a later test):
 *
 *     $counter = QueryCounter::attach($this->db);
 *     try {
 *         // ... workload ...
 *         self::assertSame(3, $counter->count());
 *     } finally {
 *         $counter->detach();
 *     }
 */
final class QueryCounter implements SQLLogger {
	/** @var list<array{sql: string, params: mixed, types: mixed}> */
	private array $queries = [];

	private \OCP\IDBConnection $db;

	private \Doctrine\DBAL\Configuration $configuration;

	private function __construct(\OCP\IDBConnection $db, \Doctrine\DBAL\Configuration $configuration) {
		$this->db = $db;
		$this->configuration = $configuration;
	}

	/**
	 * Install the counter on the live fixture connection. Only ONE counter
	 * may be installed at a time — a second attach() replaces the first
	 * (the previous counter is detached implicitly by configuration swap).
	 */
	public static function attach(\OCP\IDBConnection $db): self {
		$configuration = self::configurationOf($db);
		$counter = new self($db, $configuration);
		$configuration->setSQLLogger($counter);
		return $counter;
	}

	/**
	 * Remove the counter. Safe to call more than once.
	 */
	public function detach(): void {
		$this->configuration->setSQLLogger(null);
	}	/**
	 * Number of executed queries since attach()/mark().
	 */
	public function count(): int {
		return count($this->queries);
	}

	/**
	 * Reset the recorded queries (keeps the counter attached). Use between
	 * the fixture setup phase and the measured workload so pinning happens
	 * outside the measurement window.
	 */
	public function mark(): void {
		$this->queries = [];
	}

	/**
	 * @return list<string> Executed SQL statements in order (params omitted)
	 */
	public function sqlStatements(): array {
		return array_map(static fn (array $entry): string => $entry['sql'], $this->queries);
	}

	/**
	 * Number of SELECT … FOR UPDATE lock statements observed.
	 */
	public function countLockSelects(): int {
		return count(array_filter(
			$this->queries,
			static fn (array $entry): bool => str_contains(strtoupper($entry['sql']), 'FOR UPDATE')
		));
	}

	/**
	 * @param string $sql
	 * @param mixed[]|null $params
	 * @param mixed[]|null $types
	 */
	public function startQuery($sql, ?array $params = null, ?array $types = null): void {
		$this->queries[] = ['sql' => (string)$sql, 'params' => $params, 'types' => $types];
	}

	public function stopQuery(): void {
		// Timing is deliberately not collected — budgets count statements.
	}

	/**
	 * Resolve the Doctrine configuration behind an OCP connection. The
	 * integration fixture resolves `OC\DB\ConnectionAdapter`, which wraps the
	 * Doctrine connection in a private `$inner` field with no public accessor
	 * (verified in slice 1); the test-only reflection here is the documented
	 * workaround — production code never needs the inner connection.
	 */
	private static function configurationOf(\OCP\IDBConnection $db): \Doctrine\DBAL\Configuration {
		$connection = $db;
		if ($db instanceof \OC\DB\ConnectionAdapter) {
			$inner = new \ReflectionProperty(\OC\DB\ConnectionAdapter::class, 'inner');
			$inner->setAccessible(true);
			$connection = $inner->getValue($db);
		}

		if ($connection instanceof \Doctrine\DBAL\Connection) {
			return $connection->getConfiguration();
		}

		throw new \RuntimeException(
			'QueryCounter requires the Doctrine connection behind the fixture IDBConnection; got ' . get_class($db)
		);
	}
}
