<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Mapper;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CompletedTaskMapper
 */
class CompletedTaskMapperTest extends TestCase {
	private CompletedTaskMapper $mapper;
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

		$this->mapper = new CompletedTaskMapper($this->db);
	}

	/**
	 * GetUserStatsByHouse_GroupsRowsIntoPerUserMap
	 */
	public function testGetUserStatsByHouse_GroupsRowsIntoPerUserMap(): void {
		$this->qb->expects($this->once())
			->method('select')
			->with('ct.user_id')
			->willReturn($this->qb);
		$this->qb->method('addSelect')->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('from')
			->with('chorestrike_completed_tasks', 'ct')
			->willReturn($this->qb);
		$this->qb->method('innerJoin')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->method('andWhere')->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('groupBy')
			->with('ct.user_id')
			->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturnOnConsecutiveCalls(
			['user_id' => 1, 'task_count' => 15, 'total_coins' => 200, 'combo_count' => 5, 'active_days' => 4],
			['user_id' => 2, 'task_count' => 3, 'total_coins' => 30, 'combo_count' => 0, 'active_days' => 2],
			false
		);
		$mockResult->expects($this->once())->method('closeCursor');

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$map = $this->mapper->getUserStatsByHouse(
			1,
			new \DateTime('2026-08-01'),
			new \DateTime('2026-08-08')
		);

		$this->assertSame([1, 2], array_keys($map));
		$this->assertSame(15, $map[1]['taskCount']);
		$this->assertSame(4, $map[1]['activeDays']);
		$this->assertSame(3, $map[2]['taskCount']);
	}

	/**
	 * GetUserStatsByHouse_NoCompletions_ReturnsEmptyMap
	 */
	public function testGetUserStatsByHouse_NoCompletions_ReturnsEmptyMap(): void {
		$this->qb->method('select')->willReturn($this->qb);
		$this->qb->method('addSelect')->willReturn($this->qb);
		$this->qb->method('from')->willReturn($this->qb);
		$this->qb->method('innerJoin')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->method('andWhere')->willReturn($this->qb);
		$this->qb->method('groupBy')->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturn(false);
		$mockResult->expects($this->once())->method('closeCursor');

		$this->qb->method('executeQuery')->willReturn($mockResult);

		$this->assertSame([], $this->mapper->getUserStatsByHouse(
			1,
			new \DateTime('2026-08-01'),
			new \DateTime('2026-08-08')
		));
	}

	/**
	 * GetMaxRoomCompletionCountByHouse_ReducesPerUserMaxInPhp
	 */
	public function testGetMaxRoomCompletionCountByHouse_ReducesPerUserMaxInPhp(): void {
		$this->qb->expects($this->once())
			->method('select')
			->with('ct.user_id')
			->willReturn($this->qb);
		$this->qb->method('addSelect')->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('from')
			->with('chorestrike_completed_tasks', 'ct')
			->willReturn($this->qb);
		$this->qb->method('innerJoin')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->method('andWhere')->willReturn($this->qb);
		$this->qb->method('groupBy')->willReturn($this->qb);
		$this->qb->method('addGroupBy')->willReturn($this->qb);

		// user 1: rooms A(2) B(7) → max 7; user 2: room C(4) → max 4.
		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturnOnConsecutiveCalls(
			['user_id' => 1, 'room_id' => 10, 'room_count' => 2],
			['user_id' => 1, 'room_id' => 11, 'room_count' => 7],
			['user_id' => 2, 'room_id' => 12, 'room_count' => 4],
			false
		);
		$mockResult->expects($this->once())->method('closeCursor');

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$map = $this->mapper->getMaxRoomCompletionCountByHouse(
			1,
			new \DateTime('2026-08-01'),
			new \DateTime('2026-08-08')
		);

		$this->assertSame([1 => 7, 2 => 4], $map);
	}

	/**
	 * DeleteByRoomTaskIds_EmptyInput_SkipsStatement
	 *
	 * CS-PLAN-037: the empty-array early return must not build an IN ().
	 */
	public function testDeleteByRoomTaskIds_EmptyInput_SkipsStatement(): void {
		$this->qb->expects($this->never())->method('delete');

		$this->mapper->deleteByRoomTaskIds([]);
	}

	/**
	 * DeleteByRoomTaskIds_BuildsInDeleteWithIntArray
	 */
	public function testDeleteByRoomTaskIds_BuildsInDeleteWithIntArray(): void {
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

		$this->mapper->deleteByRoomTaskIds([10, 11]);

		$this->assertSame([10, 11], $capturedParams[0]['value']);
		$this->assertSame(IQueryBuilder::PARAM_INT_ARRAY, $capturedParams[0]['type']);
	}
}
