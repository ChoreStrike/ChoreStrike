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
 * Add sub-checklist tables for tasks
 *
 * CS-PLAN-072 WP1: checklist progress is keyed by occurrence/item — the
 * unique key is `(schedule_rev_id, ordinal, checklist_item_id)` over the
 * public occurrence identity, replacing the room-task/item key. Each row
 * carries a progress revision so stale mutations conflict (409) instead of
 * overwriting a later occurrence's progress. `room_task_id` stays as a
 * denormalized cleanup/stats column.
 */
class Version1005Date20260228160000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Create checklist items table
		if (!$schema->hasTable('chorestrike_chk_items')) {
			$table = $schema->createTable('chorestrike_chk_items');

			$table->addColumn('id', 'bigint', [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);

			$table->addColumn('task_id', 'bigint', [
				'notnull' => true,
				'unsigned' => true,
			]);

			$table->addColumn('label', 'string', [
				'notnull' => true,
				'length' => 255,
			]);

			$table->addColumn('sort_order', 'integer', [
				'notnull' => true,
				'default' => 0,
			]);

			$table->addColumn('created_at', 'datetime', [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id'], 'pk_chk_items');
			$table->addIndex(['task_id'], 'chk_items_task_idx');
		}

		// Create checklist progress table for room tasks
		if (!$schema->hasTable('chorestrike_chk_progress')) {
			$table = $schema->createTable('chorestrike_chk_progress');

			$table->addColumn('id', 'bigint', [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);

			$table->addColumn('room_task_id', 'bigint', [
				'notnull' => true,
				'unsigned' => true,
			]);

			// Occurrence identity (CS-PLAN-072 WP1): the schedule revision
			// and ordinal of the public `<scheduleRevisionId>:<ordinal>` key.
			$table->addColumn('schedule_rev_id', 'bigint', [
				'notnull' => true,
				'default' => 0,
			]);

			$table->addColumn('ordinal', 'bigint', [
				'notnull' => true,
				'default' => 0,
			]);

			// Progress revision for stale-mutation detection (409).
			$table->addColumn('revision', 'bigint', [
				'notnull' => true,
				'default' => 0,
			]);

			$table->addColumn('checklist_item_id', 'bigint', [
				'notnull' => true,
				'unsigned' => true,
			]);

			$table->addColumn('is_checked', 'boolean', [
				'notnull' => false,
				'default' => false,
			]);

			$table->addColumn('checked_at', 'datetime', [
				'notnull' => false,
				'default' => null,
			]);

			$table->addColumn('checked_by_user_id', 'bigint', [
				'notnull' => false,
				'unsigned' => true,
				'default' => null,
			]);

			$table->setPrimaryKey(['id'], 'pk_chk_prog');
			$table->addUniqueIndex(
				['schedule_rev_id', 'ordinal', 'checklist_item_id'],
				'uniq_prog_occ_item'
			);
			// RF-072-WP1-17: no separate occurrence index — the leftmost
			// prefix of uniq_prog_occ_item already serves those lookups.
			$table->addIndex(['room_task_id'], 'chk_prog_task_idx');
		}

		return $schema;
	}
}
