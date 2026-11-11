<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * CS-PLAN-090 WP-F (D-090-16, D-090-20): the completion animation is on
 * by default from `0.8.0-alpha.5`, and this step switches it on once for
 * every member who already has a preference row. A stored "off" cannot be
 * told apart from a value nobody chose, so the step also overrides a
 * deliberate "off"; the member can switch it off again afterwards.
 *
 * Data only, and the first step on the retained baseline: it changes no
 * table definition and touches two columns of one table. The row version
 * goes up by one, so a settings page that was open during the update
 * gets a conflict instead of writing the old value back. Sound and every
 * other preference stay as they are. An empty table is left alone.
 *
 * A fresh install applies the schema steps only, so this step does not
 * run there; new rows get the default from `MemberPreference::createFor()`.
 */
class Version1034Date20261111000000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->update('chorestrike_member_prefs')
			->set('animation_enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->set('version', $qb->createFunction('version + 1'))
			->executeStatement();
		$output->info('Completion animation switched on in ' . $rows . ' preference rows');
	}
}
