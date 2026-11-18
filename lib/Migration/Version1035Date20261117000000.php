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
 * CS-PLAN-091 WP-B1 (ADR-021 decision 3): the stored inputs of derived
 * due windows.
 *
 * No future cycle is stored. A room task keeps the inputs its current
 * window is derived from, a completion freezes the cycle it fulfilled,
 * and closed cycles and sub-task ticks get tables of their own (D-091-17).
 * `chorestrike_occurrences` and `chorestrike_chk_progress` stay untouched
 * as history: their keys are not null under a unique index and cannot
 * take rows without an occurrence.
 *
 * Schema only; every new column is nullable or carries a default, so no
 * populated row is rewritten. The cutover values of existing houses,
 * members and room tasks come from the data step Version1036.
 */
class Version1035Date20261117000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$this->addRoomTaskCycleState($schema);
		$this->addDefinitionAndCompletionFacts($schema);
		$this->addCutoverColumns($schema);
		$this->createCycles($schema);
		$this->createCycleProgress($schema);

		return $schema;
	}

	/** The inputs of the current cycle of a room task. */
	private function addRoomTaskCycleState(ISchemaWrapper $schema): void {
		$table = $schema->getTable('chorestrike_room_tasks');
		$columns = [
			// The first date of an after-completion or one-time task, and
			// the parity anchor of a fixed rule (house-local day).
			'anchor_on' => ['date', ['notnull' => false]],
			// The cycle the postponement below belongs to.
			'cycle_key' => ['string', ['notnull' => false, 'length' => 40]],
			'postpone_count' => ['smallint', ['notnull' => true, 'default' => 0]],
			'postpone_days' => ['integer', ['notnull' => true, 'default' => 0]],
			// Bumps with every change of the current cycle (stale targets).
			'cycle_version' => ['bigint', ['notnull' => true, 'default' => 0]],
			'sleeping_since' => ['datetime', ['notnull' => false]],
			'wake_on' => ['date', ['notnull' => false]],
		];
		foreach ($columns as $name => [$type, $options]) {
			if (!$table->hasColumn($name)) {
				$table->addColumn($name, $type, $options);
			}
		}
	}

	private function addDefinitionAndCompletionFacts(ISchemaWrapper $schema): void {
		$tasks = $schema->getTable('chorestrike_tasks');
		if (!$tasks->hasColumn('fixed_month_day')) {
			// Day 1–31 of a fixed monthly rule, clamped to the month's end on read.
			$tasks->addColumn('fixed_month_day', 'smallint', ['notnull' => false]);
		}

		// The cycle a completion fulfilled, frozen with it.
		$completions = $schema->getTable('chorestrike_completed_tasks');
		if (!$completions->hasColumn('cycle_key')) {
			$completions->addColumn('cycle_key', 'string', ['notnull' => false, 'length' => 40]);
		}
		foreach (['window_start_on', 'window_end_on'] as $name) {
			if (!$completions->hasColumn($name)) {
				$completions->addColumn($name, 'date', ['notnull' => false]);
			}
		}
	}

	/** Where the derived model starts and what the rhythm continues from (decision 12). */
	private function addCutoverColumns(ISchemaWrapper $schema): void {
		$houses = $schema->getTable('chorestrike_houses');
		if (!$houses->hasColumn('cycles_from')) {
			// The first house-local day of the derived model.
			$houses->addColumn('cycles_from', 'date', ['notnull' => false]);
		}
		$members = $schema->getTable('chorestrike_house_users');
		foreach (['streak_base', 'longest_streak_base'] as $name) {
			if (!$members->hasColumn($name)) {
				$members->addColumn($name, 'integer', ['notnull' => false]);
			}
		}
	}

	/** One row per closed cycle: done by a valid completion, or a missed fixed cycle. */
	private function createCycles(ISchemaWrapper $schema): void {
		if (!$schema->hasTable('chorestrike_cycles')) {
			$this->createCyclesTable($schema);
		}
		$table = $schema->getTable('chorestrike_cycles');
		if (!$table->hasIndex('idx_cycle_house_week')) {
			// The weekly-goal pass lists the weeks with due work by `week_start`.
			$table->addIndex(['house_id', 'week_start'], 'idx_cycle_house_week');
		}
	}

	private function createCyclesTable(ISchemaWrapper $schema): void {
		$table = $schema->createTable('chorestrike_cycles');
		$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('house_id', 'bigint', ['notnull' => true]);
		$table->addColumn('room_task_id', 'bigint', ['notnull' => true]);
		// `c<completionId>` or `d<Y-m-d>`; never depends on a query window.
		$table->addColumn('cycle_key', 'string', ['notnull' => true, 'length' => 40]);
		// The final window in house-local days, both inclusive.
		$table->addColumn('window_start_on', 'date', ['notnull' => true]);
		$table->addColumn('window_end_on', 'date', ['notnull' => true]);
		// The house-local Monday of the window's end.
		$table->addColumn('week_start', 'date', ['notnull' => true]);
		$table->addColumn('timezone', 'string', ['notnull' => true, 'length' => 64]);
		// 'done' | 'missed'.
		$table->addColumn('outcome', 'string', ['notnull' => true, 'length' => 16]);
		$table->addColumn('completion_id', 'bigint', ['notnull' => false]);
		$table->addColumn('completion_class', 'string', ['notnull' => false, 'length' => 16]);
		$table->addColumn('energy', 'integer', ['notnull' => true, 'default' => 1]);
		// Wallet ids of the room task's assignees when the cycle closed (bounded JSON).
		$table->addColumn('responsible', 'json', ['notnull' => false]);
		$table->addColumn('created_at', 'datetime', ['notnull' => true]);
		$table->setPrimaryKey(['id'], 'pk_cycles');
		// Backs exactly-once: one closed row per cycle (D-091-08).
		$table->addUniqueIndex(['room_task_id', 'cycle_key'], 'uniq_cycle_task_key');
		$table->addIndex(['house_id', 'window_end_on'], 'idx_cycle_house_end');
	}

	/** Sub-task ticks per cycle; partly ticked work stays saved (D-089-02). */
	private function createCycleProgress(ISchemaWrapper $schema): void {
		if ($schema->hasTable('chorestrike_cycle_progress')) {
			return;
		}
		$table = $schema->createTable('chorestrike_cycle_progress');
		$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('room_task_id', 'bigint', ['notnull' => true]);
		$table->addColumn('cycle_key', 'string', ['notnull' => true, 'length' => 40]);
		$table->addColumn('checklist_item_id', 'bigint', ['notnull' => true]);
		$table->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 0]);
		$table->addColumn('is_checked', 'boolean', ['notnull' => false, 'default' => false]);
		$table->addColumn('checked_at', 'datetime', ['notnull' => false]);
		$table->addColumn('checked_by_user_id', 'bigint', ['notnull' => false]);
		$table->setPrimaryKey(['id'], 'pk_cycle_progress');
		$table->addUniqueIndex(['room_task_id', 'cycle_key', 'checklist_item_id'], 'uniq_cprog_key');
	}
}
