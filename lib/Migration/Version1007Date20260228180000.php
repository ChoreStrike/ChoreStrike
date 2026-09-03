<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the overdue-claim tracking columns to room_tasks.
 *
 * The abandoned group-assignment design (assigned_to_group / assignment_type
 * + idx_room_tasks_group) was folded out of the chain — the join table
 * `chorestrike_task_assignees` is the canonical assignee store (CS-PLAN-060
 * WP1, decision D9). `claimed_by_user` stays: the live overdue-takeover
 * feature writes it.
 */
class Version1007Date20260228180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('chorestrike_room_tasks');

		// User who actually claimed/took over this task (overdue takeover)
		if (!$table->hasColumn('claimed_by_user')) {
			$table->addColumn('claimed_by_user', 'string', [
				'notnull' => false,
				'length' => 64,
				'default' => null,
				'comment' => 'User who claimed this task (overdue takeover)',
			]);
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$output->info('Claim tracking column added successfully');
	}
}
