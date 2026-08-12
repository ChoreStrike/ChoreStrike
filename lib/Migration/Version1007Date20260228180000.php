<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration for Feature 5: Group Assignment
 *
 * Adds support for assigning tasks to groups instead of individual users.
 * Tasks can be claimed by any group member, and will appear in all
 * group members' dashboards.
 */
class Version1007Date20260228180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Add group assignment fields to chorestrike_room_tasks
		$table = $schema->getTable('chorestrike_room_tasks');

		// The group/circle this task is assigned to (if assigned to a group)
		if (!$table->hasColumn('assigned_to_group')) {
			$table->addColumn('assigned_to_group', 'string', [
				'notnull' => false,
				'length' => 64,
				'default' => null,
				'comment' => 'Group ID this task is assigned to',
			]);
		}

		// Type of assignment: 'user' or 'group'
		if (!$table->hasColumn('assignment_type')) {
			$table->addColumn('assignment_type', 'string', [
				'notnull' => true,
				'length' => 10,
				'default' => 'user',
				'comment' => 'Type of assignment: user or group',
			]);
		}

		// User who actually claimed/executed this task (for group assignments)
		if (!$table->hasColumn('claimed_by_user')) {
			$table->addColumn('claimed_by_user', 'string', [
				'notnull' => false,
				'length' => 64,
				'default' => null,
				'comment' => 'User who claimed this task (for group assignments)',
			]);
		}

		// Index for querying tasks by group assignment
		if (!$table->hasIndex('idx_room_tasks_group')) {
			$table->addIndex(['assigned_to_group'], 'idx_room_tasks_group');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$output->info('Group assignment fields added successfully');
	}
}
