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
 * Migration for Phase 2 streak freeze:
 *  - adds `streak_freezes` int column (default 1) to `chorestrike_house_users`
 */
class Version1022Date20260717000000 extends SimpleMigrationStep
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

		if ($schema->hasTable('chorestrike_house_users')) {
			$table = $schema->getTable('chorestrike_house_users');
			if (!$table->hasColumn('streak_freezes')) {
				$table->addColumn('streak_freezes', 'integer', [
					'notnull' => true,
					'default' => 1,
					'unsigned' => true,
				]);
			}
		}

		return null;
	}
}
