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
	 * @param array $openedSchema
	 */
	public function preSchemaChange(IOutput $output, Closure $closure, array $openedSchema): void {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $closure
	 * @param array $openedSchema
	 */
	public function postSchemaChange(IOutput $output, Closure $closure, array $openedSchema): void {
	}

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

			// Index for scheduled date queries - commented out until column is added
			// if (!$table->hasIndex('rt_scheduled_idx')) {
			//     $table->addIndex(['scheduled_for', 'is_active'], 'rt_scheduled_idx');
			// }

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

		// chorestrike_availability indexes
		if ($schema->hasTable('chorestrike_availability')) {
			$table = $schema->getTable('chorestrike_availability');

			// Index for findByHouse queries
			if (!$table->hasIndex('av_house_id_idx')) {
				$table->addIndex(['house_id'], 'av_house_id_idx');
			}

			// Index for house + user lookup
			if (!$table->hasIndex('av_house_user_idx')) {
				$table->addIndex(['house_id', 'user_id'], 'av_house_user_idx');
			}
		}

		// chorestrike_task_scopes indexes
		if ($schema->hasTable('chorestrike_task_scopes')) {
			$table = $schema->getTable('chorestrike_task_scopes');

			// Index for findByHouseId queries
			if (!$table->hasIndex('ts_house_id_idx')) {
				$table->addIndex(['house_id'], 'ts_house_id_idx');
			}

			// Index for type queries
			if (!$table->hasIndex('ts_house_type_idx')) {
				$table->addIndex(['house_id', 'type'], 'ts_house_type_idx');
			}
		}

		// chorestrike_task_claims indexes
		if ($schema->hasTable('chorestrike_task_claims')) {
			$table = $schema->getTable('chorestrike_task_claims');

			// Index for findByRoomTask queries
			if (!$table->hasIndex('tc_room_task_idx')) {
				$table->addIndex(['room_task_id'], 'tc_room_task_idx');
			}

			// Index for findByClaimer queries
			if (!$table->hasIndex('tc_claimer_idx')) {
				$table->addIndex(['claimed_by_user'], 'tc_claimer_idx');
			}
		}

		// chorestrike_task_participants indexes
		if ($schema->hasTable('chorestrike_task_participants')) {
			$table = $schema->getTable('chorestrike_task_participants');

			// Index for findByCompletedTask queries
			if (!$table->hasIndex('tp_completed_task_idx')) {
				$table->addIndex(['completed_task_id'], 'tp_completed_task_idx');
			}

			// Index for findByUser queries
			if (!$table->hasIndex('tp_user_idx')) {
				$table->addIndex(['user_id'], 'tp_user_idx');
			}
		}

		return $schema;
	}
}
