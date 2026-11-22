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
 * CS-PLAN-091 WP-C (ADR-022 decision 3, D-091-18): the daily snapshot of
 * the live suggestion.
 *
 * One row per house and house-local day holds what the first read of that
 * day suggested for it, so today's list does not jump during the day. The
 * row is a cache without history value: the timeline job deletes old days.
 *
 * Schema only; no stored row is read or rewritten.
 */
class Version1038Date20261121000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('chorestrike_suggestion_days')) {
			$table = $schema->createTable('chorestrike_suggestion_days');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('house_id', 'bigint', ['notnull' => true]);
			// The house-local day as `Y-m-d` text: compared as a plain string on every provider.
			$table->addColumn('day_key', 'string', ['notnull' => true, 'length' => 10]);
			// Bounded JSON: one `{r, k, u, p, o}` entry per cycle placed on the day.
			$table->addColumn('placements', 'json', ['notnull' => true]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_suggestion_days');
			$table->addUniqueIndex(['house_id', 'day_key'], 'uniq_suggestion_house_day');
		}

		return $schema;
	}
}
