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
 * Adds the Nextcloud task link column to room_tasks. The completed_tasks
 * sync columns live in their Version1004 create instead (the original
 * add-guard here never fired — see CS-PLAN-055).
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
				$table->addIndex(['nextcloud_task_id'], 'idx_rt_nc_task');
			}
		}

		// The completed_tasks sync columns (nextcloud_task_id /
		// synced_to_nextcloud) were first "added" here, but the table did not
		// exist yet at this point in the chain — the create in
		// Version1004Date20260228131728 carries them inline. Guard dead
		// through every fresh install; removed in CS-PLAN-055.

		return $schema;
	}
}
