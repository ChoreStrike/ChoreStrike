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
 * CS-PLAN-070 WP4 (Q-075-01 Option A) + WP5 (CS-PLAN-076, in-place
 * amendment per the fresh-install chain policy; Version1030 precedent).
 *
 * Creates `member_prefs` — one row per active membership:
 *
 *  - `house_user_id` is the unique active-membership reference (consent
 *    AND delivery preferences belong to the membership: departure deletes
 *    the row, erasure purges it, a rejoining account starts
 *    quiet/unconsented — no same-UID rebinding),
 *  - `version` is the shared optimistic mutation counter (bumped by EVERY
 *    preference/consent write under the house lock; stale preference
 *    drafts answer 409),
 *  - `ranking_consent_generation` carries the per-house monotonic
 *    generation identity — withdrawal and disable reset ONLY the consent
 *    columns (generation 0, cleared Monday/zone) and never touch the
 *    delivery preferences; re-consent updates the row in place. 0 = the
 *    row is ranking-inactive (a preferences-only row). The watermark
 *    lives on `chorestrike_houses.ranking_generation_seq` (WP4
 *    generation-watermark design), so monotonicity never depends on the
 *    row.
 *  - `ranking_effective_monday` (date, NULLABLE) is the saved
 *    next-Monday activation in the house zone — NULL while ranking
 *    inactive,
 *  - `ranking_saved_zone` (NULLABLE) records the IANA zone the Monday was
 *    computed in (survives later house-zone edits) — NULL while ranking
 *    inactive,
 *  - the WP5 delivery columns (C5h, all neutral defaults):
 *    `task_digest_enabled` / `org_digest_enabled` (off by default),
 *    `digest_time` (`HH:mm`, house-local, default '18:00'),
 *    `quiet_start` / `quiet_end` (nullable `HH:mm`, the range may cross
 *    midnight), `sound_enabled` / `animation_enabled` (off — consumers
 *    arrive in WP6) and `arcade_visible` / `catalog_visible` (on —
 *    optional-area persistence only, presentation stays WP6),
 *
 * plus the created/updated timestamps.
 *
 * Creates `chorestrike_digest_slots` — the daily-budget identity (C5c):
 * one slot per member and house-local day, unique
 * (house_id, house_user_id, local_day), independent of opt-in toggles so
 * retained history can enforce the 24-elapsed-hour zone-change spacing
 * (C5k). Rows are deleted only with the membership (departure/erasure).
 *
 * Creates `chorestrike_digest_changes` — the organizational queue (C5m):
 * identity/receipt rows only (object type/id/revision, category, source
 * time, target slot, disposition) — never rendered payloads or free
 * text. Unique (house_user_id, object_type, object_id, target_slot)
 * coalesces per object per slot; rows expire with a missed slot.
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
			// Ranking consent block (Q-076-03 A): reset by withdraw/disable,
			// updated in place by first consent/re-consent; 0/NULL/NULL =
			// ranking-inactive delivery-only row.
			$table->addColumn('ranking_consent_generation', 'bigint', ['notnull' => true, 'default' => 0]);
			$table->addColumn('ranking_effective_monday', 'date', ['notnull' => false, 'default' => null]);
			$table->addColumn('ranking_saved_zone', 'string', ['notnull' => false, 'length' => 64, 'default' => null]);
			// Delivery preferences (C5h): self-only, server-side. (Boolean
			// columns follow the Version1031 platform convention — NOT
			// NULL bool columns cannot store FALSE there.)
			$table->addColumn('task_digest_enabled', 'boolean', ['notnull' => false, 'default' => 0]);
			$table->addColumn('org_digest_enabled', 'boolean', ['notnull' => false, 'default' => 0]);
			$table->addColumn('digest_time', 'string', ['notnull' => true, 'length' => 5, 'default' => '18:00']);
			$table->addColumn('quiet_start', 'string', ['notnull' => false, 'length' => 5, 'default' => null]);
			$table->addColumn('quiet_end', 'string', ['notnull' => false, 'length' => 5, 'default' => null]);
			$table->addColumn('sound_enabled', 'boolean', ['notnull' => false, 'default' => 0]);
			$table->addColumn('animation_enabled', 'boolean', ['notnull' => false, 'default' => 0]);
			$table->addColumn('arcade_visible', 'boolean', ['notnull' => false, 'default' => 1]);
			$table->addColumn('catalog_visible', 'boolean', ['notnull' => false, 'default' => 1]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_member_prefs');
			$table->addIndex(['house_id'], 'idx_mpref_house');
			// One active preference row per membership (consent + delivery
			// share the singular row; only departure/erasure delete it).
			$table->addUniqueIndex(['house_id', 'house_user_id'], 'uniq_mpref_house_user');
		}

		// The per-house generation watermark (allocated under the house
		// lock; survives every consent reset).
		if ($schema->hasTable('chorestrike_houses')) {
			$houses = $schema->getTable('chorestrike_houses');
			if (!$houses->hasColumn('ranking_generation_seq')) {
				$houses->addColumn('ranking_generation_seq', 'bigint', ['notnull' => true, 'default' => 0]);
			}
		}

		if (!$schema->hasTable('chorestrike_digest_slots')) {
			$table = $schema->createTable('chorestrike_digest_slots');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('house_id', 'bigint', ['notnull' => true]);
			$table->addColumn('house_user_id', 'bigint', ['notnull' => true]);
			$table->addColumn('local_day', 'string', ['notnull' => true, 'length' => 10]);
			$table->addColumn('zone', 'string', ['notnull' => true, 'length' => 64]);
			$table->addColumn('pref_revision', 'bigint', ['notnull' => true]);
			$table->addColumn('scheduled_utc', 'datetime', ['notnull' => true]);
			$table->addColumn('window_end_utc', 'datetime', ['notnull' => true]);
			$table->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'pending']);
			$table->addColumn('dispatch_token', 'string', ['notnull' => false, 'length' => 64, 'default' => null]);
			$table->addColumn('dispatched_at', 'datetime', ['notnull' => false, 'default' => null]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_digest_slots');
			// The daily budget identity (C5c): at most one slot per member
			// and house-local day — retained across opt-out and preference
			// re-creation so the 24-elapsed-hour spacing (C5k) survives.
			$table->addUniqueIndex(['house_id', 'house_user_id', 'local_day'], 'uniq_dslot_day');
			// The five-minute due scan: pending slots by their scheduled
			// instant (status first so a late worker cannot miss the end of
			// the pending set).
			$table->addIndex(['status', 'scheduled_utc'], 'idx_dslot_status_sched');
		}

		if (!$schema->hasTable('chorestrike_digest_changes')) {
			$table = $schema->createTable('chorestrike_digest_changes');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('house_id', 'bigint', ['notnull' => true]);
			$table->addColumn('house_user_id', 'bigint', ['notnull' => true]);
			$table->addColumn('object_type', 'string', ['notnull' => true, 'length' => 32]);
			$table->addColumn('object_id', 'string', ['notnull' => true, 'length' => 64]);
			$table->addColumn('object_revision', 'bigint', ['notnull' => true, 'default' => 0]);
			$table->addColumn('category', 'string', ['notnull' => true, 'length' => 16, 'default' => 'org']);
			$table->addColumn('change_time', 'datetime', ['notnull' => true]);
			$table->addColumn('target_slot', 'string', ['notnull' => true, 'length' => 10]);
			$table->addColumn('disposition', 'string', ['notnull' => true, 'length' => 16, 'default' => 'queued']);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_digest_changes');
			// Coalescing identity (C5m): the latest relevant state per
			// object per slot — a repeated write for the same identity is
			// an update, never a second row.
			$table->addUniqueIndex(['house_user_id', 'object_type', 'object_id', 'target_slot'], 'uniq_dchg_object_slot');
			// Expiry sweep + dispatch reads per house and target slot.
			$table->addIndex(['house_id', 'target_slot'], 'idx_dchg_house_slot');
		}

		return $schema;
	}
}
