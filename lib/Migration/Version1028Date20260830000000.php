<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration for CS-PLAN-057 per-house timezone:
 *  - adds `timezone` to `chorestrike_houses` (VARCHAR(64) NOT NULL DEFAULT 'UTC').
 * Stores the IANA timezone id used for every calendar-day bucketing of the
 * household (streak days, freeze coverage, due-today windows, duplicate-
 * completion guard, medal/leaderboard weeks). Column add on an existing
 * table follows the Version1023 precedent.
 */
class Version1028Date20260830000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure
	 * @param array $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('chorestrike_houses')) {
			$table = $schema->getTable('chorestrike_houses');
			if (!$table->hasColumn('timezone')) {
				$table->addColumn('timezone', 'string', [
					'notnull' => true,
					'length' => 64,
					'default' => 'UTC',
				]);
			}
		}

		// The runner persists schema diffs produced by changeSchema(), so the
		// modified wrapper MUST be returned (returning null silently drops it).
		return $schema;
	}
}
