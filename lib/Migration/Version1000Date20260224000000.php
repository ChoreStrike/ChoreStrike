<?php

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
			$table->setPrimaryKey(['id']);
			$table->addIndex(['nextcloud_instance_id'], 'idx_houses_instance');
		}

		if (!$schema->hasTable('chorestrike_floors')) {
			$table = $schema->createTable('chorestrike_floors');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('house_id', 'bigint', ['notnull' => true]);
			$table->addColumn('name', 'string', ['notnull' => true, 'length' => 255]);
			$table->addColumn('sort_order', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addForeignKeyConstraint('chorestrike_houses', ['house_id'], ['id'], ['onDelete' => 'CASCADE']);
			$table->addIndex(['house_id'], 'idx_floors_house');
			$table->addIndex(['house_id', 'sort_order'], 'idx_floors_house_order');
		}

		if (!$schema->hasTable('chorestrike_rooms')) {
			$table = $schema->createTable('chorestrike_rooms');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('floor_id', 'bigint', ['notnull' => true]);
			$table->addColumn('name', 'string', ['notnull' => true, 'length' => 255]);
			$table->addColumn('cleanliness', 'integer', ['notnull' => true, 'default' => 50]);
			$table->addColumn('monster_count', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('last_cleaned', 'datetime', ['notnull' => false]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addForeignKeyConstraint('chorestrike_floors', ['floor_id'], ['id'], ['onDelete' => 'CASCADE']);
			$table->addIndex(['floor_id'], 'idx_rooms_floor');
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
			$table->addColumn('scope_type', 'string', ['notnull' => true, 'length' => 20, 'default' => 'room']);
			$table->addColumn('scope_label', 'string', ['notnull' => false, 'length' => 255]);
			$table->addColumn('recurrence_mode', 'string', ['notnull' => true, 'length' => 20, 'default' => 'recurring']);
			$table->addColumn('active_from', 'datetime', ['notnull' => false]);
			$table->addColumn('active_until', 'datetime', ['notnull' => false]);
			$table->addColumn('is_voluntary', 'boolean', ['notnull' => true, 'default' => false]);
			$table->addColumn('is_template', 'boolean', ['notnull' => true, 'default' => false]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['is_template'], 'idx_tasks_template');
			$table->addIndex(['category'], 'idx_tasks_category');
			$table->addIndex(['scope_type'], 'idx_tasks_scope_type');
		}

		if (!$schema->hasTable('chorestrike_room_tasks')) {
			$table = $schema->createTable('chorestrike_room_tasks');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('room_id', 'bigint', ['notnull' => true]);
			$table->addColumn('scope_id', 'bigint', ['notnull' => false]);
			$table->addColumn('task_id', 'bigint', ['notnull' => true]);
			$table->addColumn('next_due', 'datetime', ['notnull' => true]);
			$table->addColumn('last_completed', 'datetime', ['notnull' => false]);
			$table->addColumn('assigned_to_user_id', 'string', ['notnull' => false, 'length' => 64]);
			$table->addColumn('is_active', 'boolean', ['notnull' => true, 'default' => true]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addForeignKeyConstraint('chorestrike_rooms', ['room_id'], ['id'], ['onDelete' => 'CASCADE']);
			$table->addForeignKeyConstraint('chorestrike_tasks', ['task_id'], ['id'], ['onDelete' => 'CASCADE']);
			$table->addIndex(['room_id'], 'idx_room_tasks_room');
			$table->addIndex(['next_due'], 'idx_room_tasks_next_due');
			$table->addIndex(['is_active'], 'idx_room_tasks_active');
			$table->addUniqueIndex(['room_id', 'task_id'], 'uniq_room_task');
		}

		if (!$schema->hasTable('chorestrike_house_users')) {
			$table = $schema->createTable('chorestrike_house_users');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('house_id', 'bigint', ['notnull' => true]);
			$table->addColumn('nextcloud_user_id', 'string', ['notnull' => true, 'length' => 64]);
			$table->addColumn('display_name', 'string', ['notnull' => true, 'length' => 255]);
			$table->addColumn('contribution_score', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('team_sparks', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('current_streak', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('last_activity', 'datetime', ['notnull' => false]);
			$table->addColumn('role', 'string', ['notnull' => true, 'length' => 20, 'default' => 'member']);
			$table->addColumn('joined_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addForeignKeyConstraint('chorestrike_houses', ['house_id'], ['id'], ['onDelete' => 'CASCADE']);
			$table->addIndex(['house_id'], 'idx_house_users_house');
			$table->addIndex(['nextcloud_user_id'], 'idx_house_users_nc_user');
			$table->addUniqueIndex(['house_id', 'nextcloud_user_id'], 'uniq_house_user');
		}

		if (!$schema->hasTable('chorestrike_completed_tasks')) {
			$table = $schema->createTable('chorestrike_completed_tasks');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('room_task_id', 'bigint', ['notnull' => true]);
			$table->addColumn('user_id', 'bigint', ['notnull' => true]);
			$table->addColumn('completed_at', 'datetime', ['notnull' => true]);
			$table->addColumn('sparks_earned', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('was_combo', 'boolean', ['notnull' => true, 'default' => false]);
			$table->addColumn('combo_with_user_id', 'bigint', ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addForeignKeyConstraint('chorestrike_room_tasks', ['room_task_id'], ['id'], ['onDelete' => 'CASCADE']);
			$table->addForeignKeyConstraint('chorestrike_house_users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
			$table->addForeignKeyConstraint('chorestrike_house_users', ['combo_with_user_id'], ['id'], ['onDelete' => 'SET NULL']);
			$table->addIndex(['user_id'], 'idx_completed_tasks_user');
			$table->addIndex(['completed_at'], 'idx_completed_tasks_completed_at');
			$table->addIndex(['room_task_id'], 'idx_completed_tasks_room_task');
		}

		if (!$schema->hasTable('chorestrike_medals')) {
			$table = $schema->createTable('chorestrike_medals');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', 'bigint', ['notnull' => true]);
			$table->addColumn('medal_type', 'string', ['notnull' => true, 'length' => 50]);
			$table->addColumn('week_start', 'date', ['notnull' => true]);
			$table->addColumn('value', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('awarded_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addForeignKeyConstraint('chorestrike_house_users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
			$table->addIndex(['user_id'], 'idx_medals_user');
			$table->addIndex(['week_start'], 'idx_medals_week');
			$table->addIndex(['medal_type'], 'idx_medals_type');
		}

		return $schema;
	}
}
