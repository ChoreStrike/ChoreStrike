<?php

declare(strict_types=1);

namespace OCA\ChoreWars\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds scheduling preferences field to house_users.
 */
class Version1003Date20260224190000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('chorewars_house_users')) {
			$table = $schema->getTable('chorewars_house_users');
			if (!$table->hasColumn('scheduling_preferences')) {
				$table->addColumn('scheduling_preferences', 'json', ['notnull' => false]);
			}
		}

		return $schema;
	}
}
