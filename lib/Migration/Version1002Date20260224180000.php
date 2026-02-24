<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds Nextcloud Task Sync fields for bidirectional completion sync.
 */
class Version1002Date20260224180000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Add nextcloud_task_id to room_tasks for linking
		if ($schema->hasTable('chorestrike_room_tasks')) {
			$table = $schema->getTable('chorestrike_room_tasks');
			if (!$table->hasColumn('nextcloud_task_id')) {
				$table->addColumn('nextcloud_task_id', 'bigint', ['notnull' => false]);
				$table->addIndex(['nextcloud_task_id'], 'idx_room_tasks_nextcloud');
			}
		}

		// Add sync fields to completed_tasks for tracking
		if ($schema->hasTable('chorestrike_completed_tasks')) {
			$table = $schema->getTable('chorestrike_completed_tasks');
			if (!$table->hasColumn('nextcloud_task_id')) {
				$table->addColumn('nextcloud_task_id', 'bigint', ['notnull' => false]);
				$table->addIndex(['nextcloud_task_id'], 'idx_completed_nextcloud');
			}
			if (!$table->hasColumn('synced_to_nextcloud')) {
				$table->addColumn('synced_to_nextcloud', 'boolean', ['notnull' => true, 'default' => false]);
				$table->addIndex(['synced_to_nextcloud'], 'idx_completed_synced');
			}
		}

		return $schema;
	}
}
