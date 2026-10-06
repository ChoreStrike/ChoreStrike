<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use OC\DB\ConnectionAdapter;
use OC\DB\SchemaWrapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\PreferenceService;
use OCA\ChoreStrike\Service\TestDataService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\Migration\IOutput;

/** Real installed-DB verification; the in-memory chain never creates DB tables. */
class SupportedDatabaseSchemaTest extends IntegrationTestCase {
	public function testInstalledProvider_MatchesSelectorAndUsesRealStorage(): void {
		$config = \OC::$server->get(IConfig::class);
		$expected = getenv('TEST_DB_TYPE') ?: 'mysql';
		self::assertContains($expected, ['mysql', 'pgsql', 'sqlite']);
		self::assertSame($expected === 'sqlite' ? 'sqlite3' : $expected, $config->getSystemValue('dbtype'));
		// Nextcloud's installation selector is pgsql; its provider API says postgres.
		self::assertSame($expected === 'pgsql' ? 'postgres' : $expected, $this->db->getDatabaseProvider());
		self::assertTrue($config->getSystemValue('installed'));
		self::assertInstanceOf(ConnectionAdapter::class, $this->db);
		if ($expected === 'sqlite') {
			$path = $this->db->getInner()->getParams()['path'];
			self::assertNotSame(':memory:', $path);
			self::assertFileExists($path);
			self::assertStringStartsWith($config->getSystemValue('datadirectory') . '/', $path);
		}
	}

	public function testFullMigratedSchema_ExactlyMatchesTheFreshMigrationChain(): void {
		self::assertInstanceOf(ConnectionAdapter::class, $this->db);
		$expected = new SchemaWrapper($this->db->getInner(), new Schema());
		$output = $this->createMock(IOutput::class);
		$files = glob(__DIR__ . '/../../../lib/Migration/Version*.php');
		sort($files, SORT_STRING);
		$versions = [];
		foreach ($files as $file) {
			$name = basename($file, '.php');
			$class = 'OCA\\ChoreStrike\\Migration\\' . $name;
			self::assertNotNull((new $class())->changeSchema($output, static fn (): ISchemaWrapper => $expected, []), $name);
			$versions[] = substr($name, strlen('Version'));
		}
		$this->assertAppliedMigrationVersions($versions);
		// Migrations use Nextcloud wrappers; parity compares the underlying DBAL schema.
		$expected = $expected->getWrappedSchema();
		$actual = $this->db->createSchema();
		$prefix = \OC::$server->get(IConfig::class)->getSystemValue('dbtableprefix', 'oc_');
		$actualTables = array_filter($actual->getTables(), static fn (Table $table): bool => str_starts_with($table->getName(), $prefix . 'chorestrike_'));
		self::assertCount(30, $expected->getTables(), 'the complete WP0-WP8 chain');
		self::assertEqualsCanonicalizing(
			array_map(static fn (Table $table): string => $table->getName(), $expected->getTables()),
			array_map(static fn (Table $table): string => $table->getName(), array_values($actualTables))
		);
		foreach ($expected->getTables() as $table) {
			$this->assertTableDefinition($table, $actual->getTable($table->getName()));
		}
	}

	private function assertAppliedMigrationVersions(array $expected): void {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('version')->from('migrations')
			->where($qb->expr()->eq('app', $qb->createNamedParameter('chorestrike')))->executeQuery();
		$actual = array_column($result->fetchAll(), 'version');
		$result->closeCursor();
		self::assertEqualsCanonicalizing($expected, $actual, 'every ordered app migration was actually applied');
	}

	private function assertTableDefinition(Table $expected, Table $actual): void {
		self::assertEqualsCanonicalizing(array_keys($expected->getColumns()), array_keys($actual->getColumns()), $expected->getName());
		$platform = $this->db->getDatabasePlatform();
		foreach ($expected->getColumns() as $name => $column) {
			$installed = $actual->getColumn($name);
			$identity = $expected->getName() . '.' . $name;
			self::assertSame($column->getNotnull(), $installed->getNotnull(), $identity . ' nullability');
			self::assertSame($column->getAutoincrement(), $installed->getAutoincrement(), $identity . ' generated identity');
			self::assertSame($column->getType()->getSQLDeclaration($column->toArray(), $platform), $installed->getType()->getSQLDeclaration($installed->toArray(), $platform), $identity . ' type');
			self::assertSame($platform->getDefaultValueDeclarationSQL($column->toArray()), $platform->getDefaultValueDeclarationSQL($installed->toArray()), $identity . ' default');
		}
		self::assertEqualsCanonicalizing($this->indexDefinitions($expected), $this->indexDefinitions($actual), $expected->getName() . ' exact indexes');
	}

	private function indexDefinitions(Table $table): array {
		return array_map(static fn ($index): array => [
			'columns' => $index->getUnquotedColumns(),
			'unique' => $index->isUnique(),
			'primary' => $index->isPrimary(),
		], array_values($table->getIndexes()));
	}

	/** This owns the documented exclusive integration seed/reset boundary. */
	public function testFamilySeedAndReset_RebuildCoherentBindingsAndQuietDefaults(): void {
		$seeder = \OC::$server->get(TestDataService::class);
		$first = $seeder->seed('family')['data'];
		$second = $seeder->seed('family')['data'];
		self::assertNotSame($first['house']['id'], $second['house']['id']);
		self::assertCount(3, $second['floors']);
		self::assertCount(6, $second['rooms']);
		self::assertCount(12, $second['roomTasks']);
		self::assertFalse($second['house']['catalogEnabled']);
		self::assertFalse($second['house']['rankingEnabled']);
		$members = \OC::$server->get(HouseUserMapper::class);
		$prefs = \OC::$server->get(PreferenceService::class);
		$wallets = [];
		foreach ($second['users'] as $user) {
			$actor = $members->findByNextcloudUserId($second['house']['id'], $user['nextcloudUserId']);
			self::assertNotNull($actor->getWalletId());
			$wallets[] = $actor->getWalletId();
			$preferences = $prefs->getOwnPreferences($second['house']['id'], $actor);
			foreach (['taskDigestEnabled', 'orgDigestEnabled', 'soundEnabled', 'animationEnabled'] as $key) {
				self::assertFalse($preferences[$key], $key . ' ships quiet');
			}
		}
		self::assertCount(6, array_unique($wallets));
	}
}
