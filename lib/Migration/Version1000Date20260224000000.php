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
 * Initial database schema for ChoreStrike
 */
class Version1000Date20260224000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('chorestrike_houses')) {
			$table = $schema->createTable('chorestrike_houses');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('nextcloud_instance_id', 'string', ['notnull' => true, 'length' => 64]);
			$table->addColumn('name', 'string', ['notnull' => true, 'length' => 255]);
			$table->addColumn('house_health', 'integer', ['notnull' => true, 'default' => 100]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_houses');
			// Unique: one house per instance — concurrent bootstrap requests
			// race on this constraint; the loser re-fetches the winner's row
			// (CR-053-07).
			$table->addUniqueIndex(['nextcloud_instance_id'], 'idx_house_inst');
		}

		if (!$schema->hasTable('chorestrike_floors')) {
			$table = $schema->createTable('chorestrike_floors');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('house_id', 'bigint', ['notnull' => true]);
			$table->addColumn('name', 'string', ['notnull' => true, 'length' => 255]);
			$table->addColumn('sort_order', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_floors');
			// Foreign keys removed for compatibility - see ADR
			$table->addIndex(['house_id'], 'idx_floor_house');
			$table->addIndex(['house_id', 'sort_order'], 'idx_floor_house_ord');
		}

		if (!$schema->hasTable('chorestrike_rooms')) {
			$table = $schema->createTable('chorestrike_rooms');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('floor_id', 'bigint', ['notnull' => true]);
			$table->addColumn('name', 'string', ['notnull' => true, 'length' => 255]);
			$table->addColumn('cleanliness', 'integer', ['notnull' => true, 'default' => 50]);
			$table->addColumn('monster_count', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('room_type', 'string', ['notnull' => true, 'length' => 50, 'default' => 'generic']);
			$table->addColumn('last_cleaned', 'datetime', ['notnull' => false]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_rooms');
			// Foreign keys removed for compatibility - see ADR
			$table->addIndex(['floor_id'], 'idx_room_floor');
		}

		if (!$schema->hasTable('chorestrike_tasks')) {
			$table = $schema->createTable('chorestrike_tasks');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('name', 'string', ['notnull' => true, 'length' => 255]);
			$table->addColumn('description', 'text', ['notnull' => false]);
			$table->addColumn('energy_cost', 'integer', ['notnull' => true, 'default' => 1]);
			$table->addColumn('priority', 'integer', ['notnull' => true, 'default' => 2]);
			$table->addColumn('frequency_type', 'string', ['notnull' => true, 'length' => 20, 'default' => 'weekly']);
			$table->addColumn('frequency_value', 'integer', ['notnull' => true, 'default' => 1]);
			$table->addColumn('estimated_minutes', 'integer', ['notnull' => true, 'default' => 15]);
			$table->addColumn('category', 'string', ['notnull' => true, 'length' => 50, 'default' => 'cleaning']);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_tasks');
			$table->addIndex(['category'], 'idx_task_cat');
		}

		if (!$schema->hasTable('chorestrike_room_tasks')) {
			$table = $schema->createTable('chorestrike_room_tasks');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('room_id', 'bigint', ['notnull' => true]);
			$table->addColumn('task_id', 'bigint', ['notnull' => true]);
			$table->addColumn('next_due', 'datetime', ['notnull' => true]);
			$table->addColumn('last_completed', 'datetime', ['notnull' => false]);
			$table->addColumn('is_active', 'boolean', ['notnull' => false, 'default' => 1]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_room_tasks');
			// Foreign keys removed for compatibility - see ADR
			$table->addIndex(['room_id'], 'idx_rt_room');
			$table->addIndex(['next_due'], 'idx_rt_due');
			$table->addIndex(['is_active'], 'idx_rt_active');
			$table->addUniqueIndex(['room_id', 'task_id'], 'uniq_rt');
		}

		return $schema;
	}
}
