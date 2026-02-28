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
		if (!$schema->hasTable('chorestrike_task_checklist_items')) {
			$table = $schema->createTable('chorestrike_task_checklist_items');
			
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
			
			$table->setPrimaryKey(['id']);
			$table->addForeignKeyConstraint(
				'chorestrike_tasks',
				['task_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'task_checklist_fk'
			);
			$table->addIndex(['task_id'], 'checklist_items_task_idx');
		}

		// Create checklist progress table for room tasks
		if (!$schema->hasTable('chorestrike_room_task_checklist_progress')) {
			$table = $schema->createTable('chorestrike_room_task_checklist_progress');
			
			$table->addColumn('id', 'bigint', [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			
			$table->addColumn('room_task_id', 'bigint', [
				'notnull' => true,
				'unsigned' => true,
			]);
			
			$table->addColumn('checklist_item_id', 'bigint', [
				'notnull' => true,
				'unsigned' => true,
			]);
			
			$table->addColumn('is_checked', 'boolean', [
				'notnull' => true,
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
			
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(
				['room_task_id', 'checklist_item_id'],
				'unique_progress_per_item'
			);
			$table->addForeignKeyConstraint(
				'chorestrike_room_tasks',
				['room_task_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'progress_room_task_fk'
			);
			$table->addForeignKeyConstraint(
				'chorestrike_task_checklist_items',
				['checklist_item_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'progress_item_fk'
			);
			$table->addIndex(['room_task_id'], 'checklist_progress_room_task_idx');
		}

		return $schema;
	}
}
