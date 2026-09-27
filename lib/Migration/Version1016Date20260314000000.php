<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration for minigame scores table
 */
class Version1016Date20260314000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $closure
	 * @param array $openedSchema
	 */
	public function preSchemaChange(IOutput $output, Closure $closure, array $openedSchema): void {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $closure
	 * @param array $openedSchema
	 */
	public function postSchemaChange(IOutput $output, Closure $closure, array $openedSchema): void {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $closure
	 * @param ISchemaWrapper $schema
	 * @return ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $closure, array $openedSchema): ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $closure();

		if (!$schema->hasTable('chorestrike_minigame_scores')) {
			$table = $schema->createTable('chorestrike_minigame_scores');

			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 4,
			]);

			$table->addColumn('house_id', 'integer', [
				'notnull' => true,
				'length' => 4,
			]);

			$table->addColumn('user_id', 'string', [
				'notnull' => true,
				'length' => 64,
			]);

			$table->addColumn('game_id', 'string', [
				'notnull' => true,
				'length' => 32,
				'default' => 'snake',
			]);

			$table->addColumn('score', 'integer', [
				'notnull' => true,
				'length' => 6,
				'default' => 0,
			]);

			// CS-PLAN-070 WP3 (§3.1): the obsolete boost metadata is gone —
			// the arcade is scores-only, no Coin spending/consumers remain.

			$table->addColumn('created_at', 'datetime', [
				'notnull' => true,
				'default' => 'CURRENT_TIMESTAMP',
			]);

			$table->setPrimaryKey(['id'], 'mgs_pk');

			// Index for leaderboard queries by house and game
			$table->addIndex(['house_id', 'game_id', 'score'], 'mgs_hgs');

			// Index for user's own scores
			$table->addIndex(['user_id', 'game_id'], 'mgs_ug');

			// Index for house + user lookup
			$table->addIndex(['house_id', 'user_id'], 'mgs_hu');
		}

		return $schema;
	}
}
