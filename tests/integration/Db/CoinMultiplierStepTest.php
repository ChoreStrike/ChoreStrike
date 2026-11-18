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
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Migration\Version1037Date20261118000000;
use OCA\ChoreStrike\Service\CoinMultiplierService;
use OCA\ChoreStrike\Service\CompletionReadModel;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\CycleFixtures;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Migration\IOutput;

/**
 * The schema step Version1037 on the real database of every supported
 * provider (CS-PLAN-091 WP-B5, ADR-021 decision 11): a populated household
 * keeps every stored row. A member reads the coin factor 1 without a
 * write, and a completion stored before the update — both new columns
 * empty — goes on being corrected at the rate it was paid, whatever the
 * multipliers are by then.
 *
 * Fresh/upgrade schema parity is {@see SupportedDatabaseSchemaTest}; the
 * real app upgrade from the retained baseline is the P4 proof of the
 * preservation runner.
 */
class CoinMultiplierStepTest extends IntegrationTestCase {
	use CycleFixtures;

	protected const OWNER = 'cms_owner';
	protected const HELPER = 'cms_helper';
	private const STEP = '1037Date20261118000000';
	private const TABLES = ['chorestrike_house_users', 'chorestrike_completed_tasks', 'chorestrike_bonus_periods'];

	protected function setUp(): void {
		parent::setUp();
		$this->initToday();
	}

	private function bookedMinor(int $houseId, string $userId): int {
		return (int)\OC::$server->get(WalletMapper::class)->findByHouseAndUser($houseId, $userId)?->getBookedMinor();
	}

	/** A completion as the build before the update stored it: paid at the plain rate, both new columns empty. */
	private function storedCompletion(array $f): int {
		$result = $this->completeOccurrenceWithParticipants(
			\OC::$server->get(CompletionService::class), $f['houseId'], static::OWNER, $f['roomTaskId'],
			[['userId' => static::OWNER, 'sharePct' => 100]]
		);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_completed_tasks')
			->set('bonus_percent', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('coin_factors', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($result['completionId'], IQueryBuilder::PARAM_INT)))
			->executeStatement();
		return (int)$result['completionId'];
	}

	/** @return array<string, array{columns: list<string>, indexes: list<string>}> */
	private function shape(\Doctrine\DBAL\Schema\Schema|ISchemaWrapper $schema): array {
		$prefix = $schema instanceof ISchemaWrapper ? '' : \OC::$server->get(\OCP\IConfig::class)->getSystemValue('dbtableprefix', 'oc_');
		$shape = [];
		foreach (self::TABLES as $name) {
			$table = $schema->getTable($prefix . $name);
			$columns = array_map(static fn ($column): string => $column->getName(), array_values($table->getColumns()));
			$indexes = array_map(static fn ($index): string => implode(',', $index->getUnquotedColumns()), array_values($table->getIndexes()));
			sort($columns);
			sort($indexes);
			$shape[$name] = ['columns' => $columns, 'indexes' => $indexes];
		}
		return $shape;
	}

	public function testStep_AMemberStoredBeforeTheUpdate_ReadsTheFactorOneFromTheColumnDefault(): void {
		$f = $this->fixture();
		// A member row written without naming the new column, as every stored row was.
		$this->addHouseMember($f['houseId'], 'cms_kid', 'Kid', 'child');

		$stored = array_column($this->rows('chorestrike_house_users', 'house_id', $f['houseId']), 'coin_factor');

		self::assertSame([1, 1, 1], array_map('intval', $stored), 'the column default, not a data step, gives every member × 1');
		self::assertSame(1, $this->member($f['houseId'], 'cms_kid')->getCoinFactor());
	}

	public function testStep_ACompletionStoredBeforeTheUpdate_IsCorrectedAtTheRateItWasPaid(): void {
		$f = $this->fixture(['flex_days' => 0]);
		$completionId = $this->storedCompletion($f);
		$owner = $this->member($f['houseId'], static::OWNER);
		self::assertSame(2000, $this->bookedMinor($f['houseId'], static::OWNER), 'energy 2 at the plain rate');

		$stored = \OC::$server->get(CompletedTaskMapper::class)->find($completionId);
		self::assertSame([null, null], [$stored->getBonusPercent(), $stored->getCoinFactors()], 'the fixture is in the shape before the update');
		self::assertSame([100, 1], [$stored->bonusPercentOrDefault(), $stored->coinFactorOf((int)$this->member($f['houseId'], static::HELPER)->getWalletId())]);
		$shown = \OC::$server->get(CompletionReadModel::class)->show($f['houseId'], $owner, $completionId);
		self::assertSame([100, [1]], [$shown['bonusPercent'], array_column($shown['allocation'], 'coinFactor')]);

		// After the update the owner raises the helper's factor and starts bonus days.
		$this->actingAs(static::OWNER);
		$multipliers = \OC::$server->get(CoinMultiplierService::class);
		$multipliers->setFactor($f['houseId'], static::HELPER, 3, $this->newCommandId());
		$multipliers->startPeriod($f['houseId'], $this->today, 1, $this->newCommandId());

		// Moving the stored work to the helper takes back what the owner was
		// paid and pays the helper the same: neither multiplier reaches it.
		$completions = \OC::$server->get(CompletionService::class);
		$completions->attribute($f['houseId'], $owner, $completionId, $this->newCommandId(), 0, [['userId' => static::HELPER, 'sharePct' => 100]], true, null);
		self::assertSame([0, 2000], [$this->bookedMinor($f['houseId'], static::OWNER), $this->bookedMinor($f['houseId'], static::HELPER)]);
		$after = $this->rows('chorestrike_completed_tasks', 'id', $completionId)[0];
		self::assertSame([null, null], [$after['bonus_percent'], $after['coin_factors']], 'a correction does not back-fill what the completion never froze');

		// The undo reverses exactly what the share rows recorded.
		$completions->undo($f['houseId'], $owner, $completionId, $this->newCommandId(), 1, null);
		self::assertSame([0, 0], [$this->bookedMinor($f['houseId'], static::OWNER), $this->bookedMinor($f['houseId'], static::HELPER)]);
	}

	public function testStep_OnTheInstalledSchema_AddsNothingASecondTime(): void {
		self::assertInstanceOf(ConnectionAdapter::class, $this->db);
		$installed = $this->shape($this->db->createSchema());
		$schema = new SchemaWrapper($this->db->getInner());

		$result = (new Version1037Date20261118000000())->changeSchema($this->createMock(IOutput::class), static fn (): ISchemaWrapper => $schema, []);

		self::assertSame($installed, $this->shape($result), 'every addition is guarded by what the schema already holds');
		self::assertContains('coin_factor', $installed['chorestrike_house_users']['columns']);
		self::assertEmpty(array_diff(['bonus_percent', 'coin_factors'], $installed['chorestrike_completed_tasks']['columns']));
		self::assertContains('house_id,ends_on', $installed['chorestrike_bonus_periods']['indexes']);
	}

	public function testRunner_DoesNotApplyTheRecordedStepAgain(): void {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('version')->from('migrations')
			->where($qb->expr()->eq('app', $qb->createNamedParameter('chorestrike')))
			->andWhere($qb->expr()->eq('version', $qb->createNamedParameter(self::STEP)))
			->executeQuery();
		$recorded = array_column($result->fetchAll(), 'version');
		$result->closeCursor();
		self::assertSame([self::STEP], $recorded, 'the installation records the step exactly once');

		// A populated household: a factor above 1, a period and a completion that froze both.
		$f = $this->fixture();
		$this->actingAs(static::OWNER);
		$multipliers = \OC::$server->get(CoinMultiplierService::class);
		$multipliers->setFactor($f['houseId'], static::HELPER, 2, $this->newCommandId());
		$multipliers->startPeriod($f['houseId'], $this->today, 3, $this->newCommandId());
		$this->completeOccurrenceWithParticipants(
			\OC::$server->get(CompletionService::class), $f['houseId'], static::OWNER, $f['roomTaskId'],
			[['userId' => static::HELPER, 'sharePct' => 100]]
		);
		$read = fn (): array => [
			$this->rows('chorestrike_house_users', 'house_id', $f['houseId']),
			$this->rows('chorestrike_bonus_periods', 'house_id', $f['houseId']),
			$this->rows('chorestrike_completed_tasks', 'room_task_id', $f['roomTaskId']),
		];
		$before = $read();
		// Energy 2 at × 2 on a bonus day: 2000 × 2 × 1.5.
		self::assertSame([150, 6000], [(int)$before[2][0]['bonus_percent'], $this->bookedMinor($f['houseId'], static::HELPER)]);

		(new MigrationService('chorestrike', \OC::$server->get(Connection::class)))->migrate();

		self::assertSame($before, $read(), 'the runner leaves a populated household as it is');
	}
}
