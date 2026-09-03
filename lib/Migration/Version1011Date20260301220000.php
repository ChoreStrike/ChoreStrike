<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the index for overdue-claim lookups on room_tasks.
 *
 * The writer-less assignment-tracking columns (assigned_by_user_id /
 * assigned_at) and the index on the dropped assigned_to_user_id column were
 * folded out of the chain (CS-PLAN-060 WP1, decision D9) — the join table's
 * assigned_by/assigned_at are canonical.
 */
class Version1011Date20260301220000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable('chorestrike_room_tasks')) {
			$table = $schema->getTable('chorestrike_room_tasks');

			if (!$table->hasIndex('idx_room_tasks_claimed')) {
				$table->addIndex(['claimed_by_user'], 'idx_room_tasks_claimed');
			}
		}

		// The completed_by_user_id / points_earned / bonus_points columns this
		// migration used to add to chorestrike_completed_tasks were removed
		// from the chain (CR-SVC-45, pre-release fresh-install edit per
		// project-rules §1): no code ever read or wrote them, and because the
		// CompletedTask entity does not declare them, every SELECT * that maps
		// a full row threw BadFunctionCallException on the real DB.

		// The runner persists schema diffs produced by changeSchema(), so the
		// modified wrapper MUST be returned (returning null silently drops it).
		return $schema;
	}
}
