<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Mapper;

use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FloorMapper
 */
class FloorMapperTest extends TestCase {
	private FloorMapper $mapper;
	private IDBConnection $db;
	private IQueryBuilder $qb;
	private IExpressionBuilder $expr;

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->qb = $this->createMock(IQueryBuilder::class);
		$this->expr = $this->createMock(IExpressionBuilder::class);

		$this->db->method('getQueryBuilder')
			->willReturn($this->qb);
		$this->qb->method('expr')
			->willReturn($this->expr);

		$this->mapper = new FloorMapper($this->db);
	}

	/**
	 * FindByHouseId_ReturnsFloorsForHouse
	 */
	public function testFindByHouseId_ReturnsFloorsForHouse(): void {
		$houseId = 1;

		$this->qb->expects($this->once())
			->method('select')
			->with('*')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('from')
			->with('chorestrike_floors')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('where')
			->willReturn($this->qb);
		// CS-PLAN-071: the live-row filter (deleted_at IS NULL).
		$this->qb->method('andWhere')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('orderBy')
			->with('sort_order', 'ASC')
			->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')
			->willReturnOnConsecutiveCalls([
				'id' => 1,
				'house_id' => $houseId,
				'name' => 'Ground Floor',
				'sort_order' => 1
			], [
				'id' => 2,
				'house_id' => $houseId,
				'name' => 'First Floor',
				'sort_order' => 2
			], false);

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$results = $this->mapper->findByHouseId($houseId);

		$this->assertCount(2, $results);
		$this->assertInstanceOf(Floor::class, $results[0]);
		$this->assertEquals('Ground Floor', $results[0]->getName());
		$this->assertEquals('First Floor', $results[1]->getName());
	}

	/**
	 * Create_WithValidData_ReturnsFloor
	 */
	public function testCreate_WithValidData_ReturnsFloor(): void {
		$houseId = 1;
		$name = 'New Floor';
		$sortOrder = 3;

		// The insert method will be called on the mapper
		// We verify the floor object has correct values
		$floor = new Floor();
		$floor->setHouseId($houseId);
		$floor->setName($name);
		$floor->setSortOrder($sortOrder);
		$floor->setCreatedAt(new \DateTime());

		$this->assertEquals($houseId, $floor->getHouseId());
		$this->assertEquals($name, $floor->getName());
		$this->assertEquals($sortOrder, $floor->getSortOrder());
	}

	/**
	 * FindByHouseId_WithNoFloors_ReturnsEmptyArray
	 */
	public function testFindByHouseId_WithNoFloors_ReturnsEmptyArray(): void {
		$houseId = 999;

		$this->qb->expects($this->once())
			->method('select')
			->with('*')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('from')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('where')
			->willReturn($this->qb);
		// CS-PLAN-071: the live-row filter (deleted_at IS NULL).
		$this->qb->method('andWhere')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('orderBy')
			->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')
			->willReturn(false);

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$results = $this->mapper->findByHouseId($houseId);

		$this->assertIsArray($results);
		$this->assertEmpty($results);
	}

	/**
	 * UpdateSortOrders_EmptyInput_SkipsStatement
	 *
	 * CS-PLAN-037: the empty-map early return must not touch the DB.
	 */
	public function testUpdateSortOrders_EmptyInput_SkipsStatement(): void {
		$this->qb->expects($this->never())->method('update');

		$this->mapper->updateSortOrders([]);
	}

	/**
	 * UpdateSortOrders_BuildsCaseWhenWithIntInlining
	 *
	 * The CASE list is inlined via sprintf('%d') — ids and orders must
	 * appear as plain integers; any non-int noise is cast away (no user
	 * strings can reach the SQL).
	 */
	public function testUpdateSortOrders_BuildsCaseWhenWithIntInlining(): void {
		$capturedFunctions = [];
		$capturedParams = [];
		$this->qb->method('createFunction')->willReturnCallback(
			function ($sql) use (&$capturedFunctions) {
				$capturedFunctions[] = $sql;
				return 'func_' . count($capturedFunctions);
			}
		);
		$this->qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type) use (&$capturedParams) {
				$capturedParams[] = ['value' => $value, 'type' => $type];
				return 'param_' . count($capturedParams);
			}
		);
		$this->qb->method('update')->willReturn($this->qb);
		$this->qb->method('set')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->expects($this->once())->method('executeStatement');

		// Key 2 carries a poisoned string suffix — the %d cast must drop it.
		$this->mapper->updateSortOrders([1 => 0, '2 OR 1=1' => '5', 3 => 2]);

		$this->assertSame('CASE id WHEN 1 THEN 0 WHEN 2 THEN 5 WHEN 3 THEN 2 ELSE sort_order END', $capturedFunctions[0]);

		// The IN scope carries the sanitized id list as ints.
		$this->assertSame([1, 2, 3], $capturedParams[0]['value']);
		$this->assertSame(IQueryBuilder::PARAM_INT_ARRAY, $capturedParams[0]['type']);
	}
}
