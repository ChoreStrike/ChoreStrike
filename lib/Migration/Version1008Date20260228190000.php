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
 * - Team completion metadata on completed_tasks
 * (the original chorestrike_task_parts audit table was orphaned and is
 * removed from the chain — CS-PLAN-055)
 */
class Version1008Date20260228190000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// The chorestrike_task_parts table originally created here was an
		// orphan (never read or written by any mapper) — removed in
		// CS-PLAN-055 (pre-release: fresh-install chain edited in place).

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
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$output->info('Multi-user teamwork fields added successfully');
	}
}
