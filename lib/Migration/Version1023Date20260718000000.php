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
 * Migration for Phase 4 weekly-goal-notification dedup:
 *  - adds `weekly_goal_notified_week` nullable string column to
 *    `chorestrike_houses`. Stores the ISO week-start (Y-m-d) for which the
 *    "weekly goal reached" notification has already been dispatched to the
 *    household. The conditional UPDATE in NotificationService::dispatch
 *    (or a caller) uses it to ensure exactly one notification per house per
 *    week even when multiple concurrent completions cross the threshold.
 */
class Version1023Date20260718000000 extends SimpleMigrationStep
{
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure
	 * @param array $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?string
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('chorestrike_houses')) {
			$table = $schema->getTable('chorestrike_houses');
			if (!$table->hasColumn('weekly_goal_notified_week')) {
				$table->addColumn('weekly_goal_notified_week', 'string', [
					'notnull' => false,
					'length' => 10,
					'default' => null,
				]);
			}
		}

		return null;
	}
}
