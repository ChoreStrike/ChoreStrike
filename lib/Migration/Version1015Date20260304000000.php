<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Performance optimization migration - adds indexes for common query patterns
 */
class Version1015Date20260304000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $closure
	 * @param ISchemaWrapper $schema
	 * @return ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $closure, array $openedSchema): ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $closure();

		// chorestrike_house_users indexes
		if ($schema->hasTable('chorestrike_house_users')) {
			$table = $schema->getTable('chorestrike_house_users');

			// Index for findByHouseId queries (most common)
			if (!$table->hasIndex('hu_house_id_idx')) {
				$table->addIndex(['house_id'], 'hu_house_id_idx');
			}

			// Index for findByNextcloudUserId queries
			if (!$table->hasIndex('hu_house_user_idx')) {
				$table->addIndex(['house_id', 'nextcloud_user_id'], 'hu_house_user_idx');
			}

			// Index for leaderboard ordering
			if (!$table->hasIndex('hu_score_idx')) {
				$table->addIndex(['house_id', 'contribution_score'], 'hu_score_idx');
			}
		}

		// chorestrike_room_tasks indexes (most critical for performance)
		if ($schema->hasTable('chorestrike_room_tasks')) {
			$table = $schema->getTable('chorestrike_room_tasks');

			// Index for findByHouseId via room join
			if (!$table->hasIndex('rt_room_id_idx')) {
				$table->addIndex(['room_id'], 'rt_room_id_idx');
			}

			// Index for findByUserId queries
			if (!$table->hasIndex('rt_user_id_idx')) {
				$table->addIndex(['assigned_to_user_id'], 'rt_user_id_idx');
			}

			// Index for due date queries
			if (!$table->hasIndex('rt_next_due_idx')) {
				$table->addIndex(['next_due', 'is_active'], 'rt_next_due_idx');
			}

			// Index for scope queries
			if (!$table->hasIndex('rt_scope_idx')) {
				$table->addIndex(['scope_id'], 'rt_scope_idx');
			}
		}

		if ($schema->hasTable('chorestrike_rooms')) {
			$table = $schema->getTable('chorestrike_rooms');

			if (!$table->hasColumn('icon')) {
				$table->addColumn('icon', 'string', [
					'notnull' => true,
					'default' => 'Home',
					'length' => 64,
				]);
			}
		}

		// chorestrike_completed_tasks indexes
		if ($schema->hasTable('chorestrike_completed_tasks')) {
			$table = $schema->getTable('chorestrike_completed_tasks');

			// Index for findByUserId queries
			if (!$table->hasIndex('ct_user_id_idx')) {
				$table->addIndex(['user_id'], 'ct_user_id_idx');
			}

			// Index for recent completions queries
			if (!$table->hasIndex('ct_user_date_idx')) {
				$table->addIndex(['user_id', 'completed_at'], 'ct_user_date_idx');
			}

			// Index for room task completions
			if (!$table->hasIndex('ct_room_task_idx')) {
				$table->addIndex(['room_task_id'], 'ct_room_task_idx');
			}
		}

		// chorestrike_user_stats indexes removed (CS-PLAN-025 / CR-SVC-08):
		// the table was dropped from the migration chain — no live code
		// referenced UserStatsService/UserStatsMapper.

		// chorestrike_availability, chorestrike_task_scopes,
		// chorestrike_task_claims and chorestrike_task_participants index
		// blocks removed (CS-PLAN-055): those tables are no longer created
		// anywhere in the chain (orphaned — no entity/mapper/writer ever
		// existed for them).

		return $schema;
	}
}
