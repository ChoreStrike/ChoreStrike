<?php

declare(strict_types=1);

namespace OCP\DB;

/**
 * Mock Nextcloud schema wrapper interface for migration unit tests.
 */
interface ISchemaWrapper {
	/**
	 * Checks whether the schema contains the given table.
	 */
	public function hasTable(string $tableName): bool;

	/**
	 * Returns a table object from the schema.
	 *
	 * @return mixed
	 */
	public function getTable(string $tableName);
}
