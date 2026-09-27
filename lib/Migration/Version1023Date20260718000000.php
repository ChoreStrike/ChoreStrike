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
 * One durable weekly-goal bell claim per house and planned week. A single
 * last-notified column cannot represent late-closing or corrected old weeks.
 */
class Version1023Date20260718000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure
	 * @param array $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('chorestrike_wgoal_receipts')) {
			$table = $schema->createTable('chorestrike_wgoal_receipts');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('house_id', 'bigint', ['notnull' => true]);
			$table->addColumn('week_start', 'date', ['notnull' => true]);
			$table->addColumn('claimed_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_weekly_goal_receipts');
			$table->addUniqueIndex(['house_id', 'week_start'], 'uniq_weekly_goal_house_week');
		}

		// The runner persists schema diffs produced by changeSchema(), so the
		// modified wrapper MUST be returned (returning null silently drops it).
		return $schema;
	}
}
