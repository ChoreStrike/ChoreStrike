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
 * Persists four columns that were never written to a fresh database.
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

		// chorestrike_tasks.task_tier (VARCHAR 10, default 'silver')
		if ($schema->hasTable('chorestrike_tasks')) {
			$table = $schema->getTable('chorestrike_tasks');
			if (!$table->hasColumn('task_tier')) {
				$table->addColumn('task_tier', 'string', [
					'notnull' => true,
					'length' => 10,
					'default' => 'silver',
				]);
			}
			// chorestrike_tasks.flex_days (INT, default 0)
			if (!$table->hasColumn('flex_days')) {
				$table->addColumn('flex_days', 'integer', [
					'notnull' => true,
					'default' => 0,
				]);
			}
		}

		// chorestrike_house_users.coin_multiplier (DECIMAL 3,2, default 1.00)
		if ($schema->hasTable('chorestrike_house_users')) {
			$table = $schema->getTable('chorestrike_house_users');
			if (!$table->hasColumn('coin_multiplier')) {
				$table->addColumn('coin_multiplier', 'decimal', [
					'notnull' => true,
					'precision' => 3,
					'scale' => 2,
					'default' => 1.00,
				]);
			}
			// chorestrike_house_users.last_freeze_date (DATE, nullable) — CR-SVC-03
			// idempotency guard for the daily streak-freeze job: records the last
			// day StreakFreezeService::process handled a user so a re-run (job
			// retry / occ trigger-jobs) is a no-op instead of double-consuming a
			// freeze or resetting an already-preserved streak.
			if (!$table->hasColumn('last_freeze_date')) {
				$table->addColumn('last_freeze_date', 'date', [
					'notnull' => false,
					'default' => null,
				]);
			}
		}

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
