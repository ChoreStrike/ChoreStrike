<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Mapper;

use OCA\ChoreStrike\Db\AvailabilitySettings;
use OCA\ChoreStrike\Db\AvailabilitySettingsMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AvailabilitySettingsMapper
 */
class AvailabilitySettingsMapperTest extends TestCase {
	private AvailabilitySettingsMapper $mapper;
	private IDBConnection $db;
	private IQueryBuilder $qb;
	private IExpressionBuilder $expr;

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->qb = $this->createMock(IQueryBuilder::class);
		$this->expr = $this->createMock(IExpressionBuilder::class);

		$this->db->method('getQueryBuilder')->willReturn($this->qb);
		$this->qb->method('expr')->willReturn($this->expr);

		$this->mapper = new AvailabilitySettingsMapper($this->db);
	}

	public function testFindByHouseAndUser_ReturnsSettings(): void {
		$this->qb->method('select')->willReturnSelf();
		$this->qb->method('from')->willReturnSelf();
		$this->qb->method('where')->willReturnSelf();
		$this->qb->method('andWhere')->willReturnSelf();

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(
			['id' => 1, 'house_id' => 2, 'user_id' => 'alice', 'planning_mode' => 'auto_balance'],
			false
		);
		$this->qb->method('executeQuery')->willReturn($result);

		$settings = $this->mapper->findByHouseAndUser(2, 'alice');

		$this->assertInstanceOf(AvailabilitySettings::class, $settings);
		$this->assertEquals('alice', $settings->getUserId());
	}

	public function testFindByHouseAndUser_NotFound_ReturnsNull(): void {
		$this->qb->method('select')->willReturnSelf();
		$this->qb->method('from')->willReturnSelf();
		$this->qb->method('where')->willReturnSelf();
		$this->qb->method('andWhere')->willReturnSelf();

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$this->qb->method('executeQuery')->willReturn($result);

		$this->assertNull($this->mapper->findByHouseAndUser(2, 'nobody'));
	}

	public function testFindByHouse_ReturnsAllSettings(): void {
		$this->qb->method('select')->willReturnSelf();
		$this->qb->method('from')->willReturnSelf();
		$this->qb->method('where')->willReturnSelf();

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(
			['id' => 1, 'house_id' => 2, 'user_id' => 'alice'],
			['id' => 2, 'house_id' => 2, 'user_id' => 'bob'],
			false
		);
		$this->qb->method('executeQuery')->willReturn($result);

		$settings = $this->mapper->findByHouse(2);

		$this->assertCount(2, $settings);
	}
}
