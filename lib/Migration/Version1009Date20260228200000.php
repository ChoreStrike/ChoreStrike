<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration for Feature 7: Claiming Overdue Tasks
 *
 * Adds support for users to claim overdue tasks from other users.
 * When claimed, the task is transferred to the new user with
 * potential penalties for the original assignee and bonuses for the claimant.
 */
class Version1009Date20260228200000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Add fields to chorestrike_room_tasks for claiming mechanism
		if ($schema->hasTable('chorestrike_room_tasks')) {
			$table = $schema->getTable('chorestrike_room_tasks');

			// Whether this task was claimed from someone else
			if (!$table->hasColumn('was_claimed')) {
				$table->addColumn('was_claimed', 'boolean', [
					'notnull' => true,
					'default' => false,
					'comment' => 'Whether this task was claimed from another user',
				]);
			}

			// User ID of the original assignee before claiming
			if (!$table->hasColumn('original_assignee')) {
				$table->addColumn('original_assignee', 'string', [
					'notnull' => false,
					'length' => 64,
					'default' => null,
					'comment' => 'Original user this task was assigned to before claiming',
				]);
			}

			// When the task was claimed
			if (!$table->hasColumn('claimed_at')) {
				$table->addColumn('claimed_at', 'datetime', [
					'notnull' => false,
					'default' => null,
					'comment' => 'When the task was claimed',
				]);
			}

			// Index for claimed tasks
			if (!$table->hasIndex('idx_room_tasks_claimed')) {
				$table->addIndex(['was_claimed'], 'idx_room_tasks_claimed');
			}
		}

		// Add claiming-related fields to chorestrike_completed_tasks
		if ($schema->hasTable('chorestrike_completed_tasks')) {
			$table = $schema->getTable('chorestrike_completed_tasks');

			// Whether this completion was a claimed task
			if (!$table->hasColumn('was_claimed_completion')) {
				$table->addColumn('was_claimed_completion', 'boolean', [
					'notnull' => true,
					'default' => false,
					'comment' => 'Whether this was a claimed overdue task',
				]);
			}

			// Bonus sparks for claiming and completing overdue task
			if (!$table->hasColumn('claim_bonus_sparks')) {
				$table->addColumn('claim_bonus_sparks', 'integer', [
					'notnull' => true,
					'default' => 0,
					'comment' => 'Bonus sparks for claiming and completing overdue task',
				]);
			}

			// Days overdue when claimed
			if (!$table->hasColumn('days_overdue_when_claimed')) {
				$table->addColumn('days_overdue_when_claimed', 'integer', [
					'notnull' => false,
					'default' => null,
					'comment' => 'How many days overdue the task was when claimed',
				]);
			}

			// Index for claimed completions
			if (!$table->hasIndex('idx_completed_claimed')) {
				$table->addIndex(['was_claimed_completion'], 'idx_completed_claimed');
			}
		}

		// Create table for tracking claim history (audit log)
		if (!$schema->hasTable('chorestrike_task_claims')) {
			$table = $schema->createTable('chorestrike_task_claims');

			// Primary key
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);

			// Room task ID that was claimed
			$table->addColumn('room_task_id', 'integer', [
				'notnull' => true,
				'comment' => 'The room task that was claimed',
			]);

			// User who claimed the task
			$table->addColumn('claimed_by_user', 'string', [
				'notnull' => true,
				'length' => 64,
				'comment' => 'User who claimed the task',
			]);

			// Original assignee (if any)
			$table->addColumn('original_assignee', 'string', [
				'notnull' => false,
				'length' => 64,
				'default' => null,
				'comment' => 'Original user the task was assigned to',
			]);

			// When the task was claimed
			$table->addColumn('claimed_at', 'datetime', [
				'notnull' => true,
			]);

			// Days overdue at time of claiming
			$table->addColumn('days_overdue', 'integer', [
				'notnull' => true,
				'comment' => 'Days overdue when claimed',
			]);

			// Whether the claim resulted in completion
			$table->addColumn('was_completed', 'boolean', [
				'notnull' => true,
				'default' => false,
				'comment' => 'Whether the task was completed after claiming',
			]);

			// When the task was completed (if applicable)
			$table->addColumn('completed_at', 'datetime', [
				'notnull' => false,
				'default' => null,
			]);

			// Bonus sparks awarded
			$table->addColumn('bonus_sparks', 'integer', [
				'notnull' => true,
				'default' => 0,
			]);

			// Primary key
			$table->setPrimaryKey(['id']);

			// Indexes
			$table->addIndex(['room_task_id'], 'idx_task_claims_room_task');
			$table->addIndex(['claimed_by_user'], 'idx_task_claims_claimer');
			$table->addIndex(['claimed_at'], 'idx_task_claims_date');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$output->info('Overdue task claiming fields added successfully');
	}
}
