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

	/**
	 * D11 query pin (CS-PLAN-064): the day-duplicate guard excludes undone
	 * rows — the query must carry `undone_at IS NULL`.
	 */
	public function testCountByRoomTaskAndUserOnDate_ExcludesUndoneRows(): void {
		$isNullColumns = [];
		$this->expr->method('isNull')->willReturnCallback(
			function (string $column) use (&$isNullColumns) {
				$isNullColumns[] = $column;
				return 'UNDONE_NULL_PREDICATE';
			}
		);
		$this->expr->method('eq')->willReturn('EQ');
		$this->expr->method('gte')->willReturn('GTE');
		$this->expr->method('lt')->willReturn('LT');
		$this->qb->method('select')->willReturn($this->qb);
		$this->qb->method('from')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->method('andWhere')->willReturn($this->qb);
		$func = $this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class);
		$func->method('count')->willReturn('COUNT');
		$this->qb->method('func')->willReturn($func);

		$predicates = [];
		$this->qb->method('andWhere')->willReturnCallback(
			function ($predicate) use (&$predicates) {
				$predicates[] = $predicate;
				return $this->qb;
			}
		);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetchOne')->willReturn(0);
		$this->qb->method('executeQuery')->willReturn($mockResult);

		$this->mapper->countByRoomTaskAndUserOnDate(1, 2, new \DateTime(), new \DateTime());

		$this->assertContains('undone_at', $isNullColumns, 'day-duplicate guard must filter undone rows');
	}

	/**
	 * D11 query pin (CS-PLAN-064): the combo window excludes undone rows —
	 * an undone completion neither keeps nor starts a combo.
	 */
	public function testFindRecentByUserId_ExcludesUndoneRows(): void {
		$isNullColumns = [];
		$this->expr->method('isNull')->willReturnCallback(
			function (string $column) use (&$isNullColumns) {
				$isNullColumns[] = $column;
				return 'UNDONE_NULL_PREDICATE';
			}
		);
		$this->expr->method('eq')->willReturn('EQ');
		$this->expr->method('gte')->willReturn('GTE');
		$this->qb->method('select')->willReturn($this->qb);
		$this->qb->method('from')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->method('andWhere')->willReturn($this->qb);
		$this->qb->method('orderBy')->willReturn($this->qb);

		$predicates = [];
		$this->qb->method('andWhere')->willReturnCallback(
			function ($predicate) use (&$predicates) {
				$predicates[] = $predicate;
				return $this->qb;
			}
		);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturn(false);
		$this->qb->method('executeQuery')->willReturn($mockResult);

		$this->assertSame([], $this->mapper->findRecentByUserId(2, 5));

		$this->assertContains('undone_at', $isNullColumns, 'combo window must filter undone rows');
	}

	/**
	 * D11 query pin (CS-PLAN-064): the weekly-goal count excludes undone
	 * rows — undo deflates the weekly goal.
	 */
	public function testFindCompletedRoomTaskIdsBetweenByHouse_ExcludesUndoneRows(): void {
		$isNullColumns = [];
		$this->expr->method('isNull')->willReturnCallback(
			function (string $column) use (&$isNullColumns) {
				$isNullColumns[] = $column;
				return 'UNDONE_NULL_PREDICATE';
			}
		);
		$this->expr->method('eq')->willReturn('EQ');
		$this->expr->method('gte')->willReturn('GTE');
		$this->expr->method('lt')->willReturn('LT');
		$this->qb->method('select')->willReturn($this->qb);
		$this->qb->method('from')->willReturn($this->qb);
		$this->qb->method('innerJoin')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->method('andWhere')->willReturn($this->qb);
		$this->qb->method('groupBy')->willReturn($this->qb);

		$predicates = [];
		$this->qb->method('andWhere')->willReturnCallback(
			function ($predicate) use (&$predicates) {
				$predicates[] = $predicate;
				return $this->qb;
			}
		);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturn(false);
		$mockResult->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($mockResult);

		$this->assertSame([], $this->mapper->findCompletedRoomTaskIdsBetweenByHouse(
			1,
			new \DateTime('2026-09-01'),
			new \DateTime('2026-09-08')
		));

		$this->assertContains('ct.undone_at', $isNullColumns, 'weekly-goal count must filter undone rows');
	}

	/**
	 * findNewestRevertibleByRoomTask: no revertible row → NULL (the undo
	 * aggregate's idempotent no-op trigger), not an exception.
	 */
	public function testFindNewestRevertibleByRoomTask_NoRow_ReturnsNull(): void {
		$this->expr->method('eq')->willReturn('EQ');
		$this->expr->method('isNull')->willReturn('NULL');
		$this->qb->method('select')->willReturn($this->qb);
		$this->qb->method('from')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->method('andWhere')->willReturn($this->qb);
		$this->qb->method('orderBy')->willReturn($this->qb);
		$this->qb->method('addOrderBy')->willReturn($this->qb);
		$this->qb->method('setMaxResults')->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturn(false);
		$this->qb->method('executeQuery')->willReturn($mockResult);

		$this->assertNull($this->mapper->findNewestRevertibleByRoomTask(1));
	}

	/**
	 * markUndone stamps `undone_at` (PARAM_DATE) on the given ledger row.
	 */
	public function testMarkUndone_SetsUndoneAtOnRow(): void {
		$capturedSets = [];
		$this->qb->method('update')->willReturn($this->qb);
		$this->qb->method('set')->willReturnCallback(
			function ($column, $value) use (&$capturedSets) {
				$capturedSets[$column] = $value;
				return $this->qb;
			}
		);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->expects($this->once())->method('executeStatement');

		$at = new \DateTime('2026-09-06T12:00:00');
		$this->mapper->markUndone(42, $at);

		$this->assertArrayHasKey('undone_at', $capturedSets, 'markUndone must write the undone_at column');
	}
}
