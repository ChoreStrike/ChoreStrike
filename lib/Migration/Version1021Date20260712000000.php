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
 * Migration for Phase 1 multi-assign:
 *  - creates the `chorestrike_task_assignees` join table (multiple assignees per room task)
 *  - adds `is_private` flag to `chorestrike_tasks` for the private-task completion gate
 */
class Version1021Date20260712000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure
	 * @param array $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Create the task assignees join table
		if (!$schema->hasTable('chorestrike_task_assignees')) {
			$table = $schema->createTable('chorestrike_task_assignees');
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 4,
				'unsigned' => true,
			]);
			$table->addColumn('room_task_id', 'integer', [
				'notnull' => true,
				'length' => 4,
				'unsigned' => true,
			]);
			$table->addColumn('user_id', 'string', [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('assigned_at', 'datetime', [
				'notnull' => false,
			]);
			$table->addColumn('assigned_by', 'string', [
				'notnull' => false,
				'length' => 255,
			]);
			$table->setPrimaryKey(['id'], 'pk_task_assignees');
			$table->addUniqueIndex(['room_task_id', 'user_id'], 'cs_task_assignees_unique');
			$table->addIndex(['user_id'], 'cs_task_assignees_user');
			$table->addIndex(['room_task_id'], 'cs_task_assignees_task');
		}

		// Add is_private flag to tasks table
		if ($schema->hasTable('chorestrike_tasks')) {
			$table = $schema->getTable('chorestrike_tasks');
			if (!$table->hasColumn('is_private')) {
				$table->addColumn('is_private', 'boolean', [
					'notnull' => false,
					'default' => false,
				]);
			}
		}

		// The runner persists schema diffs produced by changeSchema(), so the
		// modified wrapper MUST be returned (returning null silently drops it).
		return $schema;
	}
}
