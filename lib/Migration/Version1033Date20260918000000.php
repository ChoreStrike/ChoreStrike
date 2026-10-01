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
 * CS-PLAN-070 WP7 (CS-PLAN-078 §3.1): the three family-catalog feature
 * tables for the fresh-install chain. No upgrade transforms, backfills or
 * compatibility aliases — only fresh installs must work.
 *
 * Creates `chorestrike_reward_items` — owner-administered catalog wishes:
 * plain-text title/description (family-authored CONTENT, never a
 * translation key), positive `price_minor` (integer hundredths; 1250 =
 * 12.50 Coins), archived flag, optimistic version counter and nullable
 * actor membership/wallet provenance for erasure. Promised terms are
 * archived, never deleted.
 *
 * Creates `chorestrike_reward_requests` — the C4 lifecycle rows: requester
 * wallet identity (stable actor provenance surviving departure), frozen
 * title/description/price snapshot of the requested item, state, version,
 * approval provenance (current requester-role revision at approval time,
 * nullable actor membership/wallet) and the stored settlement/refund
 * ledger entry references. Pending/approved requests reserve exactly
 * their immutable positive price (mirrored in `wallets.reserved_minor`).
 * No duplicate raw UID/display-name snapshot and no funding-hold column:
 * the hold is derived (`booked < reserved`), never stored state.
 *
 * Creates `chorestrike_request_events` — the append-only audit trail
 * (unique request+revision identity) until authorized privacy erasure.
 * Entity/mapper names are RewardRequestEvent (not RequestEvent).
 */
class Version1033Date20260918000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('chorestrike_reward_items')) {
			$table = $schema->createTable('chorestrike_reward_items');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('house_id', 'bigint', ['notnull' => true]);
			$table->addColumn('title', 'string', ['notnull' => true, 'length' => 255]);
			// Family-authored free text — content, escaped at presentation
			// time (WP8); bounded to 2000 characters service-side.
			$table->addColumn('description', 'text', ['notnull' => false, 'default' => null]);
			$table->addColumn('price_minor', 'bigint', ['notnull' => true]);
			// Boolean columns follow the Version1031 platform convention —
			// NOT NULL bool columns cannot store FALSE there.
			$table->addColumn('archived', 'boolean', ['notnull' => false, 'default' => 0]);
			$table->addColumn('version', 'bigint', ['notnull' => true, 'default' => 0]);
			// Nullable actor provenance (erasure nulls them, the item stays).
			$table->addColumn('created_by_house_user_id', 'bigint', ['notnull' => false, 'default' => null]);
			$table->addColumn('created_by_wallet_id', 'bigint', ['notnull' => false, 'default' => null]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_reward_items');
			$table->addIndex(['house_id', 'archived', 'id'], 'idx_ritem_house_archived');
		}

		if (!$schema->hasTable('chorestrike_reward_requests')) {
			$table = $schema->createTable('chorestrike_reward_requests');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('house_id', 'bigint', ['notnull' => true]);
			// Requester wallet — the stable actor-wallet provenance that
			// survives departure and lets erasure find departed actors.
			$table->addColumn('wallet_id', 'bigint', ['notnull' => true]);
			$table->addColumn('item_id', 'bigint', ['notnull' => true]);
			// Frozen terms: immutable once agreed; live item changes never
			// reprice an existing request.
			$table->addColumn('title', 'string', ['notnull' => true, 'length' => 255]);
			$table->addColumn('description', 'text', ['notnull' => false, 'default' => null]);
			$table->addColumn('price_minor', 'bigint', ['notnull' => true]);
			$table->addColumn('state', 'string', ['notnull' => true, 'length' => 20, 'default' => 'pending']);
			$table->addColumn('version', 'bigint', ['notnull' => true, 'default' => 0]);
			// Approval provenance: the requester's role revision at approval
			// time plus the approving actor (nullable actor refs for erasure).
			$table->addColumn('approved_role_revision', 'bigint', ['notnull' => false, 'default' => null]);
			$table->addColumn('approved_by_house_user_id', 'bigint', ['notnull' => false, 'default' => null]);
			$table->addColumn('approved_by_wallet_id', 'bigint', ['notnull' => false, 'default' => null]);
			$table->addColumn('approved_at', 'datetime', ['notnull' => false, 'default' => null]);
			// Stored settlement/refund ledger entry identities (correction
			// verifies against the server's original entry, never client data).
			$table->addColumn('settlement_entry_id', 'bigint', ['notnull' => false, 'default' => null]);
			$table->addColumn('refund_entry_id', 'bigint', ['notnull' => false, 'default' => null]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_reward_requests');
			// Wallet-led "mine" list + per-wallet unfulfilled hooks; the owner
			// house list is covered separately below.
			$table->addIndex(['wallet_id', 'state', 'id'], 'idx_rreq_wallet_state');
			$table->addIndex(['house_id', 'state', 'id'], 'idx_rreq_house_state');
		}

		if (!$schema->hasTable('chorestrike_request_events')) {
			$table = $schema->createTable('chorestrike_request_events');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('request_id', 'bigint', ['notnull' => true]);
			$table->addColumn('revision', 'bigint', ['notnull' => true]);
			// Bounded action vocabulary (stable system codes; explanations
			// are t()-wrapped at presentation).
			$table->addColumn('action', 'string', ['notnull' => true, 'length' => 32]);
			$table->addColumn('old_state', 'string', ['notnull' => false, 'length' => 20, 'default' => null]);
			$table->addColumn('new_state', 'string', ['notnull' => true, 'length' => 20]);
			// Nullable actor membership/wallet provenance (erasure nulls).
			$table->addColumn('actor_house_user_id', 'bigint', ['notnull' => false, 'default' => null]);
			$table->addColumn('actor_wallet_id', 'bigint', ['notnull' => false, 'default' => null]);
			// Requester-role provenance where relevant (role rebase events).
			$table->addColumn('old_role_revision', 'bigint', ['notnull' => false, 'default' => null]);
			$table->addColumn('new_role_revision', 'bigint', ['notnull' => false, 'default' => null]);
			// Closed bounded reason code (max 64 characters) — never free text.
			$table->addColumn('reason_code', 'string', ['notnull' => false, 'length' => 64, 'default' => null]);
			// The settlement/refund ledger entry this event produced.
			$table->addColumn('financial_entry_id', 'bigint', ['notnull' => false, 'default' => null]);
			// The idempotency UUID of the originating command (provenance).
			$table->addColumn('source_command_uuid', 'string', ['notnull' => false, 'length' => 36, 'default' => null]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_request_events');
			// Append-only audit identity: exactly one event per request
			// revision (creation = revision 0).
			$table->addUniqueIndex(['request_id', 'revision'], 'uniq_reqev_revision');
		}

		return $schema;
	}
}
