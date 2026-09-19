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
 * `work_shares` and `room_events` are added to this migration in place by
 * CS-PLAN-070 WP3 together with their consuming code (decision D10).
 *
 * Constraints are portable unique indexes (no DB-specific partial indexes);
 * live uniqueness for `commands` holds for non-null actors, service-side
 * enforcement under the house lock covers the rest. Wallets are identity
 * only in WP0: created, bound, preserved and reattached, but with dormant
 * zero balances, no API exposure and no runtime `wallet_entries` writer
 * (decision D1).
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
			// Signed delta in minor units.
			$table->addColumn('delta_minor', 'bigint', ['notnull' => true]);
			$table->addColumn('reason_code', 'string', ['notnull' => true, 'length' => 64]);
			$table->addColumn('actor_house_user_id', 'bigint', ['notnull' => false]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_wallet_entries');
			$table->addIndex(['wallet_id'], 'idx_wentry_wallet');
			// One entry per (wallet, source) revision — the idempotency key
			// of the ledger (WP3 is the first runtime writer).
			$table->addUniqueIndex(['wallet_id', 'source_kind', 'source_id', 'source_revision'], 'uniq_wentry_source');
		}

		return $schema;
	}
}
