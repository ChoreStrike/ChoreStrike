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
 * CS-PLAN-091 WP-B5 (ADR-021 decision 11, D-091-24): the two coin
 * multipliers.
 *
 * A member carries a coin factor of 1, 2 or 3; a house holds bonus periods
 * of one to three house-local days. A completion freezes both: the factor
 * of every member (`coin_factors`, wallet id to factor) and the percent of
 * its work day (`bonus_percent`, 100 or 150), so an undo or an attribution
 * reverses exactly what was paid.
 *
 * Schema only; every new column is nullable or carries a default, so no
 * populated row is rewritten. A stored completion keeps both columns
 * empty and reads as factor 1 at 100 percent, which is what it was paid.
 */
class Version1037Date20261118000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$members = $schema->getTable('chorestrike_house_users');
		if (!$members->hasColumn('coin_factor')) {
			// 1, 2 or 3; coins only.
			$members->addColumn('coin_factor', 'smallint', ['notnull' => true, 'default' => 1]);
		}

		$completions = $schema->getTable('chorestrike_completed_tasks');
		if (!$completions->hasColumn('bonus_percent')) {
			// 100 or 150: the percent of the completion's work day.
			$completions->addColumn('bonus_percent', 'smallint', ['notnull' => false]);
		}
		if (!$completions->hasColumn('coin_factors')) {
			// Wallet id to factor for every member of the house (bounded JSON).
			$completions->addColumn('coin_factors', 'json', ['notnull' => false]);
		}

		if (!$schema->hasTable('chorestrike_bonus_periods')) {
			$this->createBonusPeriods($schema);
		}

		return $schema;
	}

	/** One row per bonus period the owner started; a cancelled one keeps its row. */
	private function createBonusPeriods(ISchemaWrapper $schema): void {
		$table = $schema->createTable('chorestrike_bonus_periods');
		$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('house_id', 'bigint', ['notnull' => true]);
		// House-local days, both inclusive.
		$table->addColumn('starts_on', 'date', ['notnull' => true]);
		$table->addColumn('ends_on', 'date', ['notnull' => true]);
		$table->addColumn('created_at', 'datetime', ['notnull' => true]);
		$table->addColumn('cancelled_at', 'datetime', ['notnull' => false]);
		$table->setPrimaryKey(['id'], 'pk_bonus_periods');
		$table->addIndex(['house_id', 'ends_on'], 'idx_bonus_house_end');
	}
}
