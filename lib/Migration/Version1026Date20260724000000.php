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
 * Drops the legacy Sparks currency columns from `chorestrike_house_users`
 * (CS-PLAN-010 — closes the Sparks/Monsters removal residue from CS-PLAN-002).
 *
 * - `current_sparks`        (INT) — a second, never-awarded currency.
 * - `total_sparks_earned`   (INT) — lifetime total of that currency.
 *
 * Both columns were frozen at zero after CS-PLAN-002: `GameLogicService`
 * awards `coins` (via `HouseUser::addCoins()`), never sparks, and the
 * `minigame#sparks` endpoint + `MinigameService::getUserSparks()` were removed.
 * The Cozy Home title pack was repointed to `coins` (CS-PLAN-006), so no live
 * code path reads either column.
 *
 * Runs strictly after `Version1025` (version ordering); its former
 * `unlocked_titles` backfill (removed in CS-PLAN-055) never referenced the
 * dropped columns (CS-PLAN-010). The
 * `hasColumn()` guards make this idempotent: a re-run, or a fresh install that
 * never had the columns, is a no-op.
 */
class Version1026Date20260724000000 extends SimpleMigrationStep {
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

		if ($table->hasColumn('current_sparks')) {
			$table->dropColumn('current_sparks');
		}

		if ($table->hasColumn('total_sparks_earned')) {
			$table->dropColumn('total_sparks_earned');
		}

		// The runner persists schema diffs produced by changeSchema(), so the
		// modified wrapper MUST be returned (returning null silently drops it).
		return $schema;
	}
}
