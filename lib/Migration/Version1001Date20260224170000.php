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
 * Adds task scopes and task lifecycle fields for one-time and bounded recurrence.
 */
class Version1001Date20260224170000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('chorestrike_task_scopes')) {
			$table = $schema->createTable('chorestrike_task_scopes');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('house_id', 'bigint', ['notnull' => true]);
			$table->addColumn('type', 'string', ['notnull' => true, 'length' => 20, 'default' => 'room']);
			$table->addColumn('room_id', 'bigint', ['notnull' => false]);
			$table->addColumn('name', 'string', ['notnull' => true, 'length' => 255]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_task_scopes');
			// Foreign keys removed for compatibility - see ADR
			$table->addIndex(['house_id', 'type'], 'idx_ts_house_type');
			$table->addIndex(['room_id'], 'idx_ts_room');
		}

		if ($schema->hasTable('chorestrike_tasks')) {
			$table = $schema->getTable('chorestrike_tasks');
			if (!$table->hasColumn('scope_type')) {
				$table->addColumn('scope_type', 'string', ['notnull' => true, 'length' => 20, 'default' => 'room']);
				$table->addIndex(['scope_type'], 'idx_tasks_scope_type');
			}
			if (!$table->hasColumn('scope_label')) {
				$table->addColumn('scope_label', 'string', ['notnull' => false, 'length' => 255]);
			}
			if (!$table->hasColumn('recurrence_mode')) {
				$table->addColumn('recurrence_mode', 'string', ['notnull' => true, 'length' => 20, 'default' => 'recurring']);
				$table->addIndex(['recurrence_mode'], 'idx_tasks_recurrence_mode');
			}
			if (!$table->hasColumn('active_from')) {
				$table->addColumn('active_from', 'datetime', ['notnull' => false]);
			}
			if (!$table->hasColumn('active_until')) {
				$table->addColumn('active_until', 'datetime', ['notnull' => false]);
				$table->addIndex(['active_until'], 'idx_tasks_active_until');
			}
			if (!$table->hasColumn('is_voluntary')) {
				$table->addColumn('is_voluntary', 'boolean', ['notnull' => false, 'default' => 0]);
				$table->addIndex(['is_voluntary'], 'idx_task_voluntary');
			}
		}

		if ($schema->hasTable('chorestrike_room_tasks')) {
			$table = $schema->getTable('chorestrike_room_tasks');
			if (!$table->hasColumn('scope_id')) {
				$table->addColumn('scope_id', 'bigint', ['notnull' => false]);
				// Foreign key removed for compatibility - see ADR
				$table->addIndex(['scope_id'], 'idx_rt_scope');
			}
		}

		return $schema;
	}
}
