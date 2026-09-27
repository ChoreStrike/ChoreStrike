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
 * CS-PLAN-070 WP3 (Q-074-01 Option A — coupled legacy-effect retirement):
 * the streak-freeze column is retired from the fresh-install chain.
 *
 * Dated recognized pauses replaced freezes as the neutral absence mechanism
 * (parent D4b); the completion path no longer consumes or awards freezes and
 * the freeze/restock jobs are gone. Fresh installs never receive the column;
 * disposable dev databases are recreated (`down -v` + `up -d`, pre-release
 * no-backcompat policy, project-rules §1).
 */
class Version1022Date20260717000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure
	 * @param array $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		// Retained as a chain placeholder: the original `streak_freezes`
		// column add is gone with its writers/readers. Keeping the version
		// entry stable preserves the migration identity for existing tracked
		// disposable databases without re-creating retired columns.
		return $schemaClosure();
	}
}
