<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Support;

/**
 * The portable part of a write budget: the MariaDB count is the frozen
 * literal, and each provider's overhead is pinned on its own — PostgreSQL
 * reads a generated id per insert, SQLite takes a row lock as a
 * self-assignment write next to its read.
 */
trait QueryBudgets {
	/**
	 * @param int $budget The frozen statement count without provider overhead
	 * @param int $locks Row locks the command takes
	 * @param int $generatedIds Inserts whose generated id is read
	 */
	protected function assertPortableWriteBudget(QueryCounter $counter, int $budget, int $locks, int $generatedIds): void {
		$sql = array_map(static fn (string $query): string => str_replace(['`', '"'], '', $query), $counter->sqlStatements());
		$lockWrites = preg_grep('/^UPDATE oc_chorestrike_\w+ SET (\w+) = \1 WHERE /', $sql);
		$identityReads = preg_grep('/^SELECT lastval\(\)$/i', $sql);
		$sqlite = $this->db->getDatabaseProvider() === \OCP\IDBConnection::PLATFORM_SQLITE;
		$postgres = $this->db->getDatabaseProvider() === \OCP\IDBConnection::PLATFORM_POSTGRES;
		self::assertCount($sqlite ? $locks : 0, $lockWrites, 'each SQLite lock is one self-assignment write plus its read');
		self::assertSame($sqlite ? 0 : $locks, $counter->countLockSelects());
		self::assertCount($postgres ? $generatedIds : 0, $identityReads, 'PostgreSQL identity reads are bounded, not arbitrary extra queries');
		$overhead = ($sqlite ? $locks : 0) + ($postgres ? $generatedIds : 0);
		self::assertLessThanOrEqual($budget + $overhead, $counter->count(), implode("\n", $counter->sqlStatements()));
	}
}
