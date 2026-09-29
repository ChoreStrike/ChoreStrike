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
 * - `unlocked_titles` JSON NULL — cache of `{pack}.{tier}` strings the user
 *   has ever unlocked (the single-series first-earned celebration marker
 *   driving the unlock detection, C3g). Recomputable from stats, so safe
 *   to clear on a house reset.
 *
 * The former `selected_title_pack` display-preference column retired in
 * place with the WP4 single-series cutover (CS-PLAN-070 WP4, Q-075-02
 * Option A: `currentTitle` is always the highest qualifying retained
 * tier; no selection surface exists). The original postSchemaChange
 * unlocked-titles backfill only made sense for migrating veteran
 * installs; dead on fresh installs and removed in CS-PLAN-055
 * (pre-release: fresh installs only, project-rules §1).
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
