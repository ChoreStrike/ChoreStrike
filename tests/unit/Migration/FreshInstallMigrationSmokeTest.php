<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Fresh-install migration smoke test (CS-PLAN-020 Phase 2 / D2).
 *
 * Runs every migration's persisted `changeSchema()` step against a single
 * stateful recording schema, in migration order, the way the Nextcloud
 * migration runner applies a fresh install. It guards the recurring defect
 * class surfaced by the 2026-07-25 review cluster:
 *
 *  - `changeSchema(...): ?ISchemaWrapper { …; return null; }` — the runner
 *    only persists schema diffs returned from `changeSchema()`, so a `null`
 *    return silently dropped the step and left fresh installs missing tables
 *    / columns (10 historical migrations had exactly this bug).
 *  - columns added in `preSchemaChange()` (void) instead of `changeSchema()`
 *    (Version1018–1020 added `task_tier`/`coin_multiplier`/`flex_days`/
 *    `ideal_due_date` there and were never persisted).
 *
 * The stateful {@see RecordingSchema} simulates a clean database that
 * accumulates tables/columns as earlier migrations create them, so later
 * `changeSchema` steps see the tables they expect (mirrors runner ordering).
 *
 * @see docs/plans/active/cs-plan-020-release-quality-gate-cluster.md Phase 2
 */
class FreshInstallMigrationSmokeTest extends TestCase {
	/**
	 * Run the full persisted migration chain and assert (a) no step returns
	 * null, and (b) the resulting schema carries the tables/columns whose
	 * absence historically broke fresh installs.
	 */
	public function testFullChainProducesExpectedSchemaOnFreshInstall(): void {
		$schema = new RecordingSchema();
		$output = $this->createMock(IOutput::class);

		foreach ($this->orderedMigrationClasses() as $class) {
			$migration = $this->instantiateMigration($class);

			// Only `changeSchema()` diffs are persisted by the runner; steps
			// that only define preSchemaChange/postSchemaChange (e.g. the
			// void Version1018–1020 column adds) contribute nothing to a fresh
			// schema and are intentionally skipped.
			if (!method_exists($migration, 'changeSchema')) {
				continue;
			}

			$result = $migration->changeSchema($output, fn (): ISchemaWrapper => $schema, []);

			$this->assertNotNull(
				$result,
				$class . '::changeSchema() returned null — the Nextcloud runner discards null diffs, '
				. 'so a fresh install would silently lose this step'
			);
		}

		// Core tables must exist after a fresh chain.
		$this->assertTrue($schema->hasTable('chorestrike_tasks'), 'chorestrike_tasks missing');
		$this->assertTrue($schema->hasTable('chorestrike_room_tasks'), 'chorestrike_room_tasks missing');
		$this->assertTrue($schema->hasTable('chorestrike_house_users'), 'chorestrike_house_users missing');

		// The four columns Version1027 re-adds via changeSchema() — absent on
		// every historical broken fresh install. Asserting them proves this
		// test would have caught the original defect. (The `coin_multiplier`
		// fourth column retired with the WP3 wallet cutover.)
		$this->assertTrue($schema->hasColumn('chorestrike_tasks', 'task_tier'));
		$this->assertTrue($schema->hasColumn('chorestrike_tasks', 'flex_days'));
		$this->assertTrue($schema->hasColumn('chorestrike_room_tasks', 'ideal_due_date'));
		$this->assertTrue($schema->hasColumn('chorestrike_room_tasks', 'claimed_source_completion_id'));

		// WP3 §3.1: exact minor-unit contribution and the work/audit/journal
		// tables are part of the fresh chain.
		$this->assertTrue($schema->hasColumn('chorestrike_house_users', 'contribution_minor'));
		$this->assertTrue($schema->hasTable('chorestrike_work_shares'), 'work_shares table missing');
		$this->assertTrue($schema->hasTable('chorestrike_completion_revs'), 'completion_revs table missing');
		$this->assertTrue($schema->hasTable('chorestrike_room_events'), 'room_events table missing');
		$this->assertTrue($schema->hasTable('chorestrike_wgoal_receipts'), 'weekly goal receipt table missing');
		$this->assertLessThanOrEqual(30, strlen('oc_chorestrike_wgoal_receipts'), 'Nextcloud-prefixed receipt table name exceeds 30 characters');
		$this->assertTrue($schema->hasColumn('chorestrike_wallet_entries', 'actor_wallet_id'));
		$this->assertTrue($schema->getTable('chorestrike_room_events')->hasIndex('idx_revent_room_reset_time'));
	}

	/**
	 * Isolated guard for the canonical historically-broken step: Version1027
	 * must add the historically missing columns through changeSchema() (not
	 * preSchemaChange), and the WP3 cutover must keep the retired freeze/
	 * multiplier columns out of a fresh install.
	 */
	public function testVersion1027AddsHistoricallyMissingColumnsViaChangeSchema(): void {
		$schema = new RecordingSchema();
		// Version1027 only adds columns to tables that already exist, so seed
		// the three target tables (as earlier migrations would on a fresh run).
		$schema->createTable('chorestrike_tasks');
		$schema->createTable('chorestrike_room_tasks');
		$schema->createTable('chorestrike_house_users');

		$migration = new \OCA\ChoreStrike\Migration\Version1027Date20260725000000();
		$result = $migration->changeSchema(
			$this->createMock(IOutput::class),
			fn (): ISchemaWrapper => $schema,
			[]
		);

		$this->assertNotNull($result, 'Version1027 must return the schema so the runner persists its diff');
		$this->assertTrue($schema->hasColumn('chorestrike_tasks', 'task_tier'));
		$this->assertTrue($schema->hasColumn('chorestrike_tasks', 'flex_days'));
		$this->assertTrue($schema->hasColumn('chorestrike_room_tasks', 'ideal_due_date'));
		// Retired with the WP3 wallet cutover (Q-074-01 Option A).
		$this->assertFalse($schema->hasColumn('chorestrike_house_users', 'coin_multiplier'));
		$this->assertFalse($schema->hasColumn('chorestrike_house_users', 'last_freeze_date'));
	}

	/**
	 * Folded-schema invariant (CS-PLAN-060 WP1 §3.1): after a fresh chain the
	 * canonical representations are in place — unique instance index, no
	 * legacy/writer-less columns — and the claim-feature surface stays.
	 */
	public function testFreshChainProducesFoldedCanonicalSchema(): void {
		$schema = new RecordingSchema();
		$output = $this->createMock(IOutput::class);

		foreach ($this->orderedMigrationClasses() as $class) {
			$migration = $this->instantiateMigration($class);
			if (!method_exists($migration, 'changeSchema')) {
				continue;
			}
			$migration->changeSchema($output, fn (): ISchemaWrapper => $schema, []);
		}

		// Bootstrap race safety (CR-053-07): one house per instance.
		$this->assertTrue(
			$schema->getTable('chorestrike_houses')->hasUniqueIndex('idx_house_inst'),
			'houses.nextcloud_instance_id must carry a UNIQUE index'
		);

		// Dropped legacy/writer-less columns must be gone.
		$absentColumns = [
			['chorestrike_tasks', 'is_private'],
			['chorestrike_room_tasks', 'assigned_to_user_id'],
			['chorestrike_room_tasks', 'nextcloud_task_id'],
			['chorestrike_room_tasks', 'assignment_type'],
			['chorestrike_room_tasks', 'assigned_to_group'],
			['chorestrike_room_tasks', 'assigned_by_user_id'],
			['chorestrike_room_tasks', 'assigned_at'],
			['chorestrike_completed_tasks', 'nextcloud_task_id'],
			['chorestrike_completed_tasks', 'synced_to_nextcloud'],
			// WP3 cutover: the completion row is work evidence, not money.
			['chorestrike_completed_tasks', 'coins_earned'],
			['chorestrike_completed_tasks', 'was_combo'],
			['chorestrike_house_users', 'coins'],
			['chorestrike_house_users', 'streak_freezes'],
			['chorestrike_house_users', 'coin_multiplier'],
			['chorestrike_house_users', 'last_freeze_date'],
			['chorestrike_minigame_scores', 'boosts_used'],
		];
		foreach ($absentColumns as [$table, $column]) {
			$this->assertFalse(
				$schema->hasColumn($table, $column),
				"$table.$column must be folded out of the fresh-install schema"
			);
		}

		// Canonical representations and the live claim feature stay intact.
		$this->assertTrue($schema->hasTable('chorestrike_task_assignees'), 'assignee join table missing');
		$this->assertTrue($schema->hasColumn('chorestrike_tasks', 'visibility_type'));
		$this->assertTrue($schema->hasColumn('chorestrike_tasks', 'owner_user_id'));
		$this->assertTrue($schema->hasColumn('chorestrike_room_tasks', 'claimed_by_user'));
		$this->assertTrue($schema->hasColumn('chorestrike_room_tasks', 'original_assignee'));

		// No index on a dropped column survives.
		$this->assertFalse(
			$schema->getTable('chorestrike_room_tasks')->hasIndex('idx_room_tasks_assigned'),
			'idx_room_tasks_assigned indexed the dropped assigned_to_user_id column'
		);
		$this->assertFalse(
			$schema->getTable('chorestrike_room_tasks')->hasIndex('rt_user_id_idx'),
			'rt_user_id_idx indexed the dropped assigned_to_user_id column'
		);
		$this->assertFalse(
			$schema->getTable('chorestrike_completed_tasks')->hasIndex('idx_ct_sync'),
			'idx_ct_sync indexed the dropped synced_to_nextcloud column'
		);
		$this->assertFalse(
			$schema->getTable('chorestrike_room_tasks')->hasIndex('idx_room_tasks_group'),
			'idx_room_tasks_group indexed the dropped assigned_to_group column'
		);

		// CS-PLAN-071 WP0 (Q-70-01/02, parent §3.2): tombstone columns, the
		// re-keyed room-task uniqueness and the frozen WP0 identity/receipt
		// tables are part of the fresh-install chain.
		$this->assertTrue($schema->hasColumn('chorestrike_floors', 'deleted_at'), 'floors.deleted_at missing');
		$this->assertTrue($schema->hasColumn('chorestrike_rooms', 'deleted_at'), 'rooms.deleted_at missing');
		$this->assertTrue($schema->hasColumn('chorestrike_room_tasks', 'deleted_at'), 'room_tasks.deleted_at missing');
		$this->assertTrue($schema->hasColumn('chorestrike_room_tasks', 'deleted_token'), 'room_tasks.deleted_token missing');
		$this->assertTrue(
			$schema->getTable('chorestrike_room_tasks')->hasUniqueIndex('uniq_rt'),
			'room_tasks must keep the (room, task, deleted_token) unique index'
		);

		$this->assertTrue($schema->hasColumn('chorestrike_houses', 'state_version'), 'houses.state_version missing');
		$this->assertTrue($schema->hasColumn('chorestrike_houses', 'timezone_version'), 'houses.timezone_version missing');
		$this->assertTrue($schema->hasColumn('chorestrike_houses', 'catalog_enabled'), 'houses.catalog_enabled missing');
		$this->assertTrue($schema->hasColumn('chorestrike_houses', 'ranking_enabled'), 'houses.ranking_enabled missing');
		$this->assertTrue($schema->hasColumn('chorestrike_house_users', 'wallet_id'), 'house_users.wallet_id missing');

		$this->assertTrue($schema->hasTable('chorestrike_commands'), 'commands receipt table missing');
		$this->assertTrue($schema->hasTable('chorestrike_wallets'), 'wallets identity table missing');
		$this->assertTrue($schema->hasTable('chorestrike_wallet_entries'), 'wallet_entries ledger table missing');
		$this->assertTrue(
			$schema->getTable('chorestrike_commands')->hasUniqueIndex('uniq_cmd_key'),
			'commands must carry the (house, actor, uuid) unique index'
		);
		$this->assertTrue(
			$schema->getTable('chorestrike_wallets')->hasUniqueIndex('uniq_wallet_house_user'),
			'wallets must carry the (house, binding) unique index'
		);
		$this->assertTrue(
			$schema->getTable('chorestrike_wallet_entries')->hasUniqueIndex('uniq_wentry_source'),
			'wallet_entries must carry the ledger idempotency index'
		);
	}

	/**
	 * @return list<class-string>
	 */
	private function orderedMigrationClasses(): array {
		$dir = __DIR__ . '/../../../lib/Migration';
		$files = glob($dir . '/Version*.php') ?: [];
		$classes = [];
		foreach ($files as $file) {
			$classes[] = 'OCA\\ChoreStrike\\Migration\\' . basename($file, '.php');
		}
		sort($classes);
		return $classes;
	}

	/**
	 * Instantiate a migration via reflection, injecting mocks for constructor
	 * dependencies (migrations variably take IDBConnection or nothing).
	 */
	private function instantiateMigration(string $class): object {
		$reflection = new \ReflectionClass($class);
		$constructor = $reflection->getConstructor();
		if ($constructor === null) {
			return new $class();
		}

		$args = [];
		foreach ($constructor->getParameters() as $param) {
			$type = $param->getType();
			$typeName = $type !== null && !$type->isBuiltin() ? (string)$type : null;

			if ($typeName !== null && (is_a($typeName, IDBConnection::class, true) || $typeName === IDBConnection::class)) {
				$args[] = $this->createMock(IDBConnection::class);
			} elseif ($param->isDefaultValueAvailable()) {
				$args[] = $param->getDefaultValue();
			} elseif ($typeName !== null) {
				$args[] = $this->createMock($typeName);
			} else {
				$args[] = null;
			}
		}

		return $reflection->newInstanceArgs($args);
	}
}

/**
 * Stateful recording schema: tables created by earlier changeSchema steps
 * become visible to later ones (mirrors the migration runner's ordering on a
 * fresh install). Implements the persisted ISchemaWrapper contract; unknown
 * methods are swallowed via {@see __call()} so the smoke test never crashes
 * on a schema API detail.
 */
class RecordingSchema implements ISchemaWrapper {
	/** @var array<string, RecordingTable> */
	private array $tables = [];

	public function hasTable(string $tableName): bool {
		return isset($this->tables[$tableName]);
	}

	public function getTable(string $tableName): RecordingTable {
		return $this->tables[$tableName] ??= new RecordingTable($tableName);
	}

	public function createTable(string $tableName): RecordingTable {
		return $this->tables[$tableName] = new RecordingTable($tableName);
	}

	public function dropTable(string $tableName): void {
		unset($this->tables[$tableName]);
	}

	/**
	 * @return RecordingTable[]
	 */
	public function getTables(): array {
		return array_values($this->tables);
	}

	public function hasColumn(string $tableName, string $columnName): bool {
		return isset($this->tables[$tableName]) && $this->tables[$tableName]->hasColumn($columnName);
	}

	/**
	 * @return mixed
	 */
	public function __call(string $name, array $arguments) {
		return $this;
	}
}

/**
 * Permissive recording table: records added/renamed columns and drops, and
 * swallows any other schema-table method via {@see __call()}.
 */
class RecordingTable {
	/** @var array<string, bool> */
	private array $columns = [];

	/** @var array<string, array{name: string, unique: bool}> Indexed by index name */
	private array $indexes = [];

	public function __construct(
		private string $name,
	) {
	}

	public function addColumn(string $name, string $type = '', array $options = []): self {
		$this->columns[$name] = true;
		return $this;
	}

	public function changeColumn(string $name, array $options = []): self {
		$this->columns[$name] = true;
		return $this;
	}

	public function renameColumn(string $oldName, string $newName): self {
		if (isset($this->columns[$oldName])) {
			unset($this->columns[$oldName]);
			$this->columns[$newName] = true;
		}
		return $this;
	}

	public function dropColumn(string $name): self {
		unset($this->columns[$name]);
		return $this;
	}

	public function hasColumn(string $name): bool {
		return isset($this->columns[$name]);
	}

	public function getName(): string {
		return $this->name;
	}

	/**
	 * @return list<string>
	 */
	public function getColumns(): array {
		return array_keys($this->columns);
	}

	public function setPrimaryKey(array $columnNames, ?string $indexName = null): self {
		return $this;
	}

	public function setUniqueConstraint(array $columnNames, ?string $indexName = null): self {
		return $this;
	}

	public function setIndex(array $columnNames, ?string $indexName = null): self {
		return $this;
	}

	public function addIndex(array $columnNames, ?string $indexName = null): self {
		$this->indexes[$indexName ?? implode('_', $columnNames)] = [
			'name' => $indexName ?? implode('_', $columnNames),
			'unique' => false,
		];
		return $this;
	}

	public function addUniqueIndex(array $columnNames, ?string $indexName = null): self {
		$this->indexes[$indexName ?? implode('_', $columnNames)] = [
			'name' => $indexName ?? implode('_', $columnNames),
			'unique' => true,
		];
		return $this;
	}

	public function hasIndex(string $name): bool {
		return isset($this->indexes[$name]);
	}

	public function hasUniqueIndex(string $name): bool {
		return isset($this->indexes[$name]) && $this->indexes[$name]['unique'];
	}

	/**
	 * @return mixed
	 */
	public function __call(string $name, array $arguments) {
		return $this;
	}
}
