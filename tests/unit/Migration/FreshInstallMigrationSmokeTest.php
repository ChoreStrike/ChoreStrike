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
		// test would have caught the original defect.
		$this->assertTrue($schema->hasColumn('chorestrike_tasks', 'task_tier'));
		$this->assertTrue($schema->hasColumn('chorestrike_tasks', 'flex_days'));
		$this->assertTrue($schema->hasColumn('chorestrike_room_tasks', 'ideal_due_date'));
		$this->assertTrue($schema->hasColumn('chorestrike_house_users', 'coin_multiplier'));
	}

	/**
	 * Isolated guard for the canonical historically-broken step: Version1027
	 * must add all four columns through changeSchema() (not preSchemaChange).
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
		$this->assertTrue($schema->hasColumn('chorestrike_house_users', 'coin_multiplier'));
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

	/**
	 * @return mixed
	 */
	public function __call(string $name, array $arguments) {
		return $this;
	}
}
