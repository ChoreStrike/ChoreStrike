<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add task_tier field and map from priority
 */
class Version1018Date20260321000000 extends SimpleMigrationStep {
	/** @var IDBConnection */
	private $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure
	 * @param array $options
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Add task_tier column with default 'silver'
		if ($schema->hasTable('chorestrike_tasks')) {
			$table = $schema->getTable('chorestrike_tasks');

			if (!$table->hasColumn('task_tier')) {
				$table->addColumn('task_tier', 'string', [
					'notnull' => true,
					'length' => 10,
					'default' => 'silver',
				]);
			}
		}
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// Map existing priority values to task_tier
		// priority 1 -> bronze, 2 -> silver, 3 -> gold
		$qb = $this->db->getQueryBuilder();

		// Update bronze tasks (priority 1)
		$qb->update('chorestrike_tasks')
			->set('task_tier', $qb->createNamedParameter('bronze', IQueryBuilder::PARAM_STR))
			->where($qb->expr()->eq('priority', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		// Update silver tasks (priority 2) - already default, but ensure consistency
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('task_tier', $qb->createNamedParameter('silver', IQueryBuilder::PARAM_STR))
			->where($qb->expr()->eq('priority', $qb->createNamedParameter(2, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		// Update gold tasks (priority 3)
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('task_tier', $qb->createNamedParameter('gold', IQueryBuilder::PARAM_STR))
			->where($qb->expr()->eq('priority', $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$output->info('Migrated priority to task_tier: bronze (1), silver (2), gold (3)');
	}
}
