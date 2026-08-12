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
 * Create multi-user support tables: house_users, completed_tasks, medals
 */
class Version1004Date20260228131728 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Create chorestrike_house_users table
		if (!$schema->hasTable('chorestrike_house_users')) {
			$table = $schema->createTable('chorestrike_house_users');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('house_id', 'bigint', ['notnull' => true]);
			$table->addColumn('nextcloud_user_id', 'string', ['notnull' => true, 'length' => 64]);
			$table->addColumn('display_name', 'string', ['notnull' => true, 'length' => 255]);
			$table->addColumn('contribution_score', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('coins', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('current_sparks', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('total_sparks_earned', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('current_streak', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('longest_streak', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('last_activity', 'datetime', ['notnull' => false]);
			$table->addColumn('role', 'string', ['notnull' => true, 'length' => 20, 'default' => 'member']);
			$table->addColumn('joined_at', 'datetime', ['notnull' => true]);
			$table->addColumn('scheduling_preferences', 'json', ['notnull' => false]);
			$table->setPrimaryKey(['id'], 'pk_house_users');
			$table->addIndex(['house_id'], 'idx_huser_house');
			$table->addIndex(['nextcloud_user_id'], 'idx_huser_ncid');
			$table->addUniqueIndex(['house_id', 'nextcloud_user_id'], 'uniq_house_user');
		}

		// Create chorestrike_completed_tasks table
		if (!$schema->hasTable('chorestrike_completed_tasks')) {
			$table = $schema->createTable('chorestrike_completed_tasks');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('room_task_id', 'bigint', ['notnull' => true]);
			$table->addColumn('user_id', 'bigint', ['notnull' => true]);
			$table->addColumn('completed_at', 'datetime', ['notnull' => true]);
			$table->addColumn('coins_earned', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('was_combo', 'boolean', ['notnull' => false, 'default' => 0]);
			$table->addColumn('combo_with_user_id', 'bigint', ['notnull' => false]);
			$table->addColumn('nextcloud_task_id', 'bigint', ['notnull' => false]);
			$table->addColumn('synced_to_nextcloud', 'boolean', ['notnull' => false, 'default' => 0]);
			$table->setPrimaryKey(['id'], 'pk_completed_tasks');
			$table->addIndex(['room_task_id'], 'idx_ct_rt');
			$table->addIndex(['user_id'], 'idx_ct_user');
			$table->addIndex(['completed_at'], 'idx_ct_date');
			$table->addIndex(['synced_to_nextcloud'], 'idx_ct_sync');
		}

		// Create chorestrike_medals table
		if (!$schema->hasTable('chorestrike_medals')) {
			$table = $schema->createTable('chorestrike_medals');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', 'bigint', ['notnull' => true]);
			$table->addColumn('medal_type', 'string', ['notnull' => true, 'length' => 50]);
			$table->addColumn('week_start', 'string', ['notnull' => true, 'length' => 10]);
			$table->addColumn('value', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('awarded_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_medals');
			$table->addIndex(['user_id'], 'idx_medal_user');
			$table->addIndex(['week_start'], 'idx_medal_week');
			$table->addIndex(['medal_type'], 'idx_medal_type');
			$table->addUniqueIndex(['user_id', 'medal_type', 'week_start'], 'uniq_medal_user_week');
		}

		return $schema;
	}
}
