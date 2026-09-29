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
 * CS-PLAN-070 WP4 (Q-075-01 Option A): the per-member preference row with
 * the RANKING-CONSENT columns only.
 *
 * Creates `member_prefs` — one row per consenting active membership:
 *
 *  - `house_user_id` is the unique active-membership reference (consent
 *    belongs to the membership: departure deletes the row, erasure purges
 *    it, a rejoining account starts quiet/unconsented — no same-UID
 *    rebinding),
 *  - `version` is the optimistic mutation counter,
 *  - `ranking_consent_generation` carries the per-house monotonic
 *    generation identity — withdrawal and disable invalidate the
 *    generation, re-consent starts a fresh one effective the NEXT full
 *    week (never retroactive),
 *  - `ranking_effective_monday` (date) is the saved next-Monday activation
 *    in the house zone,
 *  - `ranking_saved_zone` records the IANA zone the Monday was computed in
 *    (survives later house-zone edits),
 *
 * plus the created/updated timestamps.
 *
 * The generation watermark lives on `chorestrike_houses`
 * (`ranking_generation_seq`, allocated under the house lock): consent
 * rows are DELETED on withdrawal/departure/erasure/disable, so the
 * maximum-row heuristic cannot survive — a withdrawn generation must
 * never be reused.
 *
 * WP5 amends this migration IN PLACE for the digest/quiet-hours/sound
 * preference columns (fresh-install chain policy, Version1030 in-place
 * extension precedent); Version1033 stays reserved for WP7.
 */
class Version1032Date20260918000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('chorestrike_member_prefs')) {
			$table = $schema->createTable('chorestrike_member_prefs');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('house_id', 'bigint', ['notnull' => true]);
			$table->addColumn('house_user_id', 'bigint', ['notnull' => true]);
			$table->addColumn('version', 'bigint', ['notnull' => true, 'default' => 0]);
			$table->addColumn('ranking_consent_generation', 'bigint', ['notnull' => true, 'default' => 1]);
			$table->addColumn('ranking_effective_monday', 'date', ['notnull' => true]);
			$table->addColumn('ranking_saved_zone', 'string', ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_member_prefs');
			$table->addIndex(['house_id'], 'idx_mpref_house');
			// One active preference row per membership (consent is personal
			// and singular; withdrawal deletes the row).
			$table->addUniqueIndex(['house_id', 'house_user_id'], 'uniq_mpref_house_user');
		}

		// The per-house generation watermark (allocated under the house
		// lock; survives the deletion of every consent row).
		if ($schema->hasTable('chorestrike_houses')) {
			$houses = $schema->getTable('chorestrike_houses');
			if (!$houses->hasColumn('ranking_generation_seq')) {
				$houses->addColumn('ranking_generation_seq', 'bigint', ['notnull' => true, 'default' => 0]);
			}
		}

		return $schema;
	}
}
