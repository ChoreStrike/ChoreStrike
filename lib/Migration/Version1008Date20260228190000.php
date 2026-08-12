<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration for Feature 6: Multi-User Teamwork
 *
 * Adds support for collaborative task completion:
 * - Multiple users can work on the same task
 * - Team sparks for collaborative completion
 * - Task participants tracking
 */
class Version1008Date20260228190000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Create table for tracking task participants (multi-user teamwork)
		if (!$schema->hasTable('chorestrike_task_parts')) {
			$table = $schema->createTable('chorestrike_task_parts');

			// Primary key
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);

			// Reference to the completed task record
			$table->addColumn('completed_task_id', 'integer', [
				'notnull' => true,
				'comment' => 'Reference to chorestrike_completed_tasks',
			]);

			// Participant user ID
			$table->addColumn('user_id', 'string', [
				'notnull' => true,
				'length' => 64,
				'comment' => 'User who participated in this task',
			]);

			// Sparks earned by this participant
			$table->addColumn('sparks_earned', 'integer', [
				'notnull' => true,
				'default' => 0,
				'comment' => 'Sparks earned for participation',
			]);

			// Participation type: 'primary' (claimer) or 'helper'
			$table->addColumn('participation_type', 'string', [
				'notnull' => true,
				'length' => 10,
				'default' => 'helper',
				'comment' => 'primary or helper',
			]);

			// When they joined the task
			$table->addColumn('joined_at', 'datetime', [
				'notnull' => true,
			]);

			// Primary key
			$table->setPrimaryKey(['id'], 'pk_task_parts');

			// Indexes
			$table->addIndex(['completed_task_id'], 'idx_parts_completed');
			$table->addIndex(['user_id'], 'idx_parts_user');
			$table->addUniqueIndex(['completed_task_id', 'user_id'], 'uniq_parts');
		}

		// Add team-related fields to chorestrike_completed_tasks
		if ($schema->hasTable('chorestrike_completed_tasks')) {
			$table = $schema->getTable('chorestrike_completed_tasks');

			// Whether this was a team completion
			if (!$table->hasColumn('is_team_completion')) {
				$table->addColumn('is_team_completion', 'boolean', [
					'notnull' => false,
					'default' => false,
					'comment' => 'Whether multiple users participated',
				]);
			}

			// Number of participants
			if (!$table->hasColumn('participant_count')) {
				$table->addColumn('participant_count', 'integer', [
					'notnull' => true,
					'default' => 1,
					'comment' => 'Number of users who participated',
				]);
			}

			// Team spark bonus (if applicable)
			if (!$table->hasColumn('team_spark_bonus')) {
				$table->addColumn('team_spark_bonus', 'integer', [
					'notnull' => true,
					'default' => 0,
					'comment' => 'Bonus sparks for team completion',
				]);
			}

			// Index for team completions
			if (!$table->hasIndex('idx_team_complete')) {
				$table->addIndex(['is_team_completion'], 'idx_team_complete');
			}
		}

		// Add teamwork fields to chorestrike_tasks (for tasks that require/allow teamwork)
		if ($schema->hasTable('chorestrike_tasks')) {
			$table = $schema->getTable('chorestrike_tasks');

			// Whether this task allows multiple participants
			if (!$table->hasColumn('allow_teamwork')) {
				$table->addColumn('allow_teamwork', 'boolean', [
					'notnull' => false,
					'default' => false,
					'comment' => 'Whether multiple users can participate',
				]);
			}

			// Maximum number of participants (null = unlimited)
			if (!$table->hasColumn('max_participants')) {
				$table->addColumn('max_participants', 'integer', [
					'notnull' => false,
					'default' => null,
					'comment' => 'Maximum participants allowed (null = unlimited)',
				]);
			}

			// Team spark multiplier
			if (!$table->hasColumn('team_spark_multiplier')) {
				$table->addColumn('team_spark_multiplier', 'decimal', [
					'notnull' => true,
					'precision' => 3,
					'scale' => 2,
					'default' => 1.00,
					'comment' => 'Spark multiplier per additional participant',
				]);
			}
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$output->info('Multi-user teamwork fields added successfully');
	}
}
