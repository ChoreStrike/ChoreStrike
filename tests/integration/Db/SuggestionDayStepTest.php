<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OC\DB\Connection;
use OC\DB\ConnectionAdapter;
use OC\DB\MigrationService;
use OC\DB\SchemaWrapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Migration\Version1038Date20261121000000;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\SuggestionService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\CycleFixtures;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;

/**
 * The schema step Version1038 on the real database of every supported
 * provider (CS-PLAN-091 WP-C, ADR-022 decision 3): it adds the snapshot
 * table of the live suggestion and touches no stored row. A populated
 * household reads its suggestion at once, and the runner leaves both the
 * household and its snapshot as they are.
 *
 * Fresh/upgrade schema parity is {@see SupportedDatabaseSchemaTest}; the
 * real app upgrade from the retained baseline is the P4 proof of the
 * preservation runner.
 */
class SuggestionDayStepTest extends IntegrationTestCase {
	use CycleFixtures;

	protected const OWNER = 'sds_owner';
	protected const HELPER = 'sds_helper';
	private const STEP = '1038Date20261121000000';
	private const TABLE = 'chorestrike_suggestion_days';

	protected function setUp(): void {
		parent::setUp();
		$this->initToday();
	}

	/** @return array{columns: list<string>, indexes: list<string>} */
	private function shape(\Doctrine\DBAL\Schema\Schema|ISchemaWrapper $schema): array {
		$prefix = $schema instanceof ISchemaWrapper ? '' : \OC::$server->get(\OCP\IConfig::class)->getSystemValue('dbtableprefix', 'oc_');
		$table = $schema->getTable($prefix . self::TABLE);
		$columns = array_map(static fn ($column): string => $column->getName(), array_values($table->getColumns()));
		$indexes = array_map(
			static fn ($index): string => ($index->isUnique() ? 'unique:' : '') . implode(',', $index->getUnquotedColumns()),
			array_values($table->getIndexes())
		);
		sort($columns);
		sort($indexes);
		return ['columns' => $columns, 'indexes' => $indexes];
	}

	public function testStep_OnTheInstalledSchema_AddsNothingASecondTime(): void {
		self::assertInstanceOf(ConnectionAdapter::class, $this->db);
		$installed = $this->shape($this->db->createSchema());
		$schema = new SchemaWrapper($this->db->getInner());

		$result = (new Version1038Date20261121000000())->changeSchema($this->createMock(IOutput::class), static fn (): ISchemaWrapper => $schema, []);

		self::assertSame($installed, $this->shape($result), 'the table is guarded by what the schema already holds');
		self::assertSame(['created_at', 'day_key', 'house_id', 'id', 'placements'], $installed['columns']);
		self::assertContains('unique:house_id,day_key', $installed['indexes'], 'one row per house and day');
	}

	public function testRunner_DoesNotApplyTheRecordedStepAgain_AndLeavesAPopulatedHouseholdAsItIs(): void {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('version')->from('migrations')
			->where($qb->expr()->eq('app', $qb->createNamedParameter('chorestrike')))
			->andWhere($qb->expr()->eq('version', $qb->createNamedParameter(self::STEP)))
			->executeQuery();
		$recorded = array_column($result->fetchAll(), 'version');
		$result->closeCursor();
		self::assertSame([self::STEP], $recorded, 'the installation records the step exactly once');

		// A populated household: stored work and a suggestion read that stored today's list.
		$f = $this->fixture(['frequency_type' => 'weekly', 'flex_days' => 1]);
		$second = $this->assignTaskToRoom($this->createTask($f['houseId'], 'Vacuum', '', 3, 2), $f['roomId'], static::HELPER, $this->today . ' 00:00:00');
		$this->completeOccurrence(\OC::$server->get(CompletionService::class), $f['houseId'], static::OWNER, $f['roomTaskId']);
		$house = \OC::$server->get(HouseMapper::class)->find($f['houseId']);
		$today = \OC::$server->get(SuggestionService::class)->view($house, new \DateTime())->today();
		self::assertSame([$second], array_keys($today), 'the open task of the household is suggested');
		$read = fn (): array => [
			$this->rows(self::TABLE, 'house_id', $f['houseId']),
			$this->rows('chorestrike_room_tasks', 'room_id', $f['roomId']),
			$this->rows('chorestrike_completed_tasks', 'room_task_id', $f['roomTaskId']),
		];
		$before = $read();
		self::assertCount(1, $before[0]);

		(new MigrationService('chorestrike', \OC::$server->get(Connection::class)))->migrate();

		self::assertSame($before, $read(), 'the runner leaves a populated household and its snapshot as they are');
	}
}
