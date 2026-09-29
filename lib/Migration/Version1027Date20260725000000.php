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
 * Persists columns that were never written to a fresh database.
 *
 * Background (CODE_REVIEW_2026-07-24 [P2] fresh-install schema gap): the
 * columns `task_tier`, `coin_multiplier`, `flex_days` and `ideal_due_date`
 * were originally added by `Version1018`/`Version1019`/`Version1020`, but
 * those migrations add them in `preSchemaChange()` (void return). The
 * Nextcloud migration runner only persists schema diffs produced by
 * `changeSchema()`, so on a clean install (empty volume) the columns were
 * applied in-memory during the step and then dropped again — every later
 * read/write of the column failed with a 500. On upgraded installs the gap
 * was masked by a cached Docker `ALTER TABLE` workaround.
 *
 * This migration re-adds the columns via `changeSchema()` with `hasColumn`
 * guards, so it is fully idempotent: a no-op on installs that already have
 * the columns (upgraded or previously worked-around) and the canonical
 * source on a fresh install. The definitions mirror the originals exactly.
 *
 * The original steps `Version1018`–`Version1020` were deleted from the chain
 * in CS-PLAN-055 (their only lasting effect was this provenance note — on a
 * fresh install this file is now the sole creator of all four columns).
 *
 * CS-PLAN-070 WP4: the retired Coin-era `task_tier` earning-multiplier
 * column is gone from the fresh-install chain (total effort lives on the
 * occurrence, payout on the frozen completion rate); `last_freeze_date`
 * was already retired with the freeze mechanics (WP3, Q-074-01 Option A)
 * and `coin_multiplier` with the wallet cutover.
 */
class Version1027Date20260725000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure
	 * @param array $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// chorestrike_tasks.flex_days (INT, default 0)
		if ($schema->hasTable('chorestrike_tasks')) {
			$table = $schema->getTable('chorestrike_tasks');
			if (!$table->hasColumn('flex_days')) {
				$table->addColumn('flex_days', 'integer', [
					'notnull' => true,
					'default' => 0,
				]);
			}
		}

		// chorestrike_house_users.last_freeze_date — retired with the freeze
		// mechanics (CS-PLAN-070 WP3, Q-074-01 Option A: the coupled
		// legacy-effect retirement). The `coin_multiplier` earning multiplier
		// is likewise gone — Coins are wallet minor units at the frozen rate.

		// chorestrike_room_tasks.ideal_due_date (DATETIME, nullable)
		if ($schema->hasTable('chorestrike_room_tasks')) {
			$table = $schema->getTable('chorestrike_room_tasks');
			if (!$table->hasColumn('ideal_due_date')) {
				$table->addColumn('ideal_due_date', 'datetime', [
					'notnull' => false,
					'default' => null,
				]);
			}
		}

		// The runner persists schema diffs produced by changeSchema(), so the
		// modified wrapper MUST be returned (the original preSchemaChange()
		// bug this migration fixes returned void/null and was silently dropped).
		return $schema;
	}
}
