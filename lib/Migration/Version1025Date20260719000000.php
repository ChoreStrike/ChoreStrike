<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCA\ChoreStrike\Titles\TitleCatalog;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the role-title progression columns to `chorestrike_house_users`
 * (CS-PLAN-006 Phase 1).
 *
 * - `selected_title_pack` VARCHAR(20) NULL — the pack the user chose to
 *   display (null = no pack chosen yet; default).
 * - `unlocked_titles` JSON NULL — cache of `{pack}.{tier}` strings the user
 *   has ever unlocked (drives the title-history tooltip + the unlock
 *   detection). Recomputable from stats, so safe to clear on a house reset.
 *
 * Both columns are additive and nullable: existing rows get null/default
 * values and the migration is a no-op on installs that already ran it.
 * Uses the same native `json` column + entity type as
 * `scheduling_preferences` (proven portable across SQLite/MariaDB/PostgreSQL
 * in the NC 27+ test environment).
 *
 * The postSchemaChange backfill seeds `unlocked_titles` from each existing
 * user's current stats (using catalog-default thresholds) so that the first
 * task completion after deploy does NOT re-celebrate tiers a veteran already
 * earned — the title-unlock detector treats pre-existing qualifies as
 * already-cached and stays quiet. The feature flag (`titles_enabled`)
 * defaults on; this backfill is what makes that safe to ship.
 */
class Version1025Date20260719000000 extends SimpleMigrationStep
{
	private IDBConnection $db;

	public function __construct(IDBConnection $db)
	{
		$this->db = $db;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure
	 * @param array $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?string
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('chorestrike_house_users')) {
			return null;
		}

		$table = $schema->getTable('chorestrike_house_users');

		if (!$table->hasColumn('selected_title_pack')) {
			$table->addColumn('selected_title_pack', 'string', [
				'notnull' => false,
				'length' => 20,
				'default' => null,
			]);
		}

		if (!$table->hasColumn('unlocked_titles')) {
			$table->addColumn('unlocked_titles', 'json', [
				'notnull' => false,
			]);
		}

		return null;
	}

	/**
	 * Seed the unlocked-titles cache from current stats for every existing
	 * user whose cache is still NULL, so pre-existing qualifies are not
	 * re-celebrated on the first post-deploy completion.
	 *
	 * Uses catalog-default thresholds (config overrides are not present on a
	 * first deploy). Idempotent: only rows with a NULL cache are written, so
	 * a re-run is a no-op.
	 *
	 * @param IOutput $output
	 * @param Closure $schemaClosure
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('chorestrike_house_users')) {
			return;
		}

		$select = $this->db->getQueryBuilder();
		$select->select('id', 'contribution_score', 'longest_streak', 'coins')
			->from('chorestrike_house_users')
			->where($select->expr()->isNull('unlocked_titles'));
		$result = $select->executeQuery();

		while ($row = $result->fetch()) {
			$tierIds = $this->computeBackfilledTiers($row);
			if (empty($tierIds)) {
				continue;
			}

			$update = $this->db->getQueryBuilder();
			$update->update('chorestrike_house_users')
				->set('unlocked_titles', $update->createNamedParameter(json_encode($tierIds)))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
			$update->executeStatement();
		}
		$result->closeCursor();
	}

	/**
	 * Compute the tier ids a user qualifies for from a raw stat row, using
	 * catalog-default thresholds.
	 *
	 * @param array<string, mixed> $row DB row with stat columns
	 * @return string[] `{pack}.{tier}` identifiers
	 */
	private function computeBackfilledTiers(array $row): array
	{
		$tierIds = [];
		foreach (TitleCatalog::getPackKeys() as $packKey) {
			$pack = TitleCatalog::getPack($packKey);
			$column = $this->signalColumn($pack['signal']);
			if ($column === null) {
				continue;
			}
			$value = (int)($row[$column] ?? 0);
			foreach ($pack['tiers'] as $index => $tier) {
				if ($value >= $tier['threshold']) {
					$tierIds[] = TitleCatalog::tierId($packKey, $index);
				}
			}
		}
		return $tierIds;
	}

	/**
	 * Map a progression-signal identifier to its DB column.
	 */
	private function signalColumn(string $signal): ?string
	{
		return match ($signal) {
			TitleCatalog::SIGNAL_CONTRIBUTION_SCORE => 'contribution_score',
			TitleCatalog::SIGNAL_LONGEST_STREAK => 'longest_streak',
			TitleCatalog::SIGNAL_COINS => 'coins',
			default => null,
		};
	}
}
