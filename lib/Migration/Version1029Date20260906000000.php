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
 *      - `undone_at` (datetime, nullable) — NULL = revertible occurrence.
 *  - CS-PLAN-070 WP3 (§3.1): the before-state restore authority and the
 *    whole-unit reward outputs are gone — undo/attribution re-derive from
 *    the immutable evidence (work_shares/completion_revs/room_events), the
 *    wallet is the sole monetary authority. In their place the row freezes
 *    the exact work facts:
 *      - `total_energy` (integer, 1-5) — frozen task effort at completion;
 *      - `rate_minor` (integer, default 1000) — frozen Coin minor per whole
 *        energy unit at completion;
 *      - `completion_version` (bigint, initial 0) — bumped by attribution
 *        and undo;
 *      - `allocation_revision` (bigint, initial 1) — the SELECTED
 *        work_shares revision;
 *      - `command_uuid` (string 36) — the creating command receipt.
 *  - The never-written legacy columns are dropped from the fresh schema
 *    (pre-release no-backcompat policy, project-rules §1): `combo_with_user_id`,
 *    `is_team_completion`, `participant_count`, `team_spark_bonus`,
 *    `was_claimed_completion`, `claim_bonus_sparks`,
 *    `days_overdue_when_claimed`.
 *  - CS-PLAN-072 WP1: completion occurrence references and frozen timeline
 *    evidence — `schedule_rev_id` + `occurrence_ordinal` (the public
 *    occurrence key parts), `work_date`/`work_zone` (the server-owned work
 *    time basis) and `completion_class` ('agreed'|'late'|'voluntary').
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
			if (!$table->hasColumn('undone_at')) {
				$table->addColumn('undone_at', 'datetime', [
					'notnull' => false,
				]);
			}

			// CS-PLAN-070 WP3 (§3.1): frozen work facts + versioning.
			if (!$table->hasColumn('total_energy')) {
				$table->addColumn('total_energy', 'integer', [
					'notnull' => true,
					// Safety net matching the documented minimum (the
					// writer always sets the frozen value explicitly).
					'default' => 1,
				]);
			}
			if (!$table->hasColumn('rate_minor')) {
				$table->addColumn('rate_minor', 'integer', [
					'notnull' => true,
					'default' => 1000,
				]);
			}
			if (!$table->hasColumn('completion_version')) {
				$table->addColumn('completion_version', 'bigint', [
					'notnull' => true,
					'default' => 0,
				]);
			}
			if (!$table->hasColumn('allocation_revision')) {
				$table->addColumn('allocation_revision', 'bigint', [
					'notnull' => true,
					'default' => 1,
				]);
			}
			if (!$table->hasColumn('command_uuid')) {
				$table->addColumn('command_uuid', 'string', [
					'notnull' => true,
					'length' => 36,
					'default' => '',
				]);
			}

			// CS-PLAN-072 WP1: frozen timeline evidence.
			if (!$table->hasColumn('schedule_rev_id')) {
				$table->addColumn('schedule_rev_id', 'bigint', [
					'notnull' => false,
				]);
			}
			if (!$table->hasColumn('occurrence_ordinal')) {
				$table->addColumn('occurrence_ordinal', 'bigint', [
					'notnull' => false,
				]);
			}
			if (!$table->hasColumn('work_date')) {
				$table->addColumn('work_date', 'date', [
					'notnull' => false,
				]);
			}
			if (!$table->hasColumn('work_zone')) {
				$table->addColumn('work_zone', 'string', [
					'notnull' => false,
					'length' => 64,
				]);
			}
			if (!$table->hasColumn('completion_class')) {
				$table->addColumn('completion_class', 'string', [
					'notnull' => false,
					'length' => 16,
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
