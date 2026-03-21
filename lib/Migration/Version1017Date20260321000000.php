<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration for renaming Team Sparks to Coins
 * - house_users.team_sparks → coins
 * - completed_tasks.sparks_earned → coins_earned
 */
class Version1017Date20260321000000 extends SimpleMigrationStep
{
	/**
	 * @param IOutput $output
	 * @param Closure $closure
	 * @param array $openedSchema
	 */
	public function preSchemaChange(IOutput $output, Closure $closure, array $openedSchema): void
	{
	}

	/**
	 * @param IOutput $output
	 * @param Closure $closure
	 * @param array $openedSchema
	 */
	public function postSchemaChange(IOutput $output, Closure $closure, array $openedSchema): void
	{
	}

	/**
	 * @param IOutput $output
	 * @param Closure $closure
	 * @param ISchemaWrapper $schema
	 * @return ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $closure, array $openedSchema): ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $closure();

		// Rename team_sparks → coins in house_users table
		if ($schema->hasTable('chorestrike_house_users')) {
			$table = $schema->getTable('chorestrike_house_users');
			if ($table->hasColumn('team_sparks')) {
				$table->renameColumn('team_sparks', 'coins');
			}
		}

		// Rename sparks_earned → coins_earned in completed_tasks table
		if ($schema->hasTable('chorestrike_completed_tasks')) {
			$table = $schema->getTable('chorestrike_completed_tasks');
			if ($table->hasColumn('sparks_earned')) {
				$table->renameColumn('sparks_earned', 'coins_earned');
			}
		}

		// Rename sparks_earned → coins_earned in task_participants table
		if ($schema->hasTable('chorestrike_task_participants')) {
			$table = $schema->getTable('chorestrike_task_participants');
			if ($table->hasColumn('sparks_earned')) {
				$table->renameColumn('sparks_earned', 'coins_earned');
			}
		}

		return $schema;
	}
}
