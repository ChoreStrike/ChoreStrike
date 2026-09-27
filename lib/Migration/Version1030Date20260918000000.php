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
 * CS-PLAN-071 WP0: command receipts and the persistent financial identity.
 *
 * Creates `commands` (idempotency receipts), `wallets` (the persistent
 * house/account financial identity) and `wallet_entries` (the ledger).
 * CS-PLAN-070 WP3 extends this migration in place with `work_shares`
 * (immutable allocation evidence), `completion_revs` (append-only
 * correction audit) and `room_events` (the room history journal) together
 * with their consuming code (decision D10).
 *
 * Constraints are portable unique indexes (no DB-specific partial indexes);
 * live uniqueness for `commands` holds for non-null actors, service-side
 * enforcement under the house lock covers the rest.
 *
 * WP3 boundary: `wallets`/`wallet_entries` become the SOLE monetary
 * authority — the completion/attribution/undo aggregate is the first
 * runtime writer; entries carry the unique (wallet, source-kind, source-ID,
 * source-revision) identity; negative booked/available balances are valid
 * correction results and `reserved_minor >= 0` holds service-side.
 */
class Version1030Date20260918000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('chorestrike_commands')) {
			$table = $schema->createTable('chorestrike_commands');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('house_id', 'bigint', ['notnull' => true]);
			// Nullable: an erased receipt keeps its row but loses the actor.
			$table->addColumn('actor_house_user_id', 'bigint', ['notnull' => false]);
			$table->addColumn('command_uuid', 'string', ['notnull' => true, 'length' => 36]);
			$table->addColumn('action', 'string', ['notnull' => true, 'length' => 64]);
			// SHA-256 hex digest of the normalized payload — never tokens or
			// raw request bodies.
			$table->addColumn('payload_hash', 'string', ['notnull' => true, 'length' => 64]);
			// Bounded JSON result identity returned by replays.
			$table->addColumn('result_refs', 'json', ['notnull' => false]);
			$table->addColumn('status', 'string', ['notnull' => true, 'length' => 32, 'default' => 'committed']);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_commands');
			$table->addIndex(['house_id'], 'idx_cmd_house');
			$table->addIndex(['created_at'], 'idx_cmd_created');
			// Unique (house, actor, uuid): identical payload → replay,
			// changed payload → conflict. NULL actors are distinct in every
			// supported engine's unique index, so erased receipts cannot
			// collide with a re-created identity's live receipts.
			$table->addUniqueIndex(['house_id', 'actor_house_user_id', 'command_uuid'], 'uniq_cmd_key');
		}

		if (!$schema->hasTable('chorestrike_wallets')) {
			$table = $schema->createTable('chorestrike_wallets');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('house_id', 'bigint', ['notnull' => true]);
			// The account binding: removed on erasure, preserved on
			// departure (re-invitation reattaches the row via this key).
			$table->addColumn('nextcloud_user_id', 'string', ['notnull' => true, 'length' => 64]);
			// Signed minor-unit balances (WP0: identity only, dormant zeros).
			// reserved_minor >= 0 and safe-integer bounds are enforced
			// service-side (parent §3.1).
			$table->addColumn('booked_minor', 'bigint', ['notnull' => true, 'default' => 0]);
			$table->addColumn('reserved_minor', 'bigint', ['notnull' => true, 'default' => 0]);
			$table->addColumn('version', 'bigint', ['notnull' => true, 'default' => 0]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_wallets');
			$table->addIndex(['house_id'], 'idx_wallet_house');
			// Unique while bound; erasure deletes the row, so a recreated
			// same-UID account gets a fresh wallet id (no rebinding).
			$table->addUniqueIndex(['house_id', 'nextcloud_user_id'], 'uniq_wallet_house_user');
		}

		if (!$schema->hasTable('chorestrike_wallet_entries')) {
			$table = $schema->createTable('chorestrike_wallet_entries');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('wallet_id', 'bigint', ['notnull' => true]);
			$table->addColumn('source_kind', 'string', ['notnull' => true, 'length' => 32]);
			$table->addColumn('source_id', 'bigint', ['notnull' => true]);
			$table->addColumn('source_revision', 'bigint', ['notnull' => true, 'default' => 0]);
			// Signed delta in minor units. Default 0: the Entity setter-skip
			// trap omits a zero delta from the INSERT column list — a zero
			// delta is a real ledger identity (attribution keeping a share),
			// so the schema default is the safety net (same pattern as the
			// completed_tasks frozen-fact defaults).
			$table->addColumn('delta_minor', 'bigint', ['notnull' => true, 'default' => 0]);
			$table->addColumn('reason_code', 'string', ['notnull' => true, 'length' => 64]);
			$table->addColumn('actor_house_user_id', 'bigint', ['notnull' => false]);
			$table->addColumn('actor_wallet_id', 'bigint', ['notnull' => false]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_wallet_entries');
			$table->addIndex(['wallet_id'], 'idx_wentry_wallet');
			$table->addIndex(['actor_wallet_id'], 'idx_wentry_actor_wallet');
			// One entry per (wallet, source) revision — the idempotency key
			// of the ledger (WP3 is the first runtime writer).
			$table->addUniqueIndex(['wallet_id', 'source_kind', 'source_id', 'source_revision'], 'uniq_wentry_source');
		}

		// CS-PLAN-070 WP3 (§3.1): immutable work allocation evidence. One
		// SELECTED revision per valid completion (completed_tasks.
		// allocation_revision); superseded revisions remain evidence and are
		// never counted again. Erased subjects (wallet_id NULL) retain only
		// the non-identifying shared work facts.
		if (!$schema->hasTable('chorestrike_work_shares')) {
			$table = $schema->createTable('chorestrike_work_shares');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('completion_id', 'bigint', ['notnull' => true]);
			$table->addColumn('allocation_revision', 'bigint', ['notnull' => true]);
			$table->addColumn('wallet_id', 'bigint', ['notnull' => false]);
			$table->addColumn('share_pct', 'integer', ['notnull' => true]);
			$table->addColumn('energy_minor', 'integer', ['notnull' => true]);
			$table->addColumn('coin_minor', 'integer', ['notnull' => true]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_work_shares');
			$table->addIndex(['completion_id'], 'idx_wshare_completion');
			$table->addIndex(['wallet_id'], 'idx_wshare_wallet');
			$table->addUniqueIndex(['completion_id', 'allocation_revision', 'wallet_id'], 'uniq_wshare_key');
		}

		// CS-PLAN-070 WP3 (§3.1): append-only completion audit. The audit
		// revision matches the completion version (initial 0); rows are
		// immutable evidence — correction history never rewrote them.
		if (!$schema->hasTable('chorestrike_completion_revs')) {
			$table = $schema->createTable('chorestrike_completion_revs');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('completion_id', 'bigint', ['notnull' => true]);
			$table->addColumn('revision', 'bigint', ['notnull' => true]);
			$table->addColumn('action', 'string', ['notnull' => true, 'length' => 16]);
			$table->addColumn('allocation_revision', 'bigint', ['notnull' => false]);
			$table->addColumn('actor_house_user_id', 'bigint', ['notnull' => false]);
			$table->addColumn('reason_code', 'string', ['notnull' => false, 'length' => 64]);
			$table->addColumn('command_uuid', 'string', ['notnull' => true, 'length' => 36]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_completion_revs');
			$table->addIndex(['completion_id'], 'idx_crev_completion');
			$table->addUniqueIndex(['completion_id', 'revision'], 'uniq_crev_revision');
		}

		// CS-PLAN-070 WP3 (§3.5): the room history journal. Events replay in
		// (effective_at, id) order; the completion-source stable ID keeps one
		// total-room effect per completion (undo invalidates the effect via
		// the completion's undone_at, attribution never touches it), and the
		// command UUID identity keeps manual deltas/reset idempotent.
		if (!$schema->hasTable('chorestrike_room_events')) {
			$table = $schema->createTable('chorestrike_room_events');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('room_id', 'bigint', ['notnull' => true]);
			$table->addColumn('kind', 'string', ['notnull' => true, 'length' => 16]);
			$table->addColumn('effective_at', 'datetime', ['notnull' => true]);
			$table->addColumn('stable_id', 'string', ['notnull' => true, 'length' => 128]);
			$table->addColumn('source_completion_id', 'bigint', ['notnull' => false]);
			$table->addColumn('delta_cleanliness', 'integer', ['notnull' => false]);
			$table->addColumn('baseline_cleanliness', 'integer', ['notnull' => false]);
			$table->addColumn('baseline_monsters', 'integer', ['notnull' => false]);
			$table->addColumn('clears_last_cleaned', 'boolean', ['notnull' => false, 'default' => 0]);
			$table->addColumn('command_uuid', 'string', ['notnull' => false, 'length' => 36]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_room_events');
			$table->addIndex(['room_id', 'effective_at', 'id'], 'idx_revent_room_time');
			$table->addIndex(['room_id', 'kind', 'effective_at', 'id'], 'idx_revent_room_reset_time');
			$table->addUniqueIndex(['room_id', 'stable_id'], 'uniq_revent_stable');
		}

		return $schema;
	}
}
