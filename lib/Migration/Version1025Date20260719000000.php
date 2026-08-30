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
 * Adds the role-title progression columns to `chorestrike_house_users`
 * (CS-PLAN-006 Phase 1).
 *
 * - `selected_title_pack` VARCHAR(20) NULL — the pack the user chose to
 *   display (null = no pack chosen yet; default).
 * - `unlocked_titles` JSON NULL — cache of `{pack}.{tier}` strings the user
 *   has ever unlocked (drives the title-history tooltip + the unlock
 *   detection). Recomputable from stats, so safe to clear on a house reset.
 *
 * Both columns are additive and nullable; uses the same native `json`
 * column + entity type as `scheduling_preferences` (proven portable across
 * SQLite/MariaDB/PostgreSQL in the NC 27+ test environment).
 *
 * The original postSchemaChange unlocked-titles backfill only made sense
 * for migrating veteran installs; dead on fresh installs and removed in
 * CS-PLAN-055 (pre-release: fresh installs only, project-rules §1).
 */
class Version1025Date20260719000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure
	 * @param array $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('chorestrike_house_users')) {
			return null;
		}

		$table = $schema->getTable('chorestrike_house_users');

		if (!$table->hasColumn('selected_title_pack')) {
			$table->addColumn('selected_title_pack', 'string', [
				'notnull' => false,
				'length' => 20,
				'default' => null,
			]);
		}

		if (!$table->hasColumn('unlocked_titles')) {
			$table->addColumn('unlocked_titles', 'json', [
				'notnull' => false,
			]);
		}

		// The runner persists schema diffs produced by changeSchema(), so the
		// modified wrapper MUST be returned (returning null silently drops it).
		return $schema;
	}
}
