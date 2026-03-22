<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;

/**
 * Migration for renaming Team Sparks to Coins
 * - house_users.team_sparks → coins
 * - completed_tasks.sparks_earned → coins_earned
 * - task_participants.sparks_earned → coins_earned
 *
 * Uses direct SQL for column renames (Doctrine DBAL 4 compatibility)
 */
class Version1017Date20260321000000 extends SimpleMigrationStep
{
	/**
	 * @param IOutput $output
	 * @param Closure $closure
	 * @param array $openedSchema
	 */
	public function changeSchema(IOutput $output, Closure $closure, array $openedSchema): ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $closure();

		// No schema changes here - we use postSchemaChange for direct SQL renames
		// This is required for Doctrine DBAL 4 compatibility

		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $closure
	 * @param array $openedSchema
	 */
	public function postSchemaChange(IOutput $output, Closure $closure, array $openedSchema): void
	{
		/** @var IDBConnection $connection */
		$connection = \OC::$server->get(IDBConnection::class);

		// Rename team_sparks → coins in house_users table
		$this->renameColumn($connection, $output, 'chorestrike_house_users', 'team_sparks', 'coins', 'INT DEFAULT 0 NOT NULL');

		// Rename sparks_earned → coins_earned in completed_tasks table
		$this->renameColumn($connection, $output, 'chorestrike_completed_tasks', 'sparks_earned', 'coins_earned', 'INT DEFAULT 0 NOT NULL');

		// Rename sparks_earned → coins_earned in task_participants table
		$this->renameColumn($connection, $output, 'chorestrike_task_participants', 'sparks_earned', 'coins_earned', 'INT DEFAULT 0 NOT NULL');
	}

	/**
	 * Rename a column using direct SQL (MySQL/MariaDB/SQLite compatible)
	 *
	 * @param IDBConnection $connection
	 * @param IOutput $output
	 * @param string $tableName
	 * @param string $oldColumn
	 * @param string $newColumn
	 * @param string $definition
	 */
	private function renameColumn(IDBConnection $connection, IOutput $output, string $tableName, string $oldColumn, string $newColumn, string $definition): void
	{
		$schema = $connection->createSchema();

		// Check if table and old column exist
		if (!$schema->hasTable($tableName)) {
			$output->info("Table $tableName does not exist, skipping rename");
			return;
		}

		$table = $schema->getTable($tableName);
		if (!$table->hasColumn($oldColumn)) {
			$output->info("Column $oldColumn does not exist in $tableName, skipping rename");
			return;
		}

		// Use direct SQL for column rename (Doctrine DBAL 4 compatible)
		$dbPlatform = $connection->getDatabasePlatform()->getName();

		if ($dbPlatform === 'sqlite') {
			// SQLite requires different syntax
			$sql = "ALTER TABLE `{$tableName}` RENAME COLUMN `{$oldColumn}` TO `{$newColumn}`";
		} else {
			// MySQL/MariaDB syntax
			$sql = "ALTER TABLE `{$tableName}` CHANGE `{$oldColumn}` `{$newColumn}` {$definition}";
		}

		$connection->executeStatement($sql);
		$output->info("Renamed column $oldColumn → $newColumn in $tableName");
	}
}
