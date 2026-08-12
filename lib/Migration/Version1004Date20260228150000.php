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
 * Add scheduling mode fields for dynamic vs static task scheduling
 */
class Version1004Date20260228150000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('chorestrike_tasks')) {
			$table = $schema->getTable('chorestrike_tasks');

			// Add scheduling_mode field
			if (!$table->hasColumn('scheduling_mode')) {
				$table->addColumn('scheduling_mode', 'string', [
					'notnull' => true,
					'default' => 'dynamic',
					'length' => 16,
				]);
			}

			// Add fixed_schedule_time field (time only, e.g. 18:00:00)
			if (!$table->hasColumn('fixed_schedule_time')) {
				$table->addColumn('fixed_schedule_time', 'time', [
					'notnull' => false,
					'default' => null,
				]);
			}

			// Add fixed_schedule_days field (JSON array of weekdays)
			if (!$table->hasColumn('fixed_schedule_days')) {
				$table->addColumn('fixed_schedule_days', 'string', [
					'notnull' => false,
					'default' => null,
					'length' => 255,
				]);
			}
		}

		return $schema;
	}
}
