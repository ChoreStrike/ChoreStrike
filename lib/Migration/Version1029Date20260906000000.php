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
 * Migration for CS-PLAN-064 WP5 (completion ledger → full effect record):
 *
 *  - `chorestrike_completed_tasks` gains the effect-record columns:
 *      - `occurrence_due` (date, nullable) — house-local due date of the
 *        completed occurrence (ADR-009 semantics);
 *      - `before_state` (text, NOT NULL) — authoritative pre-mutation
 *        snapshot (JSON) for the exact undo inverse;
 *      - `deltas` (text, NOT NULL) — every applied domain delta (JSON);
 *      - `undone_at` (datetime, nullable) — NULL = revertible occurrence.
 *  - The never-written legacy columns are dropped from the fresh schema
 *    (pre-release no-backcompat policy, project-rules §1): `combo_with_user_id`,
 *    `is_team_completion`, `participant_count`, `team_spark_bonus`,
 *    `was_claimed_completion`, `claim_bonus_sparks`,
 *    `days_overdue_when_claimed`.
 */
class Version1029Date20260906000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure
	 * @param array $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('chorestrike_completed_tasks')) {
			$table = $schema->getTable('chorestrike_completed_tasks');

			if (!$table->hasColumn('occurrence_due')) {
				$table->addColumn('occurrence_due', 'date', [
					'notnull' => false,
				]);
			}
			if (!$table->hasColumn('before_state')) {
				$table->addColumn('before_state', 'text', [
					'notnull' => true,
				]);
			}
			if (!$table->hasColumn('deltas')) {
				$table->addColumn('deltas', 'text', [
					'notnull' => true,
				]);
			}
			if (!$table->hasColumn('undone_at')) {
				$table->addColumn('undone_at', 'datetime', [
					'notnull' => false,
				]);
			}

			// Drop the indexes built on the dead columns FIRST: MariaDB
			// rejects an ALTER that removes a column still referenced by a
			// key (error 1072 "Key column doesn't exist in table").
			// idx_team_complete (Version1008) sits on is_team_completion,
			// idx_claimed_complete (Version1009) on was_claimed_completion.
			foreach (['idx_team_complete', 'idx_claimed_complete'] as $deadIndex) {
				if ($table->hasIndex($deadIndex)) {
					$table->dropIndex($deadIndex);
				}
			}

			// Dead columns (§1.1): setter matches existed only in the entity
			// docblock — no writer anywhere in the codebase.
			foreach ([
				'combo_with_user_id',
				'is_team_completion',
				'participant_count',
				'team_spark_bonus',
				'was_claimed_completion',
				'claim_bonus_sparks',
				'days_overdue_when_claimed',
			] as $deadColumn) {
				if ($table->hasColumn($deadColumn)) {
					$table->dropColumn($deadColumn);
				}
			}
		}

		// The runner persists schema diffs produced by changeSchema(), so the
		// modified wrapper MUST be returned (returning null silently drops it).
		return $schema;
	}
}
