<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds visibility_type and owner_user_id to chorestrike_tasks (CS-PLAN-005 Phase 1).
 *
 * - visibility_type VARCHAR(20) NOT NULL DEFAULT 'public' — replaces the boolean
 *   is_private with a richer enum: 'public', 'private', 'admin-only'.
 * - owner_user_id VARCHAR(64) NULL — tracks the creator of private tasks so
 *   personal reminders are truly creator-only.
 * - Data back-fill: sets visibility_type='private' for every row where is_private=1.
 *   owner_user_id is left NULL for back-filled rows (legacy private tasks without
 *   a known creator).
 * - Composite index on (visibility_type, owner_user_id) to support the
 *   visibility-filter and private-by-owner queries.
 */
class Version1024Date20260719000000 extends SimpleMigrationStep {
	private IDBConnection $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure
	 * @param array $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('chorestrike_tasks')) {
			return null;
		}

		$table = $schema->getTable('chorestrike_tasks');
		$changed = false;

		if (!$table->hasColumn('visibility_type')) {
			$table->addColumn('visibility_type', 'string', [
				'notnull' => true,
				'length' => 20,
				'default' => 'public',
			]);
			$changed = true;
		}

		if (!$table->hasColumn('owner_user_id')) {
			$table->addColumn('owner_user_id', 'string', [
				'notnull' => false,
				'length' => 64,
				'default' => null,
			]);
			$changed = true;
		}

		if (!$table->hasIndex('idx_tasks_visibility')) {
			$table->addIndex(['visibility_type', 'owner_user_id'], 'idx_tasks_visibility');
			$changed = true;
		}

		// The runner persists schema diffs produced by changeSchema(), so the
		// modified wrapper MUST be returned (returning null silently drops it).
		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('chorestrike_tasks')) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('visibility_type', $qb->createNamedParameter('private'))
			->where($qb->expr()->eq('is_private', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
