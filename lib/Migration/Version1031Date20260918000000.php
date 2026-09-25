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
 * CS-PLAN-072 WP1 (parent CS-PLAN-070 §3.2): occurrence timeline tables.
 *
 * Creates the immutable schedule revisions (`schedule_revs`), the durable
 * occurrence agreements (`occurrences`), their participant snapshots
 * (`occ_members`) and the agreement evidence log (`agreements`, moved into
 * WP1 by Q-072-01 Option A). WP2 (CS-PLAN-073) extends this migration in
 * place with the revisioned pause lifecycle (`pauses`) and the occurrence
 * neutral-suspension/restart provenance columns; no upgrade/backfill exists
 * for disposable pre-release data (project-rules §1).
 *
 * Public occurrence identity is the canonical `<scheduleRevisionId>:<ordinal>`
 * key — never the `occurrences.id` surrogate. Constraints are portable unique
 * indexes; service-side enforcement under the house lock covers the rest.
 */
class Version1031Date20260918000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('chorestrike_schedule_revs')) {
			$table = $schema->createTable('chorestrike_schedule_revs');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('room_task_id', 'bigint', ['notnull' => true]);
			// Revision number within the room task, starting at 1.
			$table->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 1]);
			// Instant from which this revision generates agreements; rows of
			// earlier revisions keep their identity and facts.
			$table->addColumn('effective_from', 'datetime', ['notnull' => true]);
			// Monotonic agreement frontier: inclusive local planned date
			// through which occurrences of THIS revision are agreed.
			$table->addColumn('agreed_through', 'date', ['notnull' => true]);
			// Revision-closing metadata: NULL = current revision.
			$table->addColumn('closed_at', 'datetime', ['notnull' => false]);
			// 'fixed' (calendar cadence) | 'interval' (completion-relative).
			$table->addColumn('cadence_mode', 'string', ['notnull' => true, 'length' => 16]);
			// 'day' | 'week' | 'month'.
			$table->addColumn('cadence_unit', 'string', ['notnull' => true, 'length' => 16]);
			$table->addColumn('cadence_interval', 'integer', ['notnull' => true, 'default' => 1]);
			// Original anchor local date (first occurrence; for interval
			// rules the creation anchor — real completions re-anchor through
			// occurrence rows, never by mutating this input).
			$table->addColumn('anchor_date', 'date', ['notnull' => true]);
			// JSON array of weekday names (fixed weekly rules only).
			$table->addColumn('weekdays', 'string', ['notnull' => false, 'length' => 128]);
			// 'once' | 'recurring' | 'bounded'.
			$table->addColumn('lifetime', 'string', ['notnull' => true, 'length' => 16]);
			// Inclusive active local bounds.
			$table->addColumn('active_from', 'date', ['notnull' => false]);
			$table->addColumn('active_until', 'date', ['notnull' => false]);
			// Agreed early/late window days around the planned day.
			$table->addColumn('early_window_days', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('late_window_days', 'integer', ['notnull' => true, 'default' => 0]);
			// Saved zone (IANA identifier, validated service-side).
			$table->addColumn('timezone', 'string', ['notnull' => true, 'length' => 64]);
			// Frozen generation inputs: agreed total energy and classification
			// (booleans follow the chain-wide `notnull => false` MariaDB
			// convention — NOT NULL bool columns cannot store FALSE there).
			$table->addColumn('energy', 'integer', ['notnull' => true, 'default' => 1]);
			$table->addColumn('is_public', 'boolean', ['notnull' => false, 'default' => true]);
			$table->addColumn('is_voluntary', 'boolean', ['notnull' => false, 'default' => false]);
			// Default responsibility snapshot at agreement time:
			// [{walletId, userId, role, obligated, sharePct}] (bounded JSON).
			$table->addColumn('default_members', 'json', ['notnull' => false]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_schedule_revs');
			// RF-072-WP1-17: no separate room_task_id index — the leftmost
			// prefix of uniq_srev_task already serves those lookups.
			$table->addUniqueIndex(['room_task_id', 'revision'], 'uniq_srev_task_rev');
		}

		if (!$schema->hasTable('chorestrike_occurrences')) {
			$table = $schema->createTable('chorestrike_occurrences');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('house_id', 'bigint', ['notnull' => true]);
			$table->addColumn('room_task_id', 'bigint', ['notnull' => true]);
			$table->addColumn('schedule_rev_id', 'bigint', ['notnull' => true]);
			// Stable position in the revision's complete anchored sequence.
			$table->addColumn('ordinal', 'bigint', ['notnull' => true]);
			// 'scheduled' | 'voluntary'.
			$table->addColumn('kind', 'string', ['notnull' => true, 'length' => 16, 'default' => 'scheduled']);
			$table->addColumn('planned_date', 'date', ['notnull' => true]);
			// Monday of the planned date's week in the saved zone.
			$table->addColumn('week_start', 'date', ['notnull' => true]);
			// Saved zone of this agreement (survives later house-zone edits).
			$table->addColumn('timezone', 'string', ['notnull' => true, 'length' => 64]);
			// UTC instant of the saved planned-day local midnight.
			$table->addColumn('day_start', 'datetime', ['notnull' => true]);
			// Half-open on-time window [window_start, window_end), UTC.
			$table->addColumn('window_start', 'datetime', ['notnull' => true]);
			$table->addColumn('window_end', 'datetime', ['notnull' => true]);
			// Frozen energy / public classification at agreement time.
			$table->addColumn('energy', 'integer', ['notnull' => true, 'default' => 1]);
			$table->addColumn('is_public', 'boolean', ['notnull' => false, 'default' => true]);
			// Version 0 while merely materialized; domain mutation advances it.
			$table->addColumn('version', 'bigint', ['notnull' => true, 'default' => 0]);
			// Coverage provenance: catch-up work covered this expired miss.
			$table->addColumn('covered_by_completion_id', 'bigint', ['notnull' => false]);
			// Interval provenance: the completion that anchored this successor.
			$table->addColumn('source_completion_id', 'bigint', ['notnull' => false]);
			// WP2 neutral suspension: 'none' | 'suspended'. A suspended plan
			// retains its frozen effort snapshot but consumes no live
			// planned capacity and never blocks selection.
			$table->addColumn('suspension_state', 'string', ['notnull' => true, 'length' => 16, 'default' => 'none']);
			// Suspension provenance: [{pauseGroupId, revision}] of the
			// recognized pauses that neutralized this occurrence.
			$table->addColumn('suspension_evidence', 'json', ['notnull' => false]);
			// WP2 linked interval restart: the neutral predecessor this
			// agreed restart was created from (NULL = not a restart).
			$table->addColumn('restart_of_id', 'bigint', ['notnull' => false]);
			// Current valid completion reference (NULL = unfulfilled).
			$table->addColumn('completion_id', 'bigint', ['notnull' => false]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_occurrences');
			$table->addUniqueIndex(['schedule_rev_id', 'ordinal'], 'uniq_occ_rev_ord');
			$table->addIndex(['house_id', 'planned_date'], 'idx_occ_house_date');
			$table->addIndex(['room_task_id', 'window_end'], 'idx_occ_task_window');
		}

		if (!$schema->hasTable('chorestrike_occ_members')) {
			$table = $schema->createTable('chorestrike_occ_members');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('occurrence_id', 'bigint', ['notnull' => true]);
			// Persistent WP0 wallet identity (survives departure).
			$table->addColumn('wallet_id', 'bigint', ['notnull' => true]);
			// 'responsible' | 'joint' | 'helper'.
			$table->addColumn('role', 'string', ['notnull' => true, 'length' => 16, 'default' => 'responsible']);
			$table->addColumn('obligated', 'boolean', ['notnull' => false, 'default' => true]);
			// Agreed positive share percent (facts, not authorization).
			$table->addColumn('share_pct', 'integer', ['notnull' => true, 'default' => 100]);
			// Agreed work date/zone when the appointment differs from the
			// planned day (nullable = same as the occurrence).
			$table->addColumn('work_date', 'date', ['notnull' => false]);
			$table->addColumn('work_zone', 'string', ['notnull' => false, 'length' => 64]);
			// 'active' | 'released' | 'excused'.
			$table->addColumn('disposition', 'string', ['notnull' => true, 'length' => 16, 'default' => 'active']);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_occ_members');
			$table->addUniqueIndex(['occurrence_id', 'wallet_id'], 'uniq_occm_occ_wallet');
			$table->addIndex(['wallet_id', 'work_date'], 'idx_occm_wallet_date');
		}

		if (!$schema->hasTable('chorestrike_agreements')) {
			$table = $schema->createTable('chorestrike_agreements');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('occurrence_id', 'bigint', ['notnull' => true]);
			$table->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 1]);
			// 'date' | 'window' | 'handover' | 'share' | 'catchup' | 'restart' | 'pause'.
			$table->addColumn('kind', 'string', ['notnull' => true, 'length' => 32]);
			$table->addColumn('actor_house_user_id', 'bigint', ['notnull' => false]);
			$table->addColumn('affected_wallet_ids', 'json', ['notnull' => false]);
			// Bounded old/new agreement snapshots (no medical free text).
			$table->addColumn('old_state', 'json', ['notnull' => false]);
			$table->addColumn('new_state', 'json', ['notnull' => false]);
			$table->addColumn('pause_ref', 'bigint', ['notnull' => false]);
			$table->addColumn('consent', 'json', ['notnull' => false]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_agreements');
			// RF-072-WP1-17: no separate occurrence_id index — the leftmost
			// prefix of uniq_agr_occ already serves those lookups.
			$table->addUniqueIndex(['occurrence_id', 'revision'], 'uniq_agr_occ_rev');
		}

		// CS-PLAN-073 WP2: the revisioned pause lifecycle. Append-only
		// revisions per stable pause group; the current row is a
		// projection, the revisions are the evidence.
		if (!$schema->hasTable('chorestrike_pauses')) {
			$table = $schema->createTable('chorestrike_pauses');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('house_id', 'bigint', ['notnull' => true]);
			// Stable group identity across revisions (shared by every
			// revision of one logical pause).
			$table->addColumn('pause_group_id', 'bigint', ['notnull' => true]);
			$table->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 1]);
			// 'personal' (subject wallet) | 'house'. The SQL defaults mirror
			// the entity defaults (Nextcloud setters skip same-value writes,
			// so NOT NULL columns need their own defaults).
			$table->addColumn('scope', 'string', ['notnull' => true, 'length' => 16, 'default' => 'personal']);
			// Subject wallet (NULL = house scope; no medical free text).
			$table->addColumn('subject_wallet_id', 'bigint', ['notnull' => false]);
			// Actor memberships; NULL after erasure (facts stay).
			$table->addColumn('requester_house_user_id', 'bigint', ['notnull' => false]);
			$table->addColumn('decider_house_user_id', 'bigint', ['notnull' => false]);
			// 'requested' | 'acknowledged' | 'rejected' | 'cancelled'.
			$table->addColumn('state', 'string', ['notnull' => true, 'length' => 16, 'default' => 'requested']);
			// Bounded reason code ('illness' | 'travel' | 'overload' |
			// 'other') — never free text.
			$table->addColumn('reason_code', 'string', ['notnull' => true, 'length' => 32, 'default' => 'other']);
			// Retrospective-illness audit marker (explicit owner action).
			$table->addColumn('is_retrospective_illness', 'boolean', ['notnull' => false, 'default' => false]);
			// Inclusive saved-zone date range.
			$table->addColumn('start_date', 'date', ['notnull' => true]);
			$table->addColumn('end_date', 'date', ['notnull' => true]);
			// Saved IANA zone governing the next-day shortening boundary.
			$table->addColumn('timezone', 'string', ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pk_pauses');
			$table->addUniqueIndex(['pause_group_id', 'revision'], 'uniq_pause_group_rev');
			$table->addIndex(['house_id', 'start_date', 'end_date'], 'idx_pause_house_dates');
			$table->addIndex(['subject_wallet_id'], 'idx_pause_subject');
		}

		return $schema;
	}
}
