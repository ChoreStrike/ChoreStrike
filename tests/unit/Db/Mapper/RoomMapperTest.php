<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Mapper;

use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomMapper
 */
class RoomMapperTest extends TestCase {
	private RoomMapper $mapper;
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

		$this->mapper = new RoomMapper($this->db);
	}

	/**
	 * FindByFloorId_ReturnsRoomsForFloor
	 */
	public function testFindByFloorId_ReturnsRoomsForFloor(): void {
		$floorId = 1;

		$this->qb->expects($this->once())
			->method('select')
			->with('*')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('from')
			->with('chorestrike_rooms')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('where')
			->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')
			->willReturnOnConsecutiveCalls([
				'id' => 1,
				'floor_id' => $floorId,
				'name' => 'Kitchen',
				'cleanliness' => 80,
				'monster_count' => 0
			], [
				'id' => 2,
				'floor_id' => $floorId,
				'name' => 'Living Room',
				'cleanliness' => 60,
				'monster_count' => 1
			], false);

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$results = $this->mapper->findByFloorId($floorId);

		$this->assertCount(2, $results);
		$this->assertInstanceOf(Room::class, $results[0]);
		$this->assertEquals('Kitchen', $results[0]->getName());
		$this->assertEquals('Living Room', $results[1]->getName());
	}

	/**
	 * FindByIds_EmptyInput_ReturnsEmptyMapWithoutQuery
	 *
	 * CS-PLAN-036: the empty-array early return must not build an IN ().
	 */
	public function testFindByIds_EmptyInput_ReturnsEmptyMapWithoutQuery(): void {
		$this->qb->expects($this->never())->method('select');

		$this->assertSame([], $this->mapper->findByIds([]));
	}

	/**
	 * FindByIds_BuildsInQueryAndMapsById
	 */
	public function testFindByIds_BuildsInQueryAndMapsById(): void {
		$capturedTypes = [];
		$this->qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type) use (&$capturedTypes) {
				$capturedTypes[] = $type;
				return 'param_' . count($capturedTypes);
			}
		);
		$this->qb->method('select')->willReturn($this->qb);
		$this->qb->method('from')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturnOnConsecutiveCalls(
			['id' => 4, 'floor_id' => 1, 'name' => 'Kitchen', 'cleanliness' => 80, 'monster_count' => 0],
			false
		);
		$this->qb->method('executeQuery')->willReturn($mockResult);

		$map = $this->mapper->findByIds([4, 99]);

		// id 99 is missing from the result → tolerated, absent from the map.
		$this->assertSame([4], array_keys($map));
		$this->assertInstanceOf(Room::class, $map[4]);
		$this->assertSame('Kitchen', $map[4]->getName());
		$this->assertContains(IQueryBuilder::PARAM_INT_ARRAY, $capturedTypes);
	}

	/**
	 * ResetByHouseId_BuildsSubqueryUpdateAndReturnsAffectedRows
	 *
	 * CS-PLAN-037: one UPDATE scoped by a floors-of-house subquery; the
	 * house parameter placeholder is embedded into the subquery SQL and
	 * the affected row count is passed through.
	 */
	public function testResetByHouseId_BuildsSubqueryUpdateAndReturnsAffectedRows(): void {
		$capturedParams = [];
		$capturedFunctions = [];
		$this->qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type) use (&$capturedParams) {
				$capturedParams[] = ['value' => $value, 'type' => $type];
				return 'param_' . count($capturedParams);
			}
		);
		$this->qb->method('createFunction')->willReturnCallback(
			function ($sql) use (&$capturedFunctions) {
				$capturedFunctions[] = $sql;
				return 'func_' . count($capturedFunctions);
			}
		);
		$this->qb->method('getSQL')->willReturn('SELECT f.id FROM *PREFIX*chorestrike_floors f WHERE f.house_id = :param_1');
		$this->qb->method('select')->willReturn($this->qb);
		$this->qb->method('from')->willReturn($this->qb);
		$this->qb->method('update')->willReturn($this->qb);
		$this->qb->method('set')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->method('executeStatement')->willReturn(5);

		$this->assertSame(5, $this->mapper->resetByHouseId(3));

		// The WHERE carries the subquery SQL verbatim.
		$this->assertCount(1, $capturedFunctions);
		$this->assertStringContainsString('SELECT f.id FROM', $capturedFunctions[0]);
		$this->assertStringContainsString('house_id = :param_1', $capturedFunctions[0]);

		// Params in order: houseId, cleanliness 100, monsters 0, NULL.
		$this->assertSame(3, $capturedParams[0]['value']);
		$this->assertSame(IQueryBuilder::PARAM_INT, $capturedParams[0]['type']);
		$this->assertSame(100, $capturedParams[1]['value']);
		$this->assertSame(0, $capturedParams[2]['value']);
		$this->assertNull($capturedParams[3]['value']);
		$this->assertSame(IQueryBuilder::PARAM_NULL, $capturedParams[3]['type']);
	}

	/**
	 * DeleteByFloorId_BuildsFloorScopedDeleteStatement
	 */
	public function testDeleteByFloorId_BuildsFloorScopedDeleteStatement(): void {
		$capturedParams = [];
		$this->qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type) use (&$capturedParams) {
				$capturedParams[] = ['value' => $value, 'type' => $type];
				return 'param_' . count($capturedParams);
			}
		);
		$this->qb->method('delete')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->expects($this->once())->method('executeStatement');

		$this->mapper->deleteByFloorId(7);

		$this->assertSame(7, $capturedParams[0]['value']);
		$this->assertSame(IQueryBuilder::PARAM_INT, $capturedParams[0]['type']);
	}

	/**
	 * Room_GetCleanlinessLabel_ReturnsCorrectLabel
	 */
	public function testRoom_GetCleanlinessLabel_ReturnsCorrectLabel(): void {
		$cleanRoom = new Room();
		$cleanRoom->setCleanliness(80);
		$this->assertEquals('clean', $cleanRoom->getCleanlinessLabel());

		$moderateRoom = new Room();
		$moderateRoom->setCleanliness(50);
		$this->assertEquals('moderate', $moderateRoom->getCleanlinessLabel());

		$dirtyRoom = new Room();
		$dirtyRoom->setCleanliness(30);
		$this->assertEquals('dirty', $dirtyRoom->getCleanlinessLabel());

		$filthyRoom = new Room();
		$filthyRoom->setCleanliness(10);
		$this->assertEquals('filthy', $filthyRoom->getCleanlinessLabel());
	}

	/**
	 * Room_NeedsCleaning_ReturnsCorrectBoolean
	 */
	public function testRoom_NeedsCleaning_ReturnsCorrectBoolean(): void {
		$cleanRoom = new Room();
		$cleanRoom->setCleanliness(80);
		$this->assertFalse($cleanRoom->needsCleaning());

		$dirtyRoom = new Room();
		$dirtyRoom->setCleanliness(40);
		$this->assertTrue($dirtyRoom->needsCleaning());
	}
}
